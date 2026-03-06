<?php

namespace App\Events;

use App\Models\Dispute;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DisputeResolved
{
  use Dispatchable, SerializesModels;

  public function __construct(
    public readonly Dispute $dispute
    ) {}
}
