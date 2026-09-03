<?php

namespace Rushing\PackageTopology\Sources;

use Rushing\PackageTopology\Contract\TopologyContract;

/**
 * THE DECLARED-MANIFEST READER — assembles ONE {@see TopologyContract} out of the
 * topology rules each installed package declares ABOUT ITSELF in its own
 * `composer.json` `extra.package-topology` block.
 *
 * This is the "doctor / install-manifest" move applied to topology: instead of a
 * consumer hand-declaring the whole hierarchy in a bespoke test (and every
 * consumer re-copying — and drifting from — the same edges), each package owns the
 * invariants about its own place in the graph, and a consumer runs ONE thin test
 * that merges whatever the installed tree declares. Correcting a direction (e.g.
 * beam <-> frame) becomes a one-line edit in the package that owns it; every
 * consumer follows automatically.
 *
 * ## Manifest shape
 *
 * The rule keys are the {@see TopologyContract} builder-method names verbatim, so a
 * fragment reads as a direct serialization of the contract API. The SUBJECT of
 * every self-fragment rule is the declaring package (implicit):
 *
 *   "extra": {
 *       "package-topology": {
 *           "because": "the ADR-0138 diamond",           // optional, applied to this fragment's rules
 *           "mustRequire":  ["schemastud/laravel-frame"], // self mustRequire X (exact, runtime require)
 *           "mustRequireDev": ["rushing/laravel-surgeon"], // self mustRequire X in require-dev (DEV-only edge)
 *           "mustNotRequire": ["splicewire/laravel-satellite*"], // self mustNotRequire X (glob -> installed)
 *           "neverReaches": ["some/upper-tier"],          // self neverReaches X
 *           "downOnly":     ["splicewire/laravel-composition-engine"], // self depends DOWN only, from [...]
 *           "mustBeInstalled": ["some/required-peer"],    // X must be installed
 *           "sourceNeverReferences": ["Splicewire\\Circuits\\Execution\\"], // self src never references prefixes (imports OR inline FQN)
 *           "sourceNeverImports": ["Splicewire\\Tower\\"]              // self src never `use`-imports prefixes (inline runtime FQN allowed)
 *       }
 *   }
 *
 * ## Estate-wide policy (no single owner)
 *
 * Prefix rules that belong to no one package — "no `schemastud/*` may require the
 * paid engine" — live in a `policy` block (conventionally on the tier-base
 * package). Both sides are matched as PREFIXES (str_starts_with), expanded against
 * the installed set into exact `mustNotRequire` edges, so they keep teeth against
 * packages added later (a bad require materializes its target into `vendor/`):
 *
 *   "policy": {
 *       "noRequire": [
 *           { "fromPrefix": "schemastud/", "toPrefix": "splicewire/", "exceptPrefix": "splicewire/laravel-beam" }
 *       ]
 *   }
 *
 * The `mustBeAcyclic` invariant is always appended — a DAG is non-negotiable.
 */
class DeclaredContractSource
{
    private $vendorPath;

    /** @var list<string> vendor/name globs — the load-bearing scope */
    private $include;

    /**
     * @param  string  $vendorPath  the composer `vendor/` directory (e.g. base_path('vendor'))
     * @param  list<string>  $include  vendor/name globs (mirrors {@see ComposerManifestGraphSource})
     */
    public function __construct($vendorPath, array $include = ['rushing/*', 'splicewire/*', 'schemastud/*'])
    {
        $this->vendorPath = $vendorPath;
        $this->include = $include;
    }

    public function contract($name = 'declared-topology'): TopologyContract
    {
        $manifests = $this->manifests();
        $names = array_keys($manifests);
        $contract = TopologyContract::for($name);

        foreach ($manifests as $self => $manifest) {
            $decl = $manifest['extra']['package-topology'] ?? null;
            if (! is_array($decl)) {
                continue;
            }
            $because = is_string($decl['because'] ?? null) ? $decl['because'] : null;

            foreach ($this->stringList($decl['mustRequire'] ?? null) as $object) {
                $contract = $contract->mustRequire($self, $object, $because);
            }
            foreach ($this->stringList($decl['mustRequireDev'] ?? null) as $object) {
                $contract = $contract->mustRequireDev($self, $object, $because);
            }
            foreach ($this->stringList($decl['mustNotRequire'] ?? null) as $pattern) {
                foreach ($this->expand($pattern, $names, $self) as $object) {
                    $contract = $contract->mustNotRequire($self, $object, $because);
                }
            }
            foreach ($this->stringList($decl['neverReaches'] ?? null) as $object) {
                $contract = $contract->neverReaches($self, $object, $because);
            }
            $downFrom = $this->stringList($decl['downOnly'] ?? null);
            if ($downFrom !== []) {
                $contract = $contract->downOnly($self, $downFrom, $because);
            }
            foreach ($this->stringList($decl['mustBeInstalled'] ?? null) as $object) {
                $contract = $contract->mustBeInstalled($object, $because);
            }
            $prefixes = $this->stringList($decl['sourceNeverReferences'] ?? null);
            if ($prefixes !== []) {
                $contract = $contract->sourceNeverReferences($self, $prefixes, $because);
            }
            $importPrefixes = $this->stringList($decl['sourceNeverImports'] ?? null);
            if ($importPrefixes !== []) {
                $contract = $contract->sourceNeverImports($self, $importPrefixes, $because);
            }

            // Estate-wide policy fragment (owner-agnostic; merged from wherever declared).
            foreach ($this->policyRules($decl['policy'] ?? null) as $rule) {
                [$fromPrefix, $toPrefix, $except, $policyBecause] = $rule;
                foreach ($this->byPrefix($fromPrefix, $names) as $from) {
                    foreach ($this->byPrefix($toPrefix, $names) as $to) {
                        if ($from === $to || ($except !== null && str_starts_with($to, $except))) {
                            continue;
                        }
                        $contract = $contract->mustNotRequire($from, $to, $policyBecause ?? $because);
                    }
                }
            }
        }

        return $contract->mustBeAcyclic('the package hierarchy must stay a DAG')->build();
    }

    /**
     * Installed, in-scope manifests keyed by package name.
     *
     * @return array<string,array<string,mixed>>
     */
    private function manifests(): array
    {
        $found = [];
        foreach ($this->include as $glob) {
            foreach (glob("{$this->vendorPath}/{$glob}/composer.json") ?: [] as $file) {
                $decoded = json_decode((string) @file_get_contents($file), true);
                if (! is_array($decoded)) {
                    continue;
                }
                $name = is_string($decoded['name'] ?? null)
                    ? $decoded['name']
                    : basename(dirname($file, 2)).'/'.basename(dirname($file));
                $found[$name] = $decoded;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Resolve a `mustNotRequire` entry to concrete package names. A `*`-bearing
     * entry is fnmatch-expanded against the installed set; a bare name is exact
     * (kept as-is so it still fires against a not-yet-installed phantom target).
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function expand($pattern, array $names, $exclude): array
    {
        if (! str_contains($pattern, '*')) {
            return $pattern === $exclude ? [] : [$pattern];
        }

        $out = [];
        foreach ($names as $n) {
            if ($n !== $exclude && fnmatch($pattern, $n)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function byPrefix($prefix, array $names): array
    {
        $out = [];
        foreach ($names as $n) {
            if (str_starts_with($n, $prefix)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @return list<array{0:string,1:string,2:?string,3:?string}> [fromPrefix, toPrefix, exceptPrefix, because]
     */
    private function policyRules($policy): array
    {
        if (! is_array($policy) || ! is_array($policy['noRequire'] ?? null)) {
            return [];
        }
        $rules = [];
        foreach ($policy['noRequire'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['fromPrefix'] ?? null) || ! is_string($entry['toPrefix'] ?? null)) {
                continue;
            }
            $rules[] = [
                $entry['fromPrefix'],
                $entry['toPrefix'],
                is_string($entry['exceptPrefix'] ?? null) ? $entry['exceptPrefix'] : null,
                is_string($entry['because'] ?? null) ? $entry['because'] : null,
            ];
        }

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }

        return $out;
    }
}
