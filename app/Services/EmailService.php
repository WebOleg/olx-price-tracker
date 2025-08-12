<?php

namespace App\Services;

use App\Models\EmailVerification;
use App\Models\PriceHistory;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailService
{
    /**
     * Send email verification message
     *
     * @param string $email
     * @param string $token
     * @return bool
     */
    public function sendVerificationEmail(string $email, string $token): bool
    {
        try {
            $verificationUrl = config('app.url') . "/api/verify-email/{$token}";

            $subject = 'Підтвердіть підписку на відстеження цін OLX';
            $message = $this->buildVerificationEmailBody($verificationUrl);

            Mail::send([], [], function ($mail) use ($email, $subject, $message) {
                $mail->to($email)
                    ->subject($subject)
                    ->html($message);
            });

            Log::info('Verification email sent', ['email' => $email]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send verification email', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send price change notification
     *
     * @param Subscription $subscription
     * @param PriceHistory $priceHistory
     * @return bool
     */
    public function sendPriceChangeNotification(Subscription $subscription, PriceHistory $priceHistory): bool
    {
        try {
            $subject = $this->buildPriceChangeSubject($subscription, $priceHistory);
            $message = $this->buildPriceChangeEmailBody($subscription, $priceHistory);

            Mail::send([], [], function ($mail) use ($subscription, $subject, $message) {
                $mail->to($subscription->email)
                    ->subject($subject)
                    ->html($message);
            });

            Log::info('Price change notification sent', [
                'email' => $subscription->email,
                'price_change' => $priceHistory->getFormattedPriceChange()
            ]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send price change notification', [
                'subscription_id' => $subscription->id,
                'email' => $subscription->email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c3e50;'>Підтвердження підписки на відстеження цін</h2>

                <p>Привіт!</p>

                <p>Ви підписалися на відстеження змін ціни оголошення на OLX.
                Щоб активувати підписку, будь ласка, натисніть на кнопку нижче:</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$verificationUrl}'
                       style='background-color: #3498db; color: white; padding: 12px 30px;
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Підтвердити підписку
                    </a>
                </div>

                <p>Або скопіюйте це посилання у браузер:</p>
                <p style='background: #f8f9fa; padding: 10px; border-radius: 3px; word-break: break-all;'>
                    {$verificationUrl}
                </p>

                <p style='color: #666; font-size: 14px; margin-top: 30px;'>
                    Якщо ви не підписувалися на цей сервіс, просто проігноруйте це повідомлення.
                </p>

                <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='color: #888; font-size: 12px;'>
                    OLX Price Tracker Service
                </p>
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
        $changeType = $priceHistory->change_type;
        $price = number_format($priceHistory->price, 0, '.', ' ');

        $prefix = match ($changeType) {
            'decreased' => '⬇️ Ціна знизилась',
            'increased' => '⬆️ Ціна підвищилась',
            default => '📊 Зміна ціни'
        };

        $title = $subscription->listing_title
            ? mb_substr($subscription->listing_title, 0, 50) . '...'
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
        $changeType = $priceHistory->change_type;

        $changeColor = match ($changeType) {
            'decreased' => '#27ae60',
            'increased' => '#e74c3c',
            default => '#3498db'
        };

        $changeIcon = match ($changeType) {
            'decreased' => '⬇️',
            'increased' => '⬆️',
            default => '📊'
        };

        return "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #2c3e50;'>{$changeIcon} Зміна ціни оголошення</h2>

                <div style='background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                    <h3 style='margin: 0 0 10px 0; color: #2c3e50;'>
                        " . ($subscription->listing_title ?: 'Оголошення OLX') . "
                    </h3>

                    <div style='margin: 15px 0;'>
                        <span style='font-size: 24px; font-weight: bold; color: {$changeColor};'>
                            {$currentPrice} грн
                        </span>
                    </div>

                    <div style='margin: 10px 0;'>
                        <strong>Попередня ціна:</strong> {$previousPrice} грн
                    </div>

                    <div style='margin: 10px 0;'>
                        <strong>Зміна:</strong>
                        <span style='color: {$changeColor}; font-weight: bold;'>
                            {$changeFormatted}
                        </span>
                    </div>

                    <div style='margin: 10px 0; color: #666;'>
                        <strong>Перевірено:</strong> " . $priceHistory->checked_at->format('d.m.Y H:i') . "
                    </div>
                </div>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$subscription->listing_url}'
                       style='background-color: #3498db; color: white; padding: 12px 30px;
                              text-decoration: none; border-radius: 5px; display: inline-block;'>
                        Переглянути оголошення
                    </a>
                </div>

                <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>

                <p style='color: #666; font-size: 14px;'>
                    Ви отримали це повідомлення, тому що підписалися на відстеження цін цього оголошення.
                    Щоб відписатися, перейдіть за посиланням нижче:
                </p>

                <p style='color: #888; font-size: 12px;'>
                    OLX Price Tracker Service
                </p>
            </div>
        </body>
        </html>
        ";
    }
}
