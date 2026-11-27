<?php

namespace App\Http\Controllers\Employees;

use App\Access\Access;
use App\Employees\Import\ImportColumns;
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

    public function store(Request $request, SpreadsheetReader $reader): JsonResponse
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
