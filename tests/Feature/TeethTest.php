<?php

use Rushing\Graphine\Drivers\RelationalDriverFactory;
use Rushing\PackageTopology\Contract\RuleKind;
use Rushing\PackageTopology\Contract\TopologyContract;
use Rushing\PackageTopology\Contract\TopologyViolation;
use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;

/**
 * TEETH — planted-violation fixtures on BOTH axes prove the checker catches
 * violations and passes clean. A green consumer therefore means "the hierarchy
 * holds", not "the checker is broken".
 */

/** @return list<TopologyViolation> */
function evaluate(TopologyContract $contract, string $vendorPath): array
{
    $store = RelationalDriverFactory::make(new ComposerManifestGraphSource($vendorPath), 'teeth');

    return (new TopologyEvaluator)->evaluate($contract, $store, $vendorPath);
}

function teethFixture(string $relative): string
{
    return __DIR__.'/../fixtures/'.$relative;
}

// --- package-graph axis: direct edges (ticket 01) ---------------------------

test('a clean direct-edge contract holds against the clean fixture tree', function () {
    $contract = TopologyContract::for('clean-direct')
        ->mustRequire('splicewire/engine-a', 'splicewire/kernel')
        ->mustRequire('splicewire/engine-b', 'splicewire/kernel')
        ->mustNotRequire('splicewire/engine-a', 'splicewire/engine-b')
        ->mustRequire('splicewire/kernel', 'splicewire/spine')
        ->mustRequire('splicewire/kernel', 'rushing/lib')
        ->build();

    expect(evaluate($contract, teethFixture('vendor-fixture/clean')))->toBe([]);
});

test('a forbidden direct edge that actually exists is caught', function () {
    // engine-a DOES require kernel, so mustNotRequire must fail.
    $contract = TopologyContract::for('bad-forbidden')
        ->mustNotRequire('splicewire/engine-a', 'splicewire/kernel', because: 'planted violation')
        ->build();

    $violations = evaluate($contract, teethFixture('vendor-fixture/clean'));

    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::ForbiddenDirectEdge)
        ->and($violations[0]->message())->toContain('splicewire/engine-a')
        ->and($violations[0]->message())->toContain('planted violation');
});

test('a required direct edge that is missing is caught', function () {
    // engine-a requires kernel, NOT spine directly — so this must fail.
    $contract = TopologyContract::for('bad-required')
        ->mustRequire('splicewire/engine-a', 'splicewire/spine')
        ->build();

    $violations = evaluate($contract, teethFixture('vendor-fixture/clean'));

    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::RequiredDirectEdge);
});

// --- package-graph axis: transitive + cycle (ticket 02) ---------------------

test('transitive rules (neverReaches / downOnly / layerOrder) hold on the clean tree', function () {
    $contract = TopologyContract::for('clean-transitive')
        ->neverReaches('splicewire/spine', 'splicewire/engine-a')
        ->downOnly('splicewire/kernel', from: ['splicewire/engine-a', 'splicewire/engine-b'])
        ->layerOrder(['splicewire/spine', 'splicewire/kernel', 'splicewire/engine-a'])
        ->mustBeAcyclic()
        ->build();

    expect(evaluate($contract, teethFixture('vendor-fixture/clean')))->toBe([]);
});

test('downOnly is caught when a package depends UP', function () {
    // kernel reaches spine (down) — assert the INVERSE to plant a violation:
    // spine must be down-only relative to kernel, but spine→kernel is not a path,
    // so instead assert kernel is down-only relative to spine (kernel→spine exists).
    $contract = TopologyContract::for('bad-downonly')
        ->downOnly('splicewire/kernel', from: ['splicewire/spine'], because: 'planted upward claim')
        ->build();

    $violations = evaluate($contract, teethFixture('vendor-fixture/clean'));

    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::DownOnly)
        ->and($violations[0]->message())->toContain('splicewire/spine');
});

test('neverReaches is caught on a transitive path', function () {
    // engine-a → kernel → spine, so engine-a REACHES spine transitively.
    $contract = TopologyContract::for('bad-reach')
        ->neverReaches('splicewire/engine-a', 'splicewire/spine')
        ->build();

    $violations = evaluate($contract, teethFixture('vendor-fixture/clean'));

    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::ForbiddenReachable);
});

test('a require cycle is caught by mustBeAcyclic and passes on the acyclic tree', function () {
    $contract = TopologyContract::for('cycle')->mustBeAcyclic()->build();

    $violations = evaluate($contract, teethFixture('vendor-fixture/cyclic'));
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::Acyclic)
        ->and($violations[0]->message())->toContain('cyc-a');

    expect(evaluate($contract, teethFixture('vendor-fixture/clean')))->toBe([]);
});

test('a required-but-absent phantom is caught by mustBeInstalled, and its edge is kept', function () {
    $vendorPath = teethFixture('vendor-fixture/phantom');

    // The ghost has no manifest → mustBeInstalled fails.
    $installed = TopologyContract::for('phantom-installed')
        ->mustBeInstalled('splicewire/ghost')
        ->build();
    $violations = evaluate($installed, $vendorPath);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::MustBeInstalled);

    // The edge to the phantom is KEPT, so mustNotRequire still fires against it.
    $edgeKept = TopologyContract::for('phantom-edge')
        ->mustNotRequire('splicewire/kernel', 'splicewire/ghost')
        ->build();
    expect(evaluate($edgeKept, $vendorPath))->not->toBe([]);

    // An installed package passes mustBeInstalled.
    $ok = TopologyContract::for('installed-ok')
        ->mustBeInstalled('splicewire/kernel')
        ->build();
    expect(evaluate($ok, $vendorPath))->toBe([]);
});

// --- source-import axis (ticket 03) -----------------------------------------

test('sourceNeverReferences catches an upward namespace reference and passes on the clean source', function () {
    $vendorPath = teethFixture('src-fixture');

    $leaky = TopologyContract::for('leaky-src')
        ->sourceNeverReferences('leaky', prefixes: ['Splicewire\\SomeEngine\\'], because: 'engine→spine direction must not invert')
        ->build();
    $violations = evaluate($leaky, $vendorPath);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::SourceNeverReferences)
        ->and($violations[0]->message())->toContain('LeakySpine.php');

    $clean = TopologyContract::for('clean-src')
        ->sourceNeverReferences('clean', prefixes: ['Splicewire\\SomeEngine\\'])
        ->build();
    expect(evaluate($clean, $vendorPath))->toBe([]);
});

test('sourceNeverReferences skips gracefully when a package src/ is absent', function () {
    $contract = TopologyContract::for('absent-src')
        ->sourceNeverReferences('does-not-exist', prefixes: ['Splicewire\\SomeEngine\\'])
        ->build();

    expect(evaluate($contract, teethFixture('src-fixture')))->toBe([]);
});

test('sourceNeverImports catches a use import and ignores an inline fully-qualified reference', function () {
    $vendorPath = teethFixture('src-fixture');

    $leaky = TopologyContract::for('leaky-import')
        ->sourceNeverImports('leaky', prefixes: ['Splicewire\\SomeEngine\\'], because: 'no parse-time coupling to the engine')
        ->build();
    $violations = evaluate($leaky, $vendorPath);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::SourceNeverImports)
        ->and($violations[0]->message())->toContain('LeakySpine.php');

    // The same inline-only source fails the REFERENCES rule and passes the IMPORTS rule —
    // that gap is the whole reason the second kind exists.
    $inlineReferences = TopologyContract::for('inline-references')
        ->sourceNeverReferences('inline', prefixes: ['Splicewire\\SomeEngine\\'])
        ->build();
    expect(evaluate($inlineReferences, $vendorPath))->not->toBe([]);

    $inlineImports = TopologyContract::for('inline-imports')
        ->sourceNeverImports('inline', prefixes: ['Splicewire\\SomeEngine\\'])
        ->build();
    expect(evaluate($inlineImports, $vendorPath))->toBe([]);
});

// --- scope: "nothing there" vs "didn't look" (gate-reachability 03) ---------

test('a rule naming a package the source cannot see is reported unresolvable, never as a pass or a fail', function () {
    $vendorPath = teethFixture('vendor-fixture/clean');
    // A source scoped to `splicewire/*` only: `rushing/lib` is installed and required by kernel, but the
    // source never looks at it. Without a scope the evaluator cannot tell "not there" from "not looked".
    $source = new ComposerManifestGraphSource($vendorPath, ['splicewire/*']);
    $store = RelationalDriverFactory::make($source, 'teeth-scope');

    $contract = TopologyContract::for('out-of-scope')
        ->mustRequire('splicewire/kernel', 'rushing/lib')       // true on disk; the graph cannot see it
        ->mustNotRequire('splicewire/kernel', 'rushing/lib')    // false on disk; would pass vacuously
        ->neverReaches('splicewire/engine-a', 'rushing/lib')    // false on disk; would pass vacuously
        ->mustBeInstalled('rushing/lib')                        // true on disk; would fail by not looking
        ->mustRequire('splicewire/engine-a', 'splicewire/kernel') // in scope, holds
        ->build();

    $evaluator = new TopologyEvaluator;
    expect($evaluator->evaluate($contract, $store, $vendorPath, $source))->toBe([])
        ->and($evaluator->didNotLook())->toBe(4)
        ->and($evaluator->unresolved()[0]->rule->kind)->toBe(RuleKind::RequiredDirectEdge)
        ->and($evaluator->unresolved()[0]->message())->toContain('rushing/lib')
        ->and($evaluator->unresolved()[0]->message())->toContain('did not look');

    // Naming the package puts it in scope: the same contract is now fully resolved and the two
    // false claims fail on their merits.
    $named = new ComposerManifestGraphSource($vendorPath, ['splicewire/*'], named: $contract->packagesNamed());
    expect($named->sees('rushing/lib'))->toBeTrue()
        ->and($source->sees('rushing/lib'))->toBeFalse();
    $store = RelationalDriverFactory::make($named, 'teeth-scope-named');
    $violations = $evaluator->evaluate($contract, $store, $vendorPath, $named);
    expect($evaluator->didNotLook())->toBe(0)
        ->and(array_map(fn ($v) => $v->kind, $violations))->toBe([RuleKind::ForbiddenDirectEdge, RuleKind::ForbiddenReachable]);

    // Without a scope the evaluator keeps its old reading — it has nothing to distinguish with.
    $store = RelationalDriverFactory::make($source, 'teeth-scope-none');
    expect($evaluator->evaluate($contract, $store, $vendorPath))->not->toBe([])
        ->and($evaluator->didNotLook())->toBe(0);
});

test('packagesNamed lists every package a graph-axis rule references and no namespace prefix', function () {
    $contract = TopologyContract::for('names')
        ->mustRequire('a/one', 'b/two')
        ->downOnly('c/three', from: ['d/four', 'a/one'])
        ->layerOrder(['e/five', 'b/two'])
        ->mustBeInstalled('f/six')
        ->mustBeAcyclic()
        ->sourceNeverReferences('a/one', prefixes: ['Vendor\\Upper\\'])
        ->build();

    expect($contract->packagesNamed())->toBe(['a/one', 'b/two', 'c/three', 'd/four', 'e/five', 'f/six']);
});
