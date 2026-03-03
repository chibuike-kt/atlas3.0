<?php

namespace App\Services\Execution;

use App\Models\Rule;
use Illuminate\Support\Facades\Log;

class SchedulerService
{
    public function __construct(private readonly ExecutionEngine $engine)
    {
    }

    /**
     * Find all scheduled rules that are due and execute them.
     * Called every minute by the Laravel scheduler.
     */
    public function runDueRules(): array
    {
        $dueRules = Rule::dueForExecution()->with(['connectedAccount', 'user'])->get();

        if ($dueRules->isEmpty()) {
            return ['fired' => 0, 'failed' => 0];
        }

        Log::info("Scheduler: found {$dueRules->count()} due rule(s)");

        $fired  = 0;
        $failed = 0;

        foreach ($dueRules as $rule) {
            try {
                $this->engine->execute($rule, 'schedule');
                $fired++;

                Log::info('Scheduler: rule executed', ['rule_id' => $rule->id, 'name' => $rule->name]);

            } catch (\Throwable $e) {
                $failed++;

                Log::error('Scheduler: rule execution failed', [
                    'rule_id' => $rule->id,
                    'name'    => $rule->name,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return ['fired' => $fired, 'failed' => $failed];
    }

    /**
     * Find all deposit-triggered rules for a user and fire any that match.
     * Called after a salary or large credit is detected via Mono webhook.
     */
    public function runDepositTriggers(string $userId, int $amountKobo, bool $isSalary): array
    {
        $rules = Rule::where('user_id', $userId)
            ->where('trigger_type', 'deposit')
            ->active()
            ->with(['connectedAccount', 'user'])
            ->get();

        $fired  = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            $config    = $rule->trigger_config ?? [];
            $condition = $config['condition'] ?? null;
            $minAmount = (int) ($config['min_amount'] ?? 0);

            // Check if this deposit matches the trigger condition
            $matches = match($condition) {
                'is_salary'     => $isSalary,
                'balance_above' => $amountKobo >= $minAmount,
                default         => $amountKobo >= $minAmount,
            };

            if (! $matches) {
                continue;
            }

            try {
                $this->engine->execute($rule, 'deposit');
                $fired++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('Deposit trigger failed', [
                    'rule_id' => $rule->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return ['fired' => $fired, 'failed' => $failed];
    }
}
