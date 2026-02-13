<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Layer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'parent_id',
        'name',
        'layer_type',
        'source_url',
        'wms_layers',
        'geometry_type',
        'visible',
        'sort_order',
        'style',
        'schema',
    ];

    protected function casts(): array
    {
        return [
            'visible' => 'boolean',
            'style' => 'array',
            'schema' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Layer::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Layer::class, 'parent_id')->orderBy('sort_order');
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }
}
