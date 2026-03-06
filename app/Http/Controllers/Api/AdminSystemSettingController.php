<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemSettingController extends BaseApiController
{
  /**
   * GET /api/admin/settings
   */
  public function index(): JsonResponse
  {
    $settings = SystemSetting::orderBy('key')->get()
      ->map(fn($s) => $this->formatSetting($s));

    return $this->success($settings, 'Settings retrieved.');
  }

  /**
   * GET /api/admin/settings/{key}
   */
  public function show(string $key): JsonResponse
  {
    $setting = SystemSetting::where('key', $key)->first();

    if (! $setting) {
      return $this->notFound('Setting not found.');
    }

    return $this->success($this->formatSetting($setting));
  }

  /**
   * PUT /api/admin/settings/{key}
   */
  public function update(Request $request, string $key): JsonResponse
  {
    $setting = SystemSetting::where('key', $key)->first();

    if (! $setting) {
      return $this->notFound('Setting not found.');
    }

    $validated = $request->validate([
      'value' => ['required'],
    ]);

    // Type-cast the incoming value to match the setting's declared type
    $value = $this->castValue($validated['value'], $setting->type);

    $setting->update(['value' => (string) $value]);

    return $this->success($this->formatSetting($setting->fresh()), "Setting \"{$key}\" updated.");
  }

  /**
   * PUT /api/admin/settings (bulk update)
   */
  public function bulkUpdate(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'settings'       => ['required', 'array'],
      'settings.*.key' => ['required', 'string'],
      'settings.*.value' => ['required'],
    ]);

    $updated = [];

    foreach ($validated['settings'] as $item) {
      $setting = SystemSetting::where('key', $item['key'])->first();

      if (! $setting) {
        continue;
      }

      $value = $this->castValue($item['value'], $setting->type);
      $setting->update(['value' => (string) $value]);
      $updated[] = $this->formatSetting($setting->fresh());
    }

    return $this->success($updated, count($updated) . ' setting(s) updated.');
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function formatSetting(SystemSetting $setting): array
  {
    return [
      'key'         => $setting->key,
      'value'       => $setting->typedValue(),
      'type'        => $setting->type,
      'description' => $setting->description,
      'is_public'   => $setting->is_public,
      'updated_at'  => $setting->updated_at,
    ];
  }

  private function castValue(mixed $value, string $type): mixed
  {
    return match ($type) {
      'integer' => (int) $value,
      'float'   => (float) $value,
      'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
      default   => (string) $value,
    };
  }
}
