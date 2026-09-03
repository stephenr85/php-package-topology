# Package Topology

A declarative **package-topology substrate** for Laravel monorepos. A repo declares its architecture as one
`TopologyContract` and asserts it holds, instead of hand-rolling a fitness test per rule. It **rides
`rushing/laravel-graphine`** — a package-require graph *is* a graph (nodes = packages, edges = requires), so
the checks are graphine queries, not bespoke scans.

The substrate spans **two axes** in one contract:

- **Package-graph axis** — the composer `require` graph. Direct edges, transitive reachability, layering,
  down-only, installedness, and acyclicity.
- **Source-import axis** — a package's `src/` must not reference a forbidden namespace prefix. An AST parse
  (graphine's `SeamGuard`), stronger than a substring scan.

## Relationship to graphine

This package is a **consumer** of graphine, not part of it — graphine stays a pure domain-graph substrate.
The seam it composes on:

- `Sources\ComposerManifestGraphSource implements Rushing\Graphine\Contracts\GraphSource` — reads scoped
  `vendor/{pkg}/composer.json` `require` keys into `Node`/`Edge`.
- `Rushing\Graphine\Drivers\RelationalDriverFactory::make($source)` hydrates the source into the in-memory
  spine **once**; the evaluator answers every read from that snapshot.
- Direct-edge rules use `neighbours(…, Descendants, maxDepth: 1)`; reachability uses `shortestPath`; cycles
  use `detectCycles`.
- The source axis delegates to `Rushing\Graphine\Testing\SeamGuard` (nikic/php-parser AST scan).

## Language

**Axis**:
One of the two boundaries the substrate enforces. The **package-graph axis** reads composer manifests
(who requires whom); the **source-import axis** reads a package's `src/` (what namespaces it references).
One `TopologyContract` carries rules on both, checked in a single `evaluate()` pass.
_Avoid_: layer (overloaded — a `layerOrder` rule is one *kind* on the package-graph axis, not an axis).

**Contract**:
A named, immutable declaration of a repo's topology — a `TopologyContract` built fluently from
`TopologyContract::for($name)`, a list of `TopologyRule`s. Readonly: each builder call returns a new
contract with the rule appended. `->build()` is a terminal no-op that reads well at the call site.
_Avoid_: policy, ruleset (the contract is the whole declaration, not a bag of rules).

**Rule / RuleKind**:
One declared expectation and its kind. The kinds:
`mustRequire`/`mustNotRequire` (direct edge, one hop), `mustRequireDev` (a DEV-only edge — asserted
against the declaring package's `require-dev` rather than the runtime `require` graph, read straight off
the manifest like the source axis), `neverReaches` (transitive), `downOnly` (never
depends UP on a listed tier), `layerOrder` (no lower tier reaches a higher one), `mustBeAcyclic`,
`mustBeInstalled` (a required-but-absent phantom fails), and `sourceNeverReferences` (the source axis).
_Avoid_: assertion (that's the test-side act; a rule is the declared expectation).

**Violation**:
A legible finding — the readonly output of the evaluator: the failed `RuleKind`, a human `detail` sentence,
the offending endpoints, and the declared `because` rationale. An empty violation list means the contract
holds. A green consumer therefore means "the hierarchy holds", not "the checker broke" — proven by the
planted-violation teeth fixtures on **both** axes.

**Phantom node**:
An in-scope `require` target with no installed manifest. The source emits it as a `Node` with
`properties['installed'] => false` and **keeps its edge** (a deliberate divergence from graphine's
AdjacencyListSource, which drops out-of-snapshot endpoints) — a missing package is a *finding*, so
`mustNotRequire`/`mustBeInstalled` still fire against it.

**Allow-list (scope)**:
The `include` globs (`['rushing/*','splicewire/*']` by default) the source is scoped to, **plus** the exact
names passed as `named:` (the testing kit passes `TopologyContract::packagesNamed()`). **Load-bearing, not
optional**: unscoped it would ingest all of `vendor/` (hundreds of transitive packages), exploding the
reachability queries. The globs bound the *compute*; the declarations bound the *question*, so a rule about
a package outside every glob (`mustRequire('rushing/laravel-surgeon','nikic/php-parser')`) is answerable
without widening to all of `vendor/`. Widening the GLOBS still widens the compute — keep those tight.

**Unresolvable rule**:
A rule whose graph operands the source cannot see. `Evaluator\TopologyEvaluator::evaluate()` takes an
optional `Contract\PackageScope` (the source implements it via `sees()`); a rule out of that scope becomes
a `Contract\UnresolvedRule` — carried by `unresolved()`, counted by `didNotLook()` — rather than scored.
Non-observation is not neutral: `mustRequire` reads the silence as a FAIL while `mustNotRequire` and
`neverReaches` read the same silence as a PASS. With no scope passed, `didNotLook()` is 0 meaning **not
measured**, not "all resolved".

## Testing kit

- `Testing\AssertsPackageTopology` (trait) — `assertTopologyHolds()` + the `vendorPath()` /
  `topologyContract()` / `includeGlobs()` seams. Use from within a framework TestCase (Laravel / tenancy).
- `Testing\PackageTopologyConformance` (abstract TestCase) — the convenience base for a host with no
  base-class constraint. Both ship in `src/` (autoloaded); phpunit is a suggest-only host dep.

Mirrors graphine's `ConformsToGraphStore` (trait) + `GraphStoreConformance` (base) authoring split.

## Co-dev seam

Standalone `rushing/*` repo (carries zero Splicewire concepts, like graphine). Consumed by an app via a
`composer.local.json` path repo + `dev-main`, with a `dev-main` require in the app `composer.json`. A path
repo to the sibling `../laravel-graphine` lets the package's own `composer test` resolve graphine
standalone. **Do not canonicalize the app's composer lock** (standing instruction) — the unpushed-package-edit
trap applies to anyone who does.

## Consumers

- `splicewire-app`: `tests/Feature/Composition/GroundingKernelTopologyTest.php` (ADR-0129, package-graph
  axis) and `tests/Feature/Architecture/EngineSpineDependencyDirectionTest.php` (ADR-0146 / `composer
  lint:topology`, source-import axis) migrated from hand-rolled scans to declared contracts.
- Future: other satellites (numero, audiostud, beam packages) can rebase their own hierarchy guards onto
  this substrate as more engine/spine/kernel tiers land.
