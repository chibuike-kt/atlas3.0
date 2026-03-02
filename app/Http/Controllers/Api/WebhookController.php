<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends BaseApiController
{
  /**
   * POST /api/webhooks/mono
   * Full implementation arrives in Step 5 — Mono integration.
   */
  public function mono(Request $request): JsonResponse
  {
    // Log the payload for now so nothing is lost before Step 5
    \Log::info('Mono webhook received', [
      'event'   => $request->input('event'),
      'payload' => $request->all(),
    ]);

    return $this->success(null, 'Webhook received.');
  }
}
