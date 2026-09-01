<?php

use App\Http\Controllers\Auth\AuthController;
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

// Route pour l'inscription autonome du client (Appelée par flutter)
Route::post('/inscription', [AuthController::class, 'inscription']);

// Route pour la Vérification croisée (Téléphone / Machine) au lancement de l'application Flutter
Route::post('/auth/verifier-appareil', [AuthController::class, 'verifierEmpreinteAppareil']);

// Route pour la connexion classique (Uniquement autorisée si l'appareil est déjà lié)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Route pour répondre aux questions de réinitialisation du PIN
Route::post('/recuperation/verifier-identite', [\App\Http\Controllers\Auth\RecuperationController::class, 'verifierIdentite'])->middleware('throttle:5,1');

// Route pour réinitialiser le PIN
Route::post('/recuperation/reinitialiser-pin', [\App\Http\Controllers\Auth\RecuperationController::class, 'reinitialiserPIN'])->middleware('throttle:5,1');

// FLUX SÉCURISÉ NOUVEL APPAREIL ÉTAPE 1 : Vérification d'identité KYC et envoi de l'OTP par e-mail
Route::post('/login/nouvel-appareil/initier', [AuthController::class, 'initierNouvelAppareil']);

// FLUX SÉCURISÉ NOUVEL APPAREIL ÉTAPE 2 : Validation de l'OTP, liaison définitive et ouverture de session
Route::post('/login/nouvel-appareil/valider', [AuthController::class, 'validerNouvelAppareil'])->middleware('throttle:5,1');

// ========================================================
// ROUTES PROTEGEES (Nécessitent un Token Sanctum valide)
// ========================================================
Route::middleware('auth:sanctum')->group(function () {

    // Route pour récupérer le profil de l'utilisateur connecté (peu importe son rôle)
    Route::get('/user', [\App\Http\Controllers\Client\ProfilController::class, 'voirProfil']);

    // Route pour la déconnexion de l'utilisateur (Client, Admin ou Agent)
    Route::post('/logout', [AuthController::class, 'logout']);

    /**
     * =====================================================
     * ROUTES CLIENT (role:client uniquement)
     * =====================================================
     */
    Route::middleware('role:client')->group(function () {

        // --- Transferts ---
        Route::post('/client/transfert/initier', [TransfertController::class, 'initierTransfert']);
        Route::post('/client/transfert/confirmer', [TransfertController::class, 'confirmerTransfert']);

        // --- Historique des transactions du client connecté ---
        Route::get('/client/transactions', [\App\Http\Controllers\Client\TransactionController::class, 'index']);
        Route::get('/client/transactions/{reference}', [\App\Http\Controllers\Client\TransactionController::class, 'show']);

        // --- Fidélité ---
        Route::get('/client/fidelite/solde', [\App\Http\Controllers\Client\FideliteController::class, 'monSolde']);
        Route::post('/client/fidelite/convertir', [\App\Http\Controllers\Client\FideliteController::class, 'convertirPoints']);

        // --- Profil ---
        Route::post('/client/profil/changer-pin', [\App\Http\Controllers\Client\ProfilController::class, 'changerMdp']);
        Route::post('/client/profil/question-secrete', [\App\Http\Controllers\Client\ProfilController::class, 'configurerQuestionSecrete']);
    });

    /**
     * =====================================================
     * OPERATIONS GUICHET (role:admin OU role:agent)
     * =====================================================
     */
    Route::middleware('role:admin,agent')->group(function () {
        //--- Depôt ---
        Route::post('/admin/depot', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'depot']);

        //--- Retrait ---
        Route::post('/admin/retrait/initier', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'initierRetrait']);
        Route::post('/admin/retrait/confirmer', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'confirmerRetrait'])->middleware('throttle:5,1');
    });

    /**
     * =====================================================
     * GESTION DES COMPTES + SUPERVISION (role:admin uniquement)
     * =====================================================
     */
    Route::middleware('role:admin')->group(function () {

        // --- Creer un compte admin ---
        Route::post('/admin/creer-admin', [\App\Http\Controllers\Admin\AdminController::class, 'creerAdmin']);

        // --- Voir la liste des utilisateurs ---
        Route::get('/admin/comptes/{type}', [\App\Http\Controllers\Admin\AdminController::class, 'voirTousLesComptes'])
            ->where('type', 'client|agent|admin');

        //--- Voir un compte en particulier ---
        Route::get('/admin/comptes/{type}/{id}', [\App\Http\Controllers\Admin\AdminController::class, 'voirUnCompte'])
            ->where('type', 'client|agent|admin');

        //--- Suspendre un compte ---
        Route::put('/admin/comptes/{type}/{id}/suspendre', [\App\Http\Controllers\Admin\AdminController::class, 'suspendreCompte'])
            ->where('type', 'client|agent');

        //--- Geler un client ---
        Route::put('/admin/comptes/{type}/{id}/geler', [\App\Http\Controllers\Admin\AdminController::class, 'gelerCompte'])
            ->where('type', 'client|agent');

        //--- Réactiver un client ---
        Route::put('admin/comptes/{type}/{id}/reactiver', [\App\Http\Controllers\Admin\AdminController::class, 'reactiverCompte'])
            ->where('type', 'client|agent');

        //--- Modifier les infos d'un client (KYC) ---
        Route::put('/admin/client/modifier-identite', [\App\Http\Controllers\Admin\AdminController::class, 'modifierIdentiteClient']);

        // --- Supervision des transactions ---
        Route::get('/admin/transactions', [\App\Http\Controllers\Admin\AdminController::class, 'voirToutesLesTransactions']);
        Route::put('/admin/transactions/{reference}/annuler', [\App\Http\Controllers\Admin\AdminController::class, 'annulerTransaction']);
    });

});
