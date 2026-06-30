<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationOtp extends Model
{
    //Spécifions le nom exacte de la table car le pluriel automatique de laravel peut ne pas correspondre
    protected $table = 'verification_otps';

    //Champs autorisés à être enregistrés
    protected $fillable = [
        'user_id',
        'otp',
        'type_action',
        'expire_a',
        'est_utilise',
    ];

    //precision à laravel que expire_a doit être manipulé comme un objet date/heure carbon propre
    protected $casts = [
     'expire_a' => 'datetime',
     'est_utilise' => 'boolean',
    ];

    //RELATION INVERSE : Un code OTP appartient à un utilisateur précis
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
