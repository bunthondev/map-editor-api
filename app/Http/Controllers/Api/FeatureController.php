<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Layer;
use App\Services\AuditService;
use App\Services\VersioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

class FeatureController extends Controller
{
    public function index(Request $request, Layer $layer): JsonResponse
    {
        $query = Feature::where('layer_id', $layer->id)
            ->active()
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->withValidity();

        if ($request->has('bbox')) {
            $bbox = array_map('floatval', explode(',', $request->bbox));
            if (count($bbox) === 4) {
                $query->withinBBox($bbox);
            }
        }

        $features = $query->get();

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => $features->map(function ($f) {
                return [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => $f->geojson_geometry,
                    'properties' => array_merge(
                        $f->properties ?? [],
                        [
                            '_id' => $f->id,
                            '_layer_id' => $f->layer_id,
                            '_is_valid' => (bool) $f->is_valid,
                        ]
                    ),
                ];
            })->values(),
        ];

        return response()->json($featureCollection);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layer_id' => 'required|exists:layers,id',
            'geometry' => 'required|array',
            'geometry.type' => 'required|string',
            'geometry.coordinates' => 'required|array',
            'properties' => 'sometimes|array',
        ]);

        $geojson = json_encode($validated['geometry']);

        $feature = new Feature();
        $feature->layer_id = $validated['layer_id'];
        $feature->properties = $validated['properties'] ?? [];
        $feature->save();

        DB::statement(
            'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
            [$geojson, $feature->id]
        );

        $feature = Feature::where('id', $feature->id)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->first();

        // Log audit
        AuditService::logFeatureCreate($feature->id, [
            'layer_id' => $feature->layer_id,
            'geometry' => $feature->geojson_geometry,
            'properties' => $feature->properties,
        ]);

        // Create initial version
        VersioningService::createVersion($feature, 'Initial version');

        return response()->json([
            'type' => 'Feature',
            'id' => $feature->id,
            'geometry' => $feature->geojson_geometry,
            'properties' => array_merge(
                $feature->properties ?? [],
                ['_id' => $feature->id, '_layer_id' => $feature->layer_id]
            ),
        ], 201);
    }

    public function update(Request $request, Feature $feature): JsonResponse
    {
        $validated = $request->validate([
            'geometry' => 'sometimes|array',
            'geometry.type' => 'required_with:geometry|string',
            'geometry.coordinates' => 'required_with:geometry|array',
            'properties' => 'sometimes|array',
            'status' => 'sometimes|string|in:active,history',
        ]);

        // Get old values for audit
        $oldFeature = Feature::where('id', $feature->id)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->first();

        $oldValues = [
            'layer_id' => $oldFeature->layer_id,
            'geometry' => $oldFeature->geojson_geometry,
            'properties' => $oldFeature->properties,
            'status' => $oldFeature->status,
        ];

        if (isset($validated['properties'])) {
            $feature->properties = $validated['properties'];
            $feature->save();
        }

        if (isset($validated['status'])) {
            $feature->status = $validated['status'];
            $feature->save();
        }

        if (isset($validated['geometry'])) {
            $geojson = json_encode($validated['geometry']);
            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$geojson, $feature->id]
            );
        }

        $feature = Feature::where('id', $feature->id)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->first();

        // Log audit
        AuditService::logFeatureUpdate($feature->id, $oldValues, [
            'layer_id' => $feature->layer_id,
            'geometry' => $feature->geojson_geometry,
            'properties' => $feature->properties,
            'status' => $feature->status,
        ]);

        // Create new version
        $changeDesc = [];
        if (isset($validated['geometry'])) $changeDesc[] = 'geometry';
        if (isset($validated['properties'])) $changeDesc[] = 'properties';
        if (isset($validated['status'])) $changeDesc[] = 'status';
        VersioningService::createVersion($feature, 'Updated ' . implode(', ', $changeDesc));

        return response()->json([
            'type' => 'Feature',
            'id' => $feature->id,
            'geometry' => $feature->geojson_geometry,
            'properties' => array_merge(
                $feature->properties ?? [],
                ['_id' => $feature->id, '_layer_id' => $feature->layer_id]
            ),
        ]);
    }

    public function destroy(Feature $feature): JsonResponse
    {
        // Get old values before soft delete
        $oldValues = [
            'layer_id' => $feature->layer_id,
            'properties' => $feature->properties,
            'status' => $feature->status,
        ];

        $feature->update(['status' => 'history']);

        // Log audit
        AuditService::logFeatureDelete($feature->id, $oldValues);

        return response()->json(null, 204);
    }

    public function spatialOperation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'operation' => 'required|string|in:union,difference,intersection,buffer',
            'feature_ids' => 'required_without:feature_id|array|min:2|max:2',
            'feature_ids.*' => 'integer|exists:features,id',
            'feature_id' => 'required_if:operation,buffer|integer|exists:features,id',
            'distance' => 'required_if:operation,buffer|numeric|min:0',
            'layer_id' => 'required|exists:layers,id',
        ]);

        $operation = $validated['operation'];
        $layerId = $validated['layer_id'];

        if ($operation === 'buffer') {
            $featureId = $validated['feature_id'];
            $distance = $validated['distance']; // meters

            $result = DB::selectOne("
                SELECT ST_AsGeoJSON(
                    ST_Buffer(geography(geometry), ?)::geometry
                ) as geojson
                FROM features
                WHERE id = ? AND status = 'active'
            ", [$distance, $featureId]);

            if (!$result || !$result->geojson) {
                return response()->json(['message' => 'Operation failed'], 422);
            }

            // Create new feature with result
            $feature = new Feature();
            $feature->layer_id = $layerId;
            $feature->properties = ['_source' => 'buffer', '_source_id' => $featureId];
            $feature->save();

            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$result->geojson, $feature->id]
            );
        } else {
            $ids = $validated['feature_ids'];
            $stFunc = match ($operation) {
                'union' => 'ST_Union',
                'difference' => 'ST_Difference',
                'intersection' => 'ST_Intersection',
            };

            $result = DB::selectOne("
                SELECT ST_AsGeoJSON(
                    {$stFunc}(a.geometry, b.geometry)
                ) as geojson
                FROM features a, features b
                WHERE a.id = ? AND b.id = ?
                  AND a.status = 'active' AND b.status = 'active'
            ", [$ids[0], $ids[1]]);

            if (!$result || !$result->geojson) {
                return response()->json(['message' => 'Operation failed'], 422);
            }

            // Create new feature with result
            $feature = new Feature();
            $feature->layer_id = $layerId;
            $feature->properties = ['_source' => $operation, '_source_ids' => $ids];
            $feature->save();

            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$result->geojson, $feature->id]
            );
        }

        $feature = Feature::where('id', $feature->id)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->first();

        return response()->json([
            'type' => 'Feature',
            'id' => $feature->id,
            'geometry' => $feature->geojson_geometry,
            'properties' => array_merge(
                $feature->properties ?? [],
                ['_id' => $feature->id, '_layer_id' => $feature->layer_id]
            ),
        ], 201);
    }

    public function splitFeature(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'feature_id' => 'required|integer|exists:features,id',
            'split_geometry' => 'required|array',
            'split_geometry.type' => 'required|string|in:LineString',
            'split_geometry.coordinates' => 'required|array',
            'layer_id' => 'required|exists:layers,id',
        ]);

        $featureId = $validated['feature_id'];
        $layerId = $validated['layer_id'];
        $splitGeojson = json_encode($validated['split_geometry']);

        // Get the feature to be split
        $feature = Feature::where('id', $featureId)
            ->where('status', 'active')
            ->first();

        if (!$feature) {
            return response()->json(['message' => 'Feature not found'], 404);
        }

        // Perform ST_Split - returns a geometry collection
        $result = DB::selectOne("
            SELECT ST_AsGeoJSON(
                ST_Split(
                    geometry,
                    ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)
                )
            ) as geojson
            FROM features
            WHERE id = ? AND status = 'active'
        ", [$splitGeojson, $featureId]);

        if (!$result || !$result->geojson) {
            return response()->json(['message' => 'Split operation failed'], 422);
        }

        $splitResult = json_decode($result->geojson, true);

        // ST_Split returns a GeometryCollection, extract individual geometries
        $createdFeatures = [];
        if ($splitResult['type'] === 'GeometryCollection' && isset($splitResult['geometries'])) {
            foreach ($splitResult['geometries'] as $geom) {
                $newFeature = new Feature();
                $newFeature->layer_id = $layerId;
                $newFeature->properties = array_merge(
                    $feature->properties ?? [],
                    ['_source' => 'split', '_source_id' => $featureId]
                );
                $newFeature->save();

                $geomStr = json_encode($geom);
                DB::statement(
                    'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                    [$geomStr, $newFeature->id]
                );

                $createdFeatures[] = $newFeature->id;
            }
        }

        // Soft-delete the original feature
        $feature->update(['status' => 'history']);

        // Return the created features
        $features = Feature::whereIn('id', $createdFeatures)
            ->select('id', 'layer_id', 'properties', 'created_at', 'updated_at')
            ->withGeoJson()
            ->get();

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features->map(function ($f) {
                return [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => $f->geojson_geometry,
                    'properties' => array_merge(
                        $f->properties ?? [],
                        ['_id' => $f->id, '_layer_id' => $f->layer_id]
                    ),
                ];
            }),
            'split_count' => count($createdFeatures),
        ], 201);
    }

    public function spatialQuery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'layer_id' => 'required|exists:layers,id',
            'query_type' => 'required|string|in:intersects,within_radius,point_in_polygon',
            'geometry' => 'required_without:point|array',
            'geometry.type' => 'required_with:geometry|string',
            'geometry.coordinates' => 'required_with:geometry|array',
            'point' => 'required_without:geometry|array',
            'point.lng' => 'required_with:point|numeric',
            'point.lat' => 'required_with:point|numeric',
            'radius' => 'required_if:query_type,within_radius|numeric|min:0',
        ]);

        $layerId = $validated['layer_id'];
        $queryType = $validated['query_type'];

        if ($queryType === 'intersects') {
            $geojson = json_encode($validated['geometry']);
            $features = DB::select("
                SELECT id, layer_id, properties, ST_AsGeoJSON(geometry) as geojson_geometry,
                       created_at, updated_at
                FROM features
                WHERE layer_id = ? AND status = 'active'
                  AND ST_Intersects(geometry, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))
            ", [$layerId, $geojson]);
        } elseif ($queryType === 'within_radius') {
            $point = $validated['point'];
            $radius = $validated['radius']; // meters
            $features = DB::select("
                SELECT id, layer_id, properties, ST_AsGeoJSON(geometry) as geojson_geometry,
                       created_at, updated_at
                FROM features
                WHERE layer_id = ? AND status = 'active'
                  AND ST_DWithin(
                    geography(geometry),
                    geography(ST_SetSRID(ST_MakePoint(?, ?), 4326)),
                    ?
                  )
            ", [$layerId, $point['lng'], $point['lat'], $radius]);
        } elseif ($queryType === 'point_in_polygon') {
            $point = $validated['point'];
            $features = DB::select("
                SELECT id, layer_id, properties, ST_AsGeoJSON(geometry) as geojson_geometry,
                       created_at, updated_at
                FROM features
                WHERE layer_id = ? AND status = 'active'
                  AND ST_Contains(geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))
            ", [$layerId, $point['lng'], $point['lat']]);
        } else {
            return response()->json(['message' => 'Invalid query type'], 400);
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => collect($features)->map(function ($f) {
                $props = json_decode($f->properties, true) ?? [];
                return [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => json_decode($f->geojson_geometry, true),
                    'properties' => array_merge($props, [
                        '_id' => $f->id,
                        '_layer_id' => $f->layer_id,
                    ]),
                ];
            })->values(),
        ];

        return response()->json($featureCollection);
    }

    public function validateTopology(Layer $layer): JsonResponse
    {
        $errors = [];

        // 1. Self-intersection check (invalid geometries)
        $invalid = DB::select("
            SELECT id, ST_AsGeoJSON(ST_Centroid(geometry)) as location
            FROM features
            WHERE layer_id = ? AND status = 'active'
              AND NOT ST_IsValid(geometry)
        ", [$layer->id]);

        foreach ($invalid as $row) {
            $errors[] = [
                'type' => 'self_intersection',
                'feature_id' => $row->id,
                'message' => "Feature {$row->id} has invalid geometry (self-intersection)",
                'location' => json_decode($row->location, true),
            ];
        }

        // 2. Overlaps between polygon features
        $overlaps = DB::select("
            SELECT a.id as id_a, b.id as id_b,
                   ST_AsGeoJSON(ST_Centroid(ST_Intersection(a.geometry, b.geometry))) as location
            FROM features a
            JOIN features b ON a.id < b.id
            WHERE a.layer_id = ? AND b.layer_id = ?
              AND a.status = 'active' AND b.status = 'active'
              AND ST_GeometryType(a.geometry) LIKE '%Polygon%'
              AND ST_GeometryType(b.geometry) LIKE '%Polygon%'
              AND ST_Overlaps(a.geometry, b.geometry)
            LIMIT 100
        ", [$layer->id, $layer->id]);

        foreach ($overlaps as $row) {
            $errors[] = [
                'type' => 'overlap',
                'feature_ids' => [$row->id_a, $row->id_b],
                'message' => "Features {$row->id_a} and {$row->id_b} overlap",
                'location' => json_decode($row->location, true),
            ];
        }

        // 3. Duplicate geometries
        $duplicates = DB::select("
            SELECT a.id as id_a, b.id as id_b,
                   ST_AsGeoJSON(ST_Centroid(a.geometry)) as location
            FROM features a
            JOIN features b ON a.id < b.id
            WHERE a.layer_id = ? AND b.layer_id = ?
              AND a.status = 'active' AND b.status = 'active'
              AND ST_Equals(a.geometry, b.geometry)
            LIMIT 100
        ", [$layer->id, $layer->id]);

        foreach ($duplicates as $row) {
            $errors[] = [
                'type' => 'duplicate',
                'feature_ids' => [$row->id_a, $row->id_b],
                'message' => "Features {$row->id_a} and {$row->id_b} have identical geometry",
                'location' => json_decode($row->location, true),
            ];
        }

        return response()->json([
            'layer_id' => $layer->id,
            'error_count' => count($errors),
            'errors' => $errors,
        ]);
    }

    public function importShapefile(Request $request, Layer $layer): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:zip|max:51200', // 50MB max
        ]);

        $file = $request->file('file');
        $tmpDir = sys_get_temp_dir() . '/shp_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // Extract zip
        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            return response()->json(['message' => 'Failed to open ZIP file'], 422);
        }
        $zip->extractTo($tmpDir);
        $zip->close();

        // Find .shp file
        $shpFiles = glob($tmpDir . '/**/*.shp') ?: glob($tmpDir . '/*.shp');
        if (empty($shpFiles)) {
            $this->cleanupDir($tmpDir);
            return response()->json(['message' => 'No .shp file found in ZIP'], 422);
        }

        $shpFile = $shpFiles[0];
        $geojsonFile = $tmpDir . '/output.geojson';

        // Convert with ogr2ogr
        $result = Process::run([
            'ogr2ogr', '-f', 'GeoJSON',
            '-t_srs', 'EPSG:4326',
            $geojsonFile, $shpFile,
        ]);

        if (!$result->successful() || !file_exists($geojsonFile)) {
            $this->cleanupDir($tmpDir);
            return response()->json([
                'message' => 'ogr2ogr conversion failed',
                'error' => $result->errorOutput(),
            ], 422);
        }

        $geojson = json_decode(file_get_contents($geojsonFile), true);
        $this->cleanupDir($tmpDir);

        if (!$geojson || !isset($geojson['features'])) {
            return response()->json(['message' => 'Invalid GeoJSON output'], 422);
        }

        $count = 0;
        $importedIds = [];
        foreach ($geojson['features'] as $f) {
            if (!isset($f['geometry'])) continue;

            $feature = new Feature();
            $feature->layer_id = $layer->id;
            $feature->properties = $f['properties'] ?? [];
            $feature->save();

            $geoStr = json_encode($f['geometry']);
            DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [$geoStr, $feature->id]
            );
            $importedIds[] = $feature->id;
            $count++;
        }

        // Log audit
        AuditService::logImport('shapefile', $layer->id, $count);

        return response()->json([
            'message' => "Imported {$count} features",
            'count' => $count,
        ]);
    }

    public function exportShapefile(Layer $layer)
    {
        $features = Feature::where('layer_id', $layer->id)
            ->active()
            ->withGeoJson()
            ->get();

        if ($features->isEmpty()) {
            return response()->json(['message' => 'No features to export'], 422);
        }

        $featureCollection = [
            'type' => 'FeatureCollection',
            'features' => $features->map(function ($f) {
                return [
                    'type' => 'Feature',
                    'id' => $f->id,
                    'geometry' => $f->geojson_geometry,
                    'properties' => $f->properties ?? [],
                ];
            })->values(),
        ];

        $tmpDir = sys_get_temp_dir() . '/export_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $geojsonFile = $tmpDir . '/input.geojson';
        file_put_contents($geojsonFile, json_encode($featureCollection));

        $shpBaseName = 'layer_' . $layer->id;
        $shpFile = $tmpDir . '/' . $shpBaseName . '.shp';

        $result = Process::run([
            'ogr2ogr', '-f', 'ESRI Shapefile',
            '-lco', 'ENCODING=UTF-8',
            $shpFile, $geojsonFile,
        ]);

        if (!$result->successful()) {
            $this->cleanupDir($tmpDir);
            return response()->json([
                'message' => 'ogr2ogr conversion failed',
                'error' => $result->errorOutput(),
            ], 422);
        }

        $zipFile = $tmpDir . '/' . $shpBaseName . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) {
            $this->cleanupDir($tmpDir);
            return response()->json(['message' => 'Failed to create ZIP file'], 500);
        }

        $extensions = ['shp', 'shx', 'dbf', 'prj', 'cpg'];
        foreach ($extensions as $ext) {
            $file = $tmpDir . '/' . $shpBaseName . '.' . $ext;
            if (file_exists($file)) {
                $zip->addFile($file, $shpBaseName . '.' . $ext);
            }
        }
        $zip->close();

        // Log audit
        AuditService::logExport('shapefile', $layer->id);

        $response = response()->download($zipFile, $layer->name . '.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);

        register_shutdown_function(function () use ($tmpDir) {
            $this->cleanupDir($tmpDir);
        });

        return $response;
    }

    private function cleanupDir(string $dir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
