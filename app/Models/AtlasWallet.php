<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AtlasWallet extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'network',
    'token',
    'deposit_address',
    'balance',
    'total_deposited',
    'total_withdrawn',
    'last_activity_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'balance'          => 'decimal:8',
      'total_deposited'  => 'decimal:8',
      'total_withdrawn'  => 'decimal:8',
      'last_activity_at' => 'datetime',
      'meta'             => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getNetworkLabelAttribute(): string
  {
    return strtoupper($this->network);
  }

  public function getBalanceInNairaAttribute(): float
  {
    $rate = (float) SystemSetting::getValue('usd_ngn_rate', 1600);
    return (float) $this->balance * $rate;
  }

  public function getBalanceInNairaFormattedAttribute(): string
  {
    return '₦' . number_format($this->balance_in_naira, 2);
  }

  public function getMaskedAddressAttribute(): string
  {
    return substr($this->deposit_address, 0, 6) . '...' . substr($this->deposit_address, -4);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeForNetwork($query, string $network)
  {
    return $query->where('network', $network);
  }

  public function scopeWithBalance($query)
  {
    return $query->where('balance', '>', 0);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function hasSufficientBalance(float $amount): bool
  {
    return (float) $this->balance >= $amount;
  }

  public function credit(float $amount): void
  {
    $this->increment('balance', $amount);
    $this->increment('total_deposited', $amount);
    $this->update(['last_activity_at' => now()]);
  }

  public function debit(float $amount): void
  {
    $this->decrement('balance', $amount);
    $this->increment('total_withdrawn', $amount);
    $this->update(['last_activity_at' => now()]);
  }
}
