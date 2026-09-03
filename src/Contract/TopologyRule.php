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

    /**
     * The packages this rule asks the PACKAGE GRAPH about — the operands whose
     * absence from the graph source's {@see PackageScope} would make the rule
     * unanswerable rather than false.
     *
     * Empty for the off-graph kinds, and that is the point: the source-import
     * kinds read `vendor/{pkg}/src` straight off disk and
     * {@see RuleKind::RequiredDevDirectEdge} reads `vendor/{pkg}/composer.json`,
     * so neither is affected by the graph allow-list; `mustBeAcyclic` names no
     * operand at all.
     *
     * @return list<string>
     */
    public function packagesReferenced(): array
    {
        if ($this->kind->isSourceAxis() || $this->kind === RuleKind::RequiredDevDirectEdge) {
            return [];
        }

        $names = match ($this->kind) {
            RuleKind::Acyclic => [],
            RuleKind::LayerOrder => $this->targets,
            RuleKind::DownOnly => [$this->subject, ...$this->targets],
            RuleKind::MustBeInstalled => [$this->subject],
            default => [$this->subject, $this->object],
        };

        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '' && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
