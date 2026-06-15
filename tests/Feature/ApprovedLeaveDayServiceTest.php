<?php

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Hours\ApprovedLeaveDayService;

it('classifies approved leave coverage from its hours', function (
    string $startAt,
    string $endAt,
    bool $morning,
    bool $afternoon,
    bool $fullDay,
    string $message,
) {
    $user = User::factory()->create();

    LeaveRequest::query()->create([
        'requester_user_id' => $user->id,
        'target_user_id' => $user->id,
        'start_at' => $startAt,
        'end_at' => $endAt,
        'is_all_day' => $fullDay,
        'status' => LeaveRequest::STATUS_APPROVED,
    ]);

    $leave = app(ApprovedLeaveDayService::class)
        ->approvedLeaveMapForUser((int) $user->id, '2026-06-15', '2026-06-15')['2026-06-15'];

    expect($leave)
        ->morning->toBe($morning)
        ->afternoon->toBe($afternoon)
        ->is_full_day->toBe($fullDay)
        ->message->toBe($message);
})->with([
    'morning' => [
        '2026-06-15 08:00:00',
        '2026-06-15 12:00:00',
        true,
        false,
        false,
        'Vous êtes en congé ce matin.',
    ],
    'afternoon' => [
        '2026-06-15 14:00:00',
        '2026-06-15 18:00:00',
        false,
        true,
        false,
        'Vous êtes en congé cet après-midi.',
    ],
    'full day' => [
        '2026-06-15 00:00:00',
        '2026-06-15 18:00:00',
        true,
        true,
        true,
        'Congé validé — aucune heure à saisir',
    ],
]);

it('combines separate morning and afternoon leaves into a full day', function () {
    $user = User::factory()->create();

    foreach ([
        ['2026-06-15 08:00:00', '2026-06-15 12:00:00'],
        ['2026-06-15 14:00:00', '2026-06-15 18:00:00'],
    ] as [$startAt, $endAt]) {
        LeaveRequest::query()->create([
            'requester_user_id' => $user->id,
            'target_user_id' => $user->id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_all_day' => false,
            'status' => LeaveRequest::STATUS_APPROVED,
        ]);
    }

    $leave = app(ApprovedLeaveDayService::class)
        ->approvedLeaveMapForUser((int) $user->id, '2026-06-15', '2026-06-15')['2026-06-15'];

    expect($leave)
        ->morning->toBeTrue()
        ->afternoon->toBeTrue()
        ->is_full_day->toBeTrue();
});
