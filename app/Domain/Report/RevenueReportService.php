<?php

namespace App\Domain\Report;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class RevenueReportService
{
    /**
     * Generate the revenue report using optimized, aggregate database queries.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function generate(User $user, array $filters = []): array
    {
        $businessIds = $this->resolveAuthorizedBusinessIds($user, $filters['business_id'] ?? null);

        if (empty($businessIds)) {
            return $this->formatEmptyReport($filters);
        }

        $today = Carbon::today()->toDateString();
        $customerId = $filters['customer_id'] ?? $filters['customer'] ?? null;
        $status = $filters['payment_status'] ?? $filters['status'] ?? null;

        // Subquery aggregating paid amounts per invoice directly in SQL
        $paidSubquery = DB::table('payments')
            ->select('invoice_id', DB::raw('SUM(amount) as paid_amount'))
            ->where('status', PaymentStatus::Paid->value)
            ->whereIn('business_id', $businessIds)
            ->groupBy('invoice_id');

        // Base query joining invoices with the payment aggregates
        $query = DB::table('invoices')
            ->leftJoinSub($paidSubquery, 'p', function ($join) {
                $join->on('invoices.id', '=', 'p.invoice_id');
            })
            ->whereIn('invoices.business_id', $businessIds);

        // Filter: date_from
        if (! empty($filters['date_from'])) {
            $query->whereDate('invoices.issue_date', '>=', $filters['date_from']);
        }

        // Filter: date_to
        if (! empty($filters['date_to'])) {
            $query->whereDate('invoices.issue_date', '<=', $filters['date_to']);
        }

        // Filter: customer
        if (! empty($customerId)) {
            $query->where('invoices.customer_id', $customerId);
        }

        // Filter: payment_status
        if (! empty($status)) {
            if ($status === 'overdue') {
                $query->whereDate('invoices.due_date', '<', $today)
                    ->whereNotIn('invoices.status', [
                        InvoiceStatus::Paid->value,
                        InvoiceStatus::Void->value,
                        InvoiceStatus::Cancelled->value,
                    ]);
            } else {
                $query->where('invoices.status', $status);
            }
        }

        // Single optimized aggregate query computing all metrics in database
        $metrics = $query->selectRaw('
            COUNT(invoices.id) as total_invoices,
            COALESCE(SUM(CASE WHEN invoices.status NOT IN (?, ?) THEN invoices.total ELSE 0 END), 0) as total_invoiced,
            COALESCE(SUM(CASE WHEN invoices.status NOT IN (?, ?) THEN COALESCE(p.paid_amount, 0) ELSE 0 END), 0) as total_paid,
            COALESCE(SUM(CASE WHEN invoices.status NOT IN (?, ?, ?) THEN (invoices.total - COALESCE(p.paid_amount, 0)) ELSE 0 END), 0) as total_outstanding,
            COALESCE(SUM(CASE WHEN invoices.status NOT IN (?, ?, ?) AND invoices.due_date < ? THEN (invoices.total - COALESCE(p.paid_amount, 0)) ELSE 0 END), 0) as total_overdue
        ', [
            InvoiceStatus::Void->value,
            InvoiceStatus::Cancelled->value,
            InvoiceStatus::Void->value,
            InvoiceStatus::Cancelled->value,
            InvoiceStatus::Void->value,
            InvoiceStatus::Cancelled->value,
            InvoiceStatus::Paid->value,
            InvoiceStatus::Void->value,
            InvoiceStatus::Cancelled->value,
            InvoiceStatus::Paid->value,
            $today,
        ])->first();

        $totalInvoiced = (float) ($metrics->total_invoiced ?? 0.00);
        $totalPaid = (float) ($metrics->total_paid ?? 0.00);
        $totalOutstanding = (float) ($metrics->total_outstanding ?? 0.00);
        $totalOverdue = (float) ($metrics->total_overdue ?? 0.00);
        $totalInvoices = (int) ($metrics->total_invoices ?? 0);

        return [
            'data' => [
                'total_invoices' => $totalInvoices,
                'total_invoice' => $totalInvoices,
                'total_invoiced' => number_format($totalInvoiced, 2, '.', ''),
                'total_paid' => number_format($totalPaid, 2, '.', ''),
                'total_outstanding' => number_format($totalOutstanding, 2, '.', ''),
                'total_overdue' => number_format($totalOverdue, 2, '.', ''),
                'currency' => config('app.currency', 'IDR'),
            ],
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'customer' => $customerId,
                'customer_id' => $customerId,
                'payment_status' => $status,
                'business_id' => $filters['business_id'] ?? (count($businessIds) === 1 ? $businessIds[0] : null),
            ],
        ];
    }

    /**
     * Resolve the business IDs the authenticated user has access to.
     *
     * @return array<int, int>
     */
    protected function resolveAuthorizedBusinessIds(User $user, ?int $requestedBusinessId): array
    {
        if ($requestedBusinessId !== null) {
            $hasAccess = $user->ownedBusinesses()->where('id', $requestedBusinessId)->exists()
                || $user->businesses()->where('businesses.id', $requestedBusinessId)->exists();

            if (! $hasAccess) {
                throw new AccessDeniedHttpException('This action is unauthorized.');
            }

            return [$requestedBusinessId];
        }

        $ownedIds = $user->ownedBusinesses()->pluck('id')->all();
        $memberIds = $user->businesses()->pluck('businesses.id')->all();

        return array_values(array_unique(array_merge($ownedIds, $memberIds)));
    }

    /**
     * Return empty report structure when user has no accessible businesses.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function formatEmptyReport(array $filters): array
    {
        return [
            'data' => [
                'total_invoices' => 0,
                'total_invoice' => 0,
                'total_invoiced' => '0.00',
                'total_paid' => '0.00',
                'total_outstanding' => '0.00',
                'total_overdue' => '0.00',
                'currency' => config('app.currency', 'IDR'),
            ],
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'customer' => null,
                'customer_id' => null,
                'payment_status' => null,
                'business_id' => null,
            ],
        ];
    }
}
