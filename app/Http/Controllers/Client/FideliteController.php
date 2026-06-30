<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Fidelite;
use App\Models\Transaction;
use Dotenv\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FideliteController extends Controller
{
    /**
     * 1. CONSULTATION DU SOLDE DE POINTS DE FIDELITE
     */
    public function monSolde(Request $request)
    {
        $client = $request->user();

        // Récupération du compte de fidélité associé au clinet connecté
        $fidelite = Fidelite::where('user_id', $client->id)->first();

        if (!$fidelite) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Compte de fidélité introuvable',
            ], 404); // Code HTTP 404 : Not found
        }

        return response()->json([
            'statut' => 'success',
            'donnees' => [
                'solde_points' => $fidelite->solde_points,
                'total_gains' => $fidelite->total_gains,
            ]
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * 2. CONVERSION DES POINTS EN CREDIT MONETAIRE
     */
    public function convertirPoints(Request $request)
    {
        $client = $request->user();
        // Validation : Le client doit envoyer un nombre de points positif et entier
        $validateur = Validator::make($request->all(), [
            'points_a_convertir' => ['required', 'integer', 'min:50'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors(),
            ], 422);
        }

        $pointsAConvertir = (int) $request->points_a_convertir;

        // Récupération du compte de fidété avec verrouillage de sécurité
        $fidelite = Fidelite::where('user_id', $client->id)->first();

        if (!$fidelite || $fidelite->solde_points < $pointsAConvertir) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Votre solde de point de fidélité est insuffisant pour cette opération.',
            ], 400);
        }

        // Calcul du gain financier (1 point = 2 FCFA)
        $montantGagne = $pointsAConvertir * 2;

        // Début de traitement monétaire sécurisé
        DB::beginTransaction();

        try {
            // A. Déduction des points de fidélité
            $fidelite->solde_points -= $pointsAConvertir;
            $fidelite->save();

            // B. Crédit immédiat du solde principal du client
            $client->solde += $montantGagne;
            $client->save();

            // C. Génération de la référence unique de conversion
            $referenceUnique = 'KP-FID-' .strtoupper(Str::random(10));

            // D. Inscription de l'opération dans la table 'transaction' pour l'historique
            Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => null,
                'destinataire_id' => $client->id,
                'montant' => $montantGagne,
                'frais' => 0.00,
                'type' => 'depot',
                'statut' => 'complete',
            ]);

            // Validation definitive dans PostgreSQL
            DB::commit();

            return response()->json([
                'statut' => 'success',
                'message' => 'Félicitations ! Vos points ont été convertis en crédit.',
                'donnees' => [
                    'points_convertis' => $pointsAConvertir,
                    'argent_credite' => $montantGagne,
                    'nouveau_solde_points' => $fidelite->solde_points,
                    'nouveau_solde_compte' => $client->solde
                ]
            ], 200); // Code HTTP 200 : OK
        } catch (\Exception $exception) {
            DB::rollBack();

            return response()->json([
                'statut' => 'erreur',
                'message' => 'Défaillance technique lors de la conversion des points.',
                'erreur_technique' => $exception->getMessage(),
            ], 500);
        }
    }
}
