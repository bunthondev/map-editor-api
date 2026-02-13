<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Layer;
use App\Models\OfflineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OfflineController extends Controller
{
    /**
     * Sync layer data for offline use
     */
    public function sync(Request $request, Layer $layer): JsonResponse
    {
        $validated = $request->validate([
            'sync_type' => 'required|in:full,bbox,selected',
            'bbox' => 'required_if:sync_type,bbox|array',
            'bbox.0' => 'required_with:bbox|numeric',
            'bbox.1' => 'required_with:bbox|numeric',
            'bbox.2' => 'required_with:bbox|numeric',
            'bbox.3' => 'required_with:bbox|numeric',
            'feature_ids' => 'required_if:sync_type,selected|array',
            'expires_days' => 'integer|min:1|max:30',
        ]);

        $user = $request->user();
        $syncType = $validated['sync_type'];
        $expiresDays = $validated['expires_days'] ?? 7;

        // Build query based on sync type
        $query = Feature::where('layer_id', $layer->id)
            ->active()
            ->withGeoJson();

        if ($syncType === 'bbox' && isset($validated['bbox'])) {
            $query->withinBBox($validated['bbox']);
        } elseif ($syncType === 'selected' && isset($validated['feature_ids'])) {
            $query->whereIn('id', $validated['feature_ids']);
        }

        $features = $query->get();

        // Create or update sync record
        $sync = OfflineSync::updateOrCreate(
            [
                'user_id' => $user->id,
                'layer_id' => $layer->id,
            ],
            [
                'sync_type' => $syncType,
                'bbox' => $validated['bbox'] ?? null,
                'feature_ids' => $validated['feature_ids'] ?? null,
                'synced_at' => now(),
                'expires_at' => now()->addDays($expiresDays),
                'feature_count' => $features->count(),
                'metadata' => [
                    'layer_name' => $layer->name,
                    'geometry_type' => $layer->geometry_type,
                ],
            ]
        );

        return response()->json([
            'message' => 'Layer synced for offline use',
            'sync' => $sync,
            'features' => [
                'type' => 'FeatureCollection',
                'features' => $features->map(fn ($f) => [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => $f->geojson_geometry,
                    'properties' => array_merge(
                        $f->properties ?? [],
                        ['_id' => $f->id, '_layer_id' => $f->layer_id]
                    ),
                ]),
            ],
        ]);
    }

    /**
     * Get all offline data for the user
     */
    public function index(Request $request): JsonResponse
    {
        $syncs = OfflineSync::with('layer')
            ->forUser($request->user()->id)
            ->active()
            ->orderBy('synced_at', 'desc')
            ->get();

        return response()->json($syncs);
    }

    /**
     * Get offline data for a specific layer
     */
    public function show(Request $request, Layer $layer): JsonResponse
    {
        $sync = OfflineSync::with('layer')
            ->forUser($request->user()->id)
            ->forLayer($layer->id)
            ->active()
            ->first();

        if (!$sync) {
            return response()->json(['message' => 'No offline data found'], 404);
        }

        // Build query based on sync type
        $query = Feature::where('layer_id', $layer->id)
            ->active()
            ->withGeoJson();

        if ($sync->sync_type === 'bbox' && $sync->bbox) {
            $query->withinBBox($sync->bbox);
        } elseif ($sync->sync_type === 'selected' && $sync->feature_ids) {
            $query->whereIn('id', $sync->feature_ids);
        }

        $features = $query->get();

        return response()->json([
            'sync' => $sync,
            'features' => [
                'type' => 'FeatureCollection',
                'features' => $features->map(fn ($f) => [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => $f->geojson_geometry,
                    'properties' => array_merge(
                        $f->properties ?? [],
                        ['_id' => $f->id, '_layer_id' => $f->layer_id]
                    ),
                ]),
            ],
        ]);
    }

    /**
     * Delete offline sync record
     */
    public function destroy(Request $request, Layer $layer): JsonResponse
    {
        OfflineSync::forUser($request->user()->id)
            ->forLayer($layer->id)
            ->delete();

        return response()->json(['message' => 'Offline data removed']);
    }

    /**
     * Queue changes made offline to sync later
     */
    public function queueChanges(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => 'required|array',
            'changes.*.action' => 'required|in:create,update,delete',
            'changes.*.layer_id' => 'required|integer|exists:layers,id',
            'changes.*.feature_id' => 'nullable|integer',
            'changes.*.data' => 'required|array',
        ]);

        // Store queued changes (in a real app, use a queue table)
        // For now, we'll just acknowledge receipt
        return response()->json([
            'message' => 'Changes queued for sync',
            'queued_count' => count($validated['changes']),
        ]);
    }

    /**
     * Sync offline changes back to server
     */
    public function syncChanges(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'changes' => 'required|array',
            'changes.*.action' => 'required|in:create,update,delete',
            'changes.*.layer_id' => 'required|integer|exists:layers,id',
            'changes.*.feature_id' => 'nullable|integer',
            'changes.*.data' => 'required|array',
            'changes.*.offline_id' => 'string',
        ]);

        $results = [];
        
        foreach ($validated['changes'] as $change) {
            try {
                $result = match ($change['action']) {
                    'create' => $this->syncCreate($change),
                    'update' => $this->syncUpdate($change),
                    'delete' => $this->syncDelete($change),
                };
                
                $results[] = [
                    'offline_id' => $change['offline_id'] ?? null,
                    'success' => true,
                    'feature_id' => $result['id'] ?? null,
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'offline_id' => $change['offline_id'] ?? null,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => 'Changes synced',
            'results' => $results,
        ]);
    }

    private function syncCreate(array $change): array
    {
        $feature = new Feature();
        $feature->layer_id = $change['layer_id'];
        $feature->properties = $change['data']['properties'] ?? [];
        $feature->save();

        if (isset($change['data']['geometry'])) {
            $geojson = json_encode($change['data']['geometry']);
            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$geojson, $feature->id]
            );
        }

        return ['id' => $feature->id];
    }

    private function syncUpdate(array $change): array
    {
        $feature = Feature::findOrFail($change['feature_id']);

        if (isset($change['data']['properties'])) {
            $feature->properties = $change['data']['properties'];
            $feature->save();
        }

        if (isset($change['data']['geometry'])) {
            $geojson = json_encode($change['data']['geometry']);
            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$geojson, $feature->id]
            );
        }

        return ['id' => $feature->id];
    }

    private function syncDelete(array $change): array
    {
        $feature = Feature::findOrFail($change['feature_id']);
        $feature->update(['status' => 'history']);

        return ['id' => $feature->id];
    }
}
