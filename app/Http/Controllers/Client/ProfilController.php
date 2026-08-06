<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use OpenApi\Attributes as OA;

class ProfilController extends Controller
{
    /**
     * 1. CONSULTER LES INFOS DU PROFIL ET LE SOLDE EN TEMPS REEL
     */

    #[OA\Get(
        path: "/user",
        operationId: "clientVoirProfil",
        summary: "Consulter le profil et le solde en temps réel",
        description: "Permet de récupérer les informations personnelles et le solde actualisé de l'utilisateur connecté grâce à son jeton de session.",
        tags: ["Module Client : Profil et Sécurité"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: "Profil chargé avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(
                            property: "donnees",
                            type: "object",
                            properties: [
                                new OA\Property(property: "id", type: "integer", example: 1),
                                new OA\Property(property: "nom", type: "string", example: "Ndiaye"),
                                new OA\Property(property: "prenom", type: "string", example: "Awa"),
                                new OA\Property(property: "email", type: "string", example: "awa.ndiaye@isi.sn"),
                                new OA\Property(property: "telephone", type: "string", example: "+221771234567"),
                                new OA\Property(property: "solde_actuel", type: "number", example: 15000),
                                new OA\Property(property: "cree_le", type: "string", example: "08/07/2026")
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Jeton invalide ou absent)"
            )
        ]
    )]

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
     * 2. MODIFIER LE CODE PIN / MOT DE PASSE EN TOUTE SECURITE
     */

    #[OA\Post(
        path: "/client/profil/changer-pin",
        operationId: "clientChangerCodePin",
        summary: "Modifier le code PIN / mot de passe du client",
        description: "Permet au client connecté de modifier son code PIN de sécurité à 4 chiffres. Le système exige la validation de l'ancien code PIN et une confirmation du nouveau code pour éviter les erreurs de saisie.",
        tags: ["Module Client : Profil et Sécurité"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["ancien_mot_de_passe", "nouveau_mot_de_passe", "nouveau_mot_de_passe_confirmation"],
                properties: [
                    new OA\Property(property: "ancien_mot_de_passe", type: "string", example: "1234", description: "Le code PIN actuel de l'utilisateur"),
                    new OA\Property(property: "nouveau_mot_de_passe", type: "string", example: "4321", description: "Le nouveau code PIN (minimum 4 caractères/chiffres)"),
                    new OA\Property(property: "nouveau_mot_de_passe_confirmation", type: "string", example: "4321", description: "Confirmation stricte du nouveau code PIN")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Code PIN mis à jour avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Votre mot de passe a été modifié avec succès.")
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "L'ancien code PIN fourni est incorrect",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "L'ancien mot de passe saisie est incorrect.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation (champs manquants, trop courts ou non concordants)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Session expirée)"
            )
        ]
    )]

    public function changerMdp(Request $request)
    {
        $client = $request->user();
        //Validation stricte des données entrantes
        $validateur = Validator::make($request->all(), [
            'ancien_mot_de_passe' => ['required', 'string'],
            'nouveau_mot_de_passe' => ['required', 'string', 'digits:4', 'confirmed'], //confirmed permet de chercher le champ nouveau_Mot_De_Pase_Confirmation
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
                'message' => 'L\'ancien mot de passe saisi est incorrect.'
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

    /**
     * 3. CONFIGURATION DE LA QUESTION SECRETE (PARAMETRES)
     */

    #[OA\Post(
        path: "/client/profil/question-secrete",
        operationId: "clientConfigurerQuestionSecrete",
        summary: "Configurer la question secrète du client connecté",
        description: "Permet au client d'ajouter ou de modifier sa question secrète et sa réponse depuis ses paramètres applicatifs. Ces informations serviront à l'authentifier en cas de réinitialisation de code PIN.",
        tags: ["Module Client : Profil et Sécurité"],
        security: [["sanctum" => []]], // Cette ligne force l'icône de cadenas sur Swagger pour exiger le jeton Sanctum
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["question_secrete", "response_secrete"],
                properties: [
                    new OA\Property(
                        property: "question_secrete",
                        type: "string",
                        example: "Quel est le nom de votre premier animal de compagnie ?",
                        description: "La question choisie par le client"
                    ),
                    new OA\Property(
                        property: "response_secrete",
                        type: "string", example: "Rex",
                        description: "La réponse correspondante (sera enregistrée de manière hachée)"
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Question secrète enregistrée avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Question secrète configurée avec succès.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation (champs obligatoires manquants ou trop longs)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Jeton absent ou expiré)"
            )
        ]
    )]

   public function configurerQuestionSecrete(Request $request)
   {
       //Recuperation des informations du client connecté
       $client = $request->user();

       //Validation des données
       $validateur = Validator::make($request->all(), [
           'question_secrete' => ['required', 'string', 'max:255'],
           'response_secrete' => ['required', 'string', 'max:255'],
       ]);

       if ($validateur->fails()) {
           return response()->json([
               'statut' => 'erreur',
               'erreurs' => $validateur->errors()
           ],422); // Code HTTP 422 :
       }

       //Ajout de la question et reponse secrete
       $client->update([
           'question_secrete' => $request->question_secrete,
           // Sécurité monétique : On force le passage en minuscules (strtolower) pour éviter les rejets dus aux majuscules,
           // puis on hache la réponse (Hash::make) pour qu'elle soit illisible en base de données, même pour un admin.
           'response_secrete' => Hash::make(strtolower($request->response_secrete))
       ]);

       //Reponse json
       return response()->json([
           'statut' => 'success',
           'message' => 'Question secrète configurée avec succès.'
       ], 200); // Code HTTP 200 : OK
   }
}
