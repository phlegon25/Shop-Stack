<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'slug', 'email', 'phone',
        'business_type', 'address', 'city', 'region', 'country', 'currency',
        // status/suspension fields are NOT fillable — super-admin operations only.
    ];

    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    public function businessSettings(): HasOne
    {
        return $this->hasOne(BusinessSetting::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** Latest subscription that is currently usable (trialing/active and not past ends_at). */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->whereIn('status', [Subscription::STATUS_TRIALING, Subscription::STATUS_ACTIVE])
            ->where('ends_at', '>', now())
            ->latest('ends_at');
    }

    /**
     * A subscription the tenant may operate under RIGHT NOW: a live one, or
     * an expired one still inside its grace window.
     */
    public function operationalSubscription(): ?Subscription
    {
        $live = $this->currentSubscription;
        if ($live) {
            return $live;
        }

        return $this->subscriptions()
            ->where('status', Subscription::STATUS_EXPIRED)
            ->whereNotNull('grace_ends_at')
            ->where('grace_ends_at', '>', now())
            ->latest('ends_at')
            ->first();
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * A tenant may perform business operations only when it is active AND
     * holds a live subscription or is inside its grace period.
     */
    public function hasOperationalAccess(): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        return $this->operationalSubscription() !== null;
    }

    /** True only when operating inside the grace window (for UI warnings). */
    public function isInGracePeriod(): bool
    {
        return $this->currentSubscription()->doesntExist()
            && $this->operationalSubscription() !== null;
    }
}
