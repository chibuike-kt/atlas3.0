<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class ConnectedAccount extends Model
{
  use HasFactory, HasUuids, SoftDeletes;

  protected $fillable = [
    'user_id',
    'mono_account_id',
    'mono_auth_code',
    'institution',
    'bank_code',
    'account_name',
    'account_number',
    'account_type',
    'balance',
    'currency',
    'is_primary',
    'is_active',
    'last_synced_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'balance'        => 'integer',
      'is_primary'     => 'boolean',
      'is_active'      => 'boolean',
      'last_synced_at' => 'datetime',
      'meta'           => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function transactions(): HasMany
  {
    return $this->hasMany(Transaction::class);
  }

  public function rules(): HasMany
  {
    return $this->hasMany(Rule::class);
  }

  public function executions(): HasMany
  {
    return $this->hasMany(RuleExecution::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getBalanceFormattedAttribute(): string
  {
    return '₦' . number_format($this->balance / 100, 2);
  }

  public function getBalanceNairaAttribute(): float
  {
    return $this->balance / 100;
  }

  public function getMaskedAccountNumberAttribute(): string
  {
    return '****' . substr($this->account_number, -4);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeActive($query)
  {
    return $query->where('is_active', true);
  }

  public function scopePrimary($query)
  {
    return $query->where('is_primary', true);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function hasSufficientBalance(int $amountKobo): bool
  {
    return $this->balance >= $amountKobo;
  }

  public function deductBalance(int $amountKobo): void
  {
    $this->decrement('balance', $amountKobo);
  }

  public function creditBalance(int $amountKobo): void
  {
    $this->increment('balance', $amountKobo);
  }
}
