<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TransactionController extends Controller
{
    /**
     * 1. RECUPERATION DE L'HISTORIQUE COMPLET DU CLIENT CONNECTE
     */

    #[OA\Get(
        path: "/client/transactions",
        operationId: "clientListTransactions",
        summary: "Récupérer l'historique complet des transactions du client",
        description: "Retourne la liste complète et paginée (par paquets de 15) de toutes les opérations (dépôts, retraits, transferts envoyés et reçus) rattachées au compte du client connecté.",
        tags: ["Module Client : Transactions"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Historique récupéré avec succès (Données enveloppées dans une structure de pagination native)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "donnees", type: "object", description: "Objet contenant la liste des transactions et les métadonnées de pagination (current_page, data, total...)")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Jeton manquant ou expiré)"
            )
        ]
    )]

    public function index(Request $request)
    {
        $client = $request->user();

        // Récupération de toutes les transactions ou le client est soit l'expéditeur, soit le destinataire
        $transactions = Transaction::where(function ($query) use ($client) {
            $query->where('expediteur_id', $client->id)
                ->orWhere('destinataire_id', $client->id);
        })
            ->latest() //Equivalent à "orderBy('created_at', 'desc')"
        ->paginate(15); //Pagination de 15 éléments par page pour économiser la bande passante mobile

        return response()->json([
            'statut' => 'success',
            'donnees' => $transactions,
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * 2. RECUPERATION DES DETAILS D'UNE TRANSACTION SPECIFIQUE VIA SA REFERENCE
     */

    #[OA\Get(
        path: "/client/transactions/{reference}",
        operationId: "clientShowTransaction",
        summary: "Récupérer le détail d'une transaction spécifique",
        description: "Permet de charger les détails complets d'une opération financière à partir de sa référence unique globale, à condition que le client connecté en soit l'expéditeur ou le destinataire (sécurité IDOR).",
        tags: ["Module Client : Transactions"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(
                name: "reference",
                in: "path",
                required: true,
                description: "La référence de la transaction (Ex: KP-TX-XXXXXXXXXX)",
                schema: new OA\Schema(type: "string")
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: "Détails de la transaction chargés avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "donnees", type: "object", description: "Enregistrement complet de la transaction")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Transaction introuvable ou accès non autorisé pour ce compte",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Transaction introuvable ou vous n'avez pas l'autorisation d'y accéder.")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Jeton invalide)"
            )
        ]
    )]

    public function show(Request $request, $reference)
    {
        $client = $request->user();

        // Recherche de la transaction par sa référence unique globale
        $transaction = Transaction::where('reference', $reference)
            ->where(function ($query) use ($client) {
                $query->where('expediteur_id', $client->id)
                    ->orWhere('destinataire_id', $client->id);
            })
            ->first();

        if (!$transaction) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ' Transaction introuvable ou vous n\'avez pas l\'autorisation d\'y accéder.'
            ], 404); // Code HTTP 404 : Not found
        }

        return response()->json([
            'statut' => 'success',
            'donnees' => $transaction,
        ], 200); // Code HTTP 200 : OK
    }
}
