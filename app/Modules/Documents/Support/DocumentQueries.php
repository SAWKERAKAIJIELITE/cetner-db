<?php

namespace App\Modules\Documents\Support;

use App\Modules\Employees\Enums\ChangeSetStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Reads of the documents other modules need, without their models (D-049). */
final class DocumentQueries
{
    /** @return list<string> the file kinds of which the employee has a current approved file */
    public static function currentKinds(string $employeeId): array
    {
        return DB::table('employee_documents')
            ->where('employee_id', $employeeId)
            ->whereNull('conduct_record_id')
            ->where('status', ChangeSetStatus::Approved->value)
            ->whereNull('superseded_at')
            ->whereNull('removed_at')
            ->pluck('kind')->all();
    }

    /**
     * The conduct records' documents: the current one and how many wait for review, for a page of records.
     *
     * @param  list<string>  $recordIds
     * @return array<string, array{current: array{id: string, original_name: string, mime: string, size_bytes: int, uploaded_at: string}|null, pending: int}>
     */
    public static function ofConductRecords(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $rows = DB::table('employee_documents')
            ->whereIn('conduct_record_id', $recordIds)
            ->where(fn ($q) => $q->where('status', ChangeSetStatus::Pending->value)
                ->orWhere(fn ($c) => $c->where('status', ChangeSetStatus::Approved->value)->whereNull('superseded_at')->whereNull('removed_at')))
            ->get(['id', 'conduct_record_id', 'status', 'original_name', 'mime', 'size_bytes', 'uploaded_at']);

        $out = [];

        foreach ($rows as $row) {
            $out[$row->conduct_record_id] ??= ['current' => null, 'pending' => 0];

            if ($row->status === ChangeSetStatus::Pending->value) {
                $out[$row->conduct_record_id]['pending']++;
            } else {
                $out[$row->conduct_record_id]['current'] = [
                    'id' => $row->id,
                    'original_name' => $row->original_name,
                    'mime' => $row->mime,
                    'size_bytes' => (int) $row->size_bytes,
                    'uploaded_at' => Carbon::parse($row->uploaded_at)->toIso8601String(),
                ];
            }
        }

        return $out;
    }

    /** Uploads waiting for a reviewer: everybody's but the reviewer's own. */
    public static function pendingFor(string $reviewerId): int
    {
        return (int) DB::table('employee_documents')->where('status', ChangeSetStatus::Pending->value)->where('uploaded_by', '!=', $reviewerId)->count();
    }

    public static function pendingBy(string $uploaderId): int
    {
        return (int) DB::table('employee_documents')->where('status', ChangeSetStatus::Pending->value)->where('uploaded_by', $uploaderId)->count();
    }
}
