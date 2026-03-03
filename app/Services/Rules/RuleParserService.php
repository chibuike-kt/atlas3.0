<?php

namespace App\Services\Rules;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RuleParserService
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
     * Parse a plain-English rule description into a structured rule object.
     * Returns a validated array ready to be passed to RuleService::create().
     */
    public function parse(string $ruleText, string $userId, array $availableAccounts): array
    {
        $accountsJson = json_encode($availableAccounts, JSON_PRETTY_PRINT);

        $prompt = <<<PROMPT
You are Atlas, a Nigerian fintech AI assistant. Parse the following rule description into a structured JSON object.

USER'S CONNECTED ACCOUNTS:
{$accountsJson}

RULE TEXT: "{$ruleText}"

Return ONLY a valid JSON object with this exact structure — no explanation, no markdown, no preamble:

{
  "name": "Short, clear rule name (max 60 chars)",
  "description": "One-sentence description of what this rule does",
  "trigger_type": "schedule | deposit | balance | manual",
  "trigger_config": {
    "frequency": "daily | weekly | monthly | on_salary",
    "day": 1,
    "time": "08:00",
    "condition": "is_salary | balance_above | balance_below",
    "threshold": 0
  },
  "total_amount_type": "fixed | percentage | remainder",
  "total_amount": null,
  "actions": [
    {
      "step_order": 1,
      "action_type": "send_bank | save_piggvest | save_cowrywise | convert_crypto | pay_bill",
      "amount_type": "fixed | percentage | remainder",
      "amount": 0,
      "label": "Human-readable step description",
      "config": {}
    }
  ],
  "confidence": 0.95,
  "ambiguities": []
}

PARSING RULES:
- Amounts in naira: convert to kobo (multiply by 100). "10k" = 1000000 kobo. "50%" = 5000 (basis points).
- "Save" / "set aside" / "put away" → save_piggvest (default savings rail)
- "Invest" / "invest in" → save_cowrywise
- "Send" / "transfer" / "pay [person]" → send_bank
- "Convert" / "buy dollars" / "USDT" / "dollar" → convert_crypto
- "Pay bill" / "airtime" / "data" / "electricity" / "DSTV" → pay_bill
- "When salary arrives" / "on payday" → trigger_type: deposit, condition: is_salary
- "Every month" / "monthly" / "1st of month" → trigger_type: schedule, frequency: monthly
- "Every week" / "weekly" / "every Sunday" → trigger_type: schedule, frequency: weekly
- "Every day" / "daily" → trigger_type: schedule, frequency: daily
- "When balance is above X" → trigger_type: balance, condition: balance_above
- "Remainder" / "rest" / "what's left" / "everything else" → amount_type: remainder
- Percentage amounts → amount_type: percentage, amount in basis points (20% = 2000)
- Fixed naira amounts → amount_type: fixed, amount in kobo
- If multiple actions, assign step_order 1, 2, 3...
- If something is ambiguous, add it to the ambiguities array
- confidence: 0.0-1.0 based on how clear the instruction was
- Only use accounts from the provided list. If an account isn't specified, use the first one.

Respond with ONLY the JSON object.
PROMPT;

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
                    'messages'   => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Claude API returned ' . $response->status());
            }

            $content = $response->json('content.0.text', '');
            $parsed  = $this->extractJson($content);

            // Validate the parsed output has the minimum required fields
            $this->validate($parsed);

            $parsed['rule_text']    = $ruleText;
            $parsed['is_ai_parsed'] = true;

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('Rule parsing failed', [
                'rule_text' => $ruleText,
                'error'     => $e->getMessage(),
            ]);

            throw new \RuntimeException('Atlas could not understand that rule. Try rephrasing it more specifically. Example: "Every month on the 25th, save ₦20,000 to PiggyVest."');
        }
    }

    /**
     * Extract the JSON object from Claude's response.
     * Claude occasionally wraps output in markdown code blocks.
     */
    private function extractJson(string $content): array
    {
        // Strip markdown code fences if present
        $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $content = preg_replace('/\s*```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Could not parse Claude response as JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }

    private function validate(array $parsed): void
    {
        $required = ['name', 'trigger_type', 'actions'];

        foreach ($required as $field) {
            if (empty($parsed[$field])) {
                throw new \RuntimeException("Parsed rule missing required field: {$field}");
            }
        }

        if (! is_array($parsed['actions']) || empty($parsed['actions'])) {
            throw new \RuntimeException('Parsed rule must have at least one action.');
        }
    }
}
