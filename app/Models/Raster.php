<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Raster extends Model
{
    use HasFactory;

    protected $fillable = [
        'layer_id',
        'name',
        'file_path',
        'file_type',
        'file_size',
        'bounds',
        'width',
        'height',
        'bands',
        'color_interpretation',
        'metadata',
        'is_tiled',
        'tile_path',
    ];

    protected $casts = [
        'bounds' => 'array',
        'metadata' => 'array',
        'is_tiled' => 'boolean',
    ];

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function scopeForLayer($query, int $layerId)
    {
        return $query->where('layer_id', $layerId);
    }

    public function scopeTiled($query)
    {
        return $query->where('is_tiled', true);
    }
}
