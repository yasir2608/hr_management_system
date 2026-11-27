<?php

namespace App\Http\Controllers\Employees;

use App\Employees\DocumentAccess;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee documents. Files live on the private disk and are only ever served through
 * download(), which checks access and records who downloaded what.
 */
class EmployeeDocumentController extends Controller
{
    public const CATEGORIES = ['contract', 'identity', 'right_to_work', 'tax_form', 'certificate', 'policy', 'payslip', 'other'];

    public function __construct(private readonly DocumentAccess $documents) {}

    public function index(Employee $employee): JsonResponse
    {
        abort_unless($this->documents->canListAny($employee), 404);

        $visible = $employee->documents()->with('uploader:id,name')->latest()->get()
            ->filter(fn (Document $document): bool => $this->documents->canSee($employee, $document))
            ->values()
            ->map(fn (Document $document): array => [
                ...$document->only(['id', 'category', 'title', 'original_name', 'mime_type', 'size_bytes', 'visibility', 'created_at']),
                'expires_on' => $document->expires_on?->toDateString(),
                'uploaded_by' => $document->uploader?->name,
                'can_delete' => $this->documents->canUpload($employee, $document->visibility),
            ]);

        return response()->json([
            'data' => $visible,
            'can_upload' => $this->documents->isHr($employee) || $this->documents->isPayroll(),
            'upload_visibilities' => array_values(array_filter(
                [Document::VISIBILITY_EMPLOYEE, Document::VISIBILITY_HR, Document::VISIBILITY_PAYROLL],
                fn (string $visibility): bool => $this->documents->canUpload($employee, $visibility),
            )),
            'categories' => self::CATEGORIES,
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt'],
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'visibility' => ['required', Rule::in([Document::VISIBILITY_EMPLOYEE, Document::VISIBILITY_HR, Document::VISIBILITY_PAYROLL])],
            'expires_on' => ['nullable', 'date', 'after:today'],
        ], ['file.max' => 'Files can be up to 10 MB.', 'file.mimes' => 'Upload a PDF, image, Word, Excel, CSV or text file.']);

        abort_unless($this->documents->canUpload($employee, $data['visibility']), 403);

        $file = $request->file('file');
        $path = $file->storeAs("companies/{$employee->company_id}/employees/{$employee->id}", Str::ulid().'.'.$file->extension(), 'local');

        $document = $employee->documents()->create([
            'category' => $data['category'],
            'title' => $data['title'],
            'disk' => 'local',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'visibility' => $data['visibility'],
            'expires_on' => $data['expires_on'] ?? null,
            'uploaded_by' => $request->user()->id,
        ]);

        Activity::record('employee.document_uploaded', "Document “{$document->title}” added for {$employee->fullName()}", $employee, ['document_id' => $document->id, 'visibility' => $document->visibility]);

        return response()->json(['id' => $document->id, 'message' => 'Document uploaded.'], 201);
    }

    public function download(Employee $employee, string $documentId): StreamedResponse
    {
        $document = $employee->documents()->findOrFail($documentId);
        abort_unless($this->documents->canSee($employee, $document), 404);

        Activity::record('employee.document_downloaded', "Document “{$document->title}” of {$employee->fullName()} downloaded", $employee, ['document_id' => $document->id]);

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }

    public function destroy(Employee $employee, string $documentId): JsonResponse
    {
        $document = $employee->documents()->findOrFail($documentId);
        abort_unless($this->documents->canSee($employee, $document), 404);
        abort_unless($this->documents->canUpload($employee, $document->visibility), 403);

        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        Activity::record('employee.document_deleted', "Document “{$document->title}” removed from {$employee->fullName()}", $employee, ['document_id' => $document->id]);

        return response()->json(['message' => 'Document removed.']);
    }
}
