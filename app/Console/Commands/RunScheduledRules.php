<?php

namespace App\Console\Commands;

use App\Services\Execution\SchedulerService;
use Illuminate\Console\Command;

class RunScheduledRules extends Command
{
    protected $signature   = 'atlas:run-rules';
    protected $description = 'Execute all scheduled rules that are due for execution';

    public function __construct(private readonly SchedulerService $scheduler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Atlas Scheduler: checking for due rules...');

        $result = $this->scheduler->runDueRules();

        $this->info("Fired: {$result['fired']} | Failed: {$result['failed']}");

        return Command::SUCCESS;
    }
}
