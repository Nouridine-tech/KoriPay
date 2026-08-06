<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Fidelite;
use App\Models\Transaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class FideliteController extends Controller
{
    /**
     * 1. CONSULTATION DU SOLDE DE POINTS DE FIDELITE
     */

    #[OA\Get(
        path: "/client/fidelite/solde",
        operationId: "clientGetFideliteSolde",
        summary: "Consulter le compteur de points de fidélité du client",
        description: "Permet au client connecté de récupérer son solde actuel de points cumulés ainsi que l'historique de son total de gains depuis l'application mobile Flutter.",
        tags: ["Module Client : Programme de Fidélité"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Solde de points de fidélité récupéré avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(
                            property: "donnees",
                            type: "object",
                            properties: [
                                new OA\Property(property: "solde_points", type: "integer", example: 350, description: "Points actuellement disponibles pour conversion"),
                                new OA\Property(property: "total_gains", type: "integer", example: 1200, description: "Cumul historique de tous les points gagnés")
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Espace ou compte de fidélité introuvable pour cet utilisateur",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Compte de fidélité introuvable")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Jeton de session manquant)"
            )
        ]
    )]

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

    #[OA\Post(
        path: "/client/fidelite/convertir",
        operationId: "clientConvertFidelitePoints",
        summary: "Convertir les points de fidélité accumulés en crédit monétaire",
        description: "Permet au client de transformer un montant choisi de ses points disponibles en argent réel injecté sur son solde principal (taux de conversion : 1 point = 2 FCFA, conversion minimale de 50 points). L'opération génère une écriture comptable automatisée.",
        tags: ["Module Client : Programme de Fidélité"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["points_a_convertir"],
                properties: [
                    new OA\Property(property: "points_a_convertir", type: "integer", example: 500, description: "Le nombre de points à convertir (minimum 50 points)")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Points convertis avec succès, solde utilisateur mis à jour",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Félicitations ! Vos points ont été convertis en crédit."),
                        new OA\Property(property: "donnees", type: "object", description: "Bilan des soldes après conversion")
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Solde de points insuffisant pour réaliser la conversion demandée",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Votre solde de point de fidélité est insuffisant pour cette opération.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation (champs manquants ou valeur inférieure à 50)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Incident lors de l'exécution financière encapsulée",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Défaillance technique lors de la conversion des points.")
                    ]
                )
            )
        ]
    )]

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

        // 2. Début du traitement monétaire sécurisé (Normes ACID d'atomicité)
        DB::beginTransaction();

        try {
            // SÉCURITÉ CRITIQUE CORRIGÉE : Utilisation du verrou pessimiste lockForUpdate() à l'intérieur de la transaction
            // Cela bloque toute tentative de conversion simultanée frauduleuse (Race Condition)
            $fidelite = Fidelite::lockForUpdate()->where('user_id', $client->id)->first();

            // Comparaison des points
            if (!$fidelite || $fidelite->solde_points < $pointsAConvertir) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'Votre solde de points de fidélité est insuffisant pour cette opération.',
                ], 400); // Code HTTP 400 : Requête incorrecte
            }

            // Calcul du gain financier (1 point = 2 FCFA)
            $montantGagne = $pointsAConvertir * 2;

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
