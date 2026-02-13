<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineSync extends Model
{
    use HasFactory;

    protected $table = 'offline_sync';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'layer_id',
        'sync_type',
        'bbox',
        'feature_ids',
        'synced_at',
        'expires_at',
        'feature_count',
        'metadata',
    ];

    protected $casts = [
        'bbox' => 'array',
        'feature_ids' => 'array',
        'metadata' => 'array',
        'synced_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(Layer::class);
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForLayer($query, int $layerId)
    {
        return $query->where('layer_id', $layerId);
    }
}
