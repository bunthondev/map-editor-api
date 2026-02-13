<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FeatureController;
use App\Http\Controllers\Api\LayerController;
use App\Http\Controllers\Api\OfflineController;
use App\Http\Controllers\Api\RasterController;
use App\Http\Controllers\Api\VectorTileController;
use App\Http\Controllers\Api\VersionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Read-only routes (all authenticated users)
    Route::get('/layers', [LayerController::class, 'index']);
    Route::get('/layers/{layer}', [LayerController::class, 'show']);
    Route::get('/layers/{layer}/features', [FeatureController::class, 'index']);
    Route::get('/layers/{layer}/validate', [FeatureController::class, 'validateTopology']);
    Route::post('/features/spatial-query', [FeatureController::class, 'spatialQuery']);

    // Editor routes (admin + editor)
    Route::middleware('role:editor')->group(function () {
        Route::post('/layers', [LayerController::class, 'store']);
        Route::put('/layers/{layer}', [LayerController::class, 'update']);
        Route::delete('/layers/{layer}', [LayerController::class, 'destroy']);
        Route::post('/layers/reorder', [LayerController::class, 'reorder']);

        Route::post('/features', [FeatureController::class, 'store']);
        Route::put('/features/{feature}', [FeatureController::class, 'update']);
        Route::delete('/features/{feature}', [FeatureController::class, 'destroy']);
        Route::post('/features/spatial-operation', [FeatureController::class, 'spatialOperation']);
        Route::post('/features/split', [FeatureController::class, 'splitFeature']);
        Route::post('/layers/{layer}/import-shapefile', [FeatureController::class, 'importShapefile']);
        Route::get('/layers/{layer}/export-shapefile', [FeatureController::class, 'exportShapefile']);

        // Audit logs (admin only)
        Route::get('/audit-logs', [AuditController::class, 'index']);
        Route::get('/audit-logs/summary', [AuditController::class, 'summary']);
        Route::get('/audit-logs/{id}', [AuditController::class, 'show']);
        Route::get('/features/{featureId}/audit-logs', [AuditController::class, 'forFeature']);
        Route::get('/layers/{layerId}/audit-logs', [AuditController::class, 'forLayer']);

        // Versioning
        Route::get('/features/{feature}/versions', [VersionController::class, 'index']);
        Route::get('/versions/{id}', [VersionController::class, 'show']);
        Route::post('/versions/{id}/restore', [VersionController::class, 'restore']);
        Route::post('/versions/compare', [VersionController::class, 'compare']);
        Route::get('/features/{featureId}/versions/{versionNumber}', [VersionController::class, 'getFeatureAtVersion']);

        // Vector tiles
        Route::get('/layers/{layer}/tiles/{z}/{x}/{y}.mvt', [VectorTileController::class, 'tile']);
        Route::get('/layers/{layer}/tiles/metadata', [VectorTileController::class, 'metadata']);
        Route::post('/layers/{layer}/tiles/enable', [VectorTileController::class, 'enable']);
        Route::post('/layers/{layer}/tiles/disable', [VectorTileController::class, 'disable']);

        // Offline mode
        Route::get('/offline', [OfflineController::class, 'index']);
        Route::post('/layers/{layer}/offline/sync', [OfflineController::class, 'sync']);
        Route::get('/layers/{layer}/offline', [OfflineController::class, 'show']);
        Route::delete('/layers/{layer}/offline', [OfflineController::class, 'destroy']);
        Route::post('/offline/queue', [OfflineController::class, 'queueChanges']);
        Route::post('/offline/sync', [OfflineController::class, 'syncChanges']);

        // Raster editing
        Route::get('/layers/{layer}/rasters', [RasterController::class, 'index']);
        Route::post('/layers/{layer}/rasters', [RasterController::class, 'upload']);
        Route::get('/rasters/{raster}', [RasterController::class, 'show']);
        Route::put('/rasters/{raster}', [RasterController::class, 'update']);
        Route::delete('/rasters/{raster}', [RasterController::class, 'destroy']);
        Route::post('/rasters/{raster}/tiles', [RasterController::class, 'generateTiles']);
        Route::get('/rasters/{raster}/tiles/{z}/{x}/{y}.png', [RasterController::class, 'tile']);
        Route::get('/rasters/{raster}/tiles/metadata', [RasterController::class, 'tileJson']);
        Route::get('/rasters/{raster}/preview', [RasterController::class, 'preview']);
    });
});
