<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends BaseApiController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * PUT /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'full_name'                => ['sometimes', 'string', 'min:2', 'max:100'],
            'phone'                    => ['sometimes', 'string', 'regex:/^(\+234|0)[789][01]\d{8}$/', 'unique:users,phone,' . $request->user()->id],
            'avatar_url'               => ['sometimes', 'nullable', 'url'],
            'notifications_enabled'    => ['sometimes', 'boolean'],
            'notification_preferences' => ['sometimes', 'array'],
        ]);

        $user = $this->authService->updateProfile($request->user(), $validated);

        return $this->success([
            'id'                     => $user->id,
            'full_name'              => $user->full_name,
            'first_name'             => $user->first_name,
            'email'                  => $user->email,
            'phone'                  => $user->phone,
            'avatar_url'             => $user->avatar_url,
            'notifications_enabled'  => $user->notifications_enabled,
        ], 'Profile updated successfully.');
    }

    /**
     * PUT /api/profile/password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        try {
            $this->authService->changePassword(
                $request->user(),
                $request->input('current_password'),
                $request->input('new_password')
            );

            return $this->success(null, 'Password changed successfully. All other sessions have been logged out.');
        } catch (ValidationException $e) {
            return $this->unprocessable('Password change failed.', $e->errors());
        }
    }

    /**
     * PUT /api/profile/pin
     */
    public function changePin(Request $request): JsonResponse
    {
        $request->validate([
            'current_pin' => ['required', 'digits:4'],
            'new_pin'     => ['required', 'digits:4', 'different:current_pin'],
        ]);

        try {
            $this->authService->changePin(
                $request->user(),
                $request->input('current_pin'),
                $request->input('new_pin')
            );

            return $this->success(null, 'Transaction PIN changed successfully.');
        } catch (ValidationException $e) {
            return $this->unprocessable('PIN change failed.', $e->errors());
        }
    }

    /**
     * GET /api/profile/sessions
     * Lists all active refresh token sessions for the user.
     */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = $request->user()
            ->refreshTokens()
            ->valid()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn($token) => [
                'id'           => $token->id,
                'device_name'  => $token->device_name,
                'ip_address'   => $token->ip_address,
                'last_used_at' => $token->last_used_at,
                'expires_at'   => $token->expires_at,
            ]);

        return $this->success($sessions, 'Active sessions retrieved.');
    }

    /**
     * DELETE /api/profile/sessions/{id}
     * Revoke a specific session.
     */
    public function revokeSession(Request $request, string $sessionId): JsonResponse
    {
        $token = $request->user()
            ->refreshTokens()
            ->where('id', $sessionId)
            ->first();

        if (! $token) {
            return $this->notFound('Session not found.');
        }

        $token->revoke();

        return $this->success(null, 'Session revoked successfully.');
    }
}
