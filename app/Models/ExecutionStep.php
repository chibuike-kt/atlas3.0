<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionStep extends Model
{
  protected $fillable = [
    'id',
    'execution_id',
    'user_id',
    'step_order',
    'action_type',
    'label',
    'amount',
    'currency',
    'amount_type',
    'status',
    'config',
    'rail_reference',
    'result',
    'failure_reason',
    'rolled_back',
    'rollback_reference',
    'executed_at',
    'rolled_back_at',
  ];

  public $incrementing = false;
  protected $keyType   = 'string';

  protected function casts(): array
  {
    return [
      'action_type'    => 'string',
      'amount_type'    => 'string',
      'status'         => ExecutionStatus::class,
      'config'         => 'array',
      'result'         => 'array',
      'rolled_back'    => 'boolean',
      'executed_at'    => 'datetime',
      'rolled_back_at' => 'datetime',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function execution(): BelongsTo
  {
    return $this->belongsTo(RuleExecution::class, 'execution_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getAmountFormattedAttribute(): string
  {
    return '₦' . number_format($this->amount / 100, 2);
  }

  public function getIsCompletedAttribute(): bool
  {
    return $this->status === ExecutionStatus::Completed;
  }

  public function getIsFailedAttribute(): bool
  {
    return $this->status === ExecutionStatus::Failed;
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

  public function scopeRollbackable($query)
  {
    return $query->where('status', ExecutionStatus::Completed->value)
      ->where('rolled_back', false);
  }

  // ── State helpers ─────────────────────────────────────────────────────

  public function markCompleted(string $reference, array $result = []): void
  {
    $this->update([
      'status'         => ExecutionStatus::Completed,
      'rail_reference' => $reference,
      'result'         => $result,
      'executed_at'    => now(),
    ]);
  }

  public function markFailed(string $reason): void
  {
    $this->update([
      'status'         => ExecutionStatus::Failed,
      'failure_reason' => $reason,
      'executed_at'    => now(),
    ]);
  }

  public function markRolledBack(string $reference): void
  {
    $this->update([
      'status'             => ExecutionStatus::RolledBack,
      'rolled_back'        => true,
      'rollback_reference' => $reference,
      'rolled_back_at'     => now(),
    ]);
  }
}
