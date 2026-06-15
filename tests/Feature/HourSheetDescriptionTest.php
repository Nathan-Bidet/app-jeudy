<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\HourSheet;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
});

function hourSheetDescriptionUser(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole(Role::findOrCreate('admin', 'web'));

    return $user;
}

it('requires a description for a worked day', function () {
    $user = hourSheetDescriptionUser();

    $response = $this
        ->actingAs($user)
        ->post(route('hours.store'), [
            'work_date' => '2026-06-15',
            'morning_start' => '08:00',
            'morning_end' => '12:00',
            'afternoon_start' => '14:00',
            'afternoon_end' => '18:00',
            'description' => '   ',
        ]);

    $response->assertSessionHasErrors('description');
    expect(HourSheet::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('allows a non worked day without a description', function () {
    $user = hourSheetDescriptionUser();

    $response = $this
        ->actingAs($user)
        ->post(route('hours.store'), [
            'work_date' => '2026-06-15',
            'is_not_worked' => true,
            'description' => '',
        ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hour_sheets', [
        'user_id' => $user->id,
        'work_date' => '2026-06-15',
        'description' => null,
        'is_not_worked' => true,
    ]);
});

it('stores a worked day description', function () {
    $user = hourSheetDescriptionUser();

    $response = $this
        ->actingAs($user)
        ->post(route('hours.store'), [
            'work_date' => '2026-06-15',
            'morning_start' => '08:00',
            'morning_end' => '12:00',
            'afternoon_start' => '14:00',
            'afternoon_end' => '18:00',
            'description' => '  Entretien et préparation du matériel  ',
        ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseHas('hour_sheets', [
        'user_id' => $user->id,
        'work_date' => '2026-06-15',
        'description' => 'Entretien et préparation du matériel',
    ]);
});

it('does not allow an existing worked day description to be emptied', function () {
    $user = hourSheetDescriptionUser();
    HourSheet::query()->create([
        'user_id' => $user->id,
        'work_date' => '2026-06-15',
        'morning_start' => '08:00',
        'morning_end' => '12:00',
        'afternoon_start' => '14:00',
        'afternoon_end' => '18:00',
        'total_minutes' => 480,
        'description' => 'Travaux initiaux',
    ]);

    $response = $this
        ->actingAs($user)
        ->post(route('hours.store'), [
            'work_date' => '2026-06-15',
            'morning_start' => '08:00',
            'morning_end' => '12:00',
            'afternoon_start' => '14:00',
            'afternoon_end' => '18:00',
            'description' => '',
        ]);

    $response->assertSessionHasErrors('description');
    expect(HourSheet::query()->firstOrFail()->description)->toBe('Travaux initiaux');
});
