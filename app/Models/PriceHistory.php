<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'listing_url',
        'price',
        'previous_price',
        'change_type',
        'checked_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'previous_price' => 'decimal:2',
        'checked_at' => 'datetime',
    ];

    /**
     * Get subscriptions for this listing URL
     *
     * @return HasMany
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'listing_url', 'listing_url');
    }

    /**
     * Calculate price change percentage
     *
     * @return float|null
     */
    public function getPriceChangePercentage(): ?float
    {
        if (!$this->previous_price || $this->previous_price == 0) {
            return null;
        }

        return round((($this->price - $this->previous_price) / $this->previous_price) * 100, 2);
    }

    /**
     * Get formatted price change
     *
     * @return string
     */
    public function getFormattedPriceChange(): string
    {
        if (!$this->previous_price) {
            return 'Початкова ціна';
        }

        $difference = $this->price - $this->previous_price;
        $percentage = $this->getPriceChangePercentage();

        if ($difference > 0) {
            return "+{$difference} грн ({$percentage}%)";
        } elseif ($difference < 0) {
            return "{$difference} грн ({$percentage}%)";
        }

        return 'Без змін';
    }
}
