<?php

namespace App\Modules\Imports\Support;

use App\Modules\Imports\Enums\ImportSheet;
use App\Support\ArabicText;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads the workbook's known sheets row by row (D-053), streaming, so a large file never sits in memory. A sheet is
 * found by its name; its header is the row, among the first twelve, whose titles match the most known columns; the
 * rows below it keep the workbook's line numbers, so a message points at the line the owner sees in Excel.
 */
final class WorkbookReader
{
    private const HEADER_SCAN = 12;

    public function __construct(private readonly string $path) {}

    /** @return array<string, string> sheet kind => the sheet's name in the file, for the known sheets present */
    public function sheets(): array
    {
        $found = [];
        $reader = $this->open();

        foreach ($reader->getSheetIterator() as $sheet) {
            $key = ArabicText::searchKey($sheet->getName());

            foreach (ImportSheet::cases() as $kind) {
                if (! isset($found[$kind->value]) && $key === ArabicText::searchKey($kind->sheetName())) {
                    $found[$kind->value] = $sheet->getName();
                }
            }
        }

        $reader->close();

        return $found;
    }

    /**
     * @param  callable(int $headerRow, list<array{index: int, letter: string, title: string, target: ?array{0: string, 1: string|int|null}}> $columns): void  $onHeader
     * @param  callable(int $line, array<int, mixed> $values): void  $onRow  the non-empty cells of a row below the header
     */
    public function read(ImportSheet $kind, string $name, callable $onHeader, callable $onRow): void
    {
        $reader = $this->open();

        foreach ($reader->getSheetIterator() as $sheet) {
            if ($sheet->getName() !== $name) {
                continue;
            }

            $scanned = [];
            $headerRow = null;

            foreach ($sheet->getRowIterator() as $line => $row) {
                $values = [];

                foreach ($row->getCells() as $index => $cell) {
                    $value = ImportCell::value($cell);

                    if ($value !== null) {
                        // A formula's value is kept apart: the workbook's own lookups are no data of the row.
                        $values[$index] = $cell instanceof Cell\FormulaCell ? new FormulaValue($value) : $value;
                    }
                }

                if ($headerRow === null) {
                    $scanned[$line] = $values;

                    if ($line >= self::HEADER_SCAN) {
                        $headerRow = $this->header($kind, $scanned, $onHeader);

                        foreach ($scanned as $l => $v) {
                            if ($l > $headerRow && $v !== []) {
                                $onRow($l, $v);
                            }
                        }
                    }

                    continue;
                }

                if ($values !== []) {
                    $onRow($line, $values);
                }
            }

            // A sheet shorter than the scan.
            if ($headerRow === null) {
                $headerRow = $this->header($kind, $scanned, $onHeader);

                foreach ($scanned as $l => $v) {
                    if ($l > $headerRow && $v !== []) {
                        $onRow($l, $v);
                    }
                }
            }

            break;
        }

        $reader->close();
    }

    /** @param array<int, array<int, mixed>> $scanned */
    private function header(ImportSheet $kind, array $scanned, callable $onHeader): int
    {
        $best = 0;
        $bestCount = 0;

        foreach ($scanned as $line => $values) {
            $count = count(array_filter($values, fn ($v) => is_string($v) && ImportColumns::of($kind, $v) !== null));

            if ($count > $bestCount) {
                [$best, $bestCount] = [$line, $count];
            }
        }

        $columns = [];

        if ($bestCount >= 2) {
            foreach ($scanned[$best] as $index => $title) {
                $title = ImportCell::text($title instanceof FormulaValue ? $title->value : $title) ?? '';
                $columns[] = ['index' => $index, 'letter' => self::letter($index), 'title' => $title, 'target' => ImportColumns::of($kind, $title)];
            }
        }

        $onHeader($best, $columns);

        return $best === 0 ? PHP_INT_MAX : $best;
    }

    public static function letter(int $index): string
    {
        $letter = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26).$letter;
        }

        return $letter;
    }

    private function open(): Reader
    {
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->SHOULD_FORMAT_DATES = false;
        $reader = new Reader($options);
        $reader->open($this->path);

        return $reader;
    }
}
