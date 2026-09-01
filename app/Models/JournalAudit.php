<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class JournalAudit extends Model
{
    // Spécifions le nom exact de la table
    protected $table = 'journaux_audit';

    // Champs autorisés à être enregistrés
    protected $fillable = [
        'acteur_id',
        'action',
        'cible_type',
        'cible_id',
        'motif',
    ];

    // RELATION : L'entrée d'audit appartient à un acteur précis (admin ou agent)
    public function acteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acteur_id');
    }

    // RELATION POLYMORPHE : La cible peut être un User (compte) ou une Transaction, selon l'action
    public function cible(): MorphTo
    {
        return $this->morphTo();
    }
}
