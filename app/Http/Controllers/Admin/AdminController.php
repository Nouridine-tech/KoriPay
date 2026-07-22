<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class AdminController extends Controller
{
    /**
     * MODIFICATION RÉGLEMENTAIRE DE L'IDENTITÉ D'UN CLIENT (KYC)
     */
    #[OA\Put(
        path: "/admin/client/modifier-identite",
        operationId: "adminModifierIdentiteClient",
        summary: "Modifier le nom et prénom d'un client (Réservé Admin)",
        description: "Permet à l'administrateur de modifier l'identité d'un client après vérification physique de ses justificatifs officiels (Conformité réglementaire).",
        tags: ["Module Admin : Gestion Clients"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["client_id", "nom", "prenom"],
                properties: [
                    new OA\Property(property: "client_id", type: "integer", example: 2),
                    new OA\Property(property: "nom", type: "string", example: "Ndiaye"),
                    new OA\Property(property: "prenom", type: "string", example: "Fatou")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Identité mise à jour"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Client introuvable")
        ]
    )]
    public function modifierIdentiteClient(Request $request)
    {
        // Sécurité Périmétrique de Rôle
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); //Code HTTP 403 :
        }

        //Validation des données
        $validateur = Validator::make($request->all(), [
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'nom' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 :
        }

        //Verifier si le client existe dans la BD
        $client = User::where('id', $request->client_id)
            ->where('role', 'client')
            ->first();
        if (!$client) {
            return response()->json(['statut' => 'erreur',
                'message' => 'Client introuvable.'
            ], 404);
        }

        //Validation de la nouvelle identité
        $client->update([
            'nom' => $request->nom,
            'prenom' => $request->prenom
        ]);

        return response()->json([
            'statut' => 'success',
            'message' => 'Identité du client mise à jour avec succès conformément aux pièces justificatives.'
        ], 200);
    }
}
