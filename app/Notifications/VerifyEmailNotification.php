<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * The framework's verification mail, in Romanian and aware of where the
 * link should land.
 *
 * `intent=mobile` rides inside the signed URL, so it cannot be tampered
 * with: after verifying, the web controller hands the user back to the app
 * through its URL scheme instead of the web profile page.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public const INTENT_MOBILE = 'mobile';

    public function __construct(
        private readonly ?string $intent = null,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * @param  mixed  $notifiable
     */
    protected function verificationUrl($notifiable): string
    {
        $parameters = [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ];

        if ($this->intent === self::INTENT_MOBILE) {
            $parameters['intent'] = self::INTENT_MOBILE;
        }

        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60)),
            $parameters,
        );
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmă adresa de email — Ghes')
            ->greeting('Salut!')
            ->line('Apasă butonul de mai jos ca să confirmi adresa de email a contului tău Ghes.')
            ->action('Confirmă adresa de email', $url)
            ->line('Dacă nu ți-ai făcut cont pe Ghes, poți ignora acest mesaj.')
            ->salutation('Echipa Ghes');
    }
}
