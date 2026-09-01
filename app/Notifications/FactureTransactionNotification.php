<?php

namespace App\Notifications;

use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FactureTransactionNotification extends Notification
{
    use Queueable;

    // Propriété stockant la transaction concernée
    protected $transaction;

    // Propriété stockant le rôle du destinataire du mail ('expediteur' ou 'destinataire')
    protected $roleClient;

    // Constructeur recevant la transaction et le rôle
    public function __construct(Transaction $transaction, $roleClient = 'expediteur')
    {
        // Stocke la transaction reçue
        $this->transaction = $transaction;

        // Stocke le rôle reçu, ou 'expediteur' par défaut
        $this->roleClient = $roleClient;
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
        // Référence unique de la transaction
        $reference = $this->transaction->reference;

        // Montant formaté avec séparateur de milliers
        $montant = number_format($this->transaction->montant, 0, ',', ' ') . ' FCFA';

        // Frais formatés avec séparateur de milliers
        $frais = number_format($this->transaction->frais, 0, ',', ' ') . ' FCFA';

        // Type exact de l'opération (depot, retrait, transfert, reception)
        $type = $this->transaction->type;

        // Nouveau solde du destinataire de cet e-mail, déjà à jour au moment de l'envoi
        $nouveauSolde = number_format($notifiable->solde, 0, ',', ' ') . ' FCFA';

        // Construction du début du mail, commun à tous les types d'opération
        $email = (new MailMessage)
            ->subject("Kori Pay - Reçu de transaction #{$reference}")
            ->greeting("Bonjour {$notifiable->prenom},")
            ->line("Nous vous confirmons la bonne exécution de votre opération sur votre compte Kori Pay.");

        // Personnalisation du contenu du mail selon le type métier exact de votre schéma
        if ($type === 'depot') {
            // Cas d'un dépôt de fonds au guichet
            $email->line("💰 Type d'opération : Dépôt de fonds au guichet")
                ->line("Montant crédité : {$montant}")
                ->line("Nouveau solde de votre compte : {$nouveauSolde}");
        } elseif ($type === 'retrait') {
            // Cas d'un retrait d'espèces au guichet
            $email->line("🚪 Type d'opération : Retrait d'espèces au guichet")
                ->line("Montant débité : {$montant}")
                ->line("Nouveau solde de votre compte : {$nouveauSolde}");
        } elseif ($type === 'transfert') {
            // Le contact concerné est le destinataire du transfert
            $contact = $this->transaction->destinataire;
            // Nom complet du contact, ou texte générique si le compte a été supprimé
            $nomContact = $contact ? "{$contact->prenom} {$contact->nom}" : 'un compte KoriPay';

            // Cas d'un transfert envoyé par le client
            $email->line("💸 Type d'opération : Transfert d'argent")
                ->line("Envoyé à : {$nomContact}")
                ->line("Montant envoyé : {$montant}")
                ->line("Frais appliqués : {$frais}")
                ->line("Nouveau solde de votre compte : {$nouveauSolde}");
        } elseif ($type === 'reception') {
            // Le contact concerné est l'expéditeur du transfert
            $contact = $this->transaction->expediteur;
            // Nom complet du contact, ou texte générique si le compte a été supprimé
            $nomContact = $contact ? "{$contact->prenom} {$contact->nom}" : 'un compte KoriPay';

            // Cas d'une réception de transfert par le client
            $email->line("📩 Type d'opération : Réception d'un transfert d'argent")
                ->line("Reçu de : {$nomContact}")
                ->line("Montant reçu : {$montant}")
                ->line("Nouveau solde de votre compte : {$nouveauSolde}");
        }

        // Finalisation du mail avec les informations communes de fin
        return $email
            ->line("Numéro de Référence : **{$reference}**")
            ->line("Date de l'opération : " . $this->transaction->created_at->format('d/m/Y à H:i'))
            ->line("Merci d'utiliser Kori Pay pour vos transactions financières.")
            ->salutation("L'équipe Kori Pay");
    }
}
