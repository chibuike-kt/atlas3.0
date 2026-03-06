<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
  use HasFactory, HasUuids;

  protected $fillable = [
    'key',
    'value',
    'type',
    'description',
    'is_public',
  ];

  protected function casts(): array
  {
    return [
      'is_public' => 'boolean',
    ];
  }

  private const CACHE_TTL = 3600; // 1 hour

  // ── Static helpers ────────────────────────────────────────────────────

  public static function getValue(string $key, mixed $default = null): mixed
  {
    $cacheKey = "system_setting:{$key}";

    return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
      $setting = static::where('key', $key)->first();

      if (! $setting) {
        return $default;
      }

      return $setting->castValue();
    });
  }

  public static function setValue(string $key, mixed $value): void
  {
    static::updateOrCreate(
      ['key' => $key],
      ['value' => (string) $value]
    );

    Cache::forget("system_setting:{$key}");
  }

  public function typedValue(): mixed
  {
    return match ($this->type) {
      'integer' => (int) $this->value,
      'float'   => (float) $this->value,
      'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
      default   => $this->value,
    };
  }

  public static function getPublicSettings(): array
  {
    return Cache::remember('system_settings:public', self::CACHE_TTL, function () {
      return static::where('is_public', true)
        ->get()
        ->mapWithKeys(fn($s) => [$s->key => $s->castValue()])
        ->toArray();
    });
  }

  // ── Instance helpers ──────────────────────────────────────────────────

  public function castValue(): mixed
  {
    return match ($this->type) {
      'integer' => (int) $this->value,
      'float'   => (float) $this->value,
      'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
      'json'    => json_decode($this->value, true),
      default   => $this->value,
    };
  }
}
