<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodeOtpNotification extends Notification
{
    use Queueable;

    // Propriété qui va stocker le code
    protected $codeOtp;

    //Le constructeur reçoit le code généré depuis le controller
    public function __construct($codeOtp)
    {
        $this->codeOtp = $codeOtp;
    }

    // On spécifie que le canal d'envoi exclusif est le mail
    public function via($notifiable): array
    {
        return ['mail'];
    }

    // Structuration du contenu de l'e-mail
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('KoriPay - Code de validation de votre retrait')
            ->greeting("Bonjour, {$notifiable->prenom},")
            ->line("Une demande de retrait d'espèce a été initiée depuis votre compte KoriPay au niveau d'un guichet de retrait.")
            ->line("Voici votre code de confirmation à usage unique :")
            ->line("****{$this->codeOtp}****")
            ->line("Ce code est stritement confidentiel et expirera dans 5 minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.")
            ->salutation("L'équipe KoriPay");
    }
}
