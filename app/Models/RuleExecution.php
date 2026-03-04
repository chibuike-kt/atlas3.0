<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\TriggerType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RuleExecution extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'rule_id',
    'user_id',
    'connected_account_id',
    'idempotency_key',
    'status',
    'trigger_type',
    'total_amount',
    'total_fee',
    'total_debited',
    'steps_total',
    'steps_completed',
    'steps_failed',
    'failure_reason',
    'rolled_back',
    'balance_before',
    'balance_after',
    'started_at',
    'completed_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'status'          => ExecutionStatus::class,
      'trigger_type'    => TriggerType::class,
      'total_amount'    => 'integer',
      'total_fee'       => 'integer',
      'total_debited'   => 'integer',
      'steps_total'     => 'integer',
      'steps_completed' => 'integer',
      'steps_failed'    => 'integer',
      'rolled_back'     => 'boolean',
      'balance_before'  => 'integer',
      'balance_after'   => 'integer',
      'started_at'      => 'datetime',
      'completed_at'    => 'datetime',
      'meta'            => 'array',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function rule(): BelongsTo
  {
    return $this->belongsTo(Rule::class);
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function connectedAccount(): BelongsTo
  {
    return $this->belongsTo(ConnectedAccount::class);
  }

  public function steps(): HasMany
  {
    return $this->hasMany(ExecutionStep::class, 'execution_id')->orderBy('step_order');
  }

  public function receipt(): HasOne
  {
    return $this->hasOne(Receipt::class, 'execution_id');
  }

  public function dispute(): HasOne
  {
    return $this->hasOne(Dispute::class, 'execution_id');
  }

  public function feeLedgerEntries(): HasMany
  {
    return $this->hasMany(FeeLedger::class, 'execution_id');
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

  public function getDurationSecondsAttribute(): ?int
  {
    if (! $this->started_at || ! $this->completed_at) {
      return null;
    }

    return $this->completed_at->diffInSeconds($this->started_at);
  }

  public function getIsSuccessfulAttribute(): bool
  {
    return $this->status === ExecutionStatus::Completed;
  }

  public function getIsDisputedAttribute(): bool
  {
    return $this->dispute()->exists();
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeCompleted($query)
  {
    return $query->where('status', ExecutionStatus::Completed->value);
  }

  public function scopeFailed($query)
  {
    return $query->where('status', ExecutionStatus::Failed->value);
  }

  public function scopeRecent($query, int $days = 30)
  {
    return $query->where('created_at', '>=', now()->subDays($days));
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function markRunning(): void
  {
    $this->update([
      'status'     => ExecutionStatus::Running,
      'started_at' => now(),
    ]);
  }

  public function markCompleted(int $totalAmount, int $totalFee): void
  {
    $this->update([
      'status'       => ExecutionStatus::Completed,
      'total_amount' => $totalAmount,
      'total_fee'    => $totalFee,
      'total_debited' => $totalAmount + $totalFee,
      'completed_at' => now(),
    ]);
  }

  public function isCompleted(): bool
  {
    return $this->status === ExecutionStatus::Completed;
  }

  public function isFailed(): bool
  {
    return $this->status === ExecutionStatus::Failed;
  }

  public function isPending(): bool
  {
    return $this->status === ExecutionStatus::Pending;
  }

  public function markFailed(string $reason): void
  {
    $this->update([
      'status'         => ExecutionStatus::Failed,
      'failure_reason' => $reason,
      'completed_at'   => now(),
    ]);
  }

  public function markRolledBack(): void
  {
    $this->update([
      'status'      => ExecutionStatus::RolledBack,
      'rolled_back' => true,
      'completed_at' => now(),
    ]);
  }
}
