<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'key',
    'endpoint',
    'response_status',
    'response_body',
    'expires_at',
  ];

  protected function casts(): array
  {
    return [
      'response_status' => 'integer',
      'response_body'   => 'array',
      'expires_at'      => 'datetime',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeValid($query)
  {
    return $query->where('expires_at', '>', now());
  }

  public function scopeExpired($query)
  {
    return $query->where('expires_at', '<=', now());
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function getIsExpiredAttribute(): bool
  {
    return $this->expires_at && $this->expires_at->isPast();
  }
}
