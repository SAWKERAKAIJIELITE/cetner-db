<?php

namespace App\Modules\Reports\Forms;

use App\Modules\Documents\Support\DocumentCatalogue;
use App\Modules\Documents\Support\DocumentQueries;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Models\Employee;
use App\Modules\Employees\Support\EmployeeComputed;
use App\Modules\Employees\Support\EmployeeDerived;
use App\Modules\Employees\Support\FieldCatalogue;
use App\Modules\Employees\Support\FieldDefinitions;
use App\Modules\Employees\Support\FieldValues;
use App\Modules\Employees\Support\ReviewStatus;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Support\DebtTotals;
use App\Modules\Records\Support\RecordLabels;
use App\Modules\Records\Support\RecordSummary;
use App\Modules\Settings\Support\OrgUnitTree;
use App\Support\BusinessDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One of the three printed sheets of an employee (D-050), with the file's approved values as people read them (a
 * change waiting for review is not printed) and the workbook's formulas as of today in Damascus. The same content
 * serves the screen (JSON) and the PDF.
 */
final class EmployeeForm
{
    private const LEVELS = ['الأول', 'الثاني', 'الثالث', 'الرابع', 'الخامس', 'السادس'];

    /** Rows that take the whole width of a printed line. */
    private const WIDE = ['longtext'];

    private ValueLabels $labels;

    /** @var array<string, mixed>|null */
    private ?array $year = null;

    /** @var array<string, mixed>|null */
    private ?array $debts = null;

    public function __construct(private readonly Employee $employee, private readonly string $form, private readonly ?int $yearOf = null)
    {
        $this->labels = new ValueLabels;
    }

    /**
     * @return array{form: string, title: string, employee: array{id: string, personal_number: ?string, full_name: string}, year: ?int, header: list<array{label: string, display: ?string}>, sections: list<array{key: string, title: string, rows: list<array{key: string, label: string, display: ?string, wide: bool}>}>, tables: list<array{key: string, title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, ?string>>}>, generated_at: string}
     */
    public function build(): array
    {
        $definition = FormCatalogue::FORMS[$this->form];
        $year = $this->form === FormCatalogue::STATUS ? ($this->yearOf ?? (int) BusinessDay::today()->format('Y')) : null;

        $sections = [];

        foreach ($definition['sections'] as $section) {
            $rows = [];

            foreach ($section['rows'] as $key) {
                array_push($rows, ...$this->rows($key, $year));
            }

            $sections[] = ['key' => $section['key'], 'title' => $section['title'], 'rows' => $rows];
        }

        return [
            'form' => $this->form,
            'title' => $definition['title'],
            'employee' => [
                'id' => $this->employee->id,
                'personal_number' => $this->employee->personal_number,
                'full_name' => $this->employee->fullName(),
            ],
            'year' => $year,
            'header' => [
                ['label' => 'الاسم الثلاثي', 'display' => $this->employee->fullName()],
                ['label' => 'الرقم الذاتي', 'display' => $this->employee->personal_number],
                ['label' => 'جهة العمل الحالية', 'display' => $this->unitName()],
                ['label' => 'الحالة الوظيفية', 'display' => $this->field('job_status_id')],
            ],
            'sections' => $sections,
            'tables' => $this->form === FormCatalogue::STATUS ? $this->tables() : [],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array{key: string, label: string, display: ?string, wide: bool}> */
    private function rows(string $key, ?int $year): array
    {
        if (FieldCatalogue::has($key)) {
            $type = FieldCatalogue::get($key)['type'];

            return [self::row($key, FieldDefinitions::all()[$key]->label_ar ?? FieldCatalogue::get($key)['label'], $this->field($key), in_array($type, self::WIDE, true))];
        }

        if (str_starts_with($key, 'org_level_')) {
            $level = (int) substr($key, 10);
            $path = $this->unitPathParts();

            return [self::row($key, 'المستوى التنظيمي '.self::LEVELS[$level - 1], $path[$level - 1] ?? null)];
        }

        if (str_starts_with($key, 'year.')) {
            return [$this->yearRow(substr($key, 5), $year)];
        }

        if (str_starts_with($key, 'last_review_')) {
            $cycle = Cycle::from(substr($key, 12));
            $last = ReviewStatus::of($this->employee->id)[$cycle->value]['last_reviewed_on'] ?? null;

            return [self::row($key, 'آخر مراجعة — '.$cycle->label(), $last ?? 'لم تُراجع')];
        }

        return match ($key) {
            'full_name' => [self::row($key, 'الاسم الثلاثي', $this->employee->fullName())],
            'age' => [self::row($key, 'العمر', self::number(EmployeeComputed::age($this->employee)))],
            'service_years' => [self::row($key, 'سنوات الخدمة', self::number(EmployeeComputed::serviceYears($this->employee)))],
            'contract_days_left' => [self::row($key, 'المتبقي على انتهاء العقد (يوم)', self::number(EmployeeComputed::contractDaysLeft($this->employee)))],
            'org_unit_name' => [self::row($key, 'جهة العمل الحالية (أدق مستوى)', $this->unitName())],
            'has_debts' => [self::row($key, 'عليه ديون', $this->debts()['has_debts'] ? 'نعم' : 'لا')],
            'total_remaining' => [self::row($key, 'إجمالي الديون المتبقية', self::money($this->debts()['total_remaining']))],
            'debts.open' => [self::row($key, 'عدد الديون القائمة', (string) $this->debts()['open_debts'])],
            'debts.overdue' => [self::row($key, 'الديون المتأخرة', (string) $this->debts()['overdue_debts'])],
            'documents' => $this->documentRows(),
            'documents_attached' => [self::row($key, 'عدد الوثائق المرفقة (من 13)', count(DocumentQueries::currentKinds($this->employee->id)).' من '.count(DocumentCatalogue::FILE_KINDS))],
            'completeness' => [self::row($key, 'نسبة اكتمال البيانات', number_format((float) $this->employee->completeness, 2).'%')],
            'missing_fields' => [self::row($key, 'الحقول الناقصة حسب دورة التحديث', $this->missing(), true)],
        };
    }

    /** @return array{key: string, label: string, display: ?string, wide: bool} */
    private static function row(string $key, string $label, ?string $display, bool $wide = false): array
    {
        return ['key' => $key, 'label' => $label, 'display' => $display === '' ? null : $display, 'wide' => $wide];
    }

    private function field(string $key): ?string
    {
        $value = FieldValues::current($this->employee, $key);

        if ($value === null) {
            return null;
        }

        return match (FieldCatalogue::get($key)['type']) {
            'money' => self::money($value),
            default => $this->labels->display($key, $value),
        };
    }

    private function unitName(): ?string
    {
        $parts = $this->unitPathParts();

        return $parts === [] ? null : end($parts);
    }

    /** @return list<string> */
    private function unitPathParts(): array
    {
        $id = $this->employee->org_unit_id;

        if ($id === null) {
            return [];
        }

        $unit = OrgUnitTree::all(true)->firstWhere('id', (int) $id);

        return $unit === null ? [] : array_values($unit->path);
    }

    /** @return list<array{key: string, label: string, display: ?string, wide: bool}> */
    private function documentRows(): array
    {
        $current = DB::table('employee_documents')->where('employee_id', $this->employee->id)->whereNull('conduct_record_id')
            ->where('status', 'approved')->whereNull('superseded_at')->whereNull('removed_at')
            ->pluck('uploaded_at', 'kind');

        $rows = [];

        foreach (DocumentCatalogue::FILE_KINDS as $kind => $info) {
            $rows[] = self::row('documents.'.$kind, $info['label'], isset($current[$kind])
                ? 'مرفقة ('.Carbon::parse($current[$kind])->setTimezone(BusinessDay::timezone())->format('Y-m-d').')' : 'غير مرفقة');
        }

        return $rows;
    }

    private function missing(): ?string
    {
        $parts = [];

        foreach ($this->employee->missing_fields ?? [] as $cycle => $keys) {
            if ($keys !== []) {
                $parts[] = Cycle::from($cycle)->label().': '.implode('، ', array_map(fn ($k) => EmployeeDerived::label($k), $keys));
            }
        }

        return $parts === [] ? 'لا شيء' : implode(' — ', $parts);
    }

    /** @return array{key: string, label: string, display: ?string, wide: bool} */
    private function yearRow(string $key, int $year): array
    {
        $this->year ??= RecordSummary::of($this->employee->id, $year);
        $y = $this->year;

        return match ($key) {
            'leaves' => self::row('year.'.$key, 'عدد الإجازات', (string) $y['leaves']['count']),
            'leave_days' => self::row('year.'.$key, 'أيام الإجازات', (string) $y['leaves']['days']),
            'leave_balance' => self::row('year.'.$key, 'رصيد الإجازات المتبقي', (string) $y['leaves']['balance']),
            'courses' => self::row('year.'.$key, 'الدورات التدريبية', (string) $y['courses']),
            'rewards' => self::row('year.'.$key, 'إجمالي المكافآت', self::money($y['rewards_total'])),
            'deductions' => self::row('year.'.$key, 'إجمالي الخصومات', self::money($y['deductions_total'])),
            'planned_days' => self::row('year.'.$key, 'أيام الدوام المقررة', self::number($y['attendance']['planned_days'])),
            'attended_days' => self::row('year.'.$key, 'أيام الدوام الفعلية', self::number($y['attendance']['attended_days'])),
            'absent_days' => self::row('year.'.$key, 'أيام الغياب', self::number($y['attendance']['absent_days'])),
            'absence_rate' => self::row('year.'.$key, 'نسبة الغياب', number_format($y['attendance']['absence_rate'] * 100, 2).'%'),
            'complaints' => self::row('year.'.$key, 'الشكاوى', (string) $y['conduct']['complaints']),
            'warnings' => self::row('year.'.$key, 'الإنذارات', (string) $y['conduct']['warnings']),
            'punishments' => self::row('year.'.$key, 'العقوبات', (string) $y['conduct']['punishments']),
            'thanks' => self::row('year.'.$key, 'الشكر والتقدير', (string) $y['conduct']['thanks']),
        };
    }

    /** @return array<string, mixed> */
    private function debts(): array
    {
        return $this->debts ??= DebtTotals::of($this->employee->id);
    }

    /** @return list<array{key: string, title: string, columns: list<array{key: string, label: string}>, rows: list<array<string, ?string>>}> */
    private function tables(): array
    {
        $latest = RecordSummary::latest($this->employee->id);
        $movementLabels = (new RecordLabels)->prime(RecordType::Movements, $latest['movements']->map->getAttributes());
        $conductLabels = (new RecordLabels)->prime(RecordType::Conduct, $latest['conduct']->map->getAttributes());
        $documents = DocumentQueries::ofConductRecords($latest['conduct']->pluck('id')->all());
        $attendance = $latest['attendance'];

        return [
            [
                'key' => 'movements',
                'title' => 'آخر 10 حركات مُسجَّلة للفرد (من سجل الحركات)',
                'columns' => self::columns(['occurred_on' => 'التاريخ', 'type' => 'نوع الحركة', 'description' => 'الوصف', 'days' => 'عدد الأيام', 'amount' => 'المبلغ', 'status' => 'الحالة']),
                'rows' => $latest['movements']->map(fn ($m) => [
                    'occurred_on' => $m->occurred_on?->format('Y-m-d'),
                    'type' => $movementLabels->display(RecordType::Movements, 'movement_type_id', $m->movement_type_id),
                    'description' => $m->description,
                    'days' => $m->days === null ? null : (string) $m->days,
                    'amount' => $m->amount === null ? null : self::money($m->amount),
                    'status' => $m->status,
                ])->values()->all(),
            ],
            [
                'key' => 'conduct',
                'title' => 'آخر 5 سجلات للسلوك الوظيفي (الشكاوى والقرارات الرقابية)',
                'columns' => self::columns(['occurred_on' => 'التاريخ', 'type' => 'نوع السجل', 'subject' => 'الموضوع', 'decision' => 'القرار الرقابي', 'status' => 'حالة المعالجة', 'document' => 'الوثيقة']),
                'rows' => $latest['conduct']->map(fn ($c) => [
                    'occurred_on' => $c->occurred_on?->format('Y-m-d'),
                    'type' => $conductLabels->display(RecordType::Conduct, 'conduct_type_id', $c->conduct_type_id),
                    'subject' => $c->subject,
                    'decision' => $c->decision,
                    'status' => $conductLabels->display(RecordType::Conduct, 'conduct_status_id', $c->conduct_status_id),
                    'document' => ($documents[$c->id]['current'] ?? null) !== null ? 'مرفقة' : ($c->document_link ? 'رابط' : '—'),
                ])->values()->all(),
            ],
            [
                'key' => 'attendance',
                'title' => 'آخر سجل دوام شهري',
                'columns' => self::columns(['month' => 'السنة / الشهر', 'planned_days' => 'أيام الدوام المقررة', 'attended_days' => 'أيام الحضور', 'absent_days' => 'أيام الغياب', 'absence_rate' => 'نسبة الغياب', 'exceptional_skills' => 'المهارات الاستثنائية']),
                'rows' => $attendance === null ? [] : [[
                    'month' => sprintf('%04d-%02d', $attendance->year, $attendance->month),
                    'planned_days' => self::number($attendance->planned_days),
                    'attended_days' => self::number($attendance->attended_days),
                    'absent_days' => self::number($attendance->absent_days),
                    'absence_rate' => number_format(($attendance->planned_days > 0 ? $attendance->absent_days / $attendance->planned_days : 0) * 100, 2).'%',
                    'exceptional_skills' => $attendance->exceptional_skills,
                ]],
            ],
        ];
    }

    /** @return list<array{key: string, label: string}> */
    private static function columns(array $columns): array
    {
        return array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys($columns), $columns);
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }

    private static function number(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $float = (float) $value;

        return $float == (int) $float ? (string) (int) $float : (string) round($float, 2);
    }
}
