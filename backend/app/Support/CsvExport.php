<?php

namespace App\Support;

use App\Models\CompanySetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV download that can be understood later: every file starts with its title, when and by whom it was generated,
 * the filters used and any notices, then one or more tables. Exports are built from the same queries as the screens,
 * so they follow the same access rules. Cells that a spreadsheet would run as a formula are made safe.
 *
 * Layout:
 *   Title
 *   Generated, <date and time> (<time zone>), by <name>
 *   Filters, <label: value; ...>   (or "None")
 *   <notices, one per row>
 *   (blank)
 *   [section title]
 *   header row, data rows, [totals row]
 */
class CsvExport
{
    /** @var list<list<mixed>> */
    private array $lines = [];

    /** @var array<string, string|null> */
    private array $filters = [];

    /** @var list<string> */
    private array $notices = [];

    /** @var list<array{title: ?string, headers: list<string>, rows: iterable<array<int, mixed>>, totals: ?array<int, mixed>}> */
    private array $tables = [];

    public function __construct(private readonly string $title) {}

    /**
     * @param  array<string, string|null>  $filters  label => value; empty values are shown as "All"
     */
    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function notice(?string $notice): self
    {
        if ($notice !== null && $notice !== '') {
            $this->notices[] = $notice;
        }

        return $this;
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, mixed>|null  $totals  a final row, e.g. ['Total', '', '120.00']
     */
    public function table(array $headers, iterable $rows, ?array $totals = null, ?string $title = null): self
    {
        $this->tables[] = ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'totals' => $totals];

        return $this;
    }

    /**
     * Adds a table from report columns ({key, label}) and keyed rows.
     *
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals  keyed by column; the first column shows "Total"
     */
    public function keyedTable(array $columns, array $rows, ?array $totals = null, ?string $title = null): self
    {
        $values = fn (array $row): array => array_map(fn (array $column): mixed => $row[$column['key']] ?? '', $columns);
        $totalRow = $totals === null ? null : array_map(fn (array $column, int $index): mixed => $index === 0 ? 'Total' : ($totals[$column['key']] ?? ''), $columns, array_keys($columns));

        return $this->table(array_column($columns, 'label'), array_map($values, $rows), $totalRow, $title);
    }

    /**
     * Records the download in the activity history with what was exported and the filters used.
     *
     * @param  array<string, mixed>  $properties
     */
    public function logged(string $action, string $description, array $properties = []): self
    {
        Activity::record($action, $description, null, [...$properties, 'filters' => $this->filters]);

        return $this;
    }

    public function download(string $fileName): StreamedResponse
    {
        $this->lines = [];
        $timezone = CompanySetting::current()?->timezone ?? config('app.timezone');
        $now = CarbonImmutable::now($timezone);

        $this->put([$this->title]);
        $this->put(['Generated', $now->format('Y-m-d H:i').' ('.$timezone.')'.(auth()->user() ? ', by '.auth()->user()->name : '')]);
        $this->put(['Filters', $this->filterText()]);
        foreach ($this->notices as $notice) {
            $this->put([$notice]);
        }

        foreach ($this->tables as $table) {
            $this->put([]);
            if ($table['title'] !== null) {
                $this->put([$table['title']]);
            }
            $this->put($table['headers']);
            foreach ($table['rows'] as $row) {
                $this->put(array_values($row));
            }
            if ($table['totals'] !== null) {
                $this->put($table['totals']);
            }
        }

        $lines = $this->lines;
        $name = Str::endsWith($fileName, '.csv') ? $fileName : "{$fileName}.csv";

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($lines as $line) {
                fputcsv($out, $line, ',', '"', '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function filterText(): string
    {
        if ($this->filters === []) {
            return 'None';
        }

        return collect($this->filters)->map(fn (?string $value, string $label): string => "{$label}: ".($value === null || $value === '' ? 'All' : $value))->join('; ');
    }

    /**
     * @param  list<mixed>  $row
     */
    private function put(array $row): void
    {
        $this->lines[] = array_map(fn (mixed $value): string => self::safe(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) ($value ?? '')), $row);
    }

    /**
     * Stops spreadsheet programs from running a value as a formula.
     */
    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) && ! is_numeric($value) ? "'".$value : $value;
    }
}
