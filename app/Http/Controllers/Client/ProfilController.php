<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ProfilController extends Controller
{
    /**
     * 1. CONSULTER LES INFOS DU PROFIL ET LE SOLDE EN TEMPS REEL
     */
    public function voirProfil(Request $request)
    {
        // Récupération de l'utilisateur connecté via son jeton Sanctum
        $client = $request->user();

        return response()->json([
            'statut' => 'success',
            'donnees' => [
                'id' => $client->id,
                'nom' => $client->nom,
                'prenom' => $client->prenom,
                'email' => $client->email,
                'telephone' => $client->telephone,
                'solde_actuel' => $client->solde,
                'cree_le' => $client->created_at->format('d/m/Y'),
            ]
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * 2. METTRE A JOUR LES INFORMATION DE BASE
     */
    public function mettreAJourProfil(Request $request)
    {
        $client = $request->user();
        $validateur = Validator::make($request->all(), [
            'nom' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422);
        }

        // Sauvegarde des modifications
        $client->update([
            'nom' => $request->nom,
            'prenom' => $request->prenom,
        ]);

        return response()->json([
            'statut' => 'success',
            'message' => 'Votre profil a été mis à jour avec succès.',
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * 3. MODIFIER LE CODE PIN / MOT DE PASSE EN TOUTE SECURITE
     */
    public function changerMdp(Request $request)
    {
        $client = $request->user();
        //Validation stricte des données entrantes
        $validateur = Validator::make($request->all(), [
            'ancien_mot_de_passe' => ['required', 'string'],
            'nouveau_mot_de_passe' => ['required', 'string', 'min:4', 'confirmed'], //confirmed permet de chercher le champ nouveau_Mot_De_Pase_Confirmation
        ]);
        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422);
        }

        // Vérification cruciale si l'ancien MDP fourni est le bon
        if (!Hash::check($request->ancien_mot_de_passe, $client->code_pin)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'L\'ancien mot de passe saisie est incorrect.'
            ], 400);
        }

        //Mise à jour du mot de passe haché en base de données
        $client->update([
            'code_pin' => Hash::make($request->nouveau_mot_de_passe)
        ]);

        return response()->json([
            'statut' => 'success',
            'message' => 'Votre mot de passe a été modifié avec succès.'
        ], 200); // Code HTTP 200 : OK
    }
}
