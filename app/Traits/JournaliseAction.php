<?php

namespace App\Traits;

use App\Models\JournalAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait JournaliseAction
{
    // Constantes représentant les types d'action journalisables, pour éviter les fautes de frappe
    public const ACTION_SUSPENSION_COMPTE = 'suspension_compte';
    public const ACTION_GEL_COMPTE = 'gel_compte';
    public const ACTION_REACTIVATION_COMPTE = 'reactivation_compte';
    public const ACTION_CREATION_ADMIN = 'creation_admin';
    public const ACTION_ANNULATION_TRANSACTION = 'annulation_transaction';
    public const ACTION_MODIFICATION_IDENTITE_CLIENT = 'modification_identite_client';

    /**
     * Enregistre une entrée dans le journal d'audit.
     * $acteur : l'admin ou l'agent qui exécute l'action
     * $action : un identifiant court de l'action (ex: 'suspension_compte')
     * $cible : le modèle concerné par l'action (User ou Transaction), ou null si aucune cible précise
     * $motif : la raison donnée par l'acteur, si applicable
     */
    protected function journaliser(User $acteur, string $action, ?Model $cible = null, ?string $motif = null): void
    {
        JournalAudit::create([
            'acteur_id' => $acteur->id,
            'action' => $action,
            'cible_type' => $cible ? get_class($cible) : null,
            'cible_id' => $cible?->id,
            'motif' => $motif,
        ]);
    }
}
