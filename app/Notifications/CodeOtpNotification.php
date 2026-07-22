<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodeOtpNotification extends Notification
{
    use Queueable;

    // Propriétés pour stocker le code et le type d'action
    protected $codeOtp;
    protected $typeAction;

    // Le constructeur reçoit le code et optionnellement le type d'action (par défaut 'retrait')
    public function __construct($codeOtp, $typeAction = 'retrait')
    {
        $this->codeOtp = $codeOtp;
        $this->typeAction = $typeAction;
    }

    // On spécifie que le canal d'envoi exclusif est le mail
    public function via($notifiable): array
    {
        return ['mail'];
    }

    // Structuration du contenu de l'e-mail
    public function toMail($notifiable): MailMessage
    {
        // Si c'est un transfert, on affiche le texte spécifique au transfert
        if ($this->typeAction === 'transfert') {
            return (new MailMessage)
                ->subject('KoriPay - Code de validation de votre transfert')
                ->greeting("Bonjour {$notifiable->prenom},")
                ->line("Une demande de transfert de fonds d'un gros montant a été initiée depuis votre application mobile KoriPay.")
                ->line("Voici votre code de confirmation à usage unique :")
                ->line("****{$this->codeOtp}****")
                ->line("Ce code est strictement confidentiel et expirera dans 5 minutes.")
                ->line("Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.")
                ->salutation("L'équipe KoriPay");
        }

        // TEXTE D'ORIGINE POUR LE RETRAIT (STRICTEMENT INCHANGÉ)
        return (new MailMessage)
            ->subject('KoriPay - Code de validation de votre retrait')
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Une demande de retrait d'espèce a été initiée depuis votre compte KoriPay au niveau d'un guichet de retrait.")
            ->line("Voici votre code de confirmation à usage unique :")
            ->line("****{$this->codeOtp}****")
            ->line("Ce code est stritement confidentiel et expirera dans 5 minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.")
            ->salutation("L'équipe KoriPay");
    }
}
