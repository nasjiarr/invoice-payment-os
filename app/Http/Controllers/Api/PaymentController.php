<?php

namespace App\Http\Controllers\Api;

use App\Domain\Payment\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class PaymentController extends Controller
{
    /**
     * Display a listing of payments for the specified invoice.
     */
    public function index(Request $request, Invoice $invoice): AnonymousResourceCollection
    {
        Gate::authorize('view', $invoice);

        $perPage = min($request->integer('per_page', 15), 100);
        $payments = $invoice->payments()->latest()->paginate($perPage);

        return PaymentResource::collection($payments)
            ->additional(['message' => 'Payments retrieved successfully']);
    }

    /**
     * Record a new payment for the specified invoice.
     */
    public function store(StorePaymentRequest $request, Invoice $invoice, PaymentService $paymentService): JsonResponse
    {
        $payment = $paymentService->createPayment($invoice, $request->user(), $request->validated());

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment recorded successfully'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified payment.
     */
    public function show(Request $request, Payment $payment): PaymentResource
    {
        Gate::authorize('view', $payment);

        return (new PaymentResource($payment->load(['invoice', 'business'])))
            ->additional(['message' => 'Payment retrieved successfully']);
    }

    /**
     * Process a pending payment to paid status.
     */
    public function process(Request $request, Payment $payment, PaymentService $paymentService): PaymentResource
    {
        Gate::authorize('process', $payment);

        $payment = $paymentService->processPayment($payment, $request->user());

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment processed successfully']);
    }

    /**
     * Cancel a pending payment.
     */
    public function cancel(Request $request, Payment $payment, PaymentService $paymentService): PaymentResource
    {
        Gate::authorize('cancel', $payment);

        $payment = $paymentService->cancelPayment($payment, $request->user());

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment cancelled successfully']);
    }

    /**
     * Refund a paid payment.
     */
    public function refund(Request $request, Payment $payment, PaymentService $paymentService): PaymentResource
    {
        Gate::authorize('refund', $payment);

        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:'.$payment->amount, 'decimal:0,2'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $paymentService->refundPayment(
            $payment,
            $request->user(),
            $validated['amount'] ?? null,
            $validated['reason'] ?? null
        );

        return (new PaymentResource($payment))
            ->additional(['message' => 'Payment refunded successfully']);
    }

    /**
     * Get payment status directly from the gateway.
     */
    public function status(Request $request, Payment $payment, PaymentService $paymentService): JsonResponse
    {
        Gate::authorize('view', $payment);

        $response = $paymentService->getPaymentStatus($payment);

        return response()->json([
            'message' => 'Payment status retrieved successfully',
            'data' => $response->toArray(),
        ]);
    }
}
