<?php

namespace App\Modules\Records\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Support\ValueLabels;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Records\Enums\RecordAction;
use App\Modules\Records\Enums\RecordType;
use App\Modules\Records\Http\Resources\RecordResource;
use App\Modules\Records\Models\EmployeeRecord;
use App\Modules\Records\Models\RecordChangeItem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Readies a page of records for RecordResource: the list labels, the employees' units and titles, and the
 * changes waiting for each record, in a fixed number of queries whatever the page's size (D-031).
 */
final class RecordPage
{
    /** @param iterable<EmployeeRecord> $records */
    public static function prepare(RecordType $type, iterable $records): void
    {
        $records = new EloquentCollection(collect($records)->all());
        // Who entered and approved each, in two queries (a waiting addition has no approver).
        $records->loadMissing(['creator', 'approver']);

        RecordResource::$labels = (new RecordLabels)->prime($type, $records->map(fn (EmployeeRecord $r) => RecordValues::of($r)));

        $employeeLabels = new ValueLabels;

        foreach ($records as $record) {
            $employeeLabels->add('job_title_id', $record->employee?->job_title_id);
        }

        RecordResource::$employeeLabels = $employeeLabels;
        RecordResource::$pending = self::pending($records->pluck('id')->all());
        RecordComputed::$threshold = $type === RecordType::Attendance ? RecordComputed::thresholdFromSettings() : null;
    }

    /**
     * @param  list<string>  $recordIds
     * @return array<string, list<array<string, mixed>>>
     */
    public static function pending(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $items = RecordChangeItem::query()
            ->whereIn('record_id', $recordIds)
            ->where('action', '!=', RecordAction::Create->value)
            ->whereHas('changeSet', fn ($q) => $q->where('status', ChangeSetStatus::Pending))
            ->with('changeSet:id,submitted_by,submitted_at')
            ->orderBy('id')
            ->get();

        $authors = StaffUser::query()->whereIn('id', $items->pluck('changeSet.submitted_by')->unique()->all())->get()->keyBy('id');
        $pending = [];

        foreach ($items as $item) {
            $author = $authors[$item->changeSet->submitted_by] ?? null;
            $pending[$item->record_id][] = [
                'change_set_id' => $item->change_set_id,
                'action' => $item->action->value,
                /** The proposed values of the fields it changes (none for a removal). */
                'new_values' => $item->new_values,
                'submitted_by' => ['id' => $author?->id, 'full_name' => $author?->fullName()],
                'submitted_at' => $item->changeSet->submitted_at->toIso8601String(),
            ];
        }

        return $pending;
    }
}
