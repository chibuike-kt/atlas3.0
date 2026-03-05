<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryAdvance extends Model
{
  protected $fillable = [
    'id',
    'user_id',
    'connected_account_id',
    'amount',
    'fee',
    'repayment_amount',
    'repaid_amount',
    'status',
    'expected_salary_day',
    'due_date',
    'requested_at',
    'disbursed_at',
    'repaid_at',
  ];

  public $incrementing = false;
  protected $keyType   = 'string';

  protected function casts(): array
  {
    return [
      'amount'           => 'integer',
      'fee'              => 'integer',
      'repayment_amount' => 'integer',
      'repaid_amount'    => 'integer',
      'due_date'         => 'date',
      'requested_at'     => 'datetime',
      'disbursed_at'     => 'datetime',
      'repaid_at'        => 'datetime',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function connectedAccount(): BelongsTo
  {
    return $this->belongsTo(ConnectedAccount::class);
  }

  public function isActive(): bool
  {
    return in_array($this->status, ['pending', 'disbursed']);
  }

  public function isOverdue(): bool
  {
    return $this->status === 'disbursed'
      && $this->due_date
      && $this->due_date->isPast();
  }
}
