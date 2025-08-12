<?php

namespace App\Services;

use App\Models\PriceHistory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Exception;

class EmailService
{
    private const RATE_LIMIT_KEY = 'email_rate_limit:';
    private const MAX_EMAILS_PER_HOUR = 10;

    /**
     * Send email verification message with rate limiting
     *
     * @param string $email
     * @param string $token
     * @return bool
     */
    public function sendVerificationEmail(string $email, string $token): bool
    {
        if (!$this->canSendEmail($email)) {
            Log::warning('Email rate limit exceeded', ['email' => $email]);
            return false;
        }

        try {
            $verificationUrl = config('app.url') . "/api/verify-email/{$token}";

            $subject = 'Підтвердіть підписку на відстеження цін OLX';
            $message = $this->buildVerificationEmailBody($verificationUrl);

            Mail::send([], [], function ($mail) use ($email, $subject, $message) {
                $mail->to($email)
                    ->subject($subject)
                    ->html($message);
            });

            $this->incrementEmailCount($email);

            Log::info('Verification email sent successfully', [
                'email' => $email,
                'token_length' => strlen($token),
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send verification email', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Send price change notification with optimized data
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @return bool
     */
    public function sendPriceChangeNotification(Subscription $subscription, PriceHistory $priceHistory): bool
    {
        if (!$this->canSendEmail($subscription->email)) {
            Log::warning('Email rate limit exceeded for price notification', [
                'email' => $subscription->email,
            ]);
            return false;
        }

        try {
            $subject = $this->buildPriceChangeSubject($subscription, $priceHistory);
            $message = $this->buildPriceChangeEmailBody($subscription, $priceHistory);

            Mail::send([], [], function ($mail) use ($subscription, $subject, $message) {
                $mail->to($subscription->email)
                    ->subject($subject)
                    ->html($message);
            });

            $this->incrementEmailCount($subscription->email);

            Log::info('Price change notification sent successfully', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'price_change' => $priceHistory->getFormattedPriceChange(),
                'new_price' => $priceHistory->price,
                'previous_price' => $priceHistory->previous_price,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send price change notification', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Send notification about unavailable listing
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return bool
     */
    public function sendUnavailableNotification(Subscription $subscription, string $reason): bool
    {
        if (!$this->canSendEmail($subscription->email)) {
            Log::warning('Email rate limit exceeded for unavailable notification', [
                'email' => $subscription->email,
            ]);
            return false;
        }

        try {
            $subject = '⚠️ Підписка деактивована - OLX Price Tracker';
            $message = $this->buildUnavailableEmailBody($subscription, $reason);

            Mail::send([], [], function ($mail) use ($subscription, $subject, $message) {
                $mail->to($subscription->email)
                    ->subject($subject)
                    ->html($message);
            });

            $this->incrementEmailCount($subscription->email);

            Log::info('Unavailable notification sent successfully', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'reason' => $reason,
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to send unavailable notification', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if email can be sent (rate limiting)
     *
     * @param string $email
     * @return bool
     */
    private function canSendEmail(string $email): bool
    {
        $cacheKey = self::RATE_LIMIT_KEY . md5($email);
        $emailCount = Cache::get($cacheKey, 0);

        return $emailCount < self::MAX_EMAILS_PER_HOUR;
    }

    /**
     * Increment email count for rate limiting
     *
     * @param string $email
     * @return void
     */
    private function incrementEmailCount(string $email): void
    {
        $cacheKey = self::RATE_LIMIT_KEY . md5($email);
        $emailCount = Cache::get($cacheKey, 0);

        Cache::put($cacheKey, $emailCount + 1, now()->addHour());
    }

    /**
     * Build verification email HTML body
     *
     * @param string $verificationUrl
     * @return string
     */
    private function buildVerificationEmailBody(string $verificationUrl): string
    {
        return "
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Email Verification</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8f9fa;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;'>
                    <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center;'>
                        <h1 style='color: white; margin: 0; font-size: 24px; font-weight: 600;'>
                            📈 OLX Price Tracker
                        </h1>
                        <p style='color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 16px;'>
                            Підтвердження підписки
                        </p>
                    </div>

                    <div style='padding: 40px 30px;'>
                        <h2 style='color: #2c3e50; margin: 0 0 20px 0; font-size: 20px;'>Привіт! 👋</h2>

                        <p style='margin: 0 0 20px 0; font-size: 16px; line-height: 1.6;'>
                            Ви підписалися на відстеження змін ціни оголошення на OLX.
                            Щоб активувати підписку, будь ласка, натисніть на кнопку нижче:
                        </p>

                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$verificationUrl}'
                               style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                      color: white; padding: 14px 28px; text-decoration: none;
                                      border-radius: 6px; display: inline-block; font-weight: 600;
                                      font-size: 16px; transition: transform 0.2s ease;'>
                                ✅ Підтвердити підписку
                            </a>
                        </div>

                        <div style='background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0;'>
                            <p style='margin: 0 0 10px 0; font-size: 14px; color: #666;'>
                                Або скопіюйте це посилання у браузер:
                            </p>
                            <p style='margin: 0; word-break: break-all; font-size: 14px; color: #495057;'>
                                {$verificationUrl}
                            </p>
                        </div>

                        <div style='border-top: 1px solid #eee; padding-top: 20px; margin-top: 30px;'>
                            <p style='color: #666; font-size: 14px; margin: 0; text-align: center;'>
                                Якщо ви не підписувалися на цей сервіс, просто проігноруйте це повідомлення.
                            </p>
                        </div>
                    </div>
                </div>

                <div style='text-align: center; padding: 20px; color: #888; font-size: 12px;'>
                    <p style='margin: 0;'>© " . date('Y') . " OLX Price Tracker Service</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Build price change subject line
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @return string
     */
    private function buildPriceChangeSubject(Subscription $subscription, PriceHistory $priceHistory): string
    {
        $currentPrice = $priceHistory->price;
        $previousPrice = $priceHistory->previous_price;

        $prefix = $currentPrice < $previousPrice ? '⬇️ Ціна знизилась' : '⬆️ Ціна підвищилась';
        $price = number_format($currentPrice, 0, '.', ' ');

        $title = $subscription->listing_title
            ? mb_substr($subscription->listing_title, 0, 40) . '...'
            : 'Оголошення OLX';

        return "{$prefix}: {$price} грн - {$title}";
    }

    /**
     * Build price change email HTML body
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @return string
     */
    private function buildPriceChangeEmailBody(Subscription $subscription, PriceHistory $priceHistory): string
    {
        $currentPrice = number_format($priceHistory->price, 0, '.', ' ');
        $previousPrice = $priceHistory->previous_price
            ? number_format($priceHistory->previous_price, 0, '.', ' ')
            : 'Невідомо';

        $changeFormatted = $priceHistory->getFormattedPriceChange();
        $changeColor = $priceHistory->price < $priceHistory->previous_price ? '#27ae60' : '#e74c3c';
        $changeIcon = $priceHistory->price < $priceHistory->previous_price ? '⬇️' : '⬆️';
        $bgGradient = $priceHistory->price < $priceHistory->previous_price
            ? 'linear-gradient(135deg, #27ae60 0%, #2ecc71 100%)'
            : 'linear-gradient(135deg, #e74c3c 0%, #c0392b 100%)';

        $title = $subscription->listing_title ?: 'Оголошення OLX';

        return "
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Price Change Alert</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8f9fa;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;'>
                    <div style='background: {$bgGradient}; padding: 30px; text-align: center;'>
                        <h1 style='color: white; margin: 0; font-size: 24px; font-weight: 600;'>
                            {$changeIcon} Зміна ціни
                        </h1>
                        <p style='color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 16px;'>
                            OLX Price Tracker
                        </p>
                    </div>

                    <div style='padding: 40px 30px;'>
                        <div style='background: #f8f9fa; padding: 25px; border-radius: 8px; margin: 20px 0;'>
                            <h3 style='margin: 0 0 15px 0; color: #2c3e50; font-size: 18px;'>
                                {$title}
                            </h3>

                            <div style='margin: 20px 0;'>
                                <span style='font-size: 32px; font-weight: bold; color: {$changeColor}; display: block;'>
                                    {$currentPrice} грн
                                </span>
                            </div>

                            <div style='display: flex; justify-content: space-between; margin: 15px 0; flex-wrap: wrap;'>
                                <div style='margin: 5px 0;'>
                                    <strong style='color: #666;'>Попередня ціна:</strong><br>
                                    <span style='font-size: 18px;'>{$previousPrice} грн</span>
                                </div>
                                <div style='margin: 5px 0;'>
                                    <strong style='color: #666;'>Зміна:</strong><br>
                                    <span style='color: {$changeColor}; font-weight: bold; font-size: 16px;'>
                                        {$changeFormatted}
                                    </span>
                                </div>
                            </div>

                            <div style='margin: 15px 0; color: #666; font-size: 14px;'>
                                <strong>Перевірено:</strong> " . $priceHistory->checked_at->format('d.m.Y о H:i') . "
                            </div>
                        </div>

                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='{$subscription->listing_url}'
                               style='background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
                                      color: white; padding: 14px 28px; text-decoration: none;
                                      border-radius: 6px; display: inline-block; font-weight: 600;
                                      font-size: 16px;'>
                                🔗 Переглянути оголошення
                            </a>
                        </div>
                    </div>
                </div>

                <div style='text-align: center; padding: 20px; color: #888; font-size: 12px;'>
                    <p style='margin: 0;'>© " . date('Y') . " OLX Price Tracker Service</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Build unavailable listing email body
     *
     * @param Subscription $subscription
     * @param string $reason
     * @return string
     */
    private function buildUnavailableEmailBody(Subscription $subscription, string $reason): string
    {
        $title = $subscription->listing_title ?: 'Оголошення OLX';

        return "
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Subscription Deactivated</title>
        </head>
        <body style='font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f8f9fa;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='background: white; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); overflow: hidden;'>
                    <div style='background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); padding: 30px; text-align: center;'>
                        <h1 style='color: white; margin: 0; font-size: 24px; font-weight: 600;'>
                            ⚠️ Підписка деактивована
                        </h1>
                        <p style='color: rgba(255,255,255,0.9); margin: 10px 0 0 0; font-size: 16px;'>
                            OLX Price Tracker
                        </p>
                    </div>

                    <div style='padding: 40px 30px;'>
                        <div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 20px; border-radius: 6px; margin: 20px 0;'>
                            <h3 style='margin: 0 0 15px 0; color: #856404;'>
                                {$title}
                            </h3>
                            <p style='margin: 10px 0; color: #856404;'>
                                <strong>Причина:</strong> {$reason}
                            </p>
                            <p style='margin: 10px 0; color: #856404; word-break: break-all; font-size: 14px;'>
                                <strong>URL:</strong> {$subscription->listing_url}
                            </p>
                        </div>

                        <p style='margin: 20px 0; font-size: 16px;'>
                            Відстеження цін для цього оголошення припинено. Можливо, оголошення було видалено
                            або стало недоступним.
                        </p>

                        <p style='margin: 20px 0; font-size: 16px;'>
                            Ви можете створити нову підписку на інше оголошення на нашому сайті.
                        </p>
                    </div>
                </div>

                <div style='text-align: center; padding: 20px; color: #888; font-size: 12px;'>
                    <p style='margin: 0;'>© " . date('Y') . " OLX Price Tracker Service</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
