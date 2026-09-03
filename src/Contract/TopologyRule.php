<?php

namespace Rushing\PackageTopology\Contract;

use Rushing\PackageTopology\Evaluator\TopologyEvaluator;

/**
 * One declared topology rule — a readonly record. The generic payload carries
 * every kind's operands so the {@see TopologyEvaluator}
 * can dispatch on {@see RuleKind} alone:
 *
 *   - pairwise edge/reach rules (`mustRequire`, `mustNotRequire`, `neverReaches`)
 *     use {@see $subject} (from) + {@see $object} (to).
 *   - `downOnly` uses {@see $subject} (the package) + {@see $targets} (the from-list
 *     it must never depend UP on).
 *   - `layerOrder` uses {@see $targets} (layers, lowest→highest).
 *   - `sourceNeverReferences` / `sourceNeverImports` use {@see $subject} (the package)
 *     + {@see $targets} (the forbidden namespace prefixes).
 *   - `mustBeInstalled` uses {@see $subject}; `mustBeAcyclic` uses none.
 */
class TopologyRule
{
    /**
     * @param  list<string>  $targets  from-list (downOnly), layers (layerOrder), or prefixes (sourceNeverReferences / sourceNeverImports)
     */
    public function __construct(
        public RuleKind $kind,
        public ?string $subject = null,
        public ?string $object = null,
        public array $targets = [],
        public ?string $because = null,
    ) {}
}
