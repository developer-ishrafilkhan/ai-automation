<?php

namespace App\Services\Engine\Nodes;

use App\Models\ExecutionStep;
use App\Services\Engine\StepResult;

interface NodeHandler
{
    /**
     * @param  array  $data  node settings with {{...}} already rendered
     * @param  array|null  $state  resume state when a waiting step is picked up again
     */
    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult;
}
