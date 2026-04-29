<?php

namespace App\Services\Hours;

use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ApprovedLeaveDayService
{
    /**
     * @return array<string, array{date:string,status:string,message:string,is_partial:bool}>
     */
    public function approvedLeaveMapForUser(int $userId, string $startDate, string $endDate): array
    {
        return $this->approvedLeaveMapForUsers([$userId], $startDate, $endDate)[$userId] ?? [];
    }

    public function isUserOnApprovedLeaveForDate(int $userId, string $date): bool
    {
        return $this->approvedLeaveMapForUser($userId, $date, $date) !== [];
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, array{date:string,status:string,message:string,is_partial:bool}>>
     */
    public function approvedLeaveMapForUsers(array $userIds, string $startDate, string $endDate): array
    {
        $userIds = array_values(array_unique(array_map(fn ($id) => (int) $id, $userIds)));
        if ($userIds === []) {
            return [];
        }

        $tz = config('app.timezone', 'Europe/Paris');
        $start = CarbonImmutable::parse($startDate, $tz)->startOfDay();
        $end = CarbonImmutable::parse($endDate, $tz)->endOfDay();

        $rows = LeaveRequest::query()
            ->where(function ($query) use ($userIds): void {
                $query
                    ->whereIn('target_user_id', $userIds)
                    ->orWhere(function ($subQuery) use ($userIds): void {
                        $subQuery
                            ->whereNull('target_user_id')
                            ->whereIn('requester_user_id', $userIds);
                    });
            })
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->where(function ($query) use ($start, $end): void {
                $query
                    ->whereDate('start_at', '<=', $end->toDateString())
                    ->whereDate(DB::raw('COALESCE(end_at, start_at)'), '>=', $start->toDateString());
            })
            ->get([
                'id',
                'target_user_id',
                'requester_user_id',
                'start_at',
                'end_at',
                'is_all_day',
                'start_portion',
                'end_portion',
            ]);

        $result = [];

        foreach ($rows as $leave) {
            $userId = (int) ($leave->target_user_id ?: $leave->requester_user_id);
            if (! in_array($userId, $userIds, true)) {
                continue;
            }

            $leaveStart = CarbonImmutable::parse($leave->start_at, $tz)->startOfDay();
            $leaveEnd = CarbonImmutable::parse($leave->end_at ?: $leave->start_at, $tz)->startOfDay();

            if ($leaveEnd->lt($leaveStart)) {
                $leaveEnd = $leaveStart;
            }

            $rangeStart = $leaveStart->greaterThan($start) ? $leaveStart : $start;
            $rangeEnd = $leaveEnd->lessThan($end) ? $leaveEnd : $end;

            $cursor = $rangeStart;
            while ($cursor->lte($rangeEnd)) {
                $iso = $cursor->toDateString();
                $isPartial = ! (bool) $leave->is_all_day
                    || ($leaveStart->equalTo($leaveEnd)
                        && (string) ($leave->start_portion ?? 'full_day') !== 'full_day'
                        && (string) ($leave->end_portion ?? 'full_day') !== 'full_day');

                $result[$userId][$iso] = [
                    'date' => $iso,
                    'status' => 'En congé',
                    'message' => $isPartial
                        ? 'Congé validé (demi-journée) — aucune heure à saisir'
                        : 'Congé validé — aucune heure à saisir',
                    'is_partial' => $isPartial,
                ];

                $cursor = $cursor->addDay();
            }
        }

        return $result;
    }
}
