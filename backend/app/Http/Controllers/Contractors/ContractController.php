<?php

namespace App\Http\Controllers\Contractors;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorContract;
use App\Models\Document;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContractController extends Controller
{
    public function index(Contractor $contractor): JsonResponse
    {
        return response()->json(['data' => ContractorContract::query()->where('contractor_id', $contractor->id)->latest('start_date')->get()
            ->map(fn (ContractorContract $contract): array => self::present($contract))]);
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
        $document = $contract->signedDocument;

        Activity::record('contractor.contract_downloaded', "Signed contract “{$contract->title}” downloaded", $contract);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
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
        if ($file === null) {
            return;
        }

        $path = $file->storeAs("companies/{$contractor->company_id}/contractors/{$contractor->id}", Str::ulid().'.'.$file->extension(), 'local');
        $document = $contractor->documents()->create([
            'category' => 'contract', 'title' => "Signed: {$contract->title}", 'disk' => 'local', 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime_type' => $file->getMimeType() ?? 'application/pdf',
            'size_bytes' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
            'visibility' => Document::VISIBILITY_HR, 'uploaded_by' => $request->user()->id,
        ]);
        $contract->forceFill(['signed_document_id' => $document->id])->save();
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
            'signed_document' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ], ['end_date.after_or_equal' => 'The end date cannot be before the start date.']);
    }
}
