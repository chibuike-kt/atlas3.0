<?php
namespace App\Events;


use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SalaryDetected
{
  use Dispatchable, SerializesModels;

  public function __construct(
    public readonly User $user,
    public readonly int $amount
    ) {}
}
