<?php

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\AprevoirTask;
use App\Models\EngraisTask;
use App\Models\FormattingRule;
use App\Models\LdtEntry;
use App\Models\Sector;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureTwoFactorIsVerified::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function formattingUser(array $abilities): User
{
    $sector = Sector::query()->create([
        'name' => fake()->unique()->company(),
        'slug' => fake()->unique()->slug(),
    ]);

    $role = Role::findOrCreate('formatting-test-'.fake()->unique()->word(), 'web');

    foreach ($abilities as $ability) {
        $role->givePermissionTo(Permission::findOrCreate($ability, 'web'));
    }

    $user = User::factory()->create([
        'sector_id' => $sector->id,
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function formattingPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Urgent vert',
        'scope' => 'both',
        'match_type' => 'contains',
        'pattern' => 'urgent',
        'text_color' => '#111111',
        'bg_color' => '#166534',
        'phone_color' => '#FACC15',
        'is_active' => true,
        'applies_to_a_prevoir' => true,
        'applies_to_ldt' => true,
        'description' => null,
    ], $overrides);
}

function makeRuleRow(array $overrides = []): FormattingRule
{
    return FormattingRule::query()->create(array_merge([
        'name' => 'Regle',
        'scope' => 'both',
        'match_type' => 'contains',
        'pattern' => 'urgent',
        'priority' => 1,
        'is_active' => true,
        'applies_to_a_prevoir' => true,
        'applies_to_ldt' => true,
    ], $overrides));
}

it('stores the phone color with a new rule and reloads it in the index', function (): void {
    $user = formattingUser(['task.formatting.view', 'task.formatting.manage']);

    $this->actingAs($user)
        ->post(route('task.formatting.store'), formattingPayload())
        ->assertSessionHasNoErrors();

    $rule = FormattingRule::query()->sole();
    expect($rule->phone_color)->toBe('#FACC15');

    $this->actingAs($user)
        ->get(route('task.formatting.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('TaskFormatting/Index')
            ->where('rules.0.phone_color', '#FACC15')
        );
});

it('updates and clears the phone color of an existing rule without touching other properties', function (): void {
    $user = formattingUser(['task.formatting.view', 'task.formatting.manage']);
    $rule = makeRuleRow([
        'text_color' => '#111111',
        'bg_color' => '#166534',
        'priority' => 7,
        'is_active' => false,
    ]);

    $this->actingAs($user)
        ->put(route('task.formatting.update', $rule), formattingPayload(['is_active' => false, 'phone_color' => '#fff']))
        ->assertSessionHasNoErrors();

    expect($rule->refresh()->phone_color)->toBe('#fff')
        ->and($rule->priority)->toBe(7)
        ->and($rule->is_active)->toBeFalse()
        ->and($rule->bg_color)->toBe('#166534');

    $this->actingAs($user)
        ->put(route('task.formatting.update', $rule), formattingPayload(['is_active' => false, 'phone_color' => '']))
        ->assertSessionHasNoErrors();

    expect($rule->refresh()->phone_color)->toBeNull();
});

it('rejects an invalid phone color', function (string $value): void {
    $user = formattingUser(['task.formatting.view', 'task.formatting.manage']);

    $this->actingAs($user)
        ->post(route('task.formatting.store'), formattingPayload(['phone_color' => $value]))
        ->assertSessionHasErrors('phone_color');

    expect(FormattingRule::query()->count())->toBe(0);
})->with(['red', '#12', '#GGGGGG', 'rgb(0,0,0)', 'url(x)']);

it('keeps legacy rules without phone color working', function (): void {
    $user = formattingUser(['task.formatting.view']);
    makeRuleRow(['text_color' => '#111111', 'bg_color' => '#FDE68A']);

    $this->actingAs($user)
        ->get(route('task.formatting.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('rules.0.phone_color', null)
            ->where('rules.0.text_color', '#111111')
        );
});

it('does not let users without manage permission change the phone color', function (): void {
    $user = formattingUser(['task.formatting.view']);
    $rule = makeRuleRow(['phone_color' => '#FACC15']);

    $this->actingAs($user)
        ->post(route('task.formatting.store'), formattingPayload())
        ->assertRedirect();
    $this->actingAs($user)
        ->put(route('task.formatting.update', $rule), formattingPayload(['phone_color' => '#000000']))
        ->assertRedirect();

    expect(FormattingRule::query()->count())->toBe(1)
        ->and($rule->refresh()->phone_color)->toBe('#FACC15');
});

it('exposes the phone color in the task style on À prévoir', function (): void {
    $user = formattingUser(['a_prevoir.view']);
    makeRuleRow(['text_color' => '#111111', 'bg_color' => '#166534', 'phone_color' => '#FACC15']);
    AprevoirTask::query()->create([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-12',
        'task' => 'Livraison urgent 06 12 34 56 78',
        'is_direct' => true,
        'is_boursagri' => false,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('a_prevoir.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.tasks.0.style.phone_color', '#FACC15')
            ->where('groups.0.tasks.0.style.bg_color', '#166534')
        );
});

it('exposes the phone color in the task style on Engrais/Tourteaux', function (): void {
    $user = formattingUser(['engrais.view']);
    makeRuleRow(['phone_color' => '#FACC15']);
    EngraisTask::query()->create([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-12',
        'task' => 'Livraison urgent 06 12 34 56 78',
        'is_direct' => true,
        'is_boursagri' => false,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('engrais.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.tasks.0.style.phone_color', '#FACC15')
        );
});

it('leaves the phone color empty when the matching rule has none', function (): void {
    $user = formattingUser(['a_prevoir.view']);
    makeRuleRow(['text_color' => '#111111']);
    AprevoirTask::query()->create([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-12',
        'task' => 'Livraison urgent',
        'is_direct' => true,
        'is_boursagri' => false,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('a_prevoir.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.tasks.0.style.phone_color', null)
            ->where('groups.0.tasks.0.style.text_color', '#111111')
        );
});

it('exposes the phone color in the task style on the work book (LDT)', function (): void {
    $user = formattingUser(['ldt.view', 'ldt.view.all_assignees']);
    makeRuleRow(['bg_color' => '#166534', 'phone_color' => '#FACC15']);
    $task = AprevoirTask::query()->create([
        'date' => '2026-06-12',
        'fin_date' => '2026-06-12',
        'task' => 'Livraison urgent 06 12 34 56 78',
        'is_direct' => true,
        'is_boursagri' => false,
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);
    LdtEntry::query()->create([
        'date' => '2026-06-12',
        'assignee_type' => 'free',
        'assignee_id' => 0,
        'assignee_label' => 'Sans affectation',
        'tasks_text' => $task->task,
        'source_task_ids' => [$task->id],
        'updated_from_source_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('ldt.index', ['date_from' => '2026-06-01', 'date_to' => '2026-06-30']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('groups.0.entries.0.task_items.0.style.phone_color', '#FACC15')
            ->where('groups.0.entries.0.task_items.0.style.bg_color', '#166534')
        );
});
