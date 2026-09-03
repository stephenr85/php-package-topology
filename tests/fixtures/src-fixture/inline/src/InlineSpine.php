<?php

namespace Splicewire\Spine;

// RUNTIME reference only: no `use` of the engine namespace, an inline fully-qualified
// container lookup at a call site. `sourceNeverReferences` counts it (a reference is a
// reference); `sourceNeverImports` does not (nothing binds at parse time).
class InlineSpine
{
    public function schedule(): object
    {
        return app(\Splicewire\SomeEngine\Scheduling\Scheduler::class);
    }
}
