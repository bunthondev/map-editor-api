<?php

namespace App\Services;

use App\Models\Feature;
use App\Models\Version;
use Illuminate\Support\Facades\Request;

class VersioningService
{
    public static function createVersion(Feature $feature, ?string $changeDescription = null): Version
    {
        $user = Request::user();
        
        // Get the next version number
        $latestVersion = Version::where('feature_id', $feature->id)
            ->orderBy('version_number', 'desc')
            ->first();
        
        $versionNumber = $latestVersion ? $latestVersion->version_number + 1 : 1;

        return Version::create([
            'feature_id' => $feature->id,
            'user_id' => $user?->id ?? 1,
            'version_number' => $versionNumber,
            'geometry' => $feature->geometry_data ?? [],
            'properties' => $feature->properties ?? [],
            'change_description' => $changeDescription,
            'created_at' => now(),
        ]);
    }

    public static function restoreVersion(int $versionId): ?Feature
    {
        $version = Version::find($versionId);
        
        if (!$version) {
            return null;
        }

        $feature = Feature::find($version->feature_id);
        
        if (!$feature) {
            return null;
        }

        // Create a new version before restoring (to preserve current state)
        self::createVersion($feature, 'Before restore to version ' . $version->version_number);

        // Restore the feature
        $feature->properties = $version->properties;
        $feature->save();

        // Update geometry
        if ($version->geometry) {
            \Illuminate\Support\Facades\DB::statement(
                'UPDATE features SET geometry = ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)) WHERE id = ?',
                [json_encode($version->geometry), $feature->id]
            );
        }

        // Create version for the restore action
        self::createVersion($feature, 'Restored to version ' . $version->version_number);

        return $feature;
    }

    public static function getVersions(int $featureId)
    {
        return Version::where('feature_id', $featureId)
            ->with('user')
            ->orderBy('version_number', 'desc')
            ->get();
    }

    public static function compareVersions(int $versionId1, int $versionId2): array
    {
        $v1 = Version::find($versionId1);
        $v2 = Version::find($versionId2);

        if (!$v1 || !$v2) {
            return [];
        }

        return [
            'version_1' => $v1->version_number,
            'version_2' => $v2->version_number,
            'geometry_changed' => $v1->geometry !== $v2->geometry,
            'properties_changed' => $v1->properties !== $v2->properties,
            'properties_diff' => self::diffProperties($v1->properties ?? [], $v2->properties ?? []),
        ];
    }

    private static function diffProperties(array $props1, array $props2): array
    {
        $diff = [];
        $allKeys = array_unique(array_merge(array_keys($props1), array_keys($props2)));

        foreach ($allKeys as $key) {
            $val1 = $props1[$key] ?? null;
            $val2 = $props2[$key] ?? null;

            if ($val1 !== $val2) {
                $diff[$key] = [
                    'old' => $val1,
                    'new' => $val2,
                ];
            }
        }

        return $diff;
    }
}
