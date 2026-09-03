# php-package-topology

A declarative **package-topology substrate** for Laravel monorepos. Declare a repo's architecture as one
`TopologyContract` and assert it holds — instead of hand-rolling an architectural-fitness test per rule.

It **rides [`rushing/php-graphine`](https://github.com/stephenr85/php-graphine)**: a package-require
graph *is* a graph (nodes = packages, edges = requires), so the checks are graphine queries, not bespoke
scans. Nothing Splicewire-specific lives here — it reads *your* `vendor/` and *your* `src/`.

## Why

Architectural-fitness tests that enforce a package hierarchy tend to be hand-rolled and copied per repo —
one reading `composer.json` requires with `expect()->toContain()` chains, another `str_contains`-scanning a
package's `src/` for a forbidden namespace. Both are the same idea (*enforce package hierarchy*) at two
layers. This package lifts that into a reusable substrate: **declare a contract, point it at your vendor
tree.**

## Two axes, one contract

| Axis | What it reads | Rules |
|------|---------------|-------|
| **Package-graph** | `vendor/{pkg}/composer.json` `require` keys, as a graphine graph | `mustRequire` / `mustNotRequire` (direct edge), `mustRequireDev` (DEV-only edge, asserted off-graph against `require-dev`), `neverReaches` / `downOnly` / `layerOrder` (transitive, via `shortestPath`), `mustBeAcyclic` (`detectCycles`), `mustBeInstalled` (phantom node) |
| **Source-import** | a package's `src/` (parsed AST) | `sourceNeverReferences` — delegates to graphine's `SeamGuard`, stronger than a substring scan (ignores strings/comments); `sourceNeverImports` — the parse-time half only (`use` / group-use), so a sanctioned runtime `app(\Upper\Service::class)` passes and a `use Upper\Enum;` fails |

## Install

```bash
composer require --dev rushing/php-package-topology
```

Requires PHP ^8.3, `rushing/php-graphine`, and `galbar/jsonpath` (php-only, Apache-2.0 — the engine
behind `PackageJsonQuery`). The source-import axis needs a `nikic/php-parser`-providing
host (suggest-only); the testing kit needs PHPUnit.

## Usage

Fill two seams and call one assertion. From within a framework TestCase (Laravel / tenancy), use the trait:

```php
use Rushing\PackageTopology\Contract\TopologyContract;
use Rushing\PackageTopology\Testing\AssertsPackageTopology;

final class PackageHierarchyTest extends \Tests\TestCase
{
    use AssertsPackageTopology;

    protected function vendorPath(): string
    {
        return base_path('vendor');
    }

    protected function topologyContract(): TopologyContract
    {
        return TopologyContract::for('engine-hierarchy')
            // package-graph axis
            ->mustNotRequire('acme/engine-a', 'acme/engine-b', because: 'the two engines are decoupled peers')
            ->mustRequire('acme/engine-a', 'acme/kernel')
            ->downOnly('acme/kernel', from: ['acme/engine-a', 'acme/engine-b'], because: 'the kernel depends DOWN only')
            ->mustBeAcyclic()
            // source-import axis
            ->sourceNeverReferences('acme/spine-data', prefixes: ['Acme\\Engine\\'], because: 'engine→spine must not invert')
            ->build();
    }

    public function test_the_hierarchy_holds(): void
    {
        $this->assertTopologyHolds();
    }
}
```

Override `includeGlobs()` to scope which vendors the require-graph loads — it defaults to
`['rushing/*', 'splicewire/*']` and **is load-bearing**: unscoped it would ingest all of `vendor/`,
exploding the reachability queries. Keep it tight.

### Scope: a rule about a package the source cannot see

The globs bound the **compute**; the contract bounds the **question**. So every package a rule NAMES is
admitted regardless of the globs — the kit passes `TopologyContract::packagesNamed()` to the source's
`named:` argument, which is why `mustRequire('rushing/laravel-surgeon', 'nikic/php-parser')` is answerable
without widening to all of `vendor/`. The edge exists because a manifest declares it; before this the
target matched no glob, the edge was never materialised, and the rule failed at every host that ran it.

The precision half rides with it. Hand-build a source with a narrower scope and the evaluator can now tell
*"nothing there"* from *"didn't look"*: pass the source as `evaluate()`'s fourth argument (any
`PackageScope`) and a rule whose operands are out of scope is recorded as an `UnresolvedRule` —
`unresolved()` carries them, `didNotLook()` counts them — instead of being scored. That matters because
non-observation is not neutral: `mustRequire` reads it as a FAIL while `mustNotRequire` and `neverReaches`
read the very same silence as a PASS. Omit the argument and the evaluator keeps its scope-blind behaviour
and reports `didNotLook() === 0`, meaning *not measured* rather than *all resolved*.

For a host with no base-class constraint, extend `PackageTopologyConformance` instead of using the trait —
it ships a ready `test_topology_holds()` off the same two seams.

## Declared manifests — let the packages own their own rules

Hand-declaring the whole contract in one consumer works, but every consumer then re-copies (and drifts
from) the same edges. The **declared-manifest** mode moves each rule to the package it is *about*: a
package states its own invariants in its `composer.json` `extra.package-topology` block, and the consumer
runs one thin test that merges whatever the installed tree declares — it declares nothing itself. This is
the "doctor / install-manifest" pattern applied to topology; correcting a direction is a one-line edit in
the owning package, and every consumer follows.

The rule keys are the builder-method names verbatim (subject = the declaring package):

```jsonc
// splicewire/laravel-beam  composer.json
"extra": {
    "package-topology": {
        "because": "the ADR-0138 diamond",
        "mustRequire": ["schemastud/laravel-frame"],
        "mustRequireDev": ["rushing/laravel-surgeon"],         // DEV-only edge — asserted against require-dev
        "mustNotRequire": ["splicewire/laravel-satellite*"],   // glob → expanded against the installed set
        "policy": {                                            // estate-wide prefix rules (no single owner)
            "noRequire": [
                { "fromPrefix": "schemastud/", "toPrefix": "splicewire/", "exceptPrefix": "splicewire/laravel-beam" }
            ]
        }
    }
}
```

Also supported: `neverReaches`, `downOnly` (the from-list), `mustBeInstalled`, `sourceNeverReferences`
(prefixes), `sourceNeverImports` (prefixes; parse-time `use` only). `mustBeAcyclic` is always appended. Consume it with the trait / base:

```php
final class DeclaredTopologyTest extends \Tests\TestCase
{
    use \Rushing\PackageTopology\Testing\AssertsDeclaredTopology;

    protected function vendorPath(): string { return base_path('vendor'); }

    public function test_the_declared_topology_holds(): void { $this->assertDeclaredTopologyHolds(); }
}
```

`AssertsDeclaredTopology` widens the default scope to `schemastud/*` too (the open foundation
participates in the vendor-seam rules). `DeclaredTopologyConformance` is the base-class variant.

## Rule reference

| Builder call | Meaning | Check |
|---|---|---|
| `mustRequire($a, $b)` | `$a` requires `$b` directly | `neighbours($a, Descendants, maxDepth: 1)` contains `$b` |
| `mustRequireDev($a, $b)` | `$a` requires `$b` in `require-dev` (DEV-only edge) | `vendor/$a/composer.json`'s `require-dev` contains `$b` (read off-graph — the graph is runtime-`require` only) |
| `mustNotRequire($a, $b)` | `$a` must not require `$b` directly | `neighbours(…, maxDepth: 1)` excludes `$b` |
| `neverReaches($a, $b)` | no transitive require path `$a → … → $b` | `shortestPath($a, $b) === null` |
| `downOnly($pkg, from: […])` | `$pkg` never depends UP on any listed tier | each: `shortestPath($pkg, $tier) === null` |
| `layerOrder([lo … hi])` | no lower layer reaches a higher one | pairwise `shortestPath(lower, higher) === null` |
| `mustBeAcyclic()` | the in-scope require graph is a DAG | `detectCycles() === []` |
| `mustBeInstalled($pkg)` | `$pkg` has an installed manifest | node `properties['installed'] === true` |
| `sourceNeverReferences($pkg, prefixes: […])` | `$pkg`'s `src/` references none of the prefixes | `SeamGuard($prefixes)->scan(vendor/{pkg}/src) === []` |
| `sourceNeverImports($pkg, prefixes: […])` | `$pkg`'s `src/` `use`-imports none of the prefixes (inline FQN at a call site is allowed) | `SeamGuard($prefixes, importsOnly: true)->scan(vendor/{pkg}/src) === []` |

A **phantom node** — a required target with no installed manifest — is emitted with `installed => false` and
its edge is *kept*, so `mustNotRequire` and `mustBeInstalled` still fire against a missing package (a missing
dependency is a finding). A `sourceNeverReferences` rule whose package has no `src/` is skipped gracefully,
not failed.

## Package-JSON query — one JSONPath across every installed package, in dependency order

The two axes above *check* a topology. `PackageJsonQuery` *reads* against it: run one expression over every
in-scope package's JSON and get the answers back ordered by the require graph, requirements first.

```php
use Rushing\PackageTopology\Query\PackageJsonQuery;

$q = new PackageJsonQuery(base_path('vendor'));

$q->query('$.extra.laravel.providers');              // default file: composer.json
$q->query('$.name', file: 'package.json');           // any file in the package root
$q->query(fn (array $json): array => …);             // passthrough, when a path won't do

$q->queryByPackage('$.extra.laravel.providers');     // same answers, keyed by package, same order
$q->orderedPackages();                               // just the ordering
```

**The JSONPath is the CALLER's vocabulary, not this package's.** `$.extra.laravel.providers` is a *composer*
convention that a *caller* happens to care about — nothing here knows what a service provider is, and nothing
here ever will. That is why this is a path query and not a `providers()` method: the moment the key is spelled
inside the package, the package has a framework, and this one requires only `php`, `rushing/php-graphine` and
`galbar/jsonpath` (php-only, Apache-2.0) on purpose. Spell Laravel at the call site.

**The ordering is the part nothing else can supply.** A read of `installed.json`, a `glob()` over `vendor/`, or
a `ksort()` of package names all answer *which* packages and none of them answer *in what order* — composer's
file order is not dependency order. Here it falls out of the require graph `ComposerManifestGraphSource`
already builds: Kahn's sort over the **reversed** edge set, so for a `require` edge `a → b` the dependency `b`
is emitted before its dependent `a`.

| | returns | when |
|---|---|---|
| `query($path, $file)` | `list<mixed>` — flat, dependency-ordered, de-duplicated (first contributor wins) | you want the values |
| `queryByPackage($path, $file)` | `array<string, list<mixed>>` — same order, insertion-ordered | you want provenance too |
| `orderedPackages()` | `list<string>` | you only want the order |

A match that is itself a list is **spread one level** (`$.extra.laravel.providers` matches one value which is an
array of class names; you want the class names). Anything else is appended whole.

**A package that does not answer is absent, never an error.** No such file, unreadable JSON, path selecting
nothing — the package simply contributes nothing. That is the common case, not the edge: ask a real vendor tree
for `$.extra.laravel.providers` and most packages have no `extra` block at all. A malformed *path* does raise —
that is a fact about the caller's expression, not about any package.

**Cyclic packages are appended, never dropped.** Kahn excludes nodes in or behind a cycle; inheriting that would
make a require cycle present as a *shorter answer* rather than as a problem. They are appended in scope order
instead, so the result stays complete and merely stops being fully ordered. `mustBeAcyclic()` is how you learn a
cycle exists.

## How it works

`ComposerManifestGraphSource` (a graphine `GraphSource`) reads the scoped manifests into `Node`/`Edge`;
`RelationalDriverFactory::make(...)` hydrates them into graphine's in-memory spine **once**, and
`TopologyEvaluator` answers every rule from that snapshot — direct edges via bounded `neighbours`,
reachability via `shortestPath`, cycles via `detectCycles`, and source rules via `SeamGuard`. Returns legible
`TopologyViolation`s (which rule, which edge/file, the `because`); an empty list means the contract holds.

`PackageJsonQuery` rides the same source — it takes the package set and the require edges from
`ComposerManifestGraphSource` (never a second manifest walker of its own), orders them with graphine's
`TopologicalSort::kahn()` over the reversed edges, then reads `vendor/{pkg}/{file}` per package and evaluates
the caller's expression with `galbar/jsonpath`.

## Teeth

The package ships planted-violation fixtures on **both** axes (a bad direct edge, a require cycle, a
required-but-absent phantom, and an upward namespace `use`), so a green consumer means "the hierarchy holds",
not "the checker is broken".

```bash
composer test   # Pest: the two-axis teeth + the kit smoke consumer
```

## License

MIT © Stephen Rushing
