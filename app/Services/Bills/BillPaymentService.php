<?php

namespace App\Services\Bills;

use App\Models\BillPayment;
use App\Models\ConnectedAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BillPaymentService
{
  public function __construct(private readonly VTpassService $vtpass) {}

  /**
   * Pay a bill directly (one-time payment).
   */
  public function pay(User $user, array $data): BillPayment
  {
    $account   = $user->primaryAccount;
    $amountKobo = (int) $data['amount'];
    $reference  = 'BILL-' . strtoupper(Str::random(12));

    if (! $account) {
      throw new \RuntimeException('No active bank account found.');
    }

    if (! $account->hasSufficientBalance($amountKobo)) {
      throw new \RuntimeException(
        'Insufficient balance. Need ₦' . number_format($amountKobo / 100, 2) .
          ', have ₦' . number_format($account->balance / 100, 2) . '.'
      );
    }

    return DB::transaction(function () use ($user, $account, $data, $amountKobo, $reference) {
      // Execute the payment via VTpass
      $result = $this->executePayment($data, $amountKobo, $reference);

      // Deduct balance
      $account->deductBalance($amountKobo);

      // Record the payment
      $payment = BillPayment::create([
        'id'                   => Str::uuid(),
        'user_id'              => $user->id,
        'connected_account_id' => $account->id,
        'bill_type'            => $data['bill_type'],
        'provider'             => $data['provider'],
        'variation_code'       => $data['variation_code'] ?? null,
        'biller_code'          => $data['biller_code'] ?? null,
        'phone'                => $data['phone'] ?? null,
        'amount'               => $amountKobo,
        'fee'                  => 0,
        'reference'            => $reference,
        'provider_reference'   => $result['requestId'] ?? $reference,
        'status'               => 'successful',
        'token'                => $result['purchased_code'] ?? null,
        'response_data'        => $result,
        'paid_at'              => now(),
      ]);

      // Write ledger entry
      LedgerEntry::create([
        'id'             => Str::uuid(),
        'user_id'        => $user->id,
        'entry_type'     => 'debit',
        'description'    => $this->billDescription($data),
        'amount'         => $amountKobo,
        'currency'       => 'NGN',
        'running_balance' => $account->fresh()->balance,
        'reference'      => $reference,
        'posted_at'      => now(),
      ]);

      Log::info('Bill payment successful', [
        'user_id'    => $user->id,
        'bill_type'  => $data['bill_type'],
        'amount'     => $amountKobo,
        'reference'  => $reference,
      ]);

      return $payment;
    });
  }

  /**
   * Get available variations for a service.
   */
  public function getVariations(string $serviceId): array
  {
    return $this->vtpass->getVariations($serviceId);
  }

  /**
   * Verify a meter number or smart card.
   */
  public function verify(string $serviceId, string $billersCode, ?string $type = null): array
  {
    return $this->vtpass->verify($serviceId, $billersCode, $type);
  }

  /**
   * Get bill payment history for a user.
   */
  public function history(User $user, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
  {
    return $user->billPayments()
      ->orderByDesc('paid_at')
      ->paginate($perPage);
  }

  // ── Private helpers ───────────────────────────────────────────────────

  private function executePayment(array $data, int $amountKobo, string $reference): array
  {
    return match ($data['bill_type']) {
      'airtime'     => $this->vtpass->buyAirtime(
        $data['provider'],
        $data['phone'],
        $amountKobo,
        $reference
      ),
      'data'        => $this->vtpass->buyData(
        $data['provider'],
        $data['phone'],
        $data['variation_code'],
        $reference
      ),
      'electricity' => $this->vtpass->payElectricity(
        $data['provider'],
        $data['biller_code'],
        $data['meter_type'] ?? 'prepaid',
        $amountKobo,
        $data['phone'],
        $reference
      ),
      'cable'       => $this->vtpass->payCable(
        $data['provider'],
        $data['biller_code'],
        $data['variation_code'],
        $data['phone'],
        $reference
      ),
      default => throw new \RuntimeException("Unsupported bill type: {$data['bill_type']}"),
    };
  }

  private function billDescription(array $data): string
  {
    return match ($data['bill_type']) {
      'airtime'     => ucfirst($data['provider']) . ' Airtime — ' . $data['phone'],
      'data'        => ucfirst($data['provider']) . ' Data — ' . $data['phone'],
      'electricity' => ucfirst($data['provider']) . ' Electricity — ' . ($data['biller_code'] ?? ''),
      'cable'       => ucfirst($data['provider']) . ' Subscription — ' . ($data['biller_code'] ?? ''),
      default       => 'Bill Payment',
    };
  }
}
