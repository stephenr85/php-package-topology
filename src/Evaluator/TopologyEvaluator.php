<?php

namespace Rushing\PackageTopology\Evaluator;

use Rushing\Graphine\Contracts\ComputeStore;
use Rushing\Graphine\Contracts\GraphStore;
use Rushing\Graphine\Contracts\StructureStore;
use Rushing\Graphine\Dto\Node;
use Rushing\Graphine\Dto\NodeId;
use Rushing\Graphine\Dto\Path;
use Rushing\Graphine\Enums\TraversalDirection;
use Rushing\Graphine\Testing\SeamGuard;
use Rushing\PackageTopology\Contract\RuleKind;
use Rushing\PackageTopology\Contract\TopologyContract;
use Rushing\PackageTopology\Contract\TopologyRule;
use Rushing\PackageTopology\Contract\TopologyViolation;

/**
 * Evaluates a {@see TopologyContract} against a hydrated graphine store (the
 * package-graph axis) and, for source rules, against a package's `src/` via
 * graphine's AST {@see SeamGuard} (the source-import axis). Returns a flat list
 * of legible {@see TopologyViolation}s — empty means the contract holds.
 *
 * Rule → graphine query:
 *   mustRequire      → neighbours(a, Descendants, maxDepth: 1) contains b
 *   mustRequireDev   → vendor/a/composer.json['require-dev'] contains b (off-graph)
 *   mustNotRequire   → neighbours(a, Descendants, maxDepth: 1) excludes b
 *   neverReaches     → shortestPath(a, b) === null
 *   downOnly         → each from: shortestPath(pkg, from) === null
 *   layerOrder       → pairwise lower→higher: shortestPath(lower, higher) === null
 *   mustBeAcyclic    → detectCycles() === []
 *   mustBeInstalled  → getNode(pkg)?->properties['installed'] === true
 *   sourceNeverReferences → (new SeamGuard(prefixes))->scan(vendor/{pkg}/src) === []
 *   sourceNeverImports    → (new SeamGuard(prefixes, importsOnly: true))->scan(vendor/{pkg}/src) === []
 */
class TopologyEvaluator
{
    /**
     * @param  string  $vendorPath  the vendor root, used to locate `vendor/{pkg}/src` for source rules
     * @return list<TopologyViolation>
     */
    public function evaluate(TopologyContract $contract, GraphStore $store, string $vendorPath): array
    {
        $violations = [];
        foreach ($contract->rules as $rule) {
            foreach ($this->check($rule, $store, $vendorPath) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** @return list<TopologyViolation> */
    private function check(TopologyRule $rule, GraphStore $store, string $vendorPath): array
    {
        return match ($rule->kind) {
            RuleKind::RequiredDirectEdge => $this->requiredDirectEdge($rule, $store),
            RuleKind::RequiredDevDirectEdge => $this->requiredDevDirectEdge($rule, $vendorPath),
            RuleKind::ForbiddenDirectEdge => $this->forbiddenDirectEdge($rule, $store),
            RuleKind::ForbiddenReachable => $this->forbiddenReachable($rule, $store),
            RuleKind::DownOnly => $this->downOnly($rule, $store),
            RuleKind::LayerOrder => $this->layerOrder($rule, $store),
            RuleKind::Acyclic => $this->acyclic($rule, $store),
            RuleKind::MustBeInstalled => $this->mustBeInstalled($rule, $store),
            RuleKind::SourceNeverReferences => $this->sourceNeverReferences($rule, $vendorPath),
            RuleKind::SourceNeverImports => $this->sourceNeverReferences($rule, $vendorPath, importsOnly: true),
        };
    }

    // --- package-graph axis ---------------------------------------------------

    /** @return list<TopologyViolation> */
    private function requiredDirectEdge(TopologyRule $rule, GraphStore $store): array
    {
        if (in_array($rule->object, $this->directDependencies($store, (string) $rule->subject), true)) {
            return [];
        }

        return [$this->violation($rule, "{$rule->subject} must require {$rule->object} directly, but does not")];
    }

    /**
     * The DEV-ONLY parallel of {@see self::requiredDirectEdge}. The package graph
     * is hydrated from runtime `require` only, so a `require-dev` edge is invisible
     * to it — this rule instead reads the declaring package's manifest straight off
     * disk (`vendor/{pkg}/composer.json`) and asserts the edge is present in its
     * `require-dev`. Same off-graph seam {@see self::sourceNeverReferences} uses,
     * and it stays graceful when the manifest is missing (a slim CI matrix).
     *
     * @return list<TopologyViolation>
     */
    private function requiredDevDirectEdge(TopologyRule $rule, string $vendorPath): array
    {
        $pkg = (string) $rule->subject;
        $manifestPath = rtrim($vendorPath, '/')."/{$pkg}/composer.json";

        if (! is_file($manifestPath)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($manifestPath), true);
        $devRequires = is_array($decoded) ? array_keys((array) ($decoded['require-dev'] ?? [])) : [];

        if (in_array($rule->object, $devRequires, true)) {
            return [];
        }

        return [$this->violation($rule, "{$rule->subject} must require {$rule->object} as a DEV dependency (require-dev), but does not")];
    }

    /** @return list<TopologyViolation> */
    private function forbiddenDirectEdge(TopologyRule $rule, GraphStore $store): array
    {
        if (! in_array($rule->object, $this->directDependencies($store, (string) $rule->subject), true)) {
            return [];
        }

        return [$this->violation($rule, "{$rule->subject} must not require {$rule->object} directly, but does")];
    }

    /** @return list<TopologyViolation> */
    private function forbiddenReachable(TopologyRule $rule, GraphStore $store): array
    {
        if (! $this->reaches($store, (string) $rule->subject, (string) $rule->object)) {
            return [];
        }

        return [$this->violation($rule, "{$rule->subject} must never reach {$rule->object}, but a dependency path exists")];
    }

    /** @return list<TopologyViolation> */
    private function downOnly(TopologyRule $rule, GraphStore $store): array
    {
        $violations = [];
        foreach ($rule->targets as $target) {
            if ($this->reaches($store, (string) $rule->subject, $target)) {
                $violations[] = $this->violation(
                    $rule,
                    "{$rule->subject} depends UP on {$target} — it must depend DOWN only",
                    object: $target,
                );
            }
        }

        return $violations;
    }

    /** @return list<TopologyViolation> */
    private function layerOrder(TopologyRule $rule, GraphStore $store): array
    {
        $layers = array_values($rule->targets);
        $violations = [];
        foreach ($layers as $i => $lower) {
            foreach (array_slice($layers, $i + 1) as $higher) {
                if ($this->reaches($store, $lower, $higher)) {
                    $violations[] = $this->violation(
                        $rule,
                        "layer {$lower} must not reach higher layer {$higher}, but a dependency path exists",
                        subject: $lower,
                        object: $higher,
                    );
                }
            }
        }

        return $violations;
    }

    /** @return list<TopologyViolation> */
    private function acyclic(TopologyRule $rule, GraphStore $store): array
    {
        if (! $store instanceof ComputeStore) {
            return [];
        }

        $cycles = $store->detectCycles();
        if ($cycles === []) {
            return [];
        }

        return [$this->violation($rule, 'the require graph must be acyclic, but a cycle exists: '.$this->renderCycle($cycles[0]))];
    }

    /** @return list<TopologyViolation> */
    private function mustBeInstalled(TopologyRule $rule, GraphStore $store): array
    {
        $pkg = (string) $rule->subject;
        $node = $store instanceof StructureStore ? $store->getNode(NodeId::of($pkg)) : null;

        if ($node !== null && ($node->properties['installed'] ?? false) === true) {
            return [];
        }

        return [$this->violation($rule, "{$pkg} must be installed, but no manifest was found (required-but-absent phantom)")];
    }

    // --- source-import axis ---------------------------------------------------

    /** @return list<TopologyViolation> */
    private function sourceNeverReferences(TopologyRule $rule, string $vendorPath, bool $importsOnly = false): array
    {
        $pkg = (string) $rule->subject;
        $srcPath = rtrim($vendorPath, '/')."/{$pkg}/src";

        // Graceful when a package src/ is absent (e.g. a slim CI matrix): skip,
        // don't false-fail — matches the existing lint's behaviour.
        if (! is_dir($srcPath)) {
            return [];
        }

        // graphine's SeamGuard appends the `\` segment separator itself (its
        // FORBIDDEN entries carry no trailing backslash), so normalise the
        // declared prefixes — a consumer may write them either way, and the
        // documented `Splicewire\\Engine\\` form must still match.
        $prefixes = array_map(static fn (string $p): string => rtrim($p, '\\'), $rule->targets);

        $violations = [];
        $verb = $importsOnly ? 'imports' : 'references';
        foreach ((new SeamGuard($prefixes, importsOnly: $importsOnly))->scan($srcPath) as $offender) {
            $violations[] = $this->violation($rule, "{$pkg} src {$verb} a forbidden namespace — {$offender}");
        }

        return $violations;
    }

    // --- graphine helpers -----------------------------------------------------

    /**
     * Direct (one-hop) require targets of `$pkg` — a `require` is a *direct* claim.
     *
     * @return list<string>
     */
    private function directDependencies(GraphStore $store, string $pkg): array
    {
        if (! $store instanceof StructureStore) {
            return [];
        }

        return array_map(
            static fn (Node $n): string => $n->id->value,
            $store->neighbours(NodeId::of($pkg), TraversalDirection::Descendants, maxDepth: 1),
        );
    }

    private function reaches(GraphStore $store, string $from, string $to): bool
    {
        if (! $store instanceof ComputeStore || ! $store instanceof StructureStore) {
            return false;
        }

        return $store->shortestPath(NodeId::of($from), NodeId::of($to)) !== null;
    }

    private function renderCycle(Path $cycle): string
    {
        $nodes = array_map(static fn (NodeId $id): string => $id->value, $cycle->nodes);
        // Close the loop visually so a 2-cycle reads a → b → a.
        if ($nodes !== []) {
            $nodes[] = $nodes[0];
        }

        return implode(' → ', $nodes);
    }

    private function violation(TopologyRule $rule, string $detail, ?string $subject = null, ?string $object = null): TopologyViolation
    {
        return new TopologyViolation(
            kind: $rule->kind,
            detail: $detail,
            subject: $subject ?? $rule->subject,
            object: $object ?? $rule->object,
            because: $rule->because,
        );
    }
}
