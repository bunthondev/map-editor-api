<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Feature extends Model
{
    use HasFactory;

    protected $fillable = [
        'layer_id',
        'properties',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    protected $appends = [];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(Version::class)->orderBy('version_number', 'desc');
    }

    public function getGeometryDataAttribute()
    {
        $raw = $this->attributes['geojson_geometry'] ?? null;
        return $raw ? json_decode($raw, true) : null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWithGeoJson($query)
    {
        return $query->addSelect(DB::raw("ST_AsGeoJSON(geometry) as geojson_geometry"));
    }

    public function scopeWithValidity($query)
    {
        return $query->addSelect(DB::raw("ST_IsValid(geometry) as is_valid"));
    }

    public function scopeWithinBBox($query, array $bbox)
    {
        return $query->whereRaw(
            'geometry && ST_MakeEnvelope(?, ?, ?, ?, 4326)',
            [$bbox[0], $bbox[1], $bbox[2], $bbox[3]]
        );
    }

    public function getGeojsonGeometryAttribute()
    {
        $raw = $this->attributes['geojson_geometry'] ?? null;
        return $raw ? json_decode($raw, true) : null;
    }
}
