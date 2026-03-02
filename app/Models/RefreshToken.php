<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'token_hash',
    'device_name',
    'device_fingerprint',
    'ip_address',
    'user_agent',
    'is_revoked',
    'last_used_at',
    'expires_at',
  ];

  protected function casts(): array
  {
    return [
      'is_revoked'   => 'boolean',
      'last_used_at' => 'datetime',
      'expires_at'   => 'datetime',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getIsExpiredAttribute(): bool
  {
    return $this->expires_at && $this->expires_at->isPast();
  }

  public function getIsValidAttribute(): bool
  {
    return ! $this->is_revoked && ! $this->is_expired;
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeValid($query)
  {
    return $query->where('is_revoked', false)
      ->where('expires_at', '>', now());
  }

  public function scopeExpired($query)
  {
    return $query->where('expires_at', '<=', now());
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function revoke(): void
  {
    $this->update(['is_revoked' => true]);
  }

  public function recordUsage(): bool
  {
    return $this->update(['last_used_at' => now()]);
  }

  public static function hashToken(string $token): string
  {
    return hash('sha256', $token);
  }
}
