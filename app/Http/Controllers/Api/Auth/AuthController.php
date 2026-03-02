<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends BaseApiController
{
  public function __construct(private readonly AuthService $authService) {}

  /**
   * POST /api/auth/register
   */
  public function register(RegisterRequest $request): JsonResponse
  {
    try {
      $result = $this->authService->register(
        $request->validated(),
        $request->ip(),
        $request->userAgent() ?? ''
      );

      return $this->created($result, 'Account created successfully. Welcome to Atlas.');
    } catch (ValidationException $e) {
      return $this->unprocessable($e->getMessage(), $e->errors());
    } catch (\Throwable $e) {
      return $this->serverError($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
  }

  /**
   * POST /api/auth/login
   */
  public function login(LoginRequest $request): JsonResponse
  {
    try {
      $result = $this->authService->login(
        $request->validated(),
        $request->ip(),
        $request->userAgent() ?? ''
      );

      return $this->success($result, 'Login successful.');
    } catch (ValidationException $e) {
      return $this->unprocessable('Login failed.', $e->errors());
    } catch (\Throwable $e) {
      return $this->serverError('Login failed. Please try again.');
    }
  }

  /**
   * POST /api/auth/logout
   */
  public function logout(Request $request): JsonResponse
  {
    try {
      $token = $request->bearerToken() ?? '';
      $this->authService->logout($request->user(), $token);

      return $this->success(null, 'You have been logged out successfully.');
    } catch (\Throwable $e) {
      return $this->serverError('Logout failed. Please try again.');
    }
  }

  /**
   * POST /api/auth/logout-all
   * Revokes all sessions across all devices.
   */
  public function logoutAll(Request $request): JsonResponse
  {
    try {
      $this->authService->logoutAll($request->user());

      return $this->success(null, 'You have been logged out from all devices.');
    } catch (\Throwable $e) {
      return $this->serverError('Logout failed. Please try again.');
    }
  }

  /**
   * POST /api/auth/refresh
   */
  public function refresh(Request $request): JsonResponse
  {
    $request->validate([
      'refresh_token' => ['required', 'string'],
    ]);

    try {
      $result = $this->authService->refresh(
        $request->input('refresh_token'),
        $request->ip(),
        $request->userAgent() ?? ''
      );

      return $this->success($result, 'Token refreshed successfully.');
    } catch (ValidationException $e) {
      return $this->unprocessable('Token refresh failed.', $e->errors());
    } catch (\Throwable $e) {
      return $this->unauthorized('Your session has expired. Please log in again.');
    }
  }

  /**
   * GET /api/auth/me
   */
  public function me(Request $request): JsonResponse
  {
    $user = $request->user()->load('financialProfile', 'primaryAccount');

    return $this->success([
      'user'              => [
        'id'                    => $user->id,
        'full_name'             => $user->full_name,
        'first_name'            => $user->first_name,
        'initials'              => $user->initials,
        'email'                 => $user->email,
        'phone'                 => $user->phone,
        'avatar_url'            => $user->avatar_url,
        'kyc_status'            => $user->kyc_status,
        'notifications_enabled' => $user->notifications_enabled,
        'timezone'              => $user->timezone,
        'currency'              => $user->currency,
        'email_verified_at'     => $user->email_verified_at,
        'last_login_at'         => $user->last_login_at,
        'created_at'            => $user->created_at,
      ],
      'financial_profile' => $user->financialProfile ? [
        'salary_detected'        => $user->financialProfile->salary_detected,
        'salary_day'             => $user->financialProfile->salary_day,
        'average_salary'         => $user->financialProfile->average_salary,
        'savings_rate_percent'   => $user->financialProfile->savings_rate_percent,
        'savings_rate_label'     => $user->financialProfile->savings_rate_label,
        'financial_health_score' => $user->financialProfile->financial_health_score,
        'health_score_label'     => $user->financialProfile->health_score_label,
        'income_type'            => $user->financialProfile->income_type,
        'last_analyzed_at'       => $user->financialProfile->last_analyzed_at,
      ] : null,
      'primary_account'   => $user->primaryAccount ? [
        'id'              => $user->primaryAccount->id,
        'institution'     => $user->primaryAccount->institution,
        'account_name'    => $user->primaryAccount->account_name,
        'account_number'  => $user->primaryAccount->masked_account_number,
        'balance'         => $user->primaryAccount->balance,
        'balance_formatted' => $user->primaryAccount->balance_formatted,
      ] : null,
    ]);
  }

  /**
   * POST /api/auth/verify-pin
   * Verifies PIN before sensitive actions. Returns a short-lived confirmation token.
   */
  public function verifyPin(Request $request): JsonResponse
  {
    $request->validate([
      'pin' => ['required', 'digits:4'],
    ]);

    if (! $this->authService->verifyPin($request->user(), $request->input('pin'))) {
      return $this->error('Incorrect PIN. Please try again.', null, 401);
    }

    // Issue a short-lived pin confirmation token stored in cache
    $confirmationToken = \Str::random(40);
    $cacheKey = "pin_confirmed:{$request->user()->id}:{$confirmationToken}";
    \Cache::put($cacheKey, true, now()->addMinutes(5));

    return $this->success([
      'pin_token'  => $confirmationToken,
      'expires_in' => 300, // 5 minutes in seconds
    ], 'PIN verified successfully.');
  }
}
