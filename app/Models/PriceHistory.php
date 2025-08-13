<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceHistory extends Model
{
    protected $fillable = [
        'listing_url',
        'price',
        'previous_price',
        'is_available',
        'change_reason',
        'checked_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'previous_price' => 'decimal:2',
        'is_available' => 'boolean',
        'checked_at' => 'datetime',
    ];

    /**
     * Get subscriptions for this listing URL
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'listing_url', 'listing_url');
    }

    /**
     * Get formatted price change
     */
    public function getFormattedPriceChange(): string
    {
        if ($this->previous_price === null) {
            return 'Нова підписка';
        }

        $change = $this->price - $this->previous_price;
        $percentage = round(($change / $this->previous_price) * 100, 1);

        if ($change > 0) {
            return "+{$change} грн (+{$percentage}%)";
        } elseif ($change < 0) {
            return "{$change} грн ({$percentage}%)";
        } else {
            return "Без змін";
        }
    }

    /**
     * Check if price decreased
     */
    public function isPriceDecrease(): bool
    {
        return $this->previous_price !== null && $this->price < $this->previous_price;
    }

    /**
     * Check if price increased
     */
    public function isPriceIncrease(): bool
    {
        return $this->previous_price !== null && $this->price > $this->previous_price;
    }

    /**
     * Get price change amount
     */
    public function getPriceChangeAmount(): float
    {
        if ($this->previous_price === null) {
            return 0;
        }

        return $this->price - $this->previous_price;
    }

    /**
     * Get price change percentage
     */
    public function getPriceChangePercentage(): float
    {
        if ($this->previous_price === null || $this->previous_price == 0) {
            return 0;
        }

        return round((($this->price - $this->previous_price) / $this->previous_price) * 100, 2);
    }
}
