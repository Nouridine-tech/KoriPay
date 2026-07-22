<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NouvelAppareilNotification extends Notification
{
    use Queueable;

    protected $codeOtp;

    public function __construct($codeOtp)
    {
        $this->codeOtp = $codeOtp;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('KoriPay - Alerte de sécurité : Nouvel appareil détecté')
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Une tentative de connexion à votre compte KoriPay a été détectée depuis un nouvel appareil mobile.")
            ->line("Pour autoriser cet appareil et finaliser votre accès, veuillez saisir le code de sécurité unique suivant :")
            ->line("👉 **{$this->codeOtp}**")
            ->line("Ce code est strictement confidentiel et expirera dans 5 minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette tentative, votre compte est peut-être en danger. Veuillez contacter immédiatement le support KoriPay.")
            ->salutation("L'équipe Sécurité KoriPay");
    }
}
