<?php

namespace App\Employees\Import;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the first sheet of a CSV or .xlsx file into rows of strings.
 * (Built on PHP's zip and XML support, so no extra package is needed.)
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 2000;

    /**
     * @return list<list<string>>
     */
    public function read(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx' ? $this->readXlsx($path) : $this->readCsv($path);

        // Drop fully empty rows (common at the end of sheets).
        $rows = array_values(array_filter($rows, fn (array $row): bool => implode('', array_map('trim', $row)) !== ''));

        if (count($rows) - 1 > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.']);
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // UTF-8 byte-order mark from Excel
        $delimiter = substr_count(strtok($content, "\n") ?: '', ';') > substr_count(strtok($content, "\n") ?: '', ',') ? ';' : ',';

        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($cell): string => trim((string) $cell), $row);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'This Excel file could not be opened. Save it again, or export it as CSV.']);
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((new SimpleXMLElement($xml))->si as $item) {
                $shared[] = isset($item->t) ? (string) $item->t : implode('', array_map(fn ($run) => (string) $run->t, iterator_to_array($item->r, false)));
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if ($sheet === false) {
            throw ValidationException::withMessages(['file' => 'No worksheet was found in this Excel file.']);
        }

        $rows = [];
        foreach ((new SimpleXMLElement($sheet))->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $column = $this->columnIndex(preg_replace('/\d+/', '', (string) $cell['r']));
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's' => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => (string) $cell->is->t,
                    default => (string) $cell->v,
                };
                $cells[$column] = trim($value);
            }
            $width = $cells === [] ? 0 : max(array_keys($cells)) + 1;
            $rows[] = array_map(fn (int $index): string => $cells[$index] ?? '', $width === 0 ? [] : range(0, $width - 1));
        }

        return $rows;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }
}
