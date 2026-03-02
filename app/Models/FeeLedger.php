<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeLedger extends Model
{
  use HasFactory, HasUuids;

  protected $table = 'fee_ledger';

  protected $fillable = [
    'user_id',
    'execution_id',
    'fee_type',
    'amount',
    'currency',
    'description',
    'breakdown',
    'charged_at',
  ];

  protected function casts(): array
  {
    return [
      'amount'     => 'integer',
      'breakdown'  => 'array',
      'charged_at' => 'datetime',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function execution(): BelongsTo
  {
    return $this->belongsTo(RuleExecution::class, 'execution_id');
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getAmountFormattedAttribute(): string
  {
    return '₦' . number_format($this->amount / 100, 2);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeThisMonth($query)
  {
    return $query->whereMonth('charged_at', now()->month)
      ->whereYear('charged_at', now()->year);
  }

  public function scopeOfType($query, string $type)
  {
    return $query->where('fee_type', $type);
  }
}
