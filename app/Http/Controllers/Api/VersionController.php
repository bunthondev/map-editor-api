<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Version;
use App\Services\VersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    public function index(Request $request, Feature $feature): JsonResponse
    {
        $versions = Version::where('feature_id', $feature->id)
            ->with('user')
            ->orderBy('version_number', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json($versions);
    }

    public function show(int $id): JsonResponse
    {
        $version = Version::with(['feature', 'user'])->findOrFail($id);
        return response()->json($version);
    }

    public function restore(int $id): JsonResponse
    {
        $feature = VersioningService::restoreVersion($id);

        if (!$feature) {
            return response()->json(['message' => 'Version not found or restore failed'], 404);
        }

        // Refresh feature with geojson
        $feature = Feature::where('id', $feature->id)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->first();

        return response()->json([
            'message' => 'Version restored successfully',
            'feature' => [
                'type' => 'Feature',
                'id' => $feature->id,
                'geometry' => $feature->geojson_geometry,
                'properties' => array_merge(
                    $feature->properties ?? [],
                    ['_id' => $feature->id, '_layer_id' => $feature->layer_id]
                ),
            ],
        ]);
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version_id_1' => 'required|integer|exists:versions,id',
            'version_id_2' => 'required|integer|exists:versions,id',
        ]);

        $comparison = VersioningService::compareVersions(
            $validated['version_id_1'],
            $validated['version_id_2']
        );

        return response()->json($comparison);
    }

    public function getFeatureAtVersion(Request $request, int $featureId, int $versionNumber): JsonResponse
    {
        $version = Version::where('feature_id', $featureId)
            ->where('version_number', $versionNumber)
            ->firstOrFail();

        return response()->json([
            'type' => 'Feature',
            'id' => $featureId,
            'geometry' => $version->geometry,
            'properties' => $version->properties,
            'version_number' => $version->version_number,
            'created_at' => $version->created_at,
        ]);
    }
}
