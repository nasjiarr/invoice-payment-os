<?php

namespace App\Http\Controllers\Api;

use App\Domain\Invoice\InvoiceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Invoice\StoreInvoiceRequest;
use App\Http\Requests\Invoice\UpdateInvoiceRequest;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices for the user's business.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Invoice::class);

        $query = Invoice::accessibleBy($request->user())
            ->with(['customer', 'business', 'items']);

        // Filter by business_id if provided
        if ($businessId = $request->integer('business_id')) {
            $hasAccess = $request->user()->ownedBusinesses()->where('id', $businessId)->exists()
                || $request->user()->businesses()->where('businesses.id', $businessId)->exists();

            if (! $hasAccess) {
                abort(403, 'This action is unauthorized.');
            }

            $query->where('business_id', $businessId);
        }

        // Filter by customer_id if provided
        if ($customerId = $request->integer('customer_id')) {
            $query->where('customer_id', $customerId);
        }

        // Filter by status if provided
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        // Search by invoice_number or customer name
        if ($search = $request->string('search')->trim()->toString()) {
            $query->where(function ($q) use ($search): void {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('customer', fn ($cQ) => $cQ->where('name', 'like', "%{$search}%"));
            });
        }

        // Safe sorting with allowed fields
        $allowedSorts = ['id', 'invoice_number', 'issue_date', 'due_date', 'total', 'status', 'created_at', 'updated_at'];
        $sort = in_array($request->query('sort'), $allowedSorts, true) ? $request->query('sort') : 'created_at';
        $direction = strtolower($request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $direction);

        // Pagination
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $invoices = $query->paginate($perPage);

        return InvoiceResource::collection($invoices)->additional([
            'message' => 'Invoices retrieved successfully',
        ]);
    }

    /**
     * Store a newly created invoice.
     */
    public function store(StoreInvoiceRequest $request, InvoiceService $invoiceService): JsonResponse
    {
        Gate::authorize('create', [Invoice::class, $request->integer('business_id')]);

        $invoice = $invoiceService->createInvoice($request->user(), $request->validated());

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Display the specified invoice.
     */
    public function show(Request $request, Invoice $invoice): InvoiceResource
    {
        Gate::authorize('view', $invoice);

        return (new InvoiceResource($invoice->load(['items', 'customer', 'business'])))
            ->additional(['message' => 'Invoice retrieved successfully']);
    }

    /**
     * Update the specified invoice.
     */
    public function update(UpdateInvoiceRequest $request, Invoice $invoice, InvoiceService $invoiceService): InvoiceResource
    {
        Gate::authorize('update', $invoice);

        $invoice = $invoiceService->updateInvoice($invoice, $request->user(), $request->validated());

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice updated successfully']);
    }

    /**
     * Remove the specified invoice.
     */
    public function destroy(Request $request, Invoice $invoice, InvoiceService $invoiceService): JsonResponse
    {
        Gate::authorize('delete', $invoice);

        $invoiceService->deleteInvoice($invoice, $request->user());

        return response()->json([
            'message' => 'Invoice deleted successfully',
        ]);
    }

    /**
     * Action: mark invoice as sent.
     */
    public function send(Request $request, Invoice $invoice, InvoiceService $invoiceService): InvoiceResource
    {
        Gate::authorize('send', $invoice);

        $invoice = $invoiceService->sendInvoice($invoice, $request->user());

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice marked as sent successfully']);
    }

    /**
     * Action: mark invoice as void.
     */
    public function void(Request $request, Invoice $invoice, InvoiceService $invoiceService): InvoiceResource
    {
        Gate::authorize('void', $invoice);

        $invoice = $invoiceService->voidInvoice($invoice, $request->user());

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice marked as void successfully']);
    }

    /**
     * Action: mark invoice as cancelled.
     */
    public function cancel(Request $request, Invoice $invoice, InvoiceService $invoiceService): InvoiceResource
    {
        Gate::authorize('cancel', $invoice);

        $invoice = $invoiceService->cancelInvoice($invoice, $request->user());

        return (new InvoiceResource($invoice))
            ->additional(['message' => 'Invoice cancelled successfully']);
    }

    /**
     * Download the invoice PDF.
     */
    public function pdf(Request $request, Invoice $invoice, InvoiceService $invoiceService): Response
    {
        Gate::authorize('view', $invoice);

        $pdf = $invoiceService->generatePdf($invoice);
        $filename = "invoice-{$invoice->invoice_number}.pdf";

        return $pdf->download($filename);
    }
}
