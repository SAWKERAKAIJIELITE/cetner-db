<?php

namespace App\Modules\Reports\Forms;

use App\Modules\Settings\Models\WorkSettings;
use App\Support\BusinessDay;
use Mpdf\Mpdf;

/**
 * A form as the workbook prints it (D-050): A4 portrait, right to left, two pairs of label and value a line, a
 * section's title above its rows; the year's records as tables. The branch's name heads it and the footer says
 * who printed it and when (Damascus time), on every page.
 */
final class FormPdf
{
    /** @param array<string, mixed> $form EmployeeForm::build() */
    public static function render(array $form, string $printedBy): string
    {
        $dir = storage_path('framework/cache/mpdf');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'orientation' => 'P',
            'directionality' => 'rtl',
            'default_font' => 'dejavusans',
            'margin_top' => 12,
            'margin_bottom' => 16,
            'margin_left' => 10,
            'margin_right' => 10,
            'tempDir' => $dir,
        ]);
        $pdf->SetTitle($form['title'].' — '.$form['employee']['full_name']);
        $pdf->SetAuthor((string) WorkSettings::current()->branch_name);
        $pdf->SetCreator('نظام إدارة القوى البشرية');
        $pdf->SetHTMLFooter('<div class="footer">طُبعت في '.e(now()->setTimezone(BusinessDay::timezone())->format('Y-m-d H:i'))
            .' بواسطة '.e($printedBy).' — صفحة {PAGENO} من {nbpg}</div>');
        $pdf->WriteHTML(self::html($form));

        return $pdf->Output('', 'S');
    }

    /** @param array<string, mixed> $form */
    public static function html(array $form): string
    {
        // Numbers, dates and amounts keep their left-to-right order inside the right-to-left page («-307», «2026-10»).
        $h = fn (?string $v) => match (true) {
            $v === null => '<span class="empty">—</span>',
            preg_match('/^[-+]?[0-9][0-9.,:%\/ -]*$/', $v) === 1 => '<div dir="ltr" style="text-align: right">'.e($v).'</div>',
            default => nl2br(e($v)),
        };
        $out = '<html dir="rtl"><head><style>'.self::CSS.'</style></head><body dir="rtl">';
        $out .= '<div class="branch">'.e((string) WorkSettings::current()->branch_name).'</div>';
        $out .= '<h1>'.e($form['title']).($form['year'] ? ' — '.e((string) $form['year']) : '').'</h1>';

        $out .= '<table class="pairs head">';
        foreach (array_chunk($form['header'], 2) as $pair) {
            $out .= '<tr>';
            foreach ($pair as $cell) {
                $out .= '<th>'.e($cell['label']).'</th><td>'.$h($cell['display']).'</td>';
            }
            $out .= '</tr>';
        }
        $out .= '</table>';

        foreach ($form['sections'] as $section) {
            $out .= '<h2>'.e($section['title']).'</h2><table class="pairs">';
            $line = [];
            $flush = function () use (&$line, &$out, $h) {
                if ($line === []) {
                    return;
                }
                $out .= '<tr>';
                foreach ($line as $row) {
                    $out .= '<th>'.e($row['label']).'</th><td>'.$h($row['display']).'</td>';
                }
                if (count($line) === 1) {
                    $out .= '<th></th><td></td>';
                }
                $out .= '</tr>';
                $line = [];
            };

            foreach ($section['rows'] as $row) {
                if ($row['wide']) {
                    $flush();
                    $out .= '<tr><th>'.e($row['label']).'</th><td colspan="3">'.$h($row['display']).'</td></tr>';

                    continue;
                }
                $line[] = $row;
                if (count($line) === 2) {
                    $flush();
                }
            }
            $flush();
            $out .= '</table>';
        }

        foreach ($form['tables'] as $table) {
            $out .= '<h2>'.e($table['title']).'</h2><table class="grid"><thead><tr>';
            foreach ($table['columns'] as $column) {
                $out .= '<th>'.e($column['label']).'</th>';
            }
            $out .= '</tr></thead><tbody>';
            if ($table['rows'] === []) {
                $out .= '<tr><td colspan="'.count($table['columns']).'" class="none">لا توجد سجلات</td></tr>';
            }
            foreach ($table['rows'] as $row) {
                $out .= '<tr>';
                foreach ($table['columns'] as $column) {
                    $out .= '<td>'.$h($row[$column['key']] ?? null).'</td>';
                }
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
        }

        return $out.'</body></html>';
    }

    private const CSS = <<<'CSS'
        body { font-family: dejavusans; font-size: 9pt; color: #1f2933; }
        .branch { text-align: center; font-size: 9pt; color: #52606d; }
        h1 { text-align: center; font-size: 14pt; margin: 2mm 0 3mm; }
        h2 { font-size: 10pt; background: #e4e7eb; padding: 1.2mm 2mm; margin: 3mm 0 0; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        .pairs th, .pairs td, .grid th, .grid td { border: 0.2mm solid #9aa5b1; padding: 1.2mm 1.6mm; text-align: right; vertical-align: top; }
        .pairs th { width: 22%; background: #f5f7fa; font-weight: bold; }
        .pairs td { width: 28%; }
        .head th { background: #d9e2ec; }
        .grid th { background: #f5f7fa; font-weight: bold; }
        .empty, .none { color: #9aa5b1; }
        .none { text-align: center; }
        .footer { text-align: center; font-size: 7.5pt; color: #52606d; }
        CSS;
}
