<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsageLog;
use App\Services\ReportService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use Concerns;

    public function __invoke(Request $request, ReportService $reports): JsonResponse
    {
        $organization = $this->organization($request);
        $days = min(max((int) $request->query('days', 30), 1), 365);
        $from = now()->subDays($days - 1)->startOfDay();

        $query = fn () => UsageLog::query()->whereIn('app_id', $organization->apps()->select('id'))->where('created_at', '>=', $from);

        return response()->json([
            'balance' => Money::toUsd((int) $organization->apps()->sum('balance')),
            'apps_count' => $organization->apps()->count(),
            'totals' => $reports->totals($query(), false),
            'daily' => $reports->daily($query(), false),
            'by_model' => $reports->grouped($query(), 'model', false),
            'by_app' => $reports->grouped($query(), 'app_id', false),
        ]);
    }
}
