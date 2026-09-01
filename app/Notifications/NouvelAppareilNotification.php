<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NouvelAppareilNotification extends Notification
{
    use Queueable;

    // Propriété stockant le code OTP à afficher
    protected $codeOtp;

    public function __construct($codeOtp) // Constructeur recevant le code OTP
    {
        // Stocke le code OTP reçu
        $this->codeOtp = $codeOtp;
    }

    public function via($notifiable): array // Définit les canaux d'envoi de la notification
    {
        // On envoie uniquement par e-mail
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage // Construit le contenu de l'e-mail
    {
        // Construction et retour direct de l'e-mail d'alerte de sécurité
        return (new MailMessage)
            ->subject('KoriPay - Alerte de sécurité : Nouvel appareil détecté')
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Une tentative de connexion à votre compte KoriPay a été détectée depuis un nouvel appareil mobile.")
            ->line("Pour autoriser cet appareil et finaliser votre accès, veuillez saisir le code de sécurité unique suivant :")
            ->line("👉 **{$this->codeOtp}**")
            ->line("Ce code est strictement confidentiel : ne le communiquez à personne, y compris au support KoriPay.")
            ->line("Il expirera dans 5 minutes.")
            ->line("Si vous n'êtes pas à l'origine de cette tentative, votre compte est peut-être en danger. Veuillez contacter immédiatement le support KoriPay.")
            ->salutation("L'équipe Sécurité KoriPay");
    }
}
