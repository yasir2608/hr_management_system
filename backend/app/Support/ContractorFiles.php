<?php

namespace App\Support;

use App\Models\Contractor;
use App\Models\Document;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stores contractor files (signed contracts, invoices) privately, visible to HR only.
 */
class ContractorFiles
{
    public const RULES = ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx'];

    public static function store(UploadedFile $file, Contractor $contractor, string $category, string $title, User $uploader): Document
    {
        $path = $file->storeAs("companies/{$contractor->company_id}/contractors/{$contractor->id}", Str::ulid().'.'.$file->extension(), 'local');

        return $contractor->documents()->create([
            'category' => $category, 'title' => $title, 'disk' => 'local', 'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime_type' => $file->getMimeType() ?? 'application/pdf',
            'size_bytes' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
            'visibility' => Document::VISIBILITY_HR, 'uploaded_by' => $uploader->id,
        ]);
    }

    public static function download(Document $document): StreamedResponse
    {
        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
