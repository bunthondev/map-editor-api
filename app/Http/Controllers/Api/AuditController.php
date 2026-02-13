<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user')->orderBy('created_at', 'desc');

        // Filter by entity type and id
        if ($request->has('entity_type') && $request->has('entity_id')) {
            $query->forEntity($request->entity_type, $request->entity_id);
        }

        // Filter by action
        if ($request->has('action')) {
            $query->byAction($request->action);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->from);
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        // Default to recent (last 30 days)
        if (!$request->has('from') && !$request->has('to')) {
            $query->recent(30);
        }

        $perPage = $request->input('per_page', 50);
        $logs = $query->paginate($perPage);

        return response()->json($logs);
    }

    public function show(int $id): JsonResponse
    {
        $log = AuditLog::with('user')->findOrFail($id);
        return response()->json($log);
    }

    public function forFeature(Request $request, int $featureId): JsonResponse
    {
        $logs = AuditLog::with('user')
            ->forEntity('feature', $featureId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($logs);
    }

    public function forLayer(Request $request, int $layerId): JsonResponse
    {
        $logs = AuditLog::with('user')
            ->forEntity('layer', $layerId)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 50));

        return response()->json($logs);
    }

    public function summary(): JsonResponse
    {
        $today = now()->startOfDay();
        $week = now()->subDays(7)->startOfDay();
        $month = now()->subDays(30)->startOfDay();

        $summary = [
            'today' => [
                'total' => AuditLog::where('created_at', '>=', $today)->count(),
                'creates' => AuditLog::where('created_at', '>=', $today)->byAction('create')->count(),
                'updates' => AuditLog::where('created_at', '>=', $today)->byAction('update')->count(),
                'deletes' => AuditLog::where('created_at', '>=', $today)->byAction('delete')->count(),
            ],
            'week' => [
                'total' => AuditLog::where('created_at', '>=', $week)->count(),
                'creates' => AuditLog::where('created_at', '>=', $week)->byAction('create')->count(),
                'updates' => AuditLog::where('created_at', '>=', $week)->byAction('update')->count(),
                'deletes' => AuditLog::where('created_at', '>=', $week)->byAction('delete')->count(),
            ],
            'month' => [
                'total' => AuditLog::where('created_at', '>=', $month)->count(),
                'creates' => AuditLog::where('created_at', '>=', $month)->byAction('create')->count(),
                'updates' => AuditLog::where('created_at', '>=', $month)->byAction('update')->count(),
                'deletes' => AuditLog::where('created_at', '>=', $month)->byAction('delete')->count(),
            ],
            'by_entity' => AuditLog::selectRaw('entity_type, count(*) as count')
                ->groupBy('entity_type')
                ->orderBy('count', 'desc')
                ->limit(10)
                ->get(),
        ];

        return response()->json($summary);
    }
}
