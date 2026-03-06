<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPayment extends Model
{
  protected $fillable = [
    'id',
    'user_id',
    'connected_account_id',
    'bill_type',
    'provider',
    'variation_code',
    'biller_code',
    'phone',
    'amount',
    'fee',
    'reference',
    'provider_reference',
    'status',
    'token',
    'response_data',
    'paid_at',
  ];

  public $incrementing = false;
  protected $keyType   = 'string';

  protected function casts(): array
  {
    return [
      'amount'        => 'integer',
      'fee'           => 'integer',
      'response_data' => 'array',
      'paid_at'       => 'datetime',
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
}
