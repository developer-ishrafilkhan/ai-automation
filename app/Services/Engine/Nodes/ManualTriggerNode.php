<?php

namespace App\Services\Engine\Nodes;

use App\Models\Character;
use App\Models\ExecutionStep;
use App\Services\Engine\CharacterConsistency;
use App\Services\Engine\StepResult;
use App\Support\FormFields;

class ManualTriggerNode implements NodeHandler
{
    public function handle(ExecutionStep $step, array $data, ?array $state): StepResult
    {
        $submitted = $step->execution->trigger_data ?? [];
        $fields = FormFields::normalize($data['form']['fields'] ?? []);

        // A public form run uses only what the visitor typed: a blank optional field stays blank
        // instead of picking up the editor's test value.
        $output = $step->execution->source === 'form'
            ? array_merge(array_fill_keys(array_column($fields, 'key'), ''), $submitted)
            : array_merge($data['input'] ?? [], $submitted);

        return StepResult::done($this->withCharacterNames($step, $fields, $output));
    }

    /**
     * A character answer is its id(s), e.g. "12,15", for Image/Video steps; text steps get the
     * names too, as {{trigger.output.<key>_name}} ("Maya and Arjun").
     */
    private function withCharacterNames(ExecutionStep $step, array $fields, array $output): array
    {
        foreach ($fields as $field) {
            if ($field['type'] !== 'character') {
                continue;
            }
            $ids = CharacterConsistency::ids((array) ($output[$field['key']] ?? []));
            $names = Character::where('workspace_id', $step->execution->workflow->workspace_id)->whereIn('id', $ids)->pluck('name', 'id');
            $ordered = array_values(array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids)));

            $output[$field['key']] = implode(',', $ids);
            $output[$field['key'].'_name'] = match (count($ordered)) {
                0 => '',
                1 => $ordered[0],
                default => implode(', ', array_slice($ordered, 0, -1)).' and '.end($ordered),
            };
        }

        return $output;
    }
}
