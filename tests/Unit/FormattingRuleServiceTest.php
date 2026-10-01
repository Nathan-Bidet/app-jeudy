<?php

use App\Models\FormattingRule;
use App\Services\FormattingRuleService;

it('merges text and background colors from multiple matching rules in order', function () {
    $service = new FormattingRuleService();

    $result = $service->resolveFormattingWithRules(
        taskText: 'Client urgent - livraison',
        commentText: null,
        targetModule: FormattingRuleService::TARGET_A_PREVOIR,
        rules: [
            makeFormattingRule([
                'id' => 10,
                'name' => 'Urgent texte',
                'priority' => 1,
                'pattern' => 'urgent',
                'text_color' => '#111111',
                'bg_color' => null,
            ]),
            makeFormattingRule([
                'id' => 11,
                'name' => 'Urgent fond',
                'priority' => 2,
                'pattern' => 'urgent',
                'text_color' => null,
                'bg_color' => '#FDE68A',
            ]),
        ],
    );

    expect($result)->toMatchArray([
        'matchedRuleId' => 10,
        'ruleName' => 'Urgent texte',
        'matchedPriority' => 1,
        'matchedPattern' => 'urgent',
        'textColor' => '#111111',
        'bgColor' => '#FDE68A',
    ]);
});

it('keeps first matching rule metadata even when colors come from later rules', function () {
    $service = new FormattingRuleService();

    $result = $service->resolveFormattingWithRules(
        taskText: 'Alerte',
        commentText: null,
        targetModule: FormattingRuleService::TARGET_A_PREVOIR,
        rules: [
            makeFormattingRule([
                'id' => 20,
                'name' => 'Alerte principale',
                'priority' => 1,
                'pattern' => 'alerte',
                'text_color' => null,
                'bg_color' => null,
            ]),
            makeFormattingRule([
                'id' => 21,
                'name' => 'Alerte fallback',
                'priority' => 2,
                'pattern' => 'alerte',
                'text_color' => '#EF4444',
                'bg_color' => '#FEF2F2',
            ]),
        ],
    );

    expect($result)->toMatchArray([
        'matchedRuleId' => 20,
        'ruleName' => 'Alerte principale',
        'matchedPriority' => 1,
        'textColor' => '#EF4444',
        'bgColor' => '#FEF2F2',
    ]);
});

it('uses the first non-empty value per color property among matching rules', function () {
    $service = new FormattingRuleService();

    $result = $service->resolveFormattingWithRules(
        taskText: 'Risque',
        commentText: null,
        targetModule: FormattingRuleService::TARGET_LDT,
        rules: [
            makeFormattingRule([
                'id' => 30,
                'name' => 'Base risque',
                'priority' => 1,
                'pattern' => 'risque',
                'text_color' => '',
                'bg_color' => '',
                'applies_to_a_prevoir' => false,
                'applies_to_ldt' => true,
            ]),
            makeFormattingRule([
                'id' => 31,
                'name' => 'Texte risque',
                'priority' => 2,
                'pattern' => 'risque',
                'text_color' => '#B91C1C',
                'bg_color' => '',
                'applies_to_a_prevoir' => false,
                'applies_to_ldt' => true,
            ]),
            makeFormattingRule([
                'id' => 32,
                'name' => 'Fond risque',
                'priority' => 3,
                'pattern' => 'risque',
                'text_color' => '#2563EB',
                'bg_color' => '#DBEAFE',
                'applies_to_a_prevoir' => false,
                'applies_to_ldt' => true,
            ]),
        ],
    );

    expect($result)->toMatchArray([
        'matchedRuleId' => 30,
        'ruleName' => 'Base risque',
        'matchedPriority' => 1,
        'textColor' => '#B91C1C',
        'bgColor' => '#DBEAFE',
    ]);
});

function makeFormattingRule(array $overrides = []): FormattingRule
{
    $defaults = [
        'name' => 'Regle',
        'scope' => 'task',
        'match_type' => 'contains',
        'pattern' => 'test',
        'text_color' => null,
        'bg_color' => null,
        'priority' => 100,
        'is_active' => true,
        'applies_to_a_prevoir' => true,
        'applies_to_ldt' => true,
    ];

    $attributes = array_merge($defaults, $overrides);
    $rule = new FormattingRule($attributes);
    $rule->id = $attributes['id'] ?? null;

    return $rule;
}

it('merges the phone color from matching rules like text and background colors', function () {
    $service = new FormattingRuleService();

    $result = $service->resolveFormattingWithRules(
        taskText: 'Client urgent',
        commentText: null,
        targetModule: FormattingRuleService::TARGET_A_PREVOIR,
        rules: [
            makeFormattingRule(['id' => 30, 'priority' => 1, 'pattern' => 'urgent', 'text_color' => '#111111']),
            makeFormattingRule(['id' => 31, 'priority' => 2, 'pattern' => 'urgent', 'phone_color' => '#FACC15']),
            makeFormattingRule(['id' => 32, 'priority' => 3, 'pattern' => 'urgent', 'phone_color' => '#000000']),
        ],
    );

    expect($result['phoneColor'])->toBe('#FACC15')
        ->and($result['textColor'])->toBe('#111111');
});

it('returns a null phone color for legacy rules and when nothing matches', function () {
    $service = new FormattingRuleService();

    $legacy = $service->resolveFormattingWithRules(
        taskText: 'Client urgent',
        commentText: null,
        targetModule: FormattingRuleService::TARGET_LDT,
        rules: [makeFormattingRule(['id' => 40, 'pattern' => 'urgent', 'text_color' => '#111111', 'bg_color' => '#FDE68A'])],
    );
    $none = $service->resolveFormattingWithRules('Autre', null, FormattingRuleService::TARGET_LDT, [
        makeFormattingRule(['id' => 41, 'pattern' => 'urgent', 'phone_color' => '#FACC15']),
    ]);

    expect($legacy['phoneColor'])->toBeNull()
        ->and($legacy['matchedRuleId'])->toBe(40)
        ->and($none['phoneColor'])->toBeNull()
        ->and($none['matchedRuleId'])->toBeNull();
});
