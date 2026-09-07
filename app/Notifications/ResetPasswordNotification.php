<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The framework's password reset mail, in Romanian. The link lands on the
 * web reset page for every client — a native app opens it in the browser.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(string $token)
    {
        parent::__construct($token);

        $this->onQueue('notifications');
    }

    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Resetează parola — Ghes')
            ->greeting('Salut!')
            ->line('Am primit o cerere de resetare a parolei pentru contul tău Ghes.')
            ->action('Resetează parola', $url)
            ->line("Linkul expiră în {$minutes} de minute.")
            ->line('Dacă nu ai cerut resetarea parolei, poți ignora acest mesaj — parola rămâne neschimbată.')
            ->salutation('Echipa Ghes');
    }
}
