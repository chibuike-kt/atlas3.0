<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use App\Models\FinancialProfile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class AuthService
{
  /**
   * Register a new user and return tokens.
   */
  public function register(array $data, string $ip, string $userAgent): array
  {
    $user = User::create([
      'id'        => Str::uuid(),
      'full_name' => $data['full_name'],
      'email'     => $data['email'],
      'phone'     => $data['phone'],
      'password'  => Hash::make($data['password']),
      'pin_hash'  => Hash::make($data['pin']),
      'timezone'  => 'Africa/Lagos',
      'currency'  => 'NGN',
    ]);

    // Initialise financial profile immediately
    FinancialProfile::create(['user_id' => $user->id]);

    $tokens = $this->generateTokenPair($user, $data['device_name'] ?? 'Unknown Device', $ip, $userAgent);

    return [
      'user'   => $this->formatUser($user),
      'tokens' => $tokens,
    ];
  }

  /**
   * Authenticate a user and return tokens.
   */
  public function login(array $credentials, string $ip, string $userAgent): array
  {
    $user = User::where('email', $credentials['email'])->first();

    if (! $user || ! $user->is_active) {
      throw ValidationException::withMessages([
        'email' => ['No active account found with this email address.'],
      ]);
    }

    if ($user->is_locked) {
      $minutes = now()->diffInMinutes($user->locked_until);
      throw ValidationException::withMessages([
        'email' => ["Your account is temporarily locked. Try again in {$minutes} minutes."],
      ]);
    }

    if (! Hash::check($credentials['password'], $user->password)) {
      $user->incrementFailedLogins();

      $remaining = max(0, 5 - $user->fresh()->failed_login_attempts);

      throw ValidationException::withMessages([
        'password' => $remaining > 0
          ? ["Incorrect password. {$remaining} attempt(s) remaining before lockout."]
          : ['Your account has been locked for 30 minutes due to too many failed attempts.'],
      ]);
    }

    $user->resetFailedLogins();
    $user->update(['last_login_ip' => $ip]);

    $tokens = $this->generateTokenPair(
      $user,
      $credentials['device_name'] ?? 'Unknown Device',
      $ip,
      $userAgent
    );

    return [
      'user'   => $this->formatUser($user),
      'tokens' => $tokens,
    ];
  }

  /**
   * Revoke the current access token and its refresh token.
   */
  public function logout(User $user, string $token): void
  {
    // Revoke refresh token associated with this session
    RefreshToken::where('user_id', $user->id)
      ->where('token_hash', RefreshToken::hashToken($token))
      ->update(['is_revoked' => true]);

    // Invalidate the JWT
    JWTAuth::invalidate(JWTAuth::getToken());
  }

  /**
   * Logout from all devices — revoke every refresh token.
   */
  public function logoutAll(User $user): void
  {
    RefreshToken::where('user_id', $user->id)->update(['is_revoked' => true]);
    JWTAuth::invalidate(JWTAuth::getToken());
  }

  /**
   * Issue a new access token using a valid refresh token.
   */
  public function refresh(string $refreshToken, string $ip, string $userAgent): array
  {
    $hash    = RefreshToken::hashToken($refreshToken);
    $tokenRecord = RefreshToken::where('token_hash', $hash)->valid()->first();

    if (! $tokenRecord) {
      throw ValidationException::withMessages([
        'refresh_token' => ['This refresh token is invalid or has expired. Please log in again.'],
      ]);
    }

    $user = $tokenRecord->user;

    if (! $user || ! $user->is_active) {
      throw ValidationException::withMessages([
        'refresh_token' => ['This account is no longer active.'],
      ]);
    }

    // Rotate — revoke old, issue new
    $tokenRecord->revoke();

    $tokens = $this->generateTokenPair(
      $user,
      $tokenRecord->device_name ?? 'Unknown Device',
      $ip,
      $userAgent
    );

    return [
      'user'   => $this->formatUser($user),
      'tokens' => $tokens,
    ];
  }

  /**
   * Update user profile fields.
   */
  public function updateProfile(User $user, array $data): User
  {
    $allowed = ['full_name', 'phone', 'avatar_url', 'notification_preferences', 'notifications_enabled'];

    $user->update(array_intersect_key($data, array_flip($allowed)));

    return $user->fresh();
  }

  /**
   * Change user password after verifying the current one.
   */
  public function changePassword(User $user, string $currentPassword, string $newPassword): void
  {
    if (! Hash::check($currentPassword, $user->password)) {
      throw ValidationException::withMessages([
        'current_password' => ['The current password you entered is incorrect.'],
      ]);
    }

    $user->update(['password' => Hash::make($newPassword)]);

    // Revoke all refresh tokens on password change for security
    RefreshToken::where('user_id', $user->id)->update(['is_revoked' => true]);
  }

  /**
   * Change the 4-digit transaction PIN.
   */
  public function changePin(User $user, string $currentPin, string $newPin): void
  {
    if (! $user->verifyPin($currentPin)) {
      throw ValidationException::withMessages([
        'current_pin' => ['The current PIN you entered is incorrect.'],
      ]);
    }

    $user->update(['pin_hash' => Hash::make($newPin)]);
  }

  /**
   * Verify a PIN without changing it — used before sensitive actions.
   */
  public function verifyPin(User $user, string $pin): bool
  {
    return $user->verifyPin($pin);
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function generateTokenPair(User $user, string $deviceName, string $ip, string $userAgent): array
  {
    $accessToken  = JWTAuth::fromUser($user);
    $refreshToken = Str::random(80);

    $ttlMinutes = (int) config("jwt.refresh_ttl", 20160); // 14 days default

    RefreshToken::create([
      'user_id'            => $user->id,
      'token_hash'         => RefreshToken::hashToken($refreshToken),
      'device_name'        => $deviceName,
      'device_fingerprint' => hash('sha256', $userAgent . $ip),
      'ip_address'         => $ip,
      'user_agent'         => substr($userAgent, 0, 255),
      'expires_at'         => now()->addMinutes($ttlMinutes),
    ]);

    return [
      'access_token'  => $accessToken,
      'refresh_token' => $refreshToken,
      'token_type'    => 'Bearer',
      'expires_in'    => (int) config('jwt.ttl', 1440) * 60, // seconds
    ];
  }

  private function formatUser(User $user): array
  {
    return [
      'id'                     => $user->id,
      'full_name'              => $user->full_name,
      'first_name'             => $user->first_name,
      'initials'               => $user->initials,
      'email'                  => $user->email,
      'phone'                  => $user->phone,
      'avatar_url'             => $user->avatar_url,
      'kyc_status'             => $user->kyc_status,
      'is_active'              => $user->is_active,
      'notifications_enabled'  => $user->notifications_enabled,
      'timezone'               => $user->timezone,
      'currency'               => $user->currency,
      'email_verified_at'      => $user->email_verified_at,
      'last_login_at'          => $user->last_login_at,
      'created_at'             => $user->created_at,
    ];
  }
}
