<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\RecordRegister;
use App\Modules\Reports\Exports\EmployeesExport;
use App\Modules\Reports\Exports\MovementsSummaryExport;
use App\Modules\Reports\Exports\RecordsExport;
use App\Modules\Reports\Exports\XlsxDownload;
use App\Modules\Reports\Http\Requests\ExportEmployeesRequest;
use App\Modules\Reports\Http\Requests\ExportMovementsSummaryRequest;
use App\Modules\Reports\Http\Requests\ExportRecordsRequest;
use App\Modules\Reports\Support\MovementsByEmployee;
use App\Modules\Reports\Support\ReportPeriod;
use App\Support\BusinessDay;
use Dedoc\Scramble\Attributes\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Excel exports (D-052): `reports.view` and the permission to read what is exported. Each takes the filters of the
 * screen it exports, is written while it is sent, and is logged with its filters and its number of rows.
 */
class ExportController extends Controller
{
    /** «قاعدة بيانات الموظفين»: the approved files of the employees list's filters, every field and formula. */
    #[Response(200, 'The Excel file (.xlsx), sent as it is written.', mediaType: XlsxDownload::MIME, type: 'string', format: 'binary')]
    public function employees(ExportEmployeesRequest $request): StreamedResponse
    {
        $f = $request->validated();
        $this->log($request->user(), 'employees', $f, EmployeesExport::query($f)->count());

        return XlsxDownload::response(self::name('employees'), 'الموظفون', EmployeesExport::headers(), fn () => EmployeesExport::rows($f));
    }

    /** A register with its filters: movements, attendance, conduct, debts or payments. */
    #[Response(200, 'The Excel file (.xlsx), sent as it is written.', mediaType: XlsxDownload::MIME, type: 'string', format: 'binary')]
    public function records(ExportRecordsRequest $request, string $type): StreamedResponse
    {
        $t = RecordType::from($type);
        $f = $request->validated();
        $this->log($request->user(), 'records.'.$type, $f, RecordRegister::query($t, $f)->count());

        return XlsxDownload::response(self::name($type), $t->label(), RecordsExport::headers($t), fn () => RecordsExport::rows($t, $f));
    }

    /** «ملخص جميع الموظفين» of «داش بورد الحركات» for the period. */
    #[Response(200, 'The Excel file (.xlsx), sent as it is written.', mediaType: XlsxDownload::MIME, type: 'string', format: 'binary')]
    public function movementsSummary(ExportMovementsSummaryRequest $request): StreamedResponse
    {
        $f = $request->validated();
        $period = new ReportPeriod((int) $f['year'], isset($f['month']) ? (int) $f['month'] : null);
        $this->log($request->user(), 'movements_summary', $f, MovementsByEmployee::query($period, $f)->count());

        return XlsxDownload::response(self::name('movements-summary-'.$period->year.($period->month ? sprintf('-%02d', $period->month) : '')),
            'ملخص الموظفين', MovementsSummaryExport::headers(), fn () => MovementsSummaryExport::rows($period, $f));
    }

    /** @param array<string, mixed> $filters */
    private function log(StaffUser $actor, string $kind, array $filters, int $rows): void
    {
        AuditTrail::record('exports.downloaded', $actor, ['kind' => $kind, 'filters' => $filters, 'rows' => $rows]);
    }

    private static function name(string $what): string
    {
        return $what.'-'.BusinessDay::today()->format('Y-m-d').'.xlsx';
    }
}
