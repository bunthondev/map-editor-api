<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use App\Models\Raster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class RasterController extends Controller
{
    /**
     * Upload and process a raster file
     */
    public function upload(Request $request, Layer $layer): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:tif,tiff,geotiff,png,jpg,jpeg|max:102400', // 100MB max
            'name' => 'required|string|max:255',
        ]);

        $file = $request->file('file');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = $file->storeAs('rasters/' . $layer->id, $fileName, 'public');
        $fullPath = Storage::disk('public')->path($filePath);

        // Get raster info using GDAL
        $info = $this->getRasterInfo($fullPath);

        if (!$info) {
            Storage::disk('public')->delete($filePath);
            return response()->json(['message' => 'Invalid raster file'], 422);
        }

        // Create raster record
        $raster = Raster::create([
            'layer_id' => $layer->id,
            'name' => $request->name,
            'file_path' => $filePath,
            'file_type' => $file->getClientOriginalExtension(),
            'file_size' => $file->getSize(),
            'bounds' => $info['bounds'],
            'width' => $info['width'],
            'height' => $info['height'],
            'bands' => $info['bands'],
            'color_interpretation' => $info['color_interpretation'],
            'metadata' => $info['metadata'],
            'is_tiled' => false,
        ]);

        // Update layer to raster type
        $layer->update([
            'layer_type' => 'raster',
            'raster_type' => 'single',
        ]);

        return response()->json([
            'message' => 'Raster uploaded successfully',
            'raster' => $raster,
        ], 201);
    }

    /**
     * List all rasters for a layer
     */
    public function index(Layer $layer): JsonResponse
    {
        $rasters = Raster::where('layer_id', $layer->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($rasters);
    }

    /**
     * Get single raster details
     */
    public function show(Raster $raster): JsonResponse
    {
        return response()->json($raster);
    }

    /**
     * Delete a raster
     */
    public function destroy(Raster $raster): JsonResponse
    {
        // Delete file
        Storage::disk('public')->delete($raster->file_path);
        
        // Delete tiles if exists
        if ($raster->tile_path) {
            Storage::disk('public')->deleteDirectory($raster->tile_path);
        }

        $raster->delete();

        return response()->json(['message' => 'Raster deleted']);
    }

    /**
     * Generate tiles for a raster
     */
    public function generateTiles(Request $request, Raster $raster): JsonResponse
    {
        $request->validate([
            'min_zoom' => 'integer|min:0|max:22',
            'max_zoom' => 'integer|min:0|max:22',
        ]);

        $minZoom = $request->input('min_zoom', 0);
        $maxZoom = $request->input('max_zoom', 18);
        
        $filePath = Storage::disk('public')->path($raster->file_path);
        $tileDir = 'rasters/tiles/' . $raster->id;
        $tilePath = Storage::disk('public')->path($tileDir);

        // Create tile directory
        if (!file_exists($tilePath)) {
            mkdir($tilePath, 0755, true);
        }

        // Use gdal2tiles.py or similar to generate tiles
        $result = Process::run([
            'python3',
            '-m',
            'osgeo_utils.gdal2tiles',
            '-z', "$minZoom-$maxZoom",
            '-w', 'none',
            '-p', 'mercator',
            $filePath,
            $tilePath,
        ]);

        if (!$result->successful()) {
            return response()->json([
                'message' => 'Tile generation failed',
                'error' => $result->errorOutput(),
            ], 422);
        }

        $raster->update([
            'is_tiled' => true,
            'tile_path' => $tileDir,
        ]);

        return response()->json([
            'message' => 'Tiles generated successfully',
            'raster' => $raster->fresh(),
        ]);
    }

    /**
     * Serve a tile from a raster
     */
    public function tile(Raster $raster, int $z, int $x, int $y): Response
    {
        if (!$raster->is_tiled || !$raster->tile_path) {
            return response('Tiles not generated', 404);
        }

        $tilePath = Storage::disk('public')->path(
            $raster->tile_path . '/' . $z . '/' . $x . '/' . $y . '.png'
        );

        if (!file_exists($tilePath)) {
            return response('', 204);
        }

        return response(file_get_contents($tilePath), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Get raster tile JSON (TileJSON)
     */
    public function tileJson(Raster $raster): JsonResponse
    {
        return response()->json([
            'tilejson' => '3.0.0',
            'name' => $raster->name,
            'description' => 'Raster tiles for ' . $raster->name,
            'version' => '1.0.0',
            'attribution' => 'Web GIS Editor',
            'tiles' => [
                url("/api/rasters/{$raster->id}/tiles/{z}/{x}/{y}.png"),
            ],
            'bounds' => $raster->bounds,
            'minzoom' => 0,
            'maxzoom' => 18,
        ]);
    }

    /**
     * Get raster as image (for preview)
     */
    public function preview(Raster $raster): Response
    {
        $filePath = Storage::disk('public')->path($raster->file_path);
        
        if (!file_exists($filePath)) {
            return response('File not found', 404);
        }

        // Generate thumbnail if too large
        if ($raster->width > 1024 || $raster->height > 1024) {
            $thumbPath = storage_path('app/temp/thumb_' . $raster->id . '.png');
            
            Process::run([
                'gdal_translate',
                '-outsize', '1024', '1024',
                '-of', 'PNG',
                $filePath,
                $thumbPath,
            ]);

            if (file_exists($thumbPath)) {
                $content = file_get_contents($thumbPath);
                unlink($thumbPath);
                return response($content, 200, [
                    'Content-Type' => 'image/png',
                ]);
            }
        }

        return response(file_get_contents($filePath), 200, [
            'Content-Type' => $this->getMimeType($raster->file_type),
        ]);
    }

    /**
     * Update raster settings
     */
    public function update(Request $request, Raster $raster): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
        ]);

        $raster->update($validated);

        return response()->json($raster);
    }

    /**
     * Get raster info using GDAL
     */
    private function getRasterInfo(string $filePath): ?array
    {
        $result = Process::run([
            'gdalinfo',
            '-json',
            $filePath,
        ]);

        if (!$result->successful()) {
            return null;
        }

        $info = json_decode($result->output(), true);

        if (!$info) {
            return null;
        }

        // Extract bounds from corner coordinates
        $bounds = null;
        if (isset($info['wgs84Extent'])) {
            $coords = $info['wgs84Extent']['coordinates'][0];
            $lons = array_column($coords, 0);
            $lats = array_column($coords, 1);
            $bounds = [
                min($lons),
                min($lats),
                max($lons),
                max($lats),
            ];
        }

        // Get bands count
        $bands = count($info['bands'] ?? []);

        // Get color interpretation
        $colorInterpretation = $info['bands'][0]['colorInterpretation'] ?? 'Unknown';

        return [
            'bounds' => $bounds,
            'width' => $info['size'][0] ?? null,
            'height' => $info['size'][1] ?? null,
            'bands' => $bands,
            'color_interpretation' => $colorInterpretation,
            'metadata' => $info['metadata'] ?? [],
        ];
    }

    private function getMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'tif', 'tiff' => 'image/tiff',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
