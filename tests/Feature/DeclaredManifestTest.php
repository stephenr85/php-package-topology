<?php

use Rushing\Graphine\Drivers\RelationalDriverFactory;
use Rushing\PackageTopology\Contract\RuleKind;
use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;
use Rushing\PackageTopology\Sources\DeclaredContractSource;

/**
 * TEETH for the declared-manifest reader — packages declare their own topology in
 * `extra.package-topology`; the reader merges those into one contract, and the
 * evaluator catches planted violations. A green consumer therefore means "the
 * declared hierarchy holds", not "the reader is broken".
 */

/**
 * Materialise a throwaway `vendor/`-shaped tree. Each entry is
 *   name => [ 'require' => [...], 'topology' => [...] ]
 * where `topology` becomes the `extra.package-topology` block.
 */
function declaredVendorTree(array $packages): string
{
    $root = sys_get_temp_dir().'/declared-topology-'.substr(md5(implode('|', array_keys($packages))), 0, 12);

    foreach ($packages as $name => $spec) {
        $dir = "{$root}/{$name}";
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $manifest = ['name' => $name];
        if (! empty($spec['require'])) {
            $manifest['require'] = (object) $spec['require'];
        }
        if (! empty($spec['require-dev'])) {
            $manifest['require-dev'] = (object) $spec['require-dev'];
        }
        if (! empty($spec['topology'])) {
            $manifest['extra'] = ['package-topology' => $spec['topology']];
        }
        file_put_contents("{$dir}/composer.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    return $root;
}

function evaluateDeclared(string $vendorPath): array
{
    $contract = (new DeclaredContractSource($vendorPath))->contract();
    $store = RelationalDriverFactory::make(new ComposerManifestGraphSource($vendorPath, ['rushing/*', 'splicewire/*', 'schemastud/*']), 'declared');

    return (new TopologyEvaluator)->evaluate($contract, $store, $vendorPath);
}

test('a declared mustRequire that holds passes, and its absence is caught', function () {
    // beam declares "I require frame down" — and actually does.
    $ok = declaredVendorTree([
        'splicewire/laravel-beam' => [
            'require' => ['schemastud/laravel-frame' => '*'],
            'topology' => ['mustRequire' => ['schemastud/laravel-frame']],
        ],
        'schemastud/laravel-frame' => [],
    ]);
    expect(evaluateDeclared($ok))->toBe([]);

    // Same declaration, but the require edge is missing — caught.
    $bad = declaredVendorTree([
        'splicewire/laravel-beam' => [
            'topology' => ['mustRequire' => ['schemastud/laravel-frame']],
        ],
        'schemastud/laravel-frame' => [],
    ]);
    $violations = evaluateDeclared($bad);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::RequiredDirectEdge);
});

test('a declared mustRequireDev asserts the edge in require-dev — present passes, absent (or runtime-only) is caught', function () {
    // beam declares "I require surgeon as DEV tooling" — and actually has it in require-dev.
    $ok = declaredVendorTree([
        'splicewire/laravel-beam' => [
            'require-dev' => ['rushing/laravel-surgeon' => '*'],
            'topology' => ['mustRequireDev' => ['rushing/laravel-surgeon']],
        ],
        'rushing/laravel-surgeon' => [],
    ]);
    expect(evaluateDeclared($ok))->toBe([]);

    // Absent from require-dev entirely — caught.
    $missing = declaredVendorTree([
        'splicewire/laravel-beam-nodev' => [
            'topology' => ['mustRequireDev' => ['rushing/laravel-surgeon']],
        ],
        'rushing/laravel-surgeon' => [],
    ]);
    $violations = evaluateDeclared($missing);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::RequiredDevDirectEdge)
        ->and($violations[0]->message())->toContain('rushing/laravel-surgeon');

    // In runtime `require` but NOT `require-dev` — still caught (the dev edge IS the contract).
    $runtimeOnly = declaredVendorTree([
        'splicewire/laravel-beam-runtime' => [
            'require' => ['rushing/laravel-surgeon' => '*'],
            'topology' => ['mustRequireDev' => ['rushing/laravel-surgeon']],
        ],
        'rushing/laravel-surgeon' => [],
    ]);
    $violations = evaluateDeclared($runtimeOnly);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::RequiredDevDirectEdge);
});

test('a declared mustNotRequire glob expands against the installed set and catches a violation', function () {
    $bad = declaredVendorTree([
        'splicewire/laravel-beam' => [
            'require' => ['splicewire/laravel-satellite' => '*'],
            'topology' => ['mustNotRequire' => ['splicewire/laravel-satellite*']],
        ],
        'splicewire/laravel-satellite' => [],
    ]);
    $violations = evaluateDeclared($bad);
    expect($violations)->not->toBe([])
        ->and($violations[0]->kind)->toBe(RuleKind::ForbiddenDirectEdge)
        ->and($violations[0]->message())->toContain('splicewire/laravel-satellite');
});

test('an estate-wide policy noRequire catches an open-foundation -> paid-engine edge but spares the carve-out', function () {
    $tree = declaredVendorTree([
        // policy lives on the base package; both sides matched as prefixes.
        'splicewire/laravel-beam' => [
            'topology' => ['policy' => ['noRequire' => [
                ['fromPrefix' => 'schemastud/', 'toPrefix' => 'splicewire/', 'exceptPrefix' => 'splicewire/laravel-beam'],
            ]]],
        ],
        // VIOLATION: open foundation reaches up into the paid engine.
        'schemastud/laravel-open-thing' => ['require' => ['splicewire/laravel-composition-engine' => '*']],
        // CARVE-OUT: schemastud -> beam base is sanctioned (the diamond), must NOT be flagged.
        'schemastud/laravel-frame' => ['require' => ['splicewire/laravel-beam' => '*']],
        'splicewire/laravel-composition-engine' => [],
    ]);

    $violations = evaluateDeclared($tree);
    $messages = array_map(fn ($v) => $v->message(), $violations);

    expect($violations)->not->toBe([]);
    expect(implode("\n", $messages))->toContain('schemastud/laravel-open-thing');
    expect(implode("\n", $messages))->not->toContain('schemastud/laravel-frame');
});

test('a declared sourceNeverReferences is assembled (and skips gracefully with no src/)', function () {
    // With no src/ dir the source rule skips rather than false-failing — so a
    // manifest that declares it still yields a clean run on a src-less tree.
    $tree = declaredVendorTree([
        'splicewire/laravel-circuit-spine' => [
            'topology' => ['sourceNeverReferences' => ['Splicewire\\Circuits\\Execution\\']],
        ],
    ]);
    expect(evaluateDeclared($tree))->toBe([]);

    // …but the rule IS in the assembled contract.
    $contract = (new DeclaredContractSource($tree))->contract();
    $kinds = array_map(fn ($r) => $r->kind, $contract->rules);
    expect($kinds)->toContain(RuleKind::SourceNeverReferences);
});

test('a declared sourceNeverImports is assembled as its own kind', function () {
    $tree = declaredVendorTree([
        'splicewire/laravel-satellite-thing' => [
            'topology' => ['sourceNeverImports' => ['Splicewire\\Tower\\']],
        ],
    ]);
    expect(evaluateDeclared($tree))->toBe([]);

    $contract = (new DeclaredContractSource($tree))->contract();
    $kinds = array_map(fn ($r) => $r->kind, $contract->rules);
    expect($kinds)->toContain(RuleKind::SourceNeverImports)
        ->and($kinds)->not->toContain(RuleKind::SourceNeverReferences);
});
