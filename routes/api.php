<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Client\TransfertController;
use Illuminate\Http\Request;
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

//Routes pour la connexion d'un utilisateur - Client ou Admin (Appelée par flutter ou React)
Route::post('/login', [AuthController::class, 'login']);

// ========================================================
// ROUTES PROTEGEES (Nécessitent un Token Sanctum valide)
// ========================================================
Route::middleware('auth:sanctum')->group(function () {
    // Route pour récupérer le profil de l'utilisateur connecté
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

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
    // Route pour modifier le nom ou le prénom
    Route::put('/client/profil/modifier', [\App\Http\Controllers\Client\ProfilController::class, 'mettreAJourProfil']);

    // Route pour changer le code PIN
    Route::post('/client/profil/changer-pin', [\App\Http\Controllers\Client\ProfilController::class, 'changerMdp']);
});
