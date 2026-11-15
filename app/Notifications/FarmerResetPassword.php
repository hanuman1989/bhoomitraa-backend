<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use LogicException;

class FarmerResetPassword extends ResetPassword
{
    public static function resetBaseUrl(): ?string
    {
        $url = config('app.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($url);
        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($url, '/');
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Krishi AI — Reset Your Password')
            ->markdown('mail.account.reset-password', [
                'resetUrl' => $this->resetUrl($notifiable),
                'baseUrl' => self::resetBaseUrl(),
                'expiresIn' => (int) config('auth.passwords.users.expire'),
            ]);
    }

    protected function resetUrl(mixed $notifiable): string
    {
        $baseUrl = self::resetBaseUrl() ?? throw new LogicException('A valid APP_URL is required for farmer password reset links.');

        return $baseUrl.'/account/reset-password?'.http_build_query([
            'token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
