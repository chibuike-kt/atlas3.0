<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'execution_id',
    'dispute_number',
    'reason',
    'description',
    'status',
    'amount_disputed',
    'refund_amount',
    'resolution_note',
    'resolved_by',
    'opened_at',
    'reviewed_at',
    'resolved_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'amount_disputed' => 'integer',
      'refund_amount'   => 'integer',
      'opened_at'       => 'datetime',
      'reviewed_at'     => 'datetime',
      'resolved_at'     => 'datetime',
      'meta'            => 'array',
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

  public function getAmountDisputedFormattedAttribute(): string
  {
    return '₦' . number_format(($this->amount_disputed ?? 0) / 100, 2);
  }

  public function getRefundAmountFormattedAttribute(): string
  {
    return '₦' . number_format(($this->refund_amount ?? 0) / 100, 2);
  }

  public function getIsOpenAttribute(): bool
  {
    return $this->status === 'open';
  }

  public function getIsResolvedAttribute(): bool
  {
    return in_array($this->status, ['resolved_refund', 'resolved_no_action', 'closed']);
  }

  public function getStatusLabelAttribute(): string
  {
    return match ($this->status) {
      'open'                => 'Open',
      'under_review'        => 'Under Review',
      'resolved_refund'     => 'Resolved — Refund Issued',
      'resolved_no_action'  => 'Resolved — No Action',
      'closed'              => 'Closed',
      default               => ucfirst($this->status),
    };
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeOpen($query)
  {
    return $query->where('status', 'open');
  }

  public function scopePending($query)
  {
    return $query->whereIn('status', ['open', 'under_review']);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public static function generateDisputeNumber(): string
  {
    $year     = now()->year;
    $latest   = static::whereYear('opened_at', $year)->count();
    $sequence = str_pad($latest + 1, 5, '0', STR_PAD_LEFT);

    return "DSP-{$year}-{$sequence}";
  }
}
