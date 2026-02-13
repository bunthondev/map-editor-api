<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Layer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class VectorTileController extends Controller
{
    /**
     * Generate a vector tile (MVT format) for a layer at specific zoom/x/y
     */
    public function tile(Layer $layer, int $z, int $x, int $y): Response
    {
        // Check if vector tiles are enabled for this layer
        if (!$layer->vector_tile_enabled) {
            return response('Vector tiles not enabled for this layer', 404);
        }

        // Check zoom level bounds
        if ($layer->min_zoom !== null && $z < $layer->min_zoom) {
            return response('', 204);
        }
        if ($layer->max_zoom !== null && $z > $layer->max_zoom) {
            return response('', 204);
        }

        // Get tile bounds in Web Mercator (EPSG:3857)
        $bounds = $this->tileToBounds($x, $y, $z);

        // Query features within tile bounds using PostGIS MVT generation
        $mvt = DB::selectOne("
            SELECT ST_AsMVT(q, 'layer', 4096, 'geom') as tile
            FROM (
                SELECT 
                    id,
                    layer_id,
                    properties,
                    ST_AsMVTGeom(
                        ST_Transform(geometry, 3857),
                        ST_MakeEnvelope(?, ?, ?, ?, 3857),
                        4096,
                        256,
                        true
                    ) as geom
                FROM features
                WHERE layer_id = ?
                    AND status = 'active'
                    AND geometry && ST_Transform(ST_MakeEnvelope(?, ?, ?, ?, 3857), 4326)
            ) q
        ", [
            $bounds['minX'], $bounds['minY'], $bounds['maxX'], $bounds['maxY'],
            $layer->id,
            $bounds['minX'], $bounds['minY'], $bounds['maxX'], $bounds['maxY'],
        ]);

        // Return the tile
        if ($mvt && $mvt->tile) {
            return response($mvt->tile, 200, [
                'Content-Type' => 'application/vnd.mapbox-vector-tile',
                'Cache-Control' => 'public, max-age=3600',
            ]);
        }

        // Return empty tile
        return response('', 204);
    }

    /**
     * Get tile metadata for a layer
     */
    public function metadata(Layer $layer): array
    {
        return [
            'tilejson' => '3.0.0',
            'name' => $layer->name,
            'description' => "Vector tiles for layer: {$layer->name}",
            'version' => '1.0.0',
            'attribution' => 'Web GIS Editor',
            'tiles' => [
                url("/api/layers/{$layer->id}/tiles/{z}/{x}/{y}.mvt"),
            ],
            'minzoom' => $layer->min_zoom ?? 0,
            'maxzoom' => $layer->max_zoom ?? 22,
            'bounds' => $layer->tile_bounds ?? [-180, -90, 180, 90],
            'vector_layers' => [
                [
                    'id' => 'layer',
                    'description' => $layer->name,
                    'fields' => [
                        'id' => 'Number',
                        'layer_id' => 'Number',
                        'properties' => 'String',
                    ],
                ],
            ],
        ];
    }

    /**
     * Enable vector tiles for a layer
     */
    public function enable(Layer $layer): array
    {
        // Calculate bounds from features
        $bounds = DB::selectOne("
            SELECT 
                ST_XMin(extent) as min_x,
                ST_YMin(extent) as min_y,
                ST_XMax(extent) as max_x,
                ST_YMax(extent) as max_y
            FROM (
                SELECT ST_Extent(geometry) as extent
                FROM features
                WHERE layer_id = ? AND status = 'active'
            ) sub
        ", [$layer->id]);

        $layer->update([
            'vector_tile_enabled' => true,
            'min_zoom' => 0,
            'max_zoom' => 22,
            'tile_bounds' => $bounds ? [
                (float) $bounds->min_x,
                (float) $bounds->min_y,
                (float) $bounds->max_x,
                (float) $bounds->max_y,
            ] : null,
        ]);

        return [
            'message' => 'Vector tiles enabled',
            'layer' => $layer->fresh(),
        ];
    }

    /**
     * Disable vector tiles for a layer
     */
    public function disable(Layer $layer): array
    {
        $layer->update(['vector_tile_enabled' => false]);

        return [
            'message' => 'Vector tiles disabled',
            'layer' => $layer->fresh(),
        ];
    }

    /**
     * Convert tile coordinates to bounds in Web Mercator
     */
    private function tileToBounds(int $x, int $y, int $z): array
    {
        $n = pow(2, $z);
        
        // Convert tile x,y to longitude/latitude
        $minLon = ($x / $n) * 360 - 180;
        $maxLon = (($x + 1) / $n) * 360 - 180;
        
        $minLat = rad2deg(atan(sinh(pi() * (1 - 2 * ($y + 1) / $n))));
        $maxLat = rad2deg(atan(sinh(pi() * (1 - 2 * $y / $n))));

        // Convert to Web Mercator (EPSG:3857)
        $minX = $this->lonToMercatorX($minLon);
        $maxX = $this->lonToMercatorX($maxLon);
        $minY = $this->latToMercatorY($minLat);
        $maxY = $this->latToMercatorY($maxLat);

        return [
            'minX' => $minX,
            'minY' => $minY,
            'maxX' => $maxX,
            'maxY' => $maxY,
        ];
    }

    private function lonToMercatorX(float $lon): float
    {
        return $lon * 20037508.34 / 180;
    }

    private function latToMercatorY(float $lat): float
    {
        return log(tan((90 + $lat) * pi() / 360)) / (pi() / 180) * 20037508.34 / 180;
    }
}
