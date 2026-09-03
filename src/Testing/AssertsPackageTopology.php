<?php

namespace Rushing\PackageTopology\Testing;

use Rushing\Graphine\Drivers\RelationalDriverFactory;
use Rushing\PackageTopology\Contract\TopologyContract;
use Rushing\PackageTopology\Contract\TopologyViolation;
use Rushing\PackageTopology\Contract\UnresolvedRule;
use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;

/**
 * THE ASSERTION KIT — as a trait, mirroring graphine's `ConformsToGraphStore`.
 *
 * Shipping the assertions as a TRAIT (not only a base class) lets a consumer
 * certify its hierarchy from WITHIN its own framework TestCase — a Laravel /
 * tenancy-aware TestCase the abstract {@see PackageTopologyConformance} could
 * never extend (PHP is single-inheritance). The consumer fills two seams and
 * calls one assertion:
 *
 *   class MyHierarchyTest extends \Tests\TestCase
 *   {
 *       use AssertsPackageTopology;
 *
 *       protected function vendorPath(): string { return base_path('vendor'); }
 *       protected function topologyContract(): TopologyContract { return TopologyContract::for(...)->...->build(); }
 *
 *       public function test_hierarchy_holds(): void { $this->assertTopologyHolds(); }
 *   }
 *
 * `assertTopologyHolds()` builds the {@see ComposerManifestGraphSource}, hydrates
 * it through graphine's {@see RelationalDriverFactory}, runs the
 * {@see TopologyEvaluator}, and asserts zero violations — with a legible failure
 * message so a green suite means "the hierarchy holds", not "the checker broke".
 */
trait AssertsPackageTopology
{
    /** The composer `vendor/` directory to read manifests + sources from. */
    abstract protected function vendorPath(): string;

    /** The contract this consumer declares. */
    abstract protected function topologyContract(): TopologyContract;

    /**
     * The vendor/name globs the source is scoped to — the LOAD-BEARING allow-list.
     * Override to widen/narrow; the default covers the rushing + splicewire estate.
     *
     * @return list<string>
     */
    protected function includeGlobs(): array
    {
        return ['rushing/*', 'splicewire/*'];
    }

    protected function assertTopologyHolds(): void
    {
        $contract = $this->topologyContract();

        // Every package the contract NAMES is admitted, whatever the globs say: the
        // globs bound the compute, the declarations bound the question. Without this
        // a rule about an out-of-glob package (a third-party `mustRequire`) is
        // answered by a graph that never materialised the edge — a failure by not
        // looking, which is the same defect as a pass by not running.
        $source = new ComposerManifestGraphSource(
            $this->vendorPath(),
            $this->includeGlobs(),
            named: $contract->packagesNamed(),
        );
        $store = RelationalDriverFactory::make($source, 'package-topology');

        $evaluator = new TopologyEvaluator;
        $violations = $evaluator->evaluate($contract, $store, $this->vendorPath(), $source);

        $this->assertSame([], $violations, $this->renderViolations($violations, $evaluator->unresolved()));

        // Belt and braces: with `named:` above nothing SHOULD be unresolvable, so a
        // non-zero count means a rule referenced something this wiring could not
        // admit. Report it rather than scoring it either way.
        $this->assertSame(
            0,
            $evaluator->didNotLook(),
            $this->renderUnresolved($evaluator->unresolved()),
        );
    }

    /**
     * @param  list<TopologyViolation>  $violations
     * @param  list<UnresolvedRule>  $unresolved
     */
    private function renderViolations(array $violations, array $unresolved = []): string
    {
        if ($violations === []) {
            return '';
        }

        return "Package-topology contract violated:\n - ".implode("\n - ", array_map(
            static fn (TopologyViolation $v): string => $v->message(),
            $violations,
        )).$this->renderUnresolved($unresolved);
    }

    /**
     * @param  list<UnresolvedRule>  $unresolved
     */
    private function renderUnresolved(array $unresolved): string
    {
        if ($unresolved === []) {
            return '';
        }

        return "\n\n".count($unresolved).' rule(s) UNRESOLVABLE — the graph source cannot see their operands, '
            ."so they were neither passed nor failed:\n - ".implode("\n - ", array_map(
                static fn (UnresolvedRule $u): string => $u->message(),
                $unresolved,
            ));
    }
}
