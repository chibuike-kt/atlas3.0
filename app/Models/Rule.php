<?php

namespace App\Models;

use App\Enums\RuleStatus;
use App\Enums\TriggerType;
use App\Enums\AmountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rule extends Model
{
  use HasFactory, HasUuids, SoftDeletes;

  protected $fillable = [
    'user_id',
    'connected_account_id',
    'name',
    'rule_text',
    'description',
    'status',
    'trigger_type',
    'trigger_config',
    'total_amount_type',
    'total_amount',
    'actions',
    'is_ai_suggested',
    'execution_count',
    'success_count',
    'fail_count',
    'total_amount_moved',
    'last_triggered_at',
    'next_trigger_at',
    'meta',
  ];

  protected function casts(): array
  {
    return [
      'status'            => RuleStatus::class,
      'trigger_type'      => TriggerType::class,
      'total_amount_type' => AmountType::class,
      'trigger_config'    => 'array',
      'actions'           => 'array',
      'total_amount'      => 'integer',
      'is_ai_suggested'   => 'boolean',
      'execution_count'   => 'integer',
      'success_count'     => 'integer',
      'fail_count'        => 'integer',
      'total_amount_moved' => 'integer',
      'last_triggered_at' => 'datetime',
      'next_trigger_at'   => 'datetime',
      'meta'              => 'array',
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

  public function executions(): HasMany
  {
    return $this->hasMany(RuleExecution::class);
  }

  public function latestExecution()
  {
    return $this->hasOne(RuleExecution::class)->latestOfMany();
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getStepCountAttribute(): int
  {
    return count($this->actions ?? []);
  }

  public function getTotalAmountFormattedAttribute(): string
  {
    if (! $this->total_amount) {
      return 'Variable';
    }

    return '₦' . number_format($this->total_amount / 100, 2);
  }

  public function getTotalAmountMovedFormattedAttribute(): string
  {
    return '₦' . number_format($this->total_amount_moved / 100, 2);
  }

  public function getSuccessRateAttribute(): float
  {
    if (empty($this->execution_count)) {
      return 0;
    }

    return round(($this->success_count / $this->execution_count) * 100, 1);
  }

  public function getIsActiveAttribute(): bool
  {
    return $this->status === RuleStatus::Active;
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeActive($query)
  {
    return $query->where('status', RuleStatus::Active->value);
  }

  public function scopeScheduled($query)
  {
    return $query->where('trigger_type', TriggerType::Schedule->value);
  }

  public function scopeDueForExecution($query)
  {
    $window = config('atlas.scheduler.time_window_minutes', 2);

    return $query->active()
      ->scheduled()
      ->where('next_trigger_at', '<=', now()->addMinutes($window));
  }

  public function scopeAiSuggested($query)
  {
    return $query->where('is_ai_suggested', true);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function isExecutable(): bool
  {
    return $this->status->isExecutable()
      && $this->connectedAccount->is_active;
  }

  public function markTriggered(): void
  {
    $this->update([
      'last_triggered_at' => now(),
      'execution_count'   => $this->execution_count + 1,
    ]);
  }

  public function recordSuccess(int $amountMoved): void
  {
    $this->increment('success_count');
    $this->increment('total_amount_moved', $amountMoved);
  }

  public function recordFailure(): void
  {
    $this->increment('fail_count');
  }
}
