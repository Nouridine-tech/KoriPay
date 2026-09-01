<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CodeOtpNotification extends Notification
{
    use Queueable;

    // Propriété stockant le code OTP à afficher
    protected $codeOtp;

    // Propriété stockant le type d'action concernée (retrait ou transfert)
    protected $typeAction;

    // Constructeur recevant le code et le type d'action
    public function __construct($codeOtp, $typeAction = 'retrait')
    {
        // Stocke le code OTP reçu
        $this->codeOtp = $codeOtp;

        // Stocke le type d'action reçu, ou 'retrait' par défaut
        $this->typeAction = $typeAction;
    }

    // Définit les canaux d'envoi de la notification
    public function via($notifiable): array
    {
        // On envoie uniquement par e-mail
        return ['mail'];
    }

    // Construit le contenu de l'e-mail
    public function toMail($notifiable): MailMessage
    {
        // Si c'est un transfert, on affiche le texte spécifique au transfert
        if ($this->typeAction === 'transfert') {
            // Cas d'un transfert dépassant le seuil de sécurité de 50 000 FCFA
            return (new MailMessage)
                ->subject('KoriPay - Code de validation de votre transfert')
                ->greeting("Bonjour {$notifiable->prenom},")
                ->line("Un transfert de fonds supérieur à 50 000 FCFA a été initié depuis votre application mobile KoriPay.")
                ->line("Voici votre code de confirmation à usage unique :")
                ->line("****{$this->codeOtp}****")
                ->line("Ce code est strictement confidentiel : ne le communiquez à personne, y compris au support KoriPay.")
                ->line("Il expirera dans 5 minutes.")
                ->line("Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.")
                ->salutation("L'équipe KoriPay");
        }

        // Cas d'un retrait d'espèces au guichet (texte par défaut)
        return (new MailMessage)
            ->subject('KoriPay - Code de validation de votre retrait')
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Une demande de retrait d'espèces a été initiée depuis votre compte KoriPay au niveau d'un guichet de retrait.")
            ->line("Voici votre code de confirmation à usage unique :")
            ->line("****{$this->codeOtp}****")
            ->line("Ce code est strictement confidentiel : ne le communiquez à personne, y compris au support KoriPay.")
            ->line("Il expirera dans 5 minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette demande, veuillez ignorer ce message.")
            ->salutation("L'équipe KoriPay");
    }
}
