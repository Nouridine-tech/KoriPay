<?php

namespace App\Http\Controllers;

use App\Models\Fidelite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    /**
     * 1. INSCRIPTION AUTONOME DU CLIENT (Application Mobile Futter)
     */
    public function inscription(Request $request)
    {
        // Validation des données entrantes pour éviter les bugs et injections
        $validated = Validator::make($request->all(), [
            'prenom' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'unique:users,telephone'], //Pour eviter les doublons de comptes
            'email' => ['required', 'email', 'unique:users,email'], //Indispensable pour les e-factures
            'code_pin' => ['required', 'string', 'digits:4'],
        ]);

        //Si la validation échoue, on retourne immédiatement les erreurs au format JSON
        if ($validated->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validated->errors()
            ],422); //Code HTTP 422 : Entité non traitée
        }

        // Création de l'utilisateur dans la table postgreSQL 'users'
        $user = User::create([
            'prenom' => $request->prenom,
            'nom' => $request->nom,
            'telephone' => $request->telephone,
            'email' => $request->email,
            'code_pin' => Hash::make($request->code_pin),
            'solde' => 0.00,
            'role' => 'client',
            'statut' => 'actif'
        ]);

        //Création automatique de son compte Fidélité associé
        Fidelite::create([
            'user_id' => $user->id,
            'solde_points' => 0,
            'total_gains' => 0
        ]);

        // Génération du Jeton d'accès (Token) via Laravel Sanctum
        $token = $user->createToken('kori_token_session')->plainTextToken;

        // Réponse envoyée au smartphone Flutter
        return response()->json([
            'statut' => 'success',
            'message' => 'Félicitations, votre compte KoriPay a été créé !',
            'token' => $token,
            'client' => $user,
        ], 201); //Code HTTP 201 : Ressource créée avec succès
    }
    /**
     * 2. CONNEXION DU CLIENT OU DE L'ADMIN (Téléphone + Code PIN)
     */
    public function login(Request $request)
    {
        //Validation basique des champs requis
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'code_pin' => ['required', 'string'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); //Code HTTP 422 Entité non traitée
        }

        // Recherche de l'utilisateur par son numéro de téléphone
        $user = User::where('telephone', $request->telephone)->first();

        //Sécurité : On vérifie si l'utilisateur existe ET si son code PIN correspond au hash stocké
        if (!$user || !Hash::check($request->code_pin, $user->code_pin)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Numero ou code PIN incorrect.'
            ], 401); //Code HTTP 401 : Non autorisé
        }

        //Vérification de l'état du compte (Sécurité contre les fraude)
        if ($user->statut === 'suspendu'){
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Votre compte est suspendu. Veillez contacter le support KoriPay'
            ], 403); //Code HTTP 403 : Interdit
        }

        //Nettoyage : On supprime ses anciens tokens pour éviter l'accumulation
        $user->tokens()->delete();

        // Génération du nouveau Jeton d'accés pour maintenir sa session active
        $token = $user->createToken('kori_token_session')->plainTextToken;

        return response()->json([
            'statut' => 'success',
            'message' => 'Connexion réussi avec succés.',
            'token' => $token,
            'user' => $user,
        ], 200); //Code HTTP 200: OK
    }
}
