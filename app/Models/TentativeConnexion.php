<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TentativeConnexion extends Model
{
    //Spécifions le nom exact de la table
    protected $table = 'tentative_connexion';

    // Champs autorisés à être enregistrés
    protected $fillable = [
        'user_id',
        'tentatives',
        'derniere_tentative',
        'suspendu_jusqu_a',
    ];

    // Précision à Laravel que ces champs doivent être manipulés
    // comme des objets date/heure Carbon
    protected $casts = [
        'derniere_tentative' => 'datetime',
        'suspendu_jusqu_a' => 'datetime',
    ];

    // RELATION INVERSE : Une tentative appartient à un utilisateur précis
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
