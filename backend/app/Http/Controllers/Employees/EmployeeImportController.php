<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\Import\ConfirmImport;
use App\Employees\Import\ImportColumns;
use App\Employees\Import\ImportValidator;
use App\Employees\Import\SpreadsheetReader;
use App\Http\Controllers\Controller;
use App\Models\EmployeeImport;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Employee import, step 1: the sample spreadsheet and the upload.
 */
class EmployeeImportController extends Controller
{
    public function __construct(private readonly Access $access) {}

    public function columns(): JsonResponse
    {
        return response()->json([
            'columns' => ImportColumns::all(),
            'can_import_sensitive' => $this->access->can('payroll.settings.manage'),
        ]);
    }

    /**
     * Sample spreadsheet (CSV, opens in Excel): "*" marks required columns and
     * "[sensitive]" marks payroll-only columns. Row 2 is an example to replace.
     */
    public function template(): StreamedResponse
    {
        $columns = ImportColumns::all();

        return response()->streamDownload(function () use ($columns): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn (string $key) => ImportColumns::header($key, $columns[$key]), array_keys($columns)), ',', '"', '');
            fputcsv($out, array_column($columns, 'example'), ',', '"', '');
            fclose($out);
        }, 'employee-import-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Checks the rows again (for example after departments or locations were added).
     */
    public function revalidate(EmployeeImport $import, ImportValidator $validator): JsonResponse
    {
        abort_unless($import->isOpen(), 422, 'This import is already finished.');

        return response()->json($this->present($validator->validate($import)));
    }

    public function store(Request $request, SpreadsheetReader $reader, ImportValidator $validator): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
        ], ['file.extensions' => 'Upload the template as a CSV or Excel (.xlsx) file.']);

        $file = $request->file('file');
        $rows = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());

        if (count($rows) < 2) {
            return response()->json(['message' => 'The file has no employee rows under the header row.', 'errors' => ['file' => ['The file has no employee rows under the header row.']]], 422);
        }

        $headers = array_shift($rows);
        $mapping = array_map(fn (string $header): ?string => ImportColumns::match($header), $headers);
        $columns = ImportColumns::all();
        $canSensitive = $this->access->can('payroll.settings.manage');

        $missingRequired = collect($columns)->filter(fn (array $column) => $column['required'])->keys()->diff(array_filter($mapping))->values();
        if ($missingRequired->isNotEmpty()) {
            $labels = $missingRequired->map(fn (string $key) => $columns[$key]['label'])->implode(', ');

            return response()->json(['message' => "The file is missing required columns: {$labels}. Start from the template.", 'errors' => ['file' => ["Missing required columns: {$labels}."]]], 422);
        }

        $parsed = [];
        foreach ($rows as $index => $cells) {
            $values = [];
            foreach ($mapping as $position => $key) {
                if ($key === null || ($columns[$key]['sensitive'] && ! $canSensitive)) {
                    continue;
                }
                $values[$key] = ($cells[$position] ?? '') === '' ? null : $cells[$position];
            }
            $parsed[] = ['row' => $index + 2, 'values' => $values, 'errors' => []];
        }

        $import = EmployeeImport::query()->create([
            'uploaded_by' => $request->user()->id,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => EmployeeImport::STATUS_UPLOADED,
            'columns' => array_combine(array_map(fn ($header, $i) => $header !== '' ? $header : 'Column '.($i + 1), $headers, array_keys($headers)), $mapping),
            'rows' => $parsed,
            'row_count' => count($parsed),
        ]);

        Activity::record('employee_import.uploaded', "Employee import file “{$import->original_name}” uploaded ({$import->row_count} rows)", $import);

        // Step 2 runs straight away: every row is checked before anyone reviews it.
        $validator->validate($import);

        $ignoredSensitive = ! $canSensitive && collect($mapping)->filter()->contains(fn (string $key) => $columns[$key]['sensitive']);

        return response()->json([
            ...$this->present($import),
            'warnings' => array_values(array_filter([
                collect($mapping)->contains(null) ? 'Some columns were not recognised and will be ignored.' : null,
                $ignoredSensitive ? 'Pay, tax and bank columns were ignored because your role cannot enter payroll details.' : null,
            ])),
        ], 201);
    }

    public function show(EmployeeImport $import): JsonResponse
    {
        return response()->json($this->present($import));
    }

    /**
     * Inline fix: replaces values in one row, then checks the whole file again so
     * duplicates and in-file managers stay correct.
     */
    public function updateRow(Request $request, EmployeeImport $import, int $row, ImportValidator $validator): JsonResponse
    {
        abort_unless($import->isOpen(), 422, 'This import is already finished.');
        $canSensitive = $this->access->can('payroll.settings.manage');
        $allowed = collect(ImportColumns::all())->reject(fn (array $column) => $column['sensitive'] && ! $canSensitive)->keys()->all();

        $data = $request->validate(['values' => ['required', 'array'], 'values.*' => ['nullable', 'string', 'max:255']]);

        $rows = collect($import->rows);
        $index = $rows->search(fn (array $item): bool => $item['row'] === $row);
        abort_if($index === false, 404);

        $current = $rows[$index];
        $current['values'] = [...$current['values'], ...collect($data['values'])->only($allowed)->all()];
        $import->rows = $rows->put($index, $current)->values()->all();

        return response()->json($this->present($validator->validate($import)));
    }

    /**
     * Leaves a row out of the import (or brings it back).
     */
    public function toggleRow(EmployeeImport $import, int $row, ImportValidator $validator): JsonResponse
    {
        abort_unless($import->isOpen(), 422, 'This import is already finished.');

        $rows = collect($import->rows);
        $index = $rows->search(fn (array $item): bool => $item['row'] === $row);
        abort_if($index === false, 404);

        $current = $rows[$index];
        $current['excluded'] = ! ($current['excluded'] ?? false);
        $import->rows = $rows->put($index, $current)->values()->all();

        return response()->json($this->present($validator->validate($import)));
    }

    /**
     * The problems as a spreadsheet, to fix in the original file if preferred.
     * Sensitive values are not repeated in the download.
     */
    public function errors(EmployeeImport $import): StreamedResponse
    {
        $columns = ImportColumns::all();

        return response()->streamDownload(function () use ($import, $columns): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Row', 'Column', 'Value', 'Problem'], ',', '"', '');
            foreach ($import->rows as $row) {
                if ($row['excluded'] ?? false) {
                    continue;
                }
                foreach ($row['errors'] as $key => $message) {
                    $value = ($columns[$key]['sensitive'] ?? false) ? '(hidden)' : ($row['values'][$key] ?? '');
                    fputcsv($out, [$row['row'], $columns[$key]['label'] ?? $key, $value, $message], ',', '"', '');
                }
            }
            fclose($out);
        }, 'import-problems.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function confirm(Request $request, EmployeeImport $import, ConfirmImport $confirmImport): JsonResponse
    {
        abort_unless($import->isOpen(), 422, 'This import is already finished.');

        $counts = $confirmImport->handle($import, $request->user());

        return response()->json([
            ...$import->fresh()->only(['id', 'original_name', 'status', 'row_count', 'created_count', 'completed_at']),
            'counts' => $counts,
            'results' => $import->fresh()->rows,
            'message' => "{$counts['created']} employee(s) added."
                .($counts['skipped'] > 0 ? " {$counts['skipped']} row(s) with problems were not saved." : '')
                .($counts['failed'] > 0 ? " {$counts['failed']} row(s) could not be saved; try them again." : ''),
        ]);
    }

    /**
     * Past imports, with their results (never their row data).
     */
    public function index(): JsonResponse
    {
        $imports = EmployeeImport::query()->with('uploader:id,name')->latest()->limit(20)->get()
            ->map(fn (EmployeeImport $import): array => [
                ...$import->only(['id', 'original_name', 'status', 'row_count', 'created_count', 'created_at', 'completed_at']),
                'uploaded_by' => $import->uploader?->name,
            ]);

        return response()->json(['data' => $imports]);
    }

    public function cancel(EmployeeImport $import): JsonResponse
    {
        abort_unless($import->isOpen(), 422, 'This import is already finished.');

        // Clear the uploaded rows: nothing sensitive is kept for a cancelled import.
        $import->forceFill(['status' => EmployeeImport::STATUS_CANCELLED, 'rows' => []])->save();

        return response()->json(['message' => 'Import cancelled. Nothing was saved.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function present(EmployeeImport $import): array
    {
        return [
            ...$import->only(['id', 'original_name', 'status', 'columns', 'row_count', 'ready_count', 'error_count', 'created_count', 'created_at', 'completed_at']),
            'rows' => $import->isOpen() ? $import->rows : [],
        ];
    }
}
