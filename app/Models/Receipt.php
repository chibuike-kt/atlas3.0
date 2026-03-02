<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'execution_id',
    'receipt_number',
    'rule_name',
    'total_amount',
    'total_fee',
    'total_debited',
    'currency',
    'status',
    'steps_summary',
    'issued_at',
  ];

  protected function casts(): array
  {
    return [
      'total_amount'  => 'integer',
      'total_fee'     => 'integer',
      'total_debited' => 'integer',
      'steps_summary' => 'array',
      'issued_at'     => 'datetime',
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

  public function getTotalAmountFormattedAttribute(): string
  {
    return '₦' . number_format($this->total_amount / 100, 2);
  }

  public function getTotalFeeFormattedAttribute(): string
  {
    return '₦' . number_format($this->total_fee / 100, 2);
  }

  public function getTotalDebitedFormattedAttribute(): string
  {
    return '₦' . number_format($this->total_debited / 100, 2);
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeThisMonth($query)
  {
    return $query->whereMonth('issued_at', now()->month)
      ->whereYear('issued_at', now()->year);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public static function generateReceiptNumber(): string
  {
    $year    = now()->year;
    $latest  = static::whereYear('issued_at', $year)->count();
    $sequence = str_pad($latest + 1, 5, '0', STR_PAD_LEFT);

    return "ATL-{$year}-{$sequence}";
  }
}
