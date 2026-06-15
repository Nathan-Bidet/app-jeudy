<?php

namespace App\Services\Hours;

use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ApprovedLeaveDayService
{
    /**
     * @return array<string, array{date:string,status:string,message:string,is_partial:bool,is_full_day:bool,morning:bool,afternoon:bool}>
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
     * @return array<int, array<string, array{date:string,status:string,message:string,is_partial:bool,is_full_day:bool,morning:bool,afternoon:bool}>>
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

            $leaveStart = CarbonImmutable::parse($leave->start_at, $tz);
            $leaveEnd = CarbonImmutable::parse($leave->end_at ?: $leave->start_at, $tz);

            if ($leaveEnd->lt($leaveStart)) {
                $leaveEnd = $leaveStart;
            }

            $rangeStart = $leaveStart->startOfDay()->greaterThan($start) ? $leaveStart->startOfDay() : $start->startOfDay();
            $rangeEnd = $leaveEnd->startOfDay()->lessThan($end) ? $leaveEnd->startOfDay() : $end->startOfDay();

            $cursor = $rangeStart;
            while ($cursor->lte($rangeEnd)) {
                $iso = $cursor->toDateString();
                $morningStart = $cursor->setTime(8, 0);
                $morningEnd = $cursor->setTime(12, 0);
                $afternoonStart = $cursor->setTime(14, 0);
                $afternoonEnd = $cursor->setTime(18, 0);
                $coversMorning = $leaveStart->lte($morningStart) && $leaveEnd->gte($morningEnd);
                $coversAfternoon = $leaveStart->lte($afternoonStart) && $leaveEnd->gte($afternoonEnd);

                if (! $coversMorning && ! $coversAfternoon) {
                    $cursor = $cursor->addDay();
                    continue;
                }

                $existing = $result[$userId][$iso] ?? null;
                $coversMorning = $coversMorning || (bool) ($existing['morning'] ?? false);
                $coversAfternoon = $coversAfternoon || (bool) ($existing['afternoon'] ?? false);
                $isFullDay = $coversMorning && $coversAfternoon;

                $result[$userId][$iso] = [
                    'date' => $iso,
                    'status' => 'En congé',
                    'message' => match (true) {
                        $isFullDay => 'Congé validé — aucune heure à saisir',
                        $coversMorning => 'Vous êtes en congé ce matin.',
                        default => 'Vous êtes en congé cet après-midi.',
                    },
                    'is_partial' => ! $isFullDay,
                    'is_full_day' => $isFullDay,
                    'morning' => $coversMorning,
                    'afternoon' => $coversAfternoon,
                ];

                $cursor = $cursor->addDay();
            }
        }

        return $result;
    }
}
