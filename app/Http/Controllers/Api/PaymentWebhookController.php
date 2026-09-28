<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\Webhooks\PaymentWebhookProcessor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    /**
     * Handle incoming payment webhooks from external providers.
     */
    public function handle(Request $request, string $provider, PaymentWebhookProcessor $processor): JsonResponse
    {
        $payload = $request->all();

        $result = $processor->process(
            $provider,
            $payload,
            $request->headers->all(),
            $request->getContent()
        );

        return response()->json($result, 200);
    }
}
