<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\ContractorField;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The company's own fields on contractor records.
 */
class ContractorFieldController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fields' => ['present', 'array', 'max:20'],
            'fields.*.label' => ['required', 'string', 'max:100', 'distinct'],
            'fields.*.type' => ['required', Rule::in(ContractorField::TYPES)],
            'fields.*.options' => ['nullable', 'array', 'required_if:fields.*.type,select'],
            'fields.*.options.*' => ['string', 'max:100'],
            'fields.*.is_required' => ['boolean'],
        ]);

        DB::transaction(function () use ($data): void {
            $keys = [];
            foreach ($data['fields'] as $position => $field) {
                $key = Str::snake(Str::ascii($field['label']));
                $keys[] = $key;
                ContractorField::query()->updateOrCreate(['key' => $key], [
                    'label' => $field['label'], 'type' => $field['type'], 'options' => $field['options'] ?? null,
                    'is_required' => $field['is_required'] ?? false, 'position' => $position,
                ]);
            }
            // Values already saved on contractors stay in place if a field is removed.
            ContractorField::query()->whereNotIn('key', $keys)->delete();
        });

        Activity::record('contractor.fields_updated', 'Contractor fields updated', null, ['fields' => collect($data['fields'])->pluck('label')]);

        return response()->json(['data' => ContractorField::query()->orderBy('position')->get()]);
    }
}
