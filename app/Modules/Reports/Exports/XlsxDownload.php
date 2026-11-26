<?php

namespace App\Modules\Reports\Exports;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An Excel file written while it is sent (D-052): one sheet, right to left, a bold header row frozen at the top,
 * then the rows the producer yields, so a large export never sits in memory. Text is always written as text: a
 * value beginning with «=» stays a value, never a formula (no formula injection).
 */
final class XlsxDownload
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<string>  $headers
     * @param  callable(): iterable<list<string|int|float|bool|null>>  $rows
     */
    public static function response(string $fileName, string $sheetName, array $headers, callable $rows): StreamedResponse
    {
        return new StreamedResponse(function () use ($sheetName, $headers, $rows) {
            $options = new Options;
            $writer = new Writer($options);
            $writer->openToFile('php://output');

            $sheet = $writer->getCurrentSheet();
            $sheet->setName(mb_substr($sheetName, 0, 31));
            $sheet->setSheetView((new SheetView)->setRightToLeft(true)->setFreezeRow(2));

            $writer->addRow(self::row($headers, (new Style)->setFontBold()->setBackgroundColor('E4E7EB')));

            foreach ($rows() as $values) {
                $writer->addRow(self::row($values));
            }

            $writer->close();
        }, 200, [
            'Content-Type' => self::MIME,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param list<string|int|float|bool|null> $values */
    private static function row(array $values, ?Style $style = null): Row
    {
        return new Row(array_map(fn ($v) => is_string($v) ? new StringCell($v, null) : Cell::fromValue($v), array_values($values)), $style);
    }
}
