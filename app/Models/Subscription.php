<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_url',
        'email',
        'current_price',
        'listing_title',
        'is_verified',
        'verification_token',
        'is_active',
        'last_checked_at',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    /**
     * Get price histories for this subscription
     *
     * @return HasMany
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class, 'listing_url', 'listing_url');
    }

    /**
     * Check if subscription is verified and active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->is_verified && $this->is_active;
    }

    /**
     * Generate verification token
     *
     * @return string
     */
    public function generateVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->verification_token = $token;
        return $token;
    }
}
