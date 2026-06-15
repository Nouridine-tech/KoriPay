<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fidelite extends Model
{
    //Définition des champs autorisés
    protected $fillable = [
       'user_id',
       'solde_points',
       'total_gains',
    ];

    //RELATION INVERSE : Un compte de fidelité appartient à un utilisateur unique
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
