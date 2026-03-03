<?php

namespace App\Services\Rules;

use App\Enums\AmountType;
use App\Enums\RuleStatus;
use App\Enums\TriggerType;
use App\Models\ConnectedAccount;
use App\Models\Rule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class RuleService
{
    public function __construct(
        private readonly RuleParserService $parser
    ) {}

    /**
     * Create a rule from validated structured data.
     */
    public function create(User $user, array $data): Rule
    {
        $actions = $this->normaliseActions($data['actions']);

        $rule = Rule::create([
            'id'                   => Str::uuid(),
            'user_id'              => $user->id,
            'connected_account_id' => $data['connected_account_id'],
            'name'                 => $data['name'],
            'rule_text'            => $data['rule_text'] ?? null,
            'description'          => $data['description'] ?? null,
            'status'               => RuleStatus::Active,
            'trigger_type'         => $data['trigger_type'],
            'trigger_config'       => $data['trigger_config'],
            'total_amount_type'    => $data['total_amount_type'],
            'total_amount'         => $data['total_amount'] ?? null,
            'actions'              => $actions,
            'is_ai_suggested'      => $data['is_ai_suggested'] ?? false,
            'next_trigger_at'      => $this->calculateNextTrigger($data['trigger_type'], $data['trigger_config']),
        ]);

        return $rule;
    }

    /**
     * Parse plain English into a structured rule, then create it.
     */
    public function createFromText(User $user, string $ruleText, string $accountId): array
    {
        $accounts = $user->connectedAccounts()
            ->active()
            ->get()
            ->map(fn($a) => [
                'id'          => $a->id,
                'institution' => $a->institution,
                'balance'     => $a->balance_naira,
                'is_primary'  => $a->is_primary,
            ])
            ->toArray();

        // Parse the rule text using Claude
        $parsed = $this->parser->parse($ruleText, $user->id, $accounts);

        // Override account ID with the explicitly provided one
        $parsed['connected_account_id'] = $accountId;
        $parsed['is_ai_suggested']      = true;

        return [
            'parsed'     => $parsed,
            'confidence' => $parsed['confidence'] ?? 1.0,
            'ambiguities'=> $parsed['ambiguities'] ?? [],
        ];
    }

    /**
     * Update a rule.
     */
    public function update(Rule $rule, array $data): Rule
    {
        $updateData = array_filter([
            'name'                 => $data['name'] ?? null,
            'trigger_config'       => $data['trigger_config'] ?? null,
            'total_amount_type'    => $data['total_amount_type'] ?? null,
            'total_amount'         => $data['total_amount'] ?? null,
            'actions'              => isset($data['actions'])
                ? $this->normaliseActions($data['actions'])
                : null,
        ], fn($v) => $v !== null);

        if (! empty($updateData)) {
            $rule->update($updateData);
        }

        // Recalculate next trigger if trigger config changed
        if (isset($data['trigger_config'])) {
            $rule->update([
                'next_trigger_at' => $this->calculateNextTrigger(
                    $rule->trigger_type->value,
                    $rule->trigger_config
                ),
            ]);
        }

        return $rule->fresh();
    }

    /**
     * Pause a rule.
     */
    public function pause(Rule $rule): Rule
    {
        $rule->update(['status' => RuleStatus::Paused]);
        return $rule->fresh();
    }

    /**
     * Resume a paused rule.
     */
    public function resume(Rule $rule): Rule
    {
        $rule->update([
            'status'          => RuleStatus::Active,
            'next_trigger_at' => $this->calculateNextTrigger(
                $rule->trigger_type->value,
                $rule->trigger_config
            ),
        ]);

        return $rule->fresh();
    }

    /**
     * Archive (soft delete) a rule.
     */
    public function archive(Rule $rule): void
    {
        $rule->update(['status' => RuleStatus::Archived]);
        $rule->delete();
    }

    /**
     * Calculate the next trigger timestamp for a scheduled rule.
     */
    public function calculateNextTrigger(string $triggerType, array $config): ?Carbon
    {
        if ($triggerType !== TriggerType::Schedule->value) {
            return null; // Event-driven rules do not have a scheduled next trigger
        }

        $frequency = $config['frequency'] ?? 'monthly';
        $time      = $config['time'] ?? '08:00';
        [$hour, $minute] = explode(':', $time);

        $now = now()->timezone('Africa/Lagos');

        return match($frequency) {
            'daily'   => $this->nextDaily($now, (int)$hour, (int)$minute),
            'weekly'  => $this->nextWeekly($now, $config['day'] ?? 'sunday', (int)$hour, (int)$minute),
            'monthly' => $this->nextMonthly($now, (int)($config['day'] ?? 1), (int)$hour, (int)$minute),
            default   => null,
        };
    }

    /**
     * Advance the next_trigger_at after a successful execution.
     */
    public function advanceNextTrigger(Rule $rule): void
    {
        if ($rule->trigger_type !== TriggerType::Schedule) {
            return;
        }

        $next = $this->calculateNextTrigger(
            $rule->trigger_type->value,
            $rule->trigger_config
        );

        $rule->update(['next_trigger_at' => $next, 'last_triggered_at' => now()]);
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function normaliseActions(array $actions): array
    {
        return collect($actions)
            ->sortBy('step_order')
            ->values()
            ->map(function ($action, $index) {
                return [
                    'step_order'  => $action['step_order'] ?? ($index + 1),
                    'action_type' => $action['action_type'],
                    'amount_type' => $action['amount_type'],
                    'amount'      => $action['amount'],
                    'label'       => $action['label'] ?? null,
                    'config'      => $action['config'] ?? [],
                ];
            })
            ->toArray();
    }

    private function nextDaily(Carbon $now, int $hour, int $minute): Carbon
    {
        $candidate = $now->copy()->setTime($hour, $minute, 0);

        return $candidate->isPast()
            ? $candidate->addDay()
            : $candidate;
    }

    private function nextWeekly(Carbon $now, string $dayName, int $hour, int $minute): Carbon
    {
        $dayMap = [
            'sunday'    => Carbon::SUNDAY,
            'monday'    => Carbon::MONDAY,
            'tuesday'   => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday'  => Carbon::THURSDAY,
            'friday'    => Carbon::FRIDAY,
            'saturday'  => Carbon::SATURDAY,
        ];

        $targetDay = $dayMap[strtolower($dayName)] ?? Carbon::SUNDAY;
        $candidate = $now->copy()->next($targetDay)->setTime($hour, $minute, 0);

        // If today is the target day and the time hasn't passed yet — use today
        if ($now->dayOfWeek === $targetDay) {
            $today = $now->copy()->setTime($hour, $minute, 0);
            if ($today->isFuture()) {
                return $today;
            }
        }

        return $candidate;
    }

    private function nextMonthly(Carbon $now, int $day, int $hour, int $minute): Carbon
    {
        $candidate = $now->copy()->setDay(min($day, $now->daysInMonth))->setTime($hour, $minute, 0);

        return $candidate->isPast()
            ? $candidate->addMonthNoOverflow()->setDay(min($day, $candidate->addMonthNoOverflow()->daysInMonth))
            : $candidate;
    }
}
