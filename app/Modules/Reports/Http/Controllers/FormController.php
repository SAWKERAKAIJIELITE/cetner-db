<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Support\AuditTrail;
use App\Modules\Employees\Enums\FileStatus;
use App\Modules\Employees\Models\Employee;
use App\Modules\Reports\Forms\EmployeeForm;
use App\Modules\Reports\Forms\FormCatalogue;
use App\Modules\Reports\Forms\FormPdf;
use App\Modules\Reports\Http\Requests\FormRequest;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** The workbook's three printed sheets of an employee (D-050): `employees.view`. */
class FormController extends Controller
{
    /** The forms: basic («استمارة المعلومات الأساسية»), detailed («… التفصيلية»), status («تقرير الحالة الوظيفية»). */
    #[ApiResponse(200, type: 'array{data: list<array{key: string, title: string, takes_year: bool}>}')]
    public function index(): array
    {
        return ['data' => array_map(fn (string $key) => [
            'key' => $key,
            'title' => FormCatalogue::FORMS[$key]['title'],
            /** Takes `year` («سنة التقارير»). */
            'takes_year' => $key === FormCatalogue::STATUS,
        ], FormCatalogue::keys())];
    }

    /**
     * A form's content for the screen: its header, its sections' rows (label and value as people read them) and, for
     * the status report, the year's figures and the latest records. The file's approved values only.
     */
    public function show(FormRequest $request, Employee $employee, string $form): array
    {
        return ['data' => $this->build($request, $employee, $form)];
    }

    /** The form as an A4 PDF, to print or keep; logged. */
    #[ApiResponse(200, 'The PDF (A4).', mediaType: 'application/pdf', type: 'string', format: 'binary')]
    public function pdf(FormRequest $request, Employee $employee, string $form): Response
    {
        $content = $this->build($request, $employee, $form);
        $pdf = FormPdf::render($content, $request->user()->fullName());

        AuditTrail::record('reports.form_printed', $request->user(), [
            'employee_id' => $employee->id,
            'form' => $form,
            'year' => $content['year'],
        ]);

        $name = $form.'-'.preg_replace('/[^0-9A-Za-z_-]/', '', (string) $employee->personal_number).'.pdf';

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function build(Request $request, Employee $employee, string $form): array
    {
        if ($employee->file_status !== FileStatus::Active) {
            throw ValidationException::withMessages(['employee' => __('reports.file_not_approved', ['status' => $employee->file_status->label()])]);
        }

        $year = $request->validated('year');

        return (new EmployeeForm($employee, $form, $year === null ? null : (int) $year))->build();
    }
}
