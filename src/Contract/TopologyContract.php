<?php

namespace Rushing\PackageTopology\Contract;

use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Testing\AssertsPackageTopology;

/**
 * A declared package-topology contract — the whole vocabulary a consumer uses to
 * state its architecture, spanning both axes in one fluent declaration.
 *
 * Readonly + immutable: every builder call returns a NEW contract with the rule
 * appended, so a contract can be composed, shared, and reasoned about without
 * spooky mutation. Entry is {@see self::for()}; the trailing {@see self::build()}
 * is a no-op terminal that reads well at the call site.
 *
 *   TopologyContract::for('grounding-kernel-hierarchy')
 *       ->mustNotRequire('a', 'b', because: 'the two engines are decoupled peers')
 *       ->mustRequire('a', 'kernel')
 *       ->downOnly('kernel', from: ['a', 'b'])
 *       ->mustBeAcyclic()
 *       ->sourceNeverReferences('spine', prefixes: ['App\\Engine\\'])
 *       ->build();
 *
 * The rules are evaluated by {@see TopologyEvaluator};
 * the {@see AssertsPackageTopology} kit wires a
 * source + driver and asserts the contract holds against the real installed tree.
 */
class TopologyContract
{
    /**
     * @param  list<TopologyRule>  $rules
     */
    public function __construct(
        public string $name,
        public array $rules = [],
    ) {}

    public static function for(string $name): self
    {
        return new self($name);
    }

    // --- Package-graph axis: direct edges -------------------------------------

    /** `$a`'s composer manifest must `require` `$b` directly (one hop). */
    public function mustRequire(string $a, string $b, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::RequiredDirectEdge, $a, $b, [], $because));
    }

    /**
     * `$a`'s composer manifest must `require-dev` `$b` directly (a DEV-ONLY edge —
     * dev tooling `$a` pulls in only for its own test/CI, never at runtime). Its
     * absence from `require-dev` is the failure; presence in runtime `require`
     * alone does not satisfy it.
     */
    public function mustRequireDev(string $a, string $b, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::RequiredDevDirectEdge, $a, $b, [], $because));
    }

    /** `$a`'s composer manifest must NOT `require` `$b` directly. */
    public function mustNotRequire(string $a, string $b, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::ForbiddenDirectEdge, $a, $b, [], $because));
    }

    // --- Package-graph axis: transitive + cycle -------------------------------

    /** `$a` must never REACH `$b` — no transitive `require` path may exist. */
    public function neverReaches(string $a, string $b, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::ForbiddenReachable, $a, $b, [], $because));
    }

    /**
     * `$pkg` depends DOWN only: it must never reach any package in `$from`
     * (typically the higher tiers that are allowed to depend on it, not vice versa).
     *
     * @param  list<string>  $from
     */
    public function downOnly(string $pkg, array $from, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::DownOnly, $pkg, null, $from, $because));
    }

    /**
     * Declare a strict layering, lowest tier first: no lower layer may reach a
     * higher one (a higher layer may still depend down on a lower).
     *
     * @param  list<string>  $layers  ordered lowest → highest
     */
    public function layerOrder(array $layers, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::LayerOrder, null, null, $layers, $because));
    }

    /** The whole in-scope require graph must be acyclic. */
    public function mustBeAcyclic(?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::Acyclic, null, null, [], $because));
    }

    /** `$pkg` must be installed (a required-but-absent phantom fails this). */
    public function mustBeInstalled(string $pkg, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::MustBeInstalled, $pkg, null, [], $because));
    }

    // --- Source-import axis ---------------------------------------------------

    /**
     * `$pkg`'s `src/` must reference none of `$prefixes` — an AST-level namespace
     * boundary (graphine's `SeamGuard`), stronger than a substring scan.
     *
     * @param  list<string>  $prefixes  forbidden namespace prefixes
     */
    public function sourceNeverReferences(string $pkg, array $prefixes, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::SourceNeverReferences, $pkg, null, $prefixes, $because));
    }

    /**
     * `$pkg`'s `src/` must `use`-import none of `$prefixes` — the PARSE-TIME half of
     * {@see self::sourceNeverReferences}. An inline fully-qualified reference at a call
     * site (`app(\Vendor\Upper\Service::class)`) passes; a `use Vendor\Upper\Enum;`
     * fails. This is the rule for a tier seam that sanctions runtime, container-resolved
     * lookups across it but forbids binding to the upper tier at parse time.
     *
     * @param  list<string>  $prefixes  forbidden namespace prefixes
     */
    public function sourceNeverImports(string $pkg, array $prefixes, ?string $because = null): self
    {
        return $this->withRule(new TopologyRule(RuleKind::SourceNeverImports, $pkg, null, $prefixes, $because));
    }

    /** Terminal no-op — reads well at the end of a fluent chain. */
    public function build(): self
    {
        return $this;
    }

    private function withRule(TopologyRule $rule): self
    {
        return new self($this->name, [...$this->rules, $rule]);
    }
}
