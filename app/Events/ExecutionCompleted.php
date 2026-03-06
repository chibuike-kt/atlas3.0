<?php

namespace App\Events;

use App\Models\RuleExecution;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ExecutionCompleted
{
  use Dispatchable, SerializesModels;

  public function __construct(public readonly RuleExecution $execution) {}
}
