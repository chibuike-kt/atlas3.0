<?php

namespace App\Services\Rails;

use App\Models\ConnectedAccount;
use App\Models\ExecutionStep;

abstract class BaseRail
{
    /**
     * Execute the step on this rail.
     * Must return an array with at minimum a 'reference' key.
     */
    abstract public function execute(ExecutionStep $step, ConnectedAccount $account): array;

    /**
     * Reverse a previously completed step.
     * Returns the reversal reference.
     */
    public function reverse(ExecutionStep $step): string
    {
        throw new \RuntimeException(
            get_class($this) . ' does not support reversals.'
        );
    }

    /**
     * Format amount from kobo to naira for external APIs.
     */
    protected function toNaira(int $kobo): float
    {
        return $kobo / 100;
    }

    /**
     * Generate a unique transaction reference.
     */
    protected function generateReference(string $prefix = 'ATL'): string
    {
        return $prefix . '-' . strtoupper(substr(md5(uniqid()), 0, 12));
    }
}
