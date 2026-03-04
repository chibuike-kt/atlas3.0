<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'id',
    'user_id',
    'name',
    'label',
    'account_number',
    'bank_code',
    'bank_name',
    'account_name',
    'wallet_address',
    'crypto_network',
    'wallet_label',
    'is_favourite',
    'last_used_at',
    'meta',
  ];

  public $incrementing = false;
  protected $keyType   = 'string';

  protected function casts(): array
  {
    return [
      'is_favourite' => 'boolean',
      'last_used_at' => 'datetime',
      'meta'         => 'array',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
