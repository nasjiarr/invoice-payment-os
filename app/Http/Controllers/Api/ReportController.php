<?php

namespace App\Http\Controllers\Api;

use App\Domain\Report\RevenueReportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Report\RevenueReportRequest;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    /**
     * Display revenue report metrics.
     */
    public function revenue(RevenueReportRequest $request, RevenueReportService $service): JsonResponse
    {
        $report = $service->generate($request->user(), $request->validated());

        return response()->json([
            'message' => 'Revenue report generated successfully',
            'data' => $report['data'],
            'filters' => $report['filters'],
        ]);
    }
}
