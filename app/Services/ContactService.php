<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Str;

class ContactService
{
  /**
   * Resolve a bank account name via Mono or local cache.
   * In sandbox, returns a mock name.
   */
  public function resolveAccountName(string $accountNumber, string $bankCode): string
  {
    if (config('app.env') !== 'production') {
      return 'Sandbox Account Holder';
    }

    try {
      $response = \Illuminate\Support\Facades\Http::withHeaders([
        'mono-sec-key' => config('atlas.mono.secret_key'),
      ])->post(config('atlas.mono.base_url') . '/v1/lookup/account/resolve', [
        'account_number' => $accountNumber,
        'bank_code'      => $bankCode,
      ]);

      return $response->json('data.account_name', 'Unknown');
    } catch (\Throwable) {
      return 'Unknown';
    }
  }

  /**
   * Create or update a contact.
   */
  public function save(User $user, array $data): Contact
  {
    // Check for duplicate account number + bank combo
    $existing = $user->contacts()
      ->where('account_number', $data['account_number'])
      ->where('bank_code', $data['bank_code'])
      ->first();

    if ($existing) {
      $existing->update([
        'name'  => $data['name'],
        'label' => $data['label'] ?? $existing->label,
      ]);

      return $existing->fresh();
    }

    // Resolve account name if not provided
    if (empty($data['account_name'])) {
      $data['account_name'] = $this->resolveAccountName(
        $data['account_number'],
        $data['bank_code']
      );
    }

    return Contact::create([
      'id'             => Str::uuid(),
      'user_id'        => $user->id,
      'name'           => $data['name'],
      'label'          => $data['label'] ?? null,
      'account_number' => $data['account_number'],
      'bank_code'      => $data['bank_code'],
      'bank_name'      => $data['bank_name'] ?? $this->resolveBankName($data['bank_code']),
      'account_name'   => $data['account_name'],
      'is_favourite'   => $data['is_favourite'] ?? false,
    ]);
  }

  /**
   * Toggle favourite status.
   */
  public function toggleFavourite(Contact $contact): Contact
  {
    $contact->update(['is_favourite' => ! $contact->is_favourite]);
    return $contact->fresh();
  }

  /**
   * Resolve bank name from code using atlas config.
   */
  private function resolveBankName(string $bankCode): string
  {
    $banks = collect(config('atlas.banks', []));
    $bank  = $banks->firstWhere('code', $bankCode);

    return $bank['name'] ?? 'Unknown Bank';
  }
}
