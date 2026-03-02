<?php

namespace App\Models;

use App\Enums\InsightType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvisoryInsight extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'user_id',
    'type',
    'title',
    'body',
    'priority',
    'is_urgent',
    'is_read',
    'is_dismissed',
    'is_actioned',
    'action_payload',
    'data',
    'cta_label',
    'cta_action',
    'expires_at',
    'read_at',
    'actioned_at',
  ];

  protected function casts(): array
  {
    return [
      'type'           => InsightType::class,
      'priority'       => 'integer',
      'is_urgent'      => 'boolean',
      'is_read'        => 'boolean',
      'is_dismissed'   => 'boolean',
      'is_actioned'    => 'boolean',
      'action_payload' => 'array',
      'data'           => 'array',
      'expires_at'     => 'datetime',
      'read_at'        => 'datetime',
      'actioned_at'    => 'datetime',
    ];
  }

  // ── Relationships ─────────────────────────────────────────────────────

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  // ── Accessors ─────────────────────────────────────────────────────────

  public function getIsExpiredAttribute(): bool
  {
    return $this->expires_at && $this->expires_at->isPast();
  }

  public function getIsVisibleAttribute(): bool
  {
    return ! $this->is_dismissed && ! $this->is_expired;
  }

  // ── Scopes ────────────────────────────────────────────────────────────

  public function scopeUnread($query)
  {
    return $query->where('is_read', false);
  }

  public function scopeVisible($query)
  {
    return $query->where('is_dismissed', false)
      ->where(function ($q) {
        $q->whereNull('expires_at')
          ->orWhere('expires_at', '>', now());
      });
  }

  public function scopeUrgent($query)
  {
    return $query->where('is_urgent', true);
  }

  public function scopeByPriority($query)
  {
    return $query->orderBy('priority')->orderByDesc('created_at');
  }

  public function scopeOfType($query, InsightType $type)
  {
    return $query->where('type', $type->value);
  }

  // ── Helpers ───────────────────────────────────────────────────────────

  public function markRead(): void
  {
    if (! $this->is_read) {
      $this->update([
        'is_read' => true,
        'read_at' => now(),
      ]);
    }
  }

  public function markActioned(): void
  {
    $this->update([
      'is_actioned'  => true,
      'is_read'      => true,
      'actioned_at'  => now(),
      'read_at'      => $this->read_at ?? now(),
    ]);
  }

  public function dismiss(): void
  {
    $this->update(['is_dismissed' => true]);
  }
}
