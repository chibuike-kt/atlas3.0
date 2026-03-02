<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'execution_id',
    'step_id',
    'entry_type',
    'description',
    'amount',
    'currency',
    'running_balance',
    'reference',
    'counterpart_reference',
    'meta',
    'posted_at',
  ];

  protected function casts(): array
  {
    return [
      'amount'          => 'integer',
      'running_balance' => 'integer',
      'posted_at'       => 'datetime',
      'meta'            => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getAmountFormattedAttribute(): string
  {
    return '₦' . number_format($this->amount / 100, 2);
  }

  public function getIsDebitAttribute(): bool
  {
    return in_array($this->entry_type, ['debit', 'fee']);
  }

  public function getIsCreditAttribute(): bool
  {
    return in_array($this->entry_type, ['credit', 'refund', 'reversal']);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeForExecution($query, string $executionId)
  {
    return $query->where('execution_id', $executionId);
  }

  public function scopeDebits($query)
  {
    return $query->whereIn('entry_type', ['debit', 'fee']);
  }

  public function scopeCredits($query)
  {
    return $query->whereIn('entry_type', ['credit', 'refund', 'reversal']);
  }

  public function scopeThisMonth($query)
  {
    return $query->whereMonth('posted_at', now()->month)
      ->whereYear('posted_at', now()->year);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public static function generateReference(): string
  {
    return 'ATL-' . strtoupper(substr(md5(uniqid()), 0, 12));
  }
}
