<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\SalaryAdvance;
use App\Models\AtlasWallet;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'full_name',
        'email',
        'phone',
        'password',
        'pin_hash',
        'avatar_url',
        'kyc_status',
        'kyc_data',
        'bvn_hash',
        'is_active',
        'notifications_enabled',
        'notification_preferences',
        'timezone',
        'currency',
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'locked_until',
        'is_admin',
        'suspended_at',
        'suspension_reason',
    ];

    protected $hidden = [
        'password',
        'pin_hash',
        'bvn_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'last_login_at'            => 'datetime',
            'locked_until'             => 'datetime',
            'is_active'                => 'boolean',
            'notifications_enabled'    => 'boolean',
            'kyc_data'                 => 'array',
            'notification_preferences' => 'array',
            'failed_login_attempts'    => 'integer',
            'is_admin'     => 'boolean',
            'suspended_at' => 'datetime',
        ];
    }

    // ── JWT ──────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'email' => $this->email,
            'name'  => $this->full_name,
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    public function primaryAccount(): HasOne
    {
        return $this->hasOne(ConnectedAccount::class)->where('is_primary', true);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function financialProfile(): HasOne
    {
        return $this->hasOne(FinancialProfile::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(RuleExecution::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function atlasWallets(): HasMany
    {
        return $this->hasMany(AtlasWallet::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(AdvisoryInsight::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function feeLedger(): HasMany
    {
        return $this->hasMany(FeeLedger::class);
    }

    public function billPayments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    // ── Accessors ─────────────────────────────────────────────────────────

    public function getFirstNameAttribute(): string
    {
        return explode(' ', $this->full_name)[0];
    }

    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', $this->full_name);
        $initials = collect($parts)->map(fn($p) => strtoupper($p[0]))->take(2)->join('');
        return $initials;
    }

    public function getIsLockedAttribute(): bool
    {
        return $this->locked_until && $this->locked_until->isFuture();
    }

    // ── Scopes ────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVerified($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function verifyPin(string $pin): bool
    {
        return Hash::check($pin, $this->pin_hash);
    }

    public function incrementFailedLogins(): void
    {
        $this->increment('failed_login_attempts');

        if ($this->failed_login_attempts >= 5) {
            $this->update(['locked_until' => now()->addMinutes(30)]);
        }
    }

    public function resetFailedLogins(): void
    {
        $this->update([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
        ]);
    }

    public function getOrCreateFinancialProfile(): FinancialProfile
    {
        return $this->financialProfile ?? FinancialProfile::create(['user_id' => $this->id]);
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(AtlasWallet::class);
    }
}
