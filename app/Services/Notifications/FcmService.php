<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
  private string $projectId;
  private string $credentialsPath;

  public function __construct()
  {
    $this->projectId       = config('atlas.fcm.project_id', '');
    $this->credentialsPath = config('atlas.fcm.credentials_path', '');
  }

  /**
   * Send a push notification to a specific user.
   * Sends to all of the user's registered FCM tokens.
   */
  public function sendToUser(User $user, string $title, string $body, array $data = []): void
  {
    $tokens = $this->getTokensForUser($user);

    if (empty($tokens)) {
      return;
    }

    foreach ($tokens as $token) {
      $this->sendToToken($token, $title, $body, $data);
    }
  }

  /**
   * Send a notification to a specific FCM token.
   */
  public function sendToToken(string $token, string $title, string $body, array $data = []): bool
  {
    if (config('app.env') !== 'production') {
      Log::info('FCM sandbox notification', [
        'token' => substr($token, 0, 10) . '...',
        'title' => $title,
        'body'  => $body,
        'data'  => $data,
      ]);

      return true;
    }

    if (! $this->projectId || ! $this->credentialsPath) {
      Log::warning('FCM not configured — skipping notification.');
      return false;
    }

    try {
      $accessToken = $this->getAccessToken();

      $payload = [
        'message' => [
          'token'        => $token,
          'notification' => [
            'title' => $title,
            'body'  => $body,
          ],
          'data'         => array_map('strval', $data),
          'android'      => [
            'priority'     => 'high',
            'notification' => [
              'sound'        => 'default',
              'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ],
          ],
          'apns' => [
            'payload' => [
              'aps' => [
                'sound' => 'default',
                'badge' => 1,
              ],
            ],
          ],
        ],
      ];

      $response = Http::withToken($accessToken)
        ->post("https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send", $payload);

      if (! $response->successful()) {
        Log::error('FCM send failed', [
          'status' => $response->status(),
          'body'   => $response->body(),
        ]);

        return false;
      }

      return true;
    } catch (\Throwable $e) {
      Log::error('FCM exception', ['error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * Register or update an FCM token for a user.
   */
  public function registerToken(User $user, string $token, string $platform = 'android'): void
  {
    $tokens = $user->fcm_tokens ?? [];

    // Remove old entry for this token if exists
    $tokens = array_filter($tokens, fn($t) => $t['token'] !== $token);

    // Add fresh entry
    $tokens[] = [
      'token'      => $token,
      'platform'   => $platform,
      'registered' => now()->toISOString(),
    ];

    // Keep max 5 tokens per user (multi-device)
    if (count($tokens) > 5) {
      $tokens = array_slice(array_values($tokens), -5);
    }

    $user->update(['fcm_tokens' => array_values($tokens)]);
  }

  /**
   * Remove an FCM token (on logout).
   */
  public function removeToken(User $user, string $token): void
  {
    $tokens = $user->fcm_tokens ?? [];
    $tokens = array_values(array_filter($tokens, fn($t) => $t['token'] !== $token));
    $user->update(['fcm_tokens' => $tokens]);
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function getTokensForUser(User $user): array
  {
    $tokens = $user->fcm_tokens ?? [];
    return array_column($tokens, 'token');
  }

  /**
   * Get a short-lived OAuth2 access token from the service account credentials.
   * Uses Google's OAuth2 endpoint with JWT assertion.
   */
  private function getAccessToken(): string
  {
    $credentials = json_decode(file_get_contents($this->credentialsPath), true);

    $now = time();

    $header  = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64_encode(json_encode([
      'iss'   => $credentials['client_email'],
      'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
      'aud'   => 'https://oauth2.googleapis.com/token',
      'iat'   => $now,
      'exp'   => $now + 3600,
    ]));

    $signingInput = "{$header}.{$payload}";
    $key = openssl_pkey_get_private($credentials['private_key']);
    openssl_sign($signingInput, $signature, $key, 'SHA256');
    $jwt = "{$signingInput}." . base64_encode($signature);

    $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
      'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
      'assertion'  => $jwt,
    ]);

    return $response->json('access_token');
  }
}
