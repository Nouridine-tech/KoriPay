<?php

use App\Http\Controllers\auth\AuthController;
use App\Http\Controllers\Client\TransfertController;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 |API ROUTES - KoriPay
 |---------------------------------------------------------------------------

 |Ici se trouvent toutes les routes de l'API de l'application.
 |Elles sont chargées automatiquement par Laravel et possèdent le préfixe "/api".
 |
 */

// ========================================================
// ROUTES PUBLIQUES (Accessibles sans connexion)
// ========================================================

//Route pour l'inscription autonome du client (Appelée par flutter)
Route::post('/inscription', [AuthController::class, 'inscription']);

// Vérification croisée (Téléphone / Machine) au lancement de l'application Flutter
Route::post('/auth/verifier-appareil', [AuthController::class, 'verifierEmpreinteAppareil']);

// Route pour la connexion classique (Uniquement autorisée si l'appareil est déjà lié)
Route::post('/login', [AuthController::class, 'login']);

// Route pour répondre aux questions de réinitialisation du PIN
Route::post('/recuperation/verifier-identite', [\App\Http\Controllers\Auth\RecuperationController::class, 'verifierIdentite']);

// Route pour réinitialiser le PIN
Route::post('/recuperation/reinitialiser-pin', [\App\Http\Controllers\Auth\RecuperationController::class, 'reinitialiserPIN']);

// FLUX SÉCURISÉ NOUVEL APPAREIL ÉTAPE 1 : Vérification d'identité KYC et envoi de l'OTP par e-mail
Route::post('/login/nouvel-appareil/initier', [AuthController::class, 'initierNouvelAppareil']);

// FLUX SÉCURISÉ NOUVEL APPAREIL ÉTAPE 2 : Validation de l'OTP, liaison définitive et ouverture de session
Route::post('/login/nouvel-appareil/valider', [AuthController::class, 'validerNouvelAppareil']);

// ========================================================
// ROUTES PROTEGEES (Nécessitent un Token Sanctum valide)
// ========================================================
Route::middleware('auth:sanctum')->group(function () {
    // Route pour récupérer le profil de l'utilisateur connecté
    Route::get('/user', [\App\Http\Controllers\Client\ProfilController::class, 'voirProfil']);

    /**
     * OPERATIONS CLIENT
     */
    // Route pour les transferts d'argent entre clients(initier)
    Route::post('/client/transfert/initier', [TransfertController::class, 'initierTransfert']);

    // Route pour les transferts d'argent entre clients(confirmer)
    Route::post('/client/transfert/confirmer', [TransfertController::class, 'confirmerTransfert']);

    /**
     * OPERATIONS GUICHET
     */
    // Routes pour les opérations de dépôt de l'administration
    Route::post('/admin/depot', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'depot']);

    //Routes pour les opérations de retrait de l'administration(initiation)
    Route::post('/admin/retrait/initier', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'initierRetrait']);

    //Routes pour les opérations de retrait de l'administration(confirmation)
    Route::post('/admin/retrait/confirmer', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'confirmerRetrait']);

    // Routes pour modifier le profile d'un utilisateur en utilisant la methode KYK
    Route::put('/admin/client/modifier-identite', [\App\Http\Controllers\Admin\AdminController::class, 'modifierIdentiteClient']);

    /**
     * OPERATIONS TRANSACTIONS
     */
    //Routes pour récupérer l'historique des transactions
    Route::get('/client/transactions', [\App\Http\Controllers\Client\TransactionController::class, 'index']);

    //Routes pour récupérer le détail d'une seule transaction
    Route::get('/client/transactions/{reference}', [\App\Http\Controllers\Client\TransactionController::class, 'show']);

    /**
     * OPERATIONS FIDELITE
     */
    // Routes pour les consultations des points de fidélité
    Route::get('/client/fidelite/solde', [\App\Http\Controllers\Client\FideliteController::class, 'monSolde']);

    // Route pour convertir les points accumulés en argent de compte
    Route::post('/client/fidelite/convertir', [\App\Http\Controllers\Client\FideliteController::class, 'convertirPoints']);

    /**
     * OPERATIONS PROFIL CLIENT
     */

    // Route pour changer le code PIN
    Route::post('/client/profil/changer-pin', [\App\Http\Controllers\Client\ProfilController::class, 'changerMdp']);

    //Route pour configurer la question secrète du client connecté
    Route::post('/client/profil/question-secrete', [\App\Http\Controllers\Client\ProfilController::class, 'configurerQuestionSecrete']);

    // Route pour la déconnexion de l'utilisateur (Client ou Admin)
    Route::post('/logout', [AuthController::class, 'logout']);

});
