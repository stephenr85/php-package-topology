<?php

namespace Rushing\PackageTopology\Contract;

use Rushing\PackageTopology\Evaluator\TopologyEvaluator;

/**
 * A rule the evaluator DID NOT LOOK AT — its subject or object lies outside the
 * graph source's {@see PackageScope}, so no edge for it could ever have been
 * materialised and any verdict would be an artefact of the allow-list.
 *
 * It is neither a violation nor a pass: {@see TopologyEvaluator::unresolved()}
 * carries these out of band, and {@see TopologyEvaluator::didNotLook()} counts
 * them, so a consumer can surface "N rules unresolvable" instead of silently
 * scoring them either way.
 */
class UnresolvedRule
{
    /**
     * @param  list<string>  $unseen  the referenced packages the source cannot see
     */
    public function __construct(
        public TopologyRule $rule,
        public array $unseen = [],
    ) {}

    /** A one-line human rendering, deliberately naming the unseen packages. */
    public function message(): string
    {
        $unseen = implode(', ', $this->unseen);

        return "[{$this->rule->kind->value}] unresolvable: {$unseen} is outside the graph source's scope, "
            .'so this rule did not look — neither a pass nor a fail. Name it in the source scope to evaluate it.';
    }
}
