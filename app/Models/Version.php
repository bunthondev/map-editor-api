<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Version extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'feature_id',
        'user_id',
        'version_number',
        'geometry',
        'properties',
        'change_description',
        'created_at',
    ];

    protected $casts = [
        'geometry' => 'array',
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForFeature($query, int $featureId)
    {
        return $query->where('feature_id', $featureId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderBy('version_number', 'desc');
    }
}
