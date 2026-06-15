<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    //Définition des champs modifiables de la table transactions
    protected $fillable = [
      'reference',
      'expediteur_id',
      'destinataire_id',
      'montant',
      'frais',
      'type',
      'status',
    ];

    //RELATION INVERSE : Une transaction appartient à un utilisateur expediteur
    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expediteur_id');
    }

    //RELATION INVERSE : Une transaction appartient à un utilisateur destinataire
    public function destinataire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinataire_id');
    }
}
