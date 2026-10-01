<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class PublicCustomerAccess extends Notification
{
    public function __construct(public string $purpose, public ?string $token = null) {}
    public function via($notifiable): array { return ['mail']; }
    public function toMail($notifiable): MailMessage
    {
        $verify = $this->purpose === 'verify';
        $url = $verify
            ? URL::temporarySignedRoute('public-account.verification.verify', now()->addMinutes(60), ['id' => $notifiable->id, 'hash' => sha1($notifiable->getEmailForVerification())])
            : route('public-account.password.reset', ['token' => $this->token, 'email' => $notifiable->email]);

        return (new MailMessage)->from(config('mail.from.address'), 'AMD Rent')
            ->subject($verify ? 'Verifica la tua email — AMD Rent' : 'Reimposta la password — AMD Rent')
            ->view('emails.public-customer-access', [
                'heading' => $verify ? 'Attiva la tua area cliente' : 'Scegli una nuova password',
                'copy' => $verify ? 'Conferma il tuo indirizzo email per consultare le tue prenotazioni e richieste.' : 'Hai richiesto di reimpostare la password della tua area cliente AMD Rent.',
                'action' => $verify ? 'Verifica email' : 'Reimposta password', 'url' => $url,
            ]);
    }
}
