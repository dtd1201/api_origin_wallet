<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'email',
        'phone',
        'full_name',
        'password_hash',
        'status',
        'kyc_status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    public function providerAccounts(): HasMany
    {
        return $this->hasMany(UserProviderAccount::class);
    }

    public function kycProfile(): HasOne
    {
        return $this->hasOne(KycProfile::class);
    }

    public function kycSubmission(): HasOne
    {
        return $this->kycProfile();
    }

    public function kycProviderSubmissions(): HasMany
    {
        return $this->hasMany(KycProviderSubmission::class);
    }

    public function amlScreenings(): HasMany
    {
        return $this->hasMany(AmlScreening::class);
    }

    public function integrationLinks(): HasMany
    {
        return $this->hasMany(UserIntegrationLink::class);
    }

    public function integrationRequests(): HasMany
    {
        return $this->hasMany(UserIntegrationRequest::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function beneficiaries(): HasMany
    {
        return $this->hasMany(Beneficiary::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(Balance::class);
    }

    public function fxQuotes(): HasMany
    {
        return $this->hasMany(FxQuote::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function apiRequestLogs(): HasMany
    {
        return $this->hasMany(ApiRequestLog::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function transferApprovals(): HasMany
    {
        return $this->hasMany(TransferApproval::class, 'approver_user_id');
    }

    public function hasRole(string ...$roleCodes): bool
    {
        $normalizedRoleCodes = collect($roleCodes)
            ->map(fn (string $roleCode) => Str::lower($roleCode))
            ->all();

        return $this->roles()->whereIn('role_code', $normalizedRoleCodes)->exists();
    }

    public function isAdmin(): bool
    {
        $adminRoleCodes = collect(config('auth.admin_role_codes', ['admin', 'super_admin']))
            ->filter(fn ($roleCode) => is_string($roleCode) && $roleCode !== '')
            ->map(fn (string $roleCode) => Str::lower($roleCode))
            ->values()
            ->all();

        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(
                fn (UserRole $role) => in_array(Str::lower((string) $role->role_code), $adminRoleCodes, true)
            );
        }

        return $this->roles()
            ->whereIn('role_code', $adminRoleCodes)
            ->exists();
    }

    public function scopeNonAdmin(Builder $query): Builder
    {
        $adminRoleCodes = collect(config('auth.admin_role_codes', ['admin', 'super_admin']))
            ->filter(fn ($roleCode) => is_string($roleCode) && $roleCode !== '')
            ->map(fn (string $roleCode) => Str::lower($roleCode))
            ->values()
            ->all();

        return $query->whereDoesntHave('roles', function (Builder $roleQuery) use ($adminRoleCodes): void {
            $roleQuery->whereIn('role_code', $adminRoleCodes);
        });
    }
}
