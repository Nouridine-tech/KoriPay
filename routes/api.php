<?php

use App\Http\Controllers\AuthController;
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
    // Route de test par défaut pour récupérer le profil de l'utilisateur connecté
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Route pour les transferts d'argent entre clients


    // Routes pour la consultations des points de fidélité


    // Routes pour les opérations de dépôt de l'administration
    Route::post('/admin/depot', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'depot']);

    //Routes pour les opérations de retrait de l'administration(initiation)
    Route::post('/admin/retrait/initier', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'initierRetrait']);

    //Routes pour les opérations de retrait de l'administration(confirmation)
    Route::post('/admin/retrait/confirmer', [\App\Http\Controllers\Admin\OperationGuichetController::class, 'confirmerRetrait']);
});
