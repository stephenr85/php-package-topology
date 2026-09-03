<?php

namespace Rushing\PackageTopology\Sources;

use Rushing\Graphine\Contracts\GraphSource;
use Rushing\Graphine\Dto\Edge;
use Rushing\Graphine\Dto\Node;
use Rushing\Graphine\Dto\NodeId;
use Rushing\PackageTopology\Contract\PackageScope;
use Rushing\PackageTopology\Contract\TopologyContract;

/**
 * THE PACKAGE-GRAPH SOURCE — a graphine {@see GraphSource} over installed
 * composer manifests. Nodes are the scoped installed packages; edges are their
 * `require` relationships. The graphine relational driver hydrates this once and
 * answers every direct-edge / reachability / cycle query the evaluator runs.
 *
 * A package-require graph IS a graph (nodes = packages, edges = requires), so the
 * hand-rolled `composerRequire()` + `expect()->toContain()` scans collapse onto
 * the shared graph substrate instead of being re-copied per repo.
 *
 * THE ALLOW-LIST IS LOAD-BEARING, NOT OPTIONAL. Unscoped, this would ingest all
 * of `vendor/` (hundreds of transitive packages), exploding the reachability
 * queries and burying signal. The default scopes to the monorepo's own vendors
 * (~dozens of nodes → trivial `shortestPath`/`detectCycles`). Widening the scope
 * widens the compute — keep it tight.
 *
 * NAMED PACKAGES ARE IN SCOPE WHATEVER THE GLOBS SAY. `$named` (in practice
 * {@see TopologyContract::packagesNamed()}) admits the exact packages a contract
 * asks about, and nothing else — bounded by the declarations, not by `vendor/`.
 * A `mustRequire('rushing/laravel-surgeon', 'nikic/php-parser')` is a claim ABOUT
 * a manifest that declares the edge, so the edge exists; before this the target
 * matched no glob, the edge was never materialised, and the rule failed at every
 * host that ran it — a rule reporting FAILURE by not looking. Reach before
 * precision: what the instrument can SEE is worth more than what it decides.
 *
 * PHANTOM ENDPOINTS. A `require` target that is in-scope-by-glob but has no
 * installed manifest is emitted as a Node with `properties['installed'] => false`,
 * and its edge is KEPT (a deliberate divergence from the drop-endpoint behaviour
 * of graphine's AdjacencyListSource) — a missing package is a *finding*, so
 * `mustNotRequire` and `mustBeInstalled` rules still fire against it.
 *
 * Pure filesystem: it reads `vendor/{vendor}/{name}/composer.json`, no DB, no
 * Splicewire vocabulary. Spine-only — it declares no governance gates.
 */
class ComposerManifestGraphSource implements GraphSource, PackageScope
{
    /** @var array<string,array<string,mixed>>|null memoised name => manifest for installed in-scope packages */
    private ?array $manifests = null;

    /**
     * @param  string  $vendorPath  the composer `vendor/` directory (e.g. base_path('vendor'))
     * @param  list<string>  $include  vendor/name globs — the LOAD-BEARING scope
     * @param  list<string>  $requireKeys  manifest keys to read edges from (opt in 'require-dev')
     * @param  list<string>  $named  exact package names admitted regardless of the globs —
     *                               pass {@see TopologyContract::packagesNamed()}
     */
    public function __construct(
        private string $vendorPath,
        private array $include = ['rushing/*', 'splicewire/*'],
        private array $requireKeys = ['require'],
        private array $named = [],
    ) {}

    /**
     * {@inheritDoc}
     *
     * A package is SEEN when a glob matches it or a contract named it — whether or
     * not it turns out to be installed (an unseen package yields no node and no
     * edge; an in-scope uninstalled one yields a phantom, which is a finding).
     */
    public function sees(string $name): bool
    {
        return $this->inScope($name);
    }

    /** @return iterable<Node> */
    public function nodes(): iterable
    {
        foreach ($this->manifests() as $name => $manifest) {
            yield new Node(NodeId::of($name), 'Package', [
                'installed' => true,
                'version' => $manifest['version'] ?? null,
            ]);
        }

        // Phantom endpoints: in-scope require targets with no installed manifest.
        foreach ($this->phantomTargets() as $name) {
            yield new Node(NodeId::of($name), 'Package', ['installed' => false]);
        }
    }

    /** @return iterable<Edge> */
    public function edges(): iterable
    {
        foreach ($this->manifests() as $name => $manifest) {
            foreach ($this->requireKeys as $key) {
                foreach (array_keys((array) ($manifest[$key] ?? [])) as $target) {
                    $target = (string) $target;
                    // Out-of-scope + platform (php, ext-*, composer-*) requires are dropped.
                    if ($this->inScope($target)) {
                        yield new Edge(NodeId::of($name), NodeId::of($target), 'REQUIRES', 1.0);
                    }
                }
            }
        }
    }

    /** @return iterable<array{0: NodeId, 1: float}> */
    public function gates(): iterable
    {
        return [];
    }

    public function providesGates(): bool
    {
        return false;
    }

    // --- internals ------------------------------------------------------------

    /**
     * Installed, in-scope manifests keyed by package name — read once, memoised.
     *
     * @return array<string,array<string,mixed>>
     */
    private function manifests(): array
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $found = [];
        foreach ($this->include as $glob) {
            foreach (glob("{$this->vendorPath}/{$glob}/composer.json") ?: [] as $file) {
                $decoded = json_decode((string) @file_get_contents($file), true);
                if (! is_array($decoded)) {
                    continue;
                }
                $name = is_string($decoded['name'] ?? null) ? $decoded['name'] : $this->deriveName($file);
                $found[$name] = $decoded;
            }
        }

        // Named packages the globs never reached: read the one manifest each, by path.
        foreach ($this->named as $name) {
            if (isset($found[$name])) {
                continue;
            }
            $file = "{$this->vendorPath}/{$name}/composer.json";
            // A named package with no manifest is not an error — it is a PHANTOM,
            // and phantomTargets()/mustBeInstalled are what report it.
            if (! is_file($file)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $found[$name] = $decoded;
            }
        }
        ksort($found);

        return $this->manifests = $found;
    }

    /**
     * Distinct in-scope require targets that have no installed manifest.
     *
     * @return list<string>
     */
    private function phantomTargets(): array
    {
        $manifests = $this->manifests();
        $phantoms = [];

        foreach ($manifests as $manifest) {
            foreach ($this->requireKeys as $key) {
                foreach (array_keys((array) ($manifest[$key] ?? [])) as $target) {
                    $target = (string) $target;
                    if ($this->inScope($target) && ! isset($manifests[$target])) {
                        $phantoms[$target] = true;
                    }
                }
            }
        }

        return array_keys($phantoms);
    }

    private function inScope(string $name): bool
    {
        if (in_array($name, $this->named, true)) {
            return true;
        }

        foreach ($this->include as $glob) {
            if (fnmatch($glob, $name)) {
                return true;
            }
        }

        return false;
    }

    /** Fallback package name from the path: `vendor/{vendor}/{name}/composer.json` → `vendor/name`. */
    private function deriveName(string $file): string
    {
        $dir = dirname($file);

        return basename(dirname($dir)).'/'.basename($dir);
    }
}
