<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Documents\Support\DocumentQueries;
use App\Modules\Employees\Enums\ChangeSetStatus;
use App\Modules\Employees\Enums\Cycle;
use App\Modules\Identity\Models\StaffUser;
use App\Modules\Settings\Models\WorkSettings;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;

/** `/badges`: the sidebar's counts (D-046), each section's only for those who see it. A fixed number of queries. */
final class Badges
{
    /**
     * @return array{
     *     notifications: array{unread: int},
     *     reviews: array{employee_changes: int, record_changes: int, documents: int, imports: int, total: int}|null,
     *     identity: array{to_review: int, to_lock: int}|null,
     *     mine: array{pending: int},
     *     employees: array{contracts_ending_soon: int, monthly_overdue: int}|null
     * }
     */
    public static function for(StaffUser $user): array
    {
        $pending = ChangeSetStatus::Pending->value;
        $today = BusinessDay::today();

        // What waits for this reviewer: everybody's but their own (identity changes for the identity reviewer only).
        $employeeChanges = fn () => (int) DB::table('employee_change_sets')->where('status', $pending)->where('submitted_by', '!=', $user->id)
            ->when(! $user->hasPermission('identity.review'), fn ($q) => $q->where('needs_identity_review', false))->count();
        $recordChanges = fn () => (int) DB::table('record_change_sets')->where('status', $pending)->where('submitted_by', '!=', $user->id)->count();
        $reviews = $user->hasPermission('entries.review')
            ? ['employee_changes' => $e = $employeeChanges(), 'record_changes' => $r = $recordChanges(),
                'documents' => $d = DocumentQueries::pendingFor($user->id),
                // Imports waiting: a reviewer may approve their own (it applies at once, D-053).
                'imports' => $i = (int) DB::table('imports')->where('status', 'pending')->count(),
                'total' => $e + $r + $d + $i]
            : null;

        $identity = $user->hasPermission('identity.review') ? [
            /** Changes of an identity waiting for the administrator. */
            'to_review' => (int) DB::table('employee_change_sets')->where('status', $pending)->where('needs_identity_review', true)->count(),
            /** Approved files whose identity is not locked yet. */
            'to_lock' => (int) DB::table('employees')->where('file_status', 'active')->whereNull('identity_locked_at')->count(),
        ] : null;

        $mine = (int) DB::table('employee_change_sets')->where('status', $pending)->where('submitted_by', $user->id)->count()
            + (int) DB::table('record_change_sets')->where('status', $pending)->where('submitted_by', $user->id)->count()
            + DocumentQueries::pendingBy($user->id)
            + (int) DB::table('imports')->where('status', 'pending')->where('uploaded_by', $user->id)->count();

        // The same reading as the register's `overdue_cycle=monthly` filter.
        $since = $today->subDays((int) WorkSettings::current()->getAttribute(Cycle::Monthly->windowColumn()))->format('Y-m-d');
        $employees = $user->hasPermission('employees.view') ? [
            'contracts_ending_soon' => (int) DB::table('employees')->where('file_status', 'active')
                ->whereBetween('contract_ends_on', [$today->format('Y-m-d'), $today->addDays(30)->format('Y-m-d')])->count(),
            'monthly_overdue' => (int) DB::table('employees')->where('file_status', 'active')
                ->whereNotExists(fn ($r) => $r->from('employee_reviews')->whereColumn('employee_reviews.employee_id', 'employees.id')
                    ->where('employee_reviews.cycle', Cycle::Monthly->value)->where('employee_reviews.reviewed_on', '>=', $since))
                ->count(),
        ] : null;

        return [
            'notifications' => ['unread' => (int) $user->unreadNotifications()->count()],
            'reviews' => $reviews,
            'identity' => $identity,
            'mine' => ['pending' => $mine],
            'employees' => $employees,
        ];
    }
}
