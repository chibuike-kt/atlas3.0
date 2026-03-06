<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Notifications\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends BaseApiController
{
  public function __construct(private readonly FcmService $fcm) {}

  /**
   * POST /api/notifications/token
   * Register a device FCM token for the authenticated user.
   */
  public function registerToken(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'token'    => ['required', 'string'],
      'platform' => ['sometimes', 'string', 'in:android,ios,web'],
    ]);

    $this->fcm->registerToken(
      $request->user(),
      $validated['token'],
      $validated['platform'] ?? 'android'
    );

    return $this->success(null, 'Device registered for notifications.');
  }

  /**
   * DELETE /api/notifications/token
   * Remove a device FCM token (call on logout).
   */
  public function removeToken(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'token' => ['required', 'string'],
    ]);

    $this->fcm->removeToken($request->user(), $validated['token']);

    return $this->noContent('Device unregistered.');
  }
}
