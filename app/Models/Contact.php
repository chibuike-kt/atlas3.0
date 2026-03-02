<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
  use HasFactory, HasUuids, SoftDeletes;

  protected $fillable = [
    'user_id',
    'label',
    'type',
    'account_name',
    'account_number',
    'bank_code',
    'bank_name',
    'wallet_address',
    'crypto_network',
    'wallet_label',
    'usage_count',
    'last_used_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'usage_count'  => 'integer',
      'last_used_at' => 'datetime',
      'meta'         => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getIsBankContactAttribute(): bool
  {
    return $this->type === 'bank';
  }

  public function getIsCryptoContactAttribute(): bool
  {
    return $this->type === 'crypto';
  }

  public function getMaskedAccountNumberAttribute(): ?string
  {
    if (! $this->account_number) {
      return null;
    }

    return '****' . substr($this->account_number, -4);
  }

  public function getMaskedWalletAddressAttribute(): ?string
  {
    if (! $this->wallet_address) {
      return null;
    }

    return substr($this->wallet_address, 0, 6) . '...' . substr($this->wallet_address, -4);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeBank($query)
  {
    return $query->where('type', 'bank');
  }

  public function scopeCrypto($query)
  {
    return $query->where('type', 'crypto');
  }

  public function scopeFrequent($query, int $limit = 5)
  {
    return $query->orderByDesc('usage_count')->limit($limit);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function recordUsage(): void
  {
    $this->update([
      'usage_count'  => $this->usage_count + 1,
      'last_used_at' => now(),
    ]);
  }
}
