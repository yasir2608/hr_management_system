<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\CompanyUser;
use App\Models\Contractor;
use App\Models\ContractorField;
use App\Support\Activity;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContractorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive'], 'kind' => ['nullable', Rule::in(Contractor::KINDS)]]);

        $contractors = Contractor::query()->with('owner:id,name')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($inner) => $inner->where('name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->when($filters['status'] ?? 'active', fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['kind'] ?? null, fn ($query, string $kind) => $query->where('kind', $kind))
            ->orderBy('name')->get();

        return response()->json(['data' => $contractors, 'fields' => ContractorField::query()->orderBy('position')->get()]);
    }

    public function show(Contractor $contractor): JsonResponse
    {
        return response()->json([
            ...$contractor->load(['contacts', 'owner:id,name'])->toArray(),
            'fields' => ContractorField::query()->orderBy('position')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $contractor = DB::transaction(fn () => $this->save(new Contractor, $this->validated($request)));
        Activity::record('contractor.created', "Contractor {$contractor->name} added", $contractor);

        return response()->json($contractor->load('contacts'), 201);
    }

    public function update(Request $request, Contractor $contractor): JsonResponse
    {
        $contractor = DB::transaction(fn () => $this->save($contractor, $this->validated($request)));
        Activity::record('contractor.updated', "Contractor {$contractor->name} updated", $contractor, Activity::changes($contractor));

        return response()->json($contractor->load('contacts'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Contractor $contractor, array $data): Contractor
    {
        $contractor->fill(collect($data)->except('contacts')->all())->save();

        if (array_key_exists('contacts', $data)) {
            $contractor->contacts()->delete();
            foreach ($data['contacts'] ?? [] as $index => $contact) {
                $contractor->contacts()->create([...$contact, 'is_primary' => $index === 0]);
            }
        }

        return $contractor;
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $companyId = app(CurrentCompany::class)->id();
        $fields = ContractorField::query()->get();

        $data = $request->validate([
            'kind' => ['required', Rule::in(Contractor::KINDS)],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'address' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => ['nullable', Rule::exists('company_users', 'user_id')->where('company_id', $companyId)->where('status', CompanyUser::STATUS_ACTIVE)],
            'status' => ['nullable', 'in:active,inactive'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'contacts' => ['nullable', 'array', 'max:10'],
            'contacts.*.name' => ['required', 'string', 'max:255'],
            'contacts.*.role' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:30'],
            ...$fields->mapWithKeys(fn (ContractorField $field): array => ["custom_fields.{$field->key}" => $field->rules()])->all(),
        ], [], $fields->mapWithKeys(fn (ContractorField $field): array => ["custom_fields.{$field->key}" => $field->label])->all() + ['owner_user_id' => 'owner']);

        $data['custom_fields'] = collect($data['custom_fields'] ?? [])->only($fields->pluck('key'))->all();
        $data['status'] ??= 'active';

        return $data;
    }
}
