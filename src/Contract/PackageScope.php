<?php

namespace Rushing\PackageTopology\Contract;

use Rushing\PackageTopology\Evaluator\TopologyEvaluator;
use Rushing\PackageTopology\Sources\ComposerManifestGraphSource;

/**
 * WHAT THE GRAPH SOURCE CAN SEE — the scope a source was built with, asked as a
 * question rather than inferred.
 *
 * The package graph is deliberately allow-listed ({@see ComposerManifestGraphSource}),
 * so a rule naming a package outside that allow-list is answered by a graph that
 * never looked. Without this seam the evaluator cannot tell "the edge is absent"
 * from "the edge could never have been materialised", and it reports the second as
 * the first — the estate's signature defect, running in reverse: an instrument that
 * reports FAILURE by not running.
 *
 * A source that can answer this lets {@see TopologyEvaluator} report such a rule as
 * UNRESOLVABLE (counted, never folded into a pass or a fail).
 */
interface PackageScope
{
    /** Is `$name` inside this source's scope — i.e. would a node/edge for it be emitted at all? */
    public function sees(string $name): bool;
}
