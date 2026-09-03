<?php

namespace Rushing\PackageTopology\Contract;

use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;

/**
 * The kinds of topology rule a {@see TopologyContract} can carry — spanning the
 * two axes the substrate enforces:
 *
 *   - PACKAGE-GRAPH axis (composer `require` edges): every kind except
 *     {@see self::SourceNeverReferences}. Answered by the graphine spine hydrated
 *     from {@see ComposerManifestGraphSource}.
 *   - SOURCE-IMPORT axis (namespace references in a package's `src/`):
 *     {@see self::SourceNeverReferences} and {@see self::SourceNeverImports}. Answered by
 *     graphine's AST `SeamGuard` — the first counts every reference (imports AND inline
 *     fully-qualified names), the second only what binds at parse time (`use` / group-use),
 *     so a runtime `app(\Vendor\Upper\Service::class)` lookup across a sanctioned seam
 *     passes it while a `use Vendor\Upper\Enum;` does not.
 *
 * Direct-edge rules ({@see self::RequiredDirectEdge}/{@see self::ForbiddenDirectEdge})
 * are a *direct* `require` claim (one hop, `maxDepth: 1`). {@see self::RequiredDevDirectEdge}
 * is the DEV-ONLY parallel: it asserts the edge lives in the declaring package's
 * `require-dev`, not its runtime `require` — read straight from the manifest
 * (`vendor/{pkg}/composer.json`) rather than the require-only package graph, the
 * same off-graph seam {@see self::SourceNeverReferences} uses. The reachability kinds
 * ({@see self::ForbiddenReachable}/{@see self::DownOnly}/{@see self::LayerOrder})
 * are transitive (`shortestPath`); {@see self::Acyclic} is `detectCycles`.
 */
enum RuleKind: string
{
    case RequiredDirectEdge = 'required_direct_edge';
    case RequiredDevDirectEdge = 'required_dev_direct_edge';
    case ForbiddenDirectEdge = 'forbidden_direct_edge';
    case ForbiddenReachable = 'forbidden_reachable';
    case DownOnly = 'down_only';
    case LayerOrder = 'layer_order';
    case Acyclic = 'acyclic';
    case MustBeInstalled = 'must_be_installed';
    case SourceNeverReferences = 'source_never_references';
    case SourceNeverImports = 'source_never_imports';

    /** Is this rule checked on the source-import axis (SeamGuard) rather than the package graph? */
    public function isSourceAxis(): bool
    {
        return $this === self::SourceNeverReferences || $this === self::SourceNeverImports;
    }
}
