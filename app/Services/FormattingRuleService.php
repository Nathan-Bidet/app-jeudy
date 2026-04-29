<?php

namespace App\Services;

use App\Models\FormattingRule;
use Illuminate\Support\Collection;

class FormattingRuleService
{
    public const TARGET_A_PREVOIR = 'a_prevoir';

    public const TARGET_LDT = 'ldt';

    /**
     * @return Collection<int, FormattingRule>
     */
    public function getActiveRulesForTarget(string $targetModule): Collection
    {
        $target = $this->normalizeTarget($targetModule);

        if ($target === null) {
            return collect();
        }

        return FormattingRule::query()
            ->active()
            ->forTarget($target)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{matchedRuleId:?int,textColor:?string,bgColor:?string,ruleName:?string,matchedPriority:?int,matchedPattern:?string}
     */
    public function resolveFormatting(string $taskText, ?string $commentText, string $targetModule): array
    {
        return $this->resolveFormattingWithRules(
            taskText: $taskText,
            commentText: $commentText,
            targetModule: $targetModule,
            rules: $this->getActiveRulesForTarget($targetModule),
        );
    }

    /**
     * @param  iterable<int,FormattingRule>  $rules
     * @return array{matchedRuleId:?int,textColor:?string,bgColor:?string,ruleName:?string,matchedPriority:?int,matchedPattern:?string}
     */
    public function resolveFormattingWithRules(string $taskText, ?string $commentText, string $targetModule, iterable $rules): array
    {
        $target = $this->normalizeTarget($targetModule);

        if ($target === null) {
            return $this->emptyResult();
        }

        $task = trim($taskText);
        $comment = trim((string) $commentText);
        $matched = null;
        $textColor = null;
        $bgColor = null;

        foreach ($rules as $rule) {
            if (! $rule instanceof FormattingRule || ! $this->isRuleApplicableToTarget($rule, $target)) {
                continue;
            }

            if (! $this->ruleMatchesInputs($rule, $task, $comment)) {
                continue;
            }

            if ($matched === null) {
                $matched = [
                    'matchedRuleId' => $rule->id,
                    'ruleName' => $rule->name,
                    'matchedPriority' => (int) $rule->priority,
                    'matchedPattern' => $rule->pattern,
                ];
            }

            if ($textColor === null) {
                $textColor = $this->normalizeColor($rule->text_color);
            }

            if ($bgColor === null) {
                $bgColor = $this->normalizeColor($rule->bg_color);
            }

            if ($textColor !== null && $bgColor !== null) {
                break;
            }
        }

        if ($matched === null) {
            return $this->emptyResult();
        }

        return [
            'matchedRuleId' => $matched['matchedRuleId'],
            'textColor' => $textColor,
            'bgColor' => $bgColor,
            'ruleName' => $matched['ruleName'],
            'matchedPriority' => $matched['matchedPriority'],
            'matchedPattern' => $matched['matchedPattern'],
        ];
    }

    private function ruleMatchesInputs(FormattingRule $rule, string $task, string $comment): bool
    {
        $haystacks = match ($rule->scope) {
            'task' => [$task],
            'comment' => [$comment],
            default => [$task, $comment],
        };

        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && $this->matchesRule($rule, $haystack)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTarget(string $targetModule): ?string
    {
        $target = trim(mb_strtolower($targetModule));

        return in_array($target, [self::TARGET_A_PREVOIR, self::TARGET_LDT], true)
            ? $target
            : null;
    }

    private function isRuleApplicableToTarget(FormattingRule $rule, string $targetModule): bool
    {
        return match ($targetModule) {
            self::TARGET_A_PREVOIR => (bool) $rule->applies_to_a_prevoir,
            self::TARGET_LDT => (bool) $rule->applies_to_ldt,
            default => false,
        };
    }

    private function matchesRule(FormattingRule $rule, string $haystack): bool
    {
        $pattern = trim((string) $rule->pattern);

        if ($pattern === '') {
            return false;
        }

        return match ($rule->match_type) {
            'contains' => str_contains(mb_strtolower($haystack), mb_strtolower($pattern)),
            'starts_with' => str_starts_with(mb_strtolower($haystack), mb_strtolower($pattern)),
            'regex' => @preg_match($pattern, $haystack) === 1,
            default => false,
        };
    }

    private function normalizeColor(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array{matchedRuleId:?int,textColor:?string,bgColor:?string,ruleName:?string,matchedPriority:?int,matchedPattern:?string}
     */
    private function emptyResult(): array
    {
        return [
            'matchedRuleId' => null,
            'textColor' => null,
            'bgColor' => null,
            'ruleName' => null,
            'matchedPriority' => null,
            'matchedPattern' => null,
        ];
    }
}
