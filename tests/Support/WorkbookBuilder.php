<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Builds a workbook shaped like the owner's for the import's tests: titles on line 4 (employees) or 5 (registers). */
final class WorkbookBuilder
{
    /** @var list<array{name: string, header_row: int, headers: list<string>, rows: list<array<string, mixed>>}> */
    private array $sheets = [];

    /** @param list<array<string, mixed>> $rows title => value */
    public function employees(array $rows, array $extraHeaders = []): self
    {
        $headers = ['م', 'الرقم الذاتي', 'الاسم الثلاثي', 'الاسم', 'اسم الأب', 'العائلة', 'اسم الأم', 'الجنس', 'تاريخ الميلاد', 'الرقم الوطني',
            'المستوى التنظيمي الأول', 'المستوى التنظيمي الثاني', 'الحالة الوظيفية', 'حالة الموظف', 'تاريخ التسريح', 'رقم الهاتف',
            'تاريخ بداية العقد', 'تاريخ انتهاء العقد', 'الراتب الأساسي', 'لديه سلاح', 'رابط الهوية', 'آخر مراجعة - الدورة الشهرية', ...$extraHeaders];

        return $this->sheet('قاعدة بيانات الموظفين', 4, $headers, $rows);
    }

    /** @param list<array<string, mixed>> $rows */
    public function sheet(string $name, int $headerRow, array $headers, array $rows): self
    {
        $this->sheets[] = ['name' => $name, 'header_row' => $headerRow, 'headers' => $headers, 'rows' => $rows];

        return $this;
    }

    public function path(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);

        foreach ($this->sheets as $i => $sheet) {
            if ($i > 0) {
                $writer->addNewSheetAndMakeItCurrent();
            }

            $writer->getCurrentSheet()->setName($sheet['name']);

            for ($line = 1; $line < $sheet['header_row']; $line++) {
                $writer->addRow(Row::fromValues($line === 1 ? ['عنوان الورقة'] : ['']));
            }

            $writer->addRow(new Row(array_map(fn ($h) => new StringCell($h, null), $sheet['headers'])));

            foreach ($sheet['rows'] as $row) {
                $writer->addRow(new Row(array_map(fn ($h) => match (true) {
                    ! array_key_exists($h, $row) || $row[$h] === null => Cell::fromValue(''),
                    is_string($row[$h]) => new StringCell($row[$h], null),
                    // ['formula' => '=…']: a formula without a computed value, as the workbook's empty lines hold.
                    is_array($row[$h]) => new Cell\FormulaCell($row[$h]['formula'], null, null),
                    default => Cell::fromValue($row[$h]),
                }, $sheet['headers'])));
            }
        }

        $writer->close();

        return $path;
    }

    public function upload(string $name = 'نظام إدارة القوى البشرية.xlsx'): UploadedFile
    {
        return new UploadedFile($this->path(), $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
