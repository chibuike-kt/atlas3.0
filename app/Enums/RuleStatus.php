<?php

namespace App\Enums;

enum RuleStatus: string
{
  case Active   = 'active';
  case Inactive = 'inactive';
  case Paused   = 'paused';
  case Deleted  = 'deleted';

  public function isExecutable(): bool
  {
    return $this === self::Active;
  }

  public function label(): string
  {
    return match ($this) {
      self::Active   => 'Active',
      self::Inactive => 'Inactive',
      self::Paused   => 'Paused',
      self::Deleted  => 'Deleted',
    };
  }
}
