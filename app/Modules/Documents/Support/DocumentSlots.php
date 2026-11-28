<?php

namespace App\Modules\Documents\Support;

use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Employees\Support\FieldDefinitions;
use Illuminate\Support\Collection;

/** The kinds as the field guide has them now (D-049): its cycle and ✔ are editable there. */
final class DocumentSlots
{
    /** @return list<DocumentKind> */
    public static function kinds(): array
    {
        $definitions = FieldDefinitions::all();
        $kinds = [];

        foreach (DocumentCatalogue::FILE_KINDS as $key => $kind) {
            $definition = $definitions[$key] ?? null;
            $cycle = $definition?->cycle ?? Cycle::from($kind['cycle']);

            $kinds[] = new DocumentKind($key, $definition->label_ar ?? $kind['label'], $kind['workbook'], $kind['column'],
                $cycle->value, $cycle->label(), (bool) ($definition?->counts_for_completeness ?? $kind['counts']), false);
        }

        $kinds[] = new DocumentKind(DocumentCatalogue::CONDUCT, DocumentCatalogue::label(DocumentCatalogue::CONDUCT), null, null, null, null, false, true);

        return $kinds;
    }

    /**
     * An employee's documents now: each of the 13 kinds with its current file and the uploads waiting for review,
     * and the conduct records' current documents.
     *
     * @return array{kinds: list<DocumentKind>, current: Collection<string, EmployeeDocument>, pending: Collection<string, Collection<int, EmployeeDocument>>, conduct: Collection<int, EmployeeDocument>}
     */
    public static function of(string $employeeId): array
    {
        $documents = EmployeeDocument::query()
            ->with(['uploader', 'reviewer', 'remover'])
            ->where('employee_id', $employeeId)
            ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($c) => $c->current()))
            ->orderBy('uploaded_at')->orderBy('id')
            ->get();

        return [
            'kinds' => array_values(array_filter(self::kinds(), fn (DocumentKind $k) => ! $k->onConductRecord)),
            'current' => $documents->filter(fn (EmployeeDocument $d) => $d->isCurrent() && $d->conduct_record_id === null)->keyBy('kind'),
            'pending' => $documents->filter(fn (EmployeeDocument $d) => $d->isPending() && $d->conduct_record_id === null)->groupBy('kind'),
            'conduct' => $documents->filter(fn (EmployeeDocument $d) => $d->conduct_record_id !== null)->values(),
        ];
    }

    /** How many of the 13 have a current file (the file's «الوثائق المرفقة»). */
    public static function attachedCount(string $employeeId): int
    {
        return count(DocumentQueries::currentKinds($employeeId));
    }
}
