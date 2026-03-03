<?php

namespace App\Services\Mono;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MonoService
{
    private string $baseUrl;
    private string $secretKey;

    public function __construct()
    {
        $this->baseUrl   = config('atlas.mono.base_url');
        $this->secretKey = config('atlas.mono.secret_key');
    }

    // ── Account Linking ───────────────────────────────────────────────────

    /**
     * Exchange a Mono auth code for an account ID.
     */
    public function exchangeAuthCode(string $code): array
    {
        $response = $this->post('/account/auth', ['code' => $code]);

        return [
            'account_id' => $response['id'],
        ];
    }

    /**
     * Fetch account details by Mono account ID.
     */
    public function getAccount(string $accountId): array
    {
        return $this->get("/accounts/{$accountId}");
    }

    /**
     * Fetch current account balance.
     */
    public function getAccountBalance(string $accountId): array
    {
        return $this->get("/accounts/{$accountId}/balance");
    }

    /**
     * Unlink (revoke) an account.
     */
    public function unlinkAccount(string $accountId): bool
    {
        try {
            $this->post("/accounts/{$accountId}/unlink", []);
            return true;
        } catch (\Throwable $e) {
            Log::error('Mono unlink failed', ['account_id' => $accountId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    // ── Transactions ──────────────────────────────────────────────────────

    /**
     * Fetch transactions for an account.
     * Returns paginated results — call repeatedly with page param until empty.
     */
    public function getTransactions(string $accountId, array $params = []): array
    {
        $query = array_merge([
            'paginate' => 'true',
            'limit'    => 100,
            'page'     => 1,
        ], $params);

        return $this->get("/accounts/{$accountId}/transactions", $query);
    }

    /**
     * Fetch all transactions across all pages for an account.
     * Used for initial sync — may take multiple requests.
     */
    public function getAllTransactions(string $accountId, int $limitDays = 90): array
    {
        $allTransactions = [];
        $page = 1;
        $startDate = now()->subDays($limitDays)->format('d-m-Y');

        do {
            $response = $this->getTransactions($accountId, [
                'page'  => $page,
                'start' => $startDate,
            ]);

            $transactions = $response['data'] ?? [];
            $allTransactions = array_merge($allTransactions, $transactions);

            $hasMore = isset($response['paging']['next']) && ! empty($response['paging']['next']);
            $page++;

            // Safety cap — never pull more than 1000 transactions in one sync
            if (count($allTransactions) >= 1000) {
                break;
            }

        } while ($hasMore);

        return $allTransactions;
    }

    // ── Identity ──────────────────────────────────────────────────────────

    /**
     * Fetch account owner identity details.
     */
    public function getIdentity(string $accountId): array
    {
        return $this->get("/accounts/{$accountId}/identity");
    }

    // ── Statement ─────────────────────────────────────────────────────────

    /**
     * Request a bank statement PDF (async — Mono sends webhook when ready).
     */
    public function requestStatement(string $accountId, int $months = 3): array
    {
        return $this->post("/accounts/{$accountId}/statement/pdf", [
            'period' => "last{$months}months",
        ]);
    }

    // ── HTTP helpers ──────────────────────────────────────────────────────

    private function get(string $path, array $query = []): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->get($this->baseUrl . $path, $query);

        return $this->handleResponse($response, 'GET', $path);
    }

    private function post(string $path, array $data): array
    {
        $response = Http::withHeaders($this->headers())
            ->timeout(30)
            ->post($this->baseUrl . $path, $data);

        return $this->handleResponse($response, 'POST', $path);
    }

    private function headers(): array
    {
        return [
            'mono-sec-key'  => $this->secretKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
    }

    private function handleResponse($response, string $method, string $path): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $status = $response->status();
        $body   = $response->json();

        Log::error('Mono API error', [
            'method'  => $method,
            'path'    => $path,
            'status'  => $status,
            'body'    => $body,
        ]);

        $message = $body['message'] ?? "Mono API request failed with status {$status}";

        throw new \RuntimeException($message, $status);
    }
}
