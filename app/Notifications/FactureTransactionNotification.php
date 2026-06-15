<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FactureTransactionNotification extends Notification
{
    use Queueable;

    protected $transaction;
    protected $roleClient; // 'expediteur' ou 'destinataire' pour personnaliser le texte

    public function __construct(Transaction $transaction, $roleClient = 'expediteur')
    {
        $this->transaction = $transaction;
        $this->roleClient = $roleClient;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $reference = $this->transaction->reference;
        $montant = number_format($this->transaction->montant, 0, ',', ' ') . ' FCFA';
        $frais = number_format($this->transaction->frais, 0, ',', ' ') . ' FCFA';
        $type = $this->transaction->type;

        $email = (new MailMessage)
            ->subject("Kori Pay - Reçu de transaction #{$reference}")
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Nous vous confirmons la bonne exécution de votre opération sur votre compte Kori Pay.");

        // Personnalisation du contenu du mail selon le type métier exact de votre schéma
        if ($type === 'depot') {
            $email->line("💰 Type d'opération : Dépôt de fonds au guichet")
                ->line("Montant crédité : {$montant}");
        } elseif ($type === 'retrait') {
            $email->line("🚪 Type d'opération : Retrait d'espèces au guichet")
                ->line("Montant débité : {$montant}");
        } elseif ($type === 'transfert') {
            $email->line("💸 Type d'opération : Transfert d'argent")
                ->line("Montant envoyé : {$montant}")
                ->line("Frais appliqués : {$frais}");
        } elseif ($type === 'reception') {
            $email->line("📩 Type d'opération : Réception d'un transfert d'argent")
                ->line("Montant reçu : {$montant}");
        }

        return $email
            ->line("Numéro de Référence : **{$reference}**")
            ->line("Date de l'opération : " . $this->transaction->created_at->format('d/m/Y à H:i'))
            ->line("Merci d'utiliser Kori Pay pour vos transactions financières.")
            ->salutation("L'équipe Kori Pay");
    }
}
