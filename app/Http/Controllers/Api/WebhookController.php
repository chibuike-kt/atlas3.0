<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Mono\MonoWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends BaseApiController
{
    public function __construct(private readonly MonoWebhookProcessor $processor)
    {
    }

    /**
     * POST /api/webhooks/mono
     */
    public function mono(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Mono webhook received', ['event' => $payload['event'] ?? 'unknown']);

        try {
            $this->processor->process($payload);
        } catch (\Throwable $e) {
            Log::error('Mono webhook processing failed', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        // Always return 200 to Mono even on processing failure
        // so Mono does not keep retrying
        return $this->success(null, 'Webhook received.');
    }
}
