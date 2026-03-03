<?php

namespace App\Console\Commands;

use App\Models\ConnectedAccount;
use App\Services\Mono\AccountService;
use Illuminate\Console\Command;

class SyncAccountBalances extends Command
{
    protected $signature   = 'atlas:sync-balances';
    protected $description = 'Sync account balances from Mono for all active connected accounts';

    public function __construct(private readonly AccountService $accountService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $accounts = ConnectedAccount::active()->with('user')->get();

        $this->info("Syncing balances for {$accounts->count()} account(s)...");

        $synced = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $this->accountService->syncBalance($account);
                $synced++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed: {$account->institution} ({$account->id}): {$e->getMessage()}");
            }
        }

        $this->info("Done. Synced: {$synced} | Failed: {$failed}");

        return Command::SUCCESS;
    }
}
