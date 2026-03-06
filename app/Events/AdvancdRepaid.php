<?php

namespace App\Events;

use App\Models\SalaryAdvance;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdvanceRepaid
{
  use Dispatchable, SerializesModels;

  public function __construct(public readonly SalaryAdvance $advance) {}
}
