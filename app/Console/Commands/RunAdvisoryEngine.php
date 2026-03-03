<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Advisory\AdvisoryService;
use Illuminate\Console\Command;

class RunAdvisoryEngine extends Command
{
    protected $signature   = 'atlas:run-advisory {--user= : Run for a specific user ID only}';
    protected $description = 'Generate advisory insights and rule suggestions for all active users';

    public function __construct(private readonly AdvisoryService $advisoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $userId = $this->option('user');

        $query = User::where('is_active', true)
            ->whereHas('connectedAccounts', fn($q) => $q->where('is_active', true));

        if ($userId) {
            $query->where('id', $userId);
        }

        $users = $query->get();

        $this->info("Running advisory engine for {$users->count()} user(s)...");

        $totalInsights    = 0;
        $totalSuggestions = 0;

        foreach ($users as $user) {
            try {
                $result = $this->advisoryService->runForUser($user);
                $totalInsights    += $result['insights_generated'];
                $totalSuggestions += $result['suggestions_generated'];

                if ($result['insights_generated'] > 0 || $result['suggestions_generated'] > 0) {
                    $this->line("  ✓ {$user->full_name}: {$result['insights_generated']} insights, {$result['suggestions_generated']} suggestions");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ {$user->full_name}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Total: {$totalInsights} insights, {$totalSuggestions} suggestions generated.");

        return Command::SUCCESS;
    }
}
