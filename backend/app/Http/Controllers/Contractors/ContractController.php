<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorContract;
use App\Support\Activity;
use App\Support\ContractorFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    public function index(Contractor $contractor): JsonResponse
    {
        return response()->json(['data' => ContractorContract::query()->where('contractor_id', $contractor->id)->latest('start_date')->get()
            ->map(fn (ContractorContract $contract): array => self::present($contract))]);
    }

    /**
     * Draft and active contracts whose end or renewal date falls within the window, or has already passed.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $request->validate(['days' => ['nullable', 'integer', 'between:1,365']]);
        $days = $request->integer('days', 60);
        $today = now()->startOfDay();
        $until = $today->copy()->addDays($days)->toDateString();

        $alerts = ContractorContract::query()->with('contractor:id,name')->whereIn('status', ['draft', 'active'])
            ->where(fn ($query) => $query->where('end_date', '<=', $until)->orWhere('renewal_date', '<=', $until))
            ->get()
            ->flatMap(function (ContractorContract $contract) use ($today, $until): array {
                $items = [];
                foreach (['end_date' => 'ends', 'renewal_date' => 'renewal'] as $column => $kind) {
                    $date = $contract->{$column};
                    if ($date !== null && $date->toDateString() <= $until) {
                        $items[] = [
                            'kind' => $kind, 'date' => $date->toDateString(), 'days_left' => (int) $today->diffInDays($date, false),
                            'contract' => self::present($contract), 'contractor' => ['id' => $contract->contractor->id, 'name' => $contract->contractor->name],
                        ];
                    }
                }

                return $items;
            })
            ->sortBy('date')->values();

        return response()->json(['data' => $alerts, 'days' => $days]);
    }

    public function store(Request $request, Contractor $contractor): JsonResponse
    {
        $data = $this->validated($request);
        $contract = ContractorContract::query()->create([...collect($data)->except('signed_document')->all(), 'contractor_id' => $contractor->id]);
        $this->attach($request->file('signed_document'), $contract, $contractor, $request);

        Activity::record('contractor.contract_created', "Contract “{$contract->title}” added for {$contractor->name}", $contract);

        return response()->json(self::present($contract->fresh()), 201);
    }

    public function update(Request $request, Contractor $contractor, ContractorContract $contract): JsonResponse
    {
        abort_unless($contract->contractor_id === $contractor->id, 404);
        $contract->fill(collect($this->validated($request))->except('signed_document')->all())->save();
        $this->attach($request->file('signed_document'), $contract, $contractor, $request);

        Activity::record('contractor.contract_updated', "Contract “{$contract->title}” updated", $contract, Activity::changes($contract));

        return response()->json(self::present($contract->fresh()));
    }

    public function download(Contractor $contractor, ContractorContract $contract): StreamedResponse
    {
        abort_unless($contract->contractor_id === $contractor->id && $contract->signedDocument !== null, 404);
        Activity::record('contractor.contract_downloaded', "Signed contract “{$contract->title}” downloaded", $contract);

        return ContractorFiles::download($contract->signedDocument);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ContractorContract $contract): array
    {
        return [
            ...$contract->only(['id', 'contractor_id', 'title', 'notice_days', 'rate_type', 'amount', 'currency', 'status', 'notes']),
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'renewal_date' => $contract->renewal_date?->toDateString(),
            'has_signed_document' => $contract->signed_document_id !== null,
        ];
    }

    private function attach(?UploadedFile $file, ContractorContract $contract, Contractor $contractor, Request $request): void
    {
        if ($file !== null) {
            $document = ContractorFiles::store($file, $contractor, 'contract', "Signed: {$contract->title}", $request->user());
            $contract->forceFill(['signed_document_id' => $document->id])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'renewal_date' => ['nullable', 'date'],
            'notice_days' => ['nullable', 'integer', 'between:0,365'],
            'rate_type' => ['required', Rule::in(ContractorContract::RATE_TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['required', Rule::in(ContractorContract::STATUSES)],
            'notes' => ['nullable', 'string', 'max:5000'],
            'signed_document' => ContractorFiles::RULES,
        ], ['end_date.after_or_equal' => 'The end date cannot be before the start date.']);
    }
}
