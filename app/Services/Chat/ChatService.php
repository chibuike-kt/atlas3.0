<?php

namespace App\Services\Chat;

use App\Models\User;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatService
{
  private string $apiKey;
  private string $model;
  private string $baseUrl;

  public function __construct()
  {
    $this->apiKey  = config('atlas.anthropic.api_key');
    $this->model   = config('atlas.anthropic.model');
    $this->baseUrl = config('atlas.anthropic.base_url');
  }

  /**
   * Send a message to Atlas and get a response.
   * Builds full financial context from the user's profile.
   */
  public function chat(User $user, string $message, array $history = []): array
  {
    $context = $this->buildContext($user);
    $system  = $this->buildSystemPrompt($user, $context);

    // Build message history for multi-turn conversation
    $messages = [];

    foreach ($history as $turn) {
      $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
    }

    $messages[] = ['role' => 'user', 'content' => $message];

    try {
      $response = Http::withHeaders([
        'x-api-key'         => $this->apiKey,
        'anthropic-version' => '2023-06-01',
        'Content-Type'      => 'application/json',
      ])
        ->timeout(config('atlas.anthropic.timeout', 30))
        ->post($this->baseUrl . '/messages', [
          'model'      => $this->model,
          'max_tokens' => 1024,
          'system'     => $system,
          'messages'   => $messages,
        ]);

      if (! $response->successful()) {
        throw new \RuntimeException('Atlas AI is unavailable right now. Please try again shortly.');
      }

      $reply = $response->json('content.0.text', '');

      // Detect if the reply contains a rule suggestion
      $ruleSuggestion = $this->extractRuleSuggestion($reply);

      return [
        'reply'          => $reply,
        'rule_suggestion' => $ruleSuggestion,
        'context_used'   => array_keys($context),
      ];
    } catch (\Throwable $e) {
      Log::error('Chat failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
      throw new \RuntimeException($e->getMessage());
    }
  }

  // ── Private methods ───────────────────────────────────────────────────

  private function buildSystemPrompt(User $user, array $context): string
  {
    $firstName = explode(' ', $user->full_name)[0];
    $usdtRate  = SystemSetting::getValue('usdt_buy_rate', 1620);

    return <<<PROMPT
You are Atlas, a personal AI financial advisor for Nigerians. You are speaking with {$firstName}.

Your personality:
- Direct, warm, and practical — like a knowledgeable friend, not a bank
- You speak in plain English, avoid jargon
- You give specific, actionable advice based on the user's actual financial data
- You are pro-Nigerian — you understand ajo, esusu, salary culture, DSTV, airtime, POS charges, school fees
- You never make up numbers. If you don't have the data, say so honestly
- Keep responses concise — this is a mobile chat interface, not a report
- When relevant, suggest Atlas rules the user can create to automate a solution

CURRENT FINANCIAL CONTEXT:
{$this->formatContext($context)}

ATLAS PLATFORM CAPABILITIES (you can suggest these):
- Connect bank accounts via Mono to read transactions
- Create automated rules: "When salary arrives, save 20% to PiggyVest"
- Track spending by category (food, transport, bills, etc.)
- Detect salary day, savings rate, idle cash
- Project end-of-month balance
- Convert naira to USDT for dollar hedging (rate: ₦{$usdtRate}/USDT)
- Pay bills automatically (airtime, data, electricity, DSTV)
- Dispute unauthorized transactions
- Save contacts for recurring transfers

IMPORTANT RULES:
- Never provide specific investment advice beyond what Atlas supports
- Never ask for sensitive information (passwords, PINs, card numbers)
- If asked about something outside your capabilities, acknowledge it and redirect
- Always ground your advice in the user's actual numbers when available
- Amounts are in naira. Be specific: say "₦45,000" not "your salary"
PROMPT;
  }

  private function buildContext(User $user): array
  {
    $context = [];
    $profile = $user->financialProfile;
    $account = $user->primaryAccount;

    // Balance
    if ($account) {
      $context['balance'] = [
        'current'           => $account->balance,
        'formatted'         => '₦' . number_format($account->balance / 100, 2),
        'institution'       => $account->institution,
        'last_synced'       => $account->last_synced_at?->diffForHumans(),
      ];
    }

    // Financial profile
    if ($profile) {
      if ($profile->salary_detected) {
        $context['salary'] = [
          'detected'    => true,
          'day'         => $profile->salary_day,
          'average'     => '₦' . number_format(($profile->average_salary ?? 0) / 100, 2),
          'last_amount' => '₦' . number_format(($profile->last_salary_amount ?? 0) / 100, 2),
          'last_date'   => $profile->last_salary_date,
          'source'      => $profile->salary_source,
        ];
      }

      $context['spending'] = [
        'avg_monthly'    => '₦' . number_format(($profile->avg_monthly_spend ?? 0) / 100, 2),
        'avg_income'     => '₦' . number_format(($profile->avg_monthly_income ?? 0) / 100, 2),
        'savings_rate'   => ($profile->savings_rate_percent ?? 0) . '%',
        'top_categories' => $this->formatTopCategories($profile->spend_by_category),
      ];

      $context['cashflow'] = [
        'projected_eom'   => '₦' . number_format(($profile->projected_eom_balance ?? 0) / 100, 2),
        'volatility_score' => $profile->cashflow_volatility_score,
        'health_score'    => $profile->financial_health_score,
      ];

      if ($profile->personal_inflation_rate) {
        $context['inflation'] = [
          'personal_rate' => $profile->personal_inflation_rate . '%',
          'by_category'   => $profile->inflation_by_category,
        ];
      }
    }

    // Active rules
    $ruleCount = $user->rules()->active()->count();
    if ($ruleCount > 0) {
      $context['rules'] = [
        'active_count' => $ruleCount,
        'names'        => $user->rules()->active()->pluck('name')->toArray(),
      ];
    }

    // Recent spending — last 7 days
    $recentSpend = $user->transactions()
      ->debits()
      ->lastNDays(7)
      ->sum('amount');

    if ($recentSpend > 0) {
      $context['recent_spend'] = [
        'last_7_days' => '₦' . number_format($recentSpend / 100, 2),
      ];
    }

    return $context;
  }

  private function formatContext(array $context): string
  {
    if (empty($context)) {
      return 'No financial data available yet. The user has not connected a bank account.';
    }

    $lines = [];

    if (isset($context['balance'])) {
      $b = $context['balance'];
      $lines[] = "Current balance: {$b['formatted']} ({$b['institution']}, synced {$b['last_synced']})";
    }

    if (isset($context['salary'])) {
      $s = $context['salary'];
      $lines[] = "Salary: {$s['average']} avg, arrives around the {$s['day']}th" .
        ($s['source'] ? " from {$s['source']}" : '');
    }

    if (isset($context['spending'])) {
      $s = $context['spending'];
      $lines[] = "Avg monthly spend: {$s['avg_monthly']} | Avg income: {$s['avg_income']} | Savings rate: {$s['savings_rate']}";

      if (! empty($s['top_categories'])) {
        $lines[] = "Top spend categories: " . implode(', ', $s['top_categories']);
      }
    }

    if (isset($context['cashflow'])) {
      $c = $context['cashflow'];
      $lines[] = "Projected end-of-month balance: {$c['projected_eom']} | Health score: {$c['health_score']}/100";
    }

    if (isset($context['inflation'])) {
      $lines[] = "Personal inflation rate: {$context['inflation']['personal_rate']} this month";
    }

    if (isset($context['rules'])) {
      $r = $context['rules'];
      $lines[] = "Active rules: {$r['active_count']} — " . implode(', ', $r['names']);
    }

    if (isset($context['recent_spend'])) {
      $lines[] = "Spent in last 7 days: {$context['recent_spend']['last_7_days']}";
    }

    return implode("\n", $lines);
  }

  private function formatTopCategories(?array $categories): array
  {
    if (empty($categories)) {
      return [];
    }

    arsort($categories);

    return collect(array_slice($categories, 0, 4, true))
      ->map(fn($amount, $cat) => ucfirst($cat) . ' (₦' . number_format($amount / 100, 2) . ')')
      ->values()
      ->toArray();
  }

  /**
   * Detect if Atlas suggested a rule in its reply and extract a parse prompt.
   * Returns a rule_text string the frontend can send to POST /api/rules/parse.
   */
  private function extractRuleSuggestion(string $reply): ?string
  {
    // Look for patterns like "I can create a rule: ..." or "Rule: ..."
    $patterns = [
      '/(?:create a rule|set up a rule|automate this)[:\s]+["""]?([^"""\n]+)["""]?/i',
      '/(?:suggestion|try this rule)[:\s]+["""]?([^"""\n]+)["""]?/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $reply, $matches)) {
        return trim($matches[1]);
      }
    }

    return null;
  }
}
