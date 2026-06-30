<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    //token et notification
    use HasApiTokens, HasFactory, Notifiable;
    //Liste des champs tapés par user
    protected $fillable = [
        'nom',
        'prenom',
        'telephone',
        'email',
        'code_pin',
        'solde',
        'role',
        'statut',
    ];

    //Masquage du code PIN et le remember_token
    protected $hidden = ['code_pin', 'remember_token'];

    //RELATION 1 à 1 : Un utilisateur possède un et un seul compte fidelité
    public function fidelite(): HasOne
    {
        return $this->hasOne(Fidelite::class, 'user_id');

    }

    //RELATION 1 à N : Un utilisateur peut envoyer plusieurs transactions(cote expediteur)
    public function transfertsEnvoyes(): HasMany
    {
        return  $this->hasMany(Transaction::class, 'expediteur_id');

    }

    //RELATION 1 à N : Un utilisateur peut recevoir plusieurs transactions (cote destinataire)
    public function transfertsRecus(): HasMany
    {
        return  $this->hasMany(Transaction::class, 'destinataire_id');
    }

    //RELATION 1 à N : Un utilisateur peut générer plusieurs codes OTP au fil du temps
    public function otps(): HasMany
    {
        return $this->hasMany(VerificationOtp::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
