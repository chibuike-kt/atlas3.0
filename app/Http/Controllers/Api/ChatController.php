<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ChatController extends BaseApiController
{
  public function __construct(private readonly ChatService $chatService) {}

  /**
   * POST /api/chat
   * Send a message to Atlas and get a response.
   */
  public function message(Request $request): JsonResponse
  {
    if (! config('atlas.anthropic.api_key')) {
      return $this->error('Atlas AI chat is not configured on this server.');
    }

    $validated = $request->validate([
      'message'      => ['required', 'string', 'min:1', 'max:2000'],
      'session_id'   => ['sometimes', 'nullable', 'string', 'max:64'],
    ]);

    $user      = $request->user();
    $sessionId = $validated['session_id'] ?? $user->id;
    $cacheKey  = "chat_history:{$user->id}:{$sessionId}";

    // Load conversation history from cache (last 10 turns)
    $history = Cache::get($cacheKey, []);

    try {
      $result = $this->chatService->chat(
        $user,
        $validated['message'],
        $history
      );

      // Append this turn to history and cache it
      $history[] = ['role' => 'user',      'content' => $validated['message']];
      $history[] = ['role' => 'assistant',  'content' => $result['reply']];

      // Keep last 10 turns (20 messages) to stay within context limits
      if (count($history) > 20) {
        $history = array_slice($history, -20);
      }

      Cache::put($cacheKey, $history, now()->addHours(2));

      return $this->success([
        'reply'           => $result['reply'],
        'rule_suggestion' => $result['rule_suggestion'],
        'session_id'      => $sessionId,
        'turn_count'      => count($history) / 2,
      ], 'Message sent.');
    } catch (\RuntimeException $e) {
      return $this->error($e->getMessage());
    } catch (\Throwable $e) {
      return $this->serverError('Atlas is having trouble right now. Please try again.');
    }
  }

  /**
   * GET /api/chat/history
   * Returns the conversation history for a session.
   */
  public function history(Request $request): JsonResponse
  {
    $user      = $request->user();
    $sessionId = $request->input('session_id', $user->id);
    $cacheKey  = "chat_history:{$user->id}:{$sessionId}";

    $history = Cache::get($cacheKey, []);

    $formatted = collect($history)->map(fn($turn) => [
      'role'    => $turn['role'],
      'content' => $turn['content'],
    ])->values();

    return $this->success([
      'session_id' => $sessionId,
      'turns'      => count($history) / 2,
      'messages'   => $formatted,
    ], 'Chat history retrieved.');
  }

  /**
   * DELETE /api/chat/history
   * Clear the conversation history for a session.
   */
  public function clearHistory(Request $request): JsonResponse
  {
    $user      = $request->user();
    $sessionId = $request->input('session_id', $user->id);
    $cacheKey  = "chat_history:{$user->id}:{$sessionId}";

    Cache::forget($cacheKey);

    return $this->noContent('Conversation cleared.');
  }

  /**
   * GET /api/chat/starters
   * Returns suggested conversation starters based on the user's profile.
   */
  public function starters(Request $request): JsonResponse
  {
    $user    = $request->user();
    $profile = $user->financialProfile;
    $account = $user->primaryAccount;

    $starters = [
      "How much have I spent this month?",
      "Am I saving enough?",
      "What's my biggest expense category?",
      "Will I run out of money before my next salary?",
      "How do I protect my money from naira depreciation?",
    ];

    // Personalise based on profile
    if ($profile?->salary_detected) {
      $starters[] = "What should I do when my salary arrives on the {$profile->salary_day}th?";
    }

    if ($account && $account->balance > 10000000) { // > ₦100,000
      $starters[] = "I have some extra money sitting in my account. What should I do with it?";
    }

    if (($profile->savings_rate_percent ?? 0) < 10) {
      $starters[] = "How can I start saving more consistently?";
    }

    if ($profile?->personal_inflation_rate > 10) {
      $starters[] = "My expenses keep going up. How do I deal with inflation?";
    }

    // Shuffle and return 5
    shuffle($starters);

    return $this->success([
      'starters' => array_slice($starters, 0, 5),
    ], 'Conversation starters retrieved.');
  }
}
