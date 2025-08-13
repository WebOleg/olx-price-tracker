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
        'verification_token',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Generate verification token
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($subscription) {
            if (!$subscription->verification_token) {
                $subscription->verification_token = bin2hex(random_bytes(32)); // 64 character hex string
            }
        });
    }

    /**
     * Get the price history for this subscription's listing URL
     */
    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class, 'listing_url', 'listing_url');
    }

    /**
     * Get the latest price history record
     */
    public function latestPriceHistory()
    {
        return $this->priceHistory()->latest('checked_at')->first();
    }

    /**
     * Check if the listing is currently available
     */
    public function isListingAvailable(): bool
    {
        $latest = $this->latestPriceHistory();
        return $latest ? $latest->is_available : false;
    }

    /**
     * Get the current price
     */
    public function getCurrentPrice(): ?float
    {
        $latest = $this->latestPriceHistory();
        return $latest && $latest->is_available ? $latest->price : null;
    }
}
