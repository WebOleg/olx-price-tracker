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
        'is_verified',
        'verification_token',
        'verified_at',
    ];

    protected $casts = [
        'current_price' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * Отримати історію цін для цього оголошення
     */
    public function priceHistories(): HasMany
    {
        return $this->hasMany(PriceHistory::class, 'listing_url', 'listing_url');
    }

    /**
     * Скоуп для отримання тільки верифікованих підписок
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Скоуп для отримання підписок по URL оголошення
     */
    public function scopeForListing($query, string $listingUrl)
    {
        return $query->where('listing_url', $listingUrl);
    }

    /**
     * Генерує токен для верифікації email
     */
    public function generateVerificationToken(): string
    {
        $this->verification_token = bin2hex(random_bytes(32));
        $this->save();

        return $this->verification_token;
    }

    /**
     * Верифікує підписку
     */
    public function verify(): bool
    {
        $this->is_verified = true;
        $this->verified_at = now();
        $this->verification_token = null;

        return $this->save();
    }
}
