<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public static function log(
        string $action,
        string $entityType,
        int $entityId,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $user = Request::user();
        
        if (!$user) {
            return;
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    public static function logFeatureCreate(int $featureId, array $data): void
    {
        self::log('create', 'feature', $featureId, null, $data);
    }

    public static function logFeatureUpdate(int $featureId, array $oldData, array $newData): void
    {
        self::log('update', 'feature', $featureId, $oldData, $newData);
    }

    public static function logFeatureDelete(int $featureId, array $data): void
    {
        self::log('delete', 'feature', $featureId, $data, null);
    }

    public static function logLayerCreate(int $layerId, array $data): void
    {
        self::log('create', 'layer', $layerId, null, $data);
    }

    public static function logLayerUpdate(int $layerId, array $oldData, array $newData): void
    {
        self::log('update', 'layer', $layerId, $oldData, $newData);
    }

    public static function logLayerDelete(int $layerId, array $data): void
    {
        self::log('delete', 'layer', $layerId, $data, null);
    }

    public static function logExport(string $format, int $layerId): void
    {
        self::log('export', 'layer', $layerId, null, ['format' => $format]);
    }

    public static function logImport(string $format, int $layerId, int $count): void
    {
        self::log('import', 'layer', $layerId, null, ['format' => $format, 'count' => $count]);
    }
}
