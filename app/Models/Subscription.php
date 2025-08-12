<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'listing_url',
        'listing_title',
        'is_verified',
        'verification_token',
        'verified_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * Get price histories for this subscription URL
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class, 'listing_url', 'listing_url');
    }

    /**
     * Get the latest price history for this listing
     */
    public function latestPriceHistory()
    {
        return $this->priceHistories()
            ->orderBy('checked_at', 'desc')
            ->first();
    }

    /**
     * Generate verification token
     */
    public function generateVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->verification_token = $token;
        return $token;
    }

    /**
     * Mark subscription as verified
     */
    public function markAsVerified(): void
    {
        $this->is_verified = true;
        $this->verified_at = now();
        $this->verification_token = null;
        $this->save();
    }

    /**
     * Check if verification token is expired
     */
    public function isVerificationExpired(): bool
    {
        if (!$this->created_at) {
            return true;
        }

        $expirationHours = config('app.email_verification_expire', 24);
        return $this->created_at->addHours($expirationHours)->isPast();
    }
}
