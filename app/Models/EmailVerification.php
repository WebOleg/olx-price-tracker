<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmailVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'token',
        'expires_at',
        'verified_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /**
     * Check if verification token is expired
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expires_at < now();
    }

    /**
     * Check if email is verified
     *
     * @return bool
     */
    public function isVerified(): bool
    {
        return !is_null($this->verified_at);
    }

    /**
     * Mark as verified
     *
     * @return bool
     */
    public function markAsVerified(): bool
    {
        $this->verified_at = now();
        return $this->save();
    }

    /**
     * Generate verification token
     *
     * @return string
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
