<?php

namespace App\Enums;

enum ExecutionStatus: string
{
  case Pending   = 'pending';
  case Running   = 'running';
  case Completed = 'completed';
  case Failed    = 'failed';
  case RolledBack = 'rolled_back';

  public function isTerminal(): bool
  {
    return in_array($this, [self::Completed, self::Failed, self::RolledBack]);
  }

  public function label(): string
  {
    return match ($this) {
      self::Pending    => 'Pending',
      self::Running    => 'Running',
      self::Completed  => 'Completed',
      self::Failed     => 'Failed',
      self::RolledBack => 'Rolled Back',
    };
  }
}
