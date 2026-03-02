<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'connected_account_id',
    'mono_transaction_id',
    'type',
    'amount',
    'balance_after',
    'currency',
    'description',
    'narration',
    'reference',
    'category',
    'sub_category',
    'is_salary',
    'is_family_transfer',
    'is_ajo',
    'is_bill_payment',
    'is_atlas_execution',
    'confidence_score',
    'counterparty_name',
    'counterparty_account',
    'counterparty_bank',
    'transaction_date',
    'processed_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'type'               => TransactionType::class,
      'amount'             => 'integer',
      'balance_after'      => 'integer',
      'is_salary'          => 'boolean',
      'is_family_transfer' => 'boolean',
      'is_ajo'             => 'boolean',
      'is_bill_payment'    => 'boolean',
      'is_atlas_execution' => 'boolean',
      'confidence_score'   => 'float',
      'transaction_date'   => 'date',
      'processed_at'       => 'datetime',
      'meta'               => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function connectedAccount(): BelongsTo
  {
    return $this->belongsTo(ConnectedAccount::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getAmountFormattedAttribute(): string
  {
    return '₦' . number_format($this->amount / 100, 2);
  }

  public function getAmountNairaAttribute(): float
  {
    return $this->amount / 100;
  }

  public function getIsDebitAttribute(): bool
  {
    return $this->type === TransactionType::Debit;
  }

  public function getIsCreditAttribute(): bool
  {
    return $this->type === TransactionType::Credit;
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeCredits($query)
  {
    return $query->where('type', TransactionType::Credit->value);
  }

  public function scopeDebits($query)
  {
    return $query->where('type', TransactionType::Debit->value);
  }

  public function scopeSalaries($query)
  {
    return $query->where('is_salary', true);
  }

  public function scopeInCategory($query, string $category)
  {
    return $query->where('category', $category);
  }

  public function scopeInDateRange($query, string $from, string $to)
  {
    return $query->whereBetween('transaction_date', [$from, $to]);
  }

  public function scopeThisMonth($query)
  {
    return $query->whereMonth('transaction_date', now()->month)
      ->whereYear('transaction_date', now()->year);
  }

  public function scopeLastNDays($query, int $days)
  {
    return $query->where('transaction_date', '>=', now()->subDays($days)->toDateString());
  }
}
