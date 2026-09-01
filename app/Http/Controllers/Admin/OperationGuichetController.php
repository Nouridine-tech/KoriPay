<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VerificationOtp;
use App\Notifications\FactureTransactionNotification;
use App\Notifications\CodeOtpNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class OperationGuichetController extends Controller
{
    /**
     * EFFECTUER UN DEPÔT D'ARGENT (Guichet Administration -> compte Client)
     */

    #[OA\Post(
        path: "/admin/depot",
        operationId: "adminDepotFonds",
        summary: "Effectuer un dépôt de fonds sur le compte d'un client",
        description: "Permet à un administrateur ou un agent authentifié de créditer le compte d'un client depuis la console de guichet React. Génère une écriture comptable et transmet une facture par e-mail au bénéficiaire.",
        tags: ["Module Admin : Opérations Guichet"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "montant"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "+221771234567", description: "Numéro de téléphone du client bénéficiaire"),
                    new OA\Property(property: "montant", type: "number", example: 50000, description: "Montant en FCFA à déposer")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Dépôt effectué avec succès et solde mis à jour",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Dépôt effectué avec succés !"),
                        new OA\Property(property: "donnees", type: "object", description: "Détails du reçu de dépôt généré")
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Opération rejetée car le compte client cible est suspendu",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Impossible de créditer ce compte car il est actuellement suspendu.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Action non autorisée (l'utilisateur connecté n'est ni admin ni agent)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Action non autorisée.")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Aucun client KoriPay actif trouvé avec ce numéro",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Aucun client trouvé avec ce numéro de téléphone")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation (champs obligatoires manquants ou montant inférieur au minimum)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Incident technique ou crash système au cours de l'écriture en base de données",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Une erreur technique est survenue lors du dépôt.")
                    ]
                )
            )
        ]
    )]

    public function depot(Request $request)
    {
        //1. Validation des données saisies dans la console Réact
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:100'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 : Entité non traitée
        }

        //2. Recherche du client bénéficiaire par son numéro de téléphone
        $client = User::where('telephone', $request->telephone)->where('role', 'client')->first();

        if (!$client) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Aucun client trouvé avec ce numéro de téléphone'
            ], 404); //Code HTTP 404 : Introuvable
        }

        if ($client->statut === 'suspendu'){
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Impossible de créditer ce compte car il est actuellement suspendu.'
            ], 400); //Code  HTTP 400 : Requête incorrecte
        }
        //3. Utilisateur d'une TRANSACTION DE BASE DE DONNEES( si l'envoi du mail plante, le solde du client n'est pas modifié (pas de fausse monnaie))
        DB::beginTransaction();

        try {
            //Génération d'une référence unique inaltérable pour le reçu (Ex: KP-DEP-XXXXXXXX)
            $referenceUnique = 'KP-DEP-' .strtoupper(Str::random(10));

            //Mise à jour du solde du client dans PostgreSQL
            $client->solde += $request->montant;
            $client->save();

            //Enregistrement du mouvement financier dans la table 'transactions' conformément au schéma
            $transaction = Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => null,
                'destinataire_id' => $client->id,
                'effectue_par_id' => $request->user()->id,
                'montant' => $request->montant,
                'frais' => 0.00,
                'type' => 'depot',
                'statut' => 'complete',
            ]);

            //Validation définitive des écritures dans PostgreSQL
            DB::commit();

            //5. Envoi immédiat de la facture numérique par e-mail au client
            try {
                // Protégé dans son propre bloc pour éviter qu'une panne SMTP n'annule la transaction monétaire valide
                $client->notify(new FactureTransactionNotification($transaction, 'destinataire'));
            } catch (\Exception $emailException) {
                // Enregistrement silencieux de la panne réseau dans les logs Laravel sans perturber l'expérience utilisateur
                Log::error('Échec d\'envoi de la facture par e-mail pour le dépôt ' . $referenceUnique . ' : ' . $emailException->getMessage());
            }

            //Réponse JSON renvoyée à la console d'administration React
            return response()->json([
                'statut' => 'success',
                'message' => 'Dépôt effectué avec succés !',
                'donnees' => [
                    'reference' => $transaction->reference,
                    'client_nom' => $client->prenom.' '.$client->nom,
                    'montant' => $transaction->montant,
                    'nouveau_solde' => $client->solde
                ]
            ], 200); //Code HTTP 200 : OK
        } catch (\Exception $exception) {
            //En cas de panne technique ou de bug au milieu du processus, on annule tout immédiatement
            DB::rollBack();

            return response()->json([
                'statut' => 'erreur',
                'message' => 'Une erreur technique est survenue lors du dépôt.',
                'erreur_technique' => $exception->getMessage()
            ], 500); //Code HTTP 500 : Erreur interne du serveur
        }
    }


    /**
     * RETRAIT ETAPE 1 : INITIALISATION ET ENVOI DE L'OTP PAR MAIL
     */

    #[OA\Post(
        path: "/admin/retrait/initier",
        operationId: "adminInitierRetrait",
        summary: "Étape 1 : Initialiser un retrait au guichet",
        description: "Permet à un administrateur ou un agent de guichet de lancer une demande de retrait pour un client. Un code OTP à 6 chiffres est généré et envoyé par e-mail au client.",
        tags: ["Module Admin : Opérations Guichet"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "montant"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "+221771234567", description: "Téléphone du client qui retire"),
                    new OA\Property(property: "montant", type: "number", example: 25000, description: "Montant du retrait")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Code OTP généré et envoyé par e-mail",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Code OTP de validation généré et envoyé par e-mail au client.")
                    ]
                )
            ),
            new OA\Response(response: 400, description: "Solde insuffisant pour effectuer le retrait"),
            new OA\Response(response: 403, description: "Action non autorisée (réservé aux admins et agents)"),
            new OA\Response(response: 404, description: "Aucun client trouvé avec ce numéro"),
            new OA\Response(response: 422, description: "Erreur de validation des données fournies")
        ]
    )]

    public function initierRetrait(Request $request)
    {
        //1. Validation des champs d'entrée reçus du backoffice React
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:500'],
        ]);

        if ($validateur->fails()) {
            return response()->json(['statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); //Code HTTP 422 :
        }

        // 2. Recherche du client qui demande à faire un retrait
        $client = User::where('telephone', $request->telephone)->where('role', 'client')->first();
        if (!$client) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Aucun client avec ce numero.'
            ], 404); //Code HTTP 404 :
        }

        // 3. Vérification si le solde disponible couvre le retrait
        if ($client->solde < $request->montant) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Le solde du client est insuffisant pour effectuer ce retrait.'
            ], 400); // Code HTTP 400 : Requête erronée
        }

        // 4. Génération d'un code OTP aléatoire à 6 chiffres avec random_int
        $codeOtp = (string) random_int(100000, 999999);

        // 5. Enregistrement du jeton dans la table 'verification_otps'
        VerificationOtp::create([
            'user_id' => $client->id,
            'otp' => $codeOtp,
            'type_action' => 'retrait',
            'montant' => $request->montant,
            'expire_a' => Carbon::now()->addMinutes(5),
            'est_utilise' => false,
        ]);

        // 6. Expédition immédiate du code secret vers le mail du client
        $client->notify(new CodeOtpNotification($codeOtp, 'retrait'));
        return response()->json([
            'statut' => 'success',
            'message' => 'Code OTP de validation généré et envoyé par e-mail au client.'
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * RETRAIT ETAPE 2 : VERIFICATION DE L'OTP, DEBIT ET FACTURATION (CONFIRMER RETRAIT)
     */

    #[OA\Post(
        path: "/admin/retrait/confirmer",
        operationId: "adminConfirmerRetrait",
        summary: "Étape 2 : Valider et décaisser le retrait",
        description: "Vérifie le code OTP fourni par le client. Si valide et non expiré, débite le compte du client et enregistre la transaction comptable de retrait. Accessible aux administrateurs et aux agents de guichet.",
        tags: ["Module Admin : Opérations Guichet"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "montant", "code_otp"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "+221771234567"),
                    new OA\Property(property: "montant", type: "number", example: 25000),
                    new OA\Property(property: "code_otp", type: "string", example: "123456", description: "Le code reçu par le client")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Retrait d'espèce validé avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Retrait d'espèce validé avec succés ! Argent remis au client."),
                        new OA\Property(property: "donnees", type: "object")
                    ]
                )
            ),
            new OA\Response(response: 400, description: "Code OTP incorrect, déjà consommé ou expiré / Solde insuffisant"),
            new OA\Response(response: 403, description: "Action non autorisée (réservé aux admins et agents)"),
            new OA\Response(response: 404, description: "Client introuvable"),
            new OA\Response(response: 422, description: "Erreur de validation"),
            new OA\Response(response: 500, description: "Défaillance technique lors du traitement de retrait")
        ]
    )]

    public function confirmerRetrait(Request $request)
    {
        // VAlidation du code OTP fourni de vive voix par le client
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'montant' => ['required', 'numeric'],
            'code_otp' => ['required', 'string', 'digits:6']
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
                ], 422);
        }

        $client = User::where(['telephone' => $request->telephone, 'role' => 'client'])->first();
        if (!$client) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Client introuvable.'
            ], 404); // Code HTTP 404 :
        }
        // 1. Algorithme de vérification de l'OTP en base de données
        $otpRecord = VerificationOtp::where('user_id', $client->id)
            ->where('otp', $request->code_otp)
            ->where('type_action', 'retrait')
            ->where('est_utilise', false)
            ->latest() //Analyse en priorité du jeton le plus récent
        ->first();

        // 2. Si aucun enregistrement ne correspond ou si le code est faux
        if (!$otpRecord) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Code OTP de validation incorrect ou déjà consommé.'
            ], 400); // Code HTTP 400 : Requête invalide
        }

        // 3. Vérification de la validité temporelle grâce à Carbon
        if ($otpRecord->expire_a->isPast()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Ce code OTP a expiré. Veuillez en générer un nouveau.'
            ], 400); // Code HTTP 400 : Requête invalide
        }

        // 4. SÉCURITÉ MONÉTIQUE ABSOLUE (Anti-Falsification) : On vérifie que le montant demandé correspond à l'initiation
        if ((float) $otpRecord->montant !== (float) $request->montant) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Tentative de falsification monétaire détectée. Le montant ne correspond pas au jeton généré.'
            ], 400); // Code HTTP 400 : Requête invalide
        }

        // 5. Traitement monétaire sécurisé encapsulé
        DB::beginTransaction();
        try {
            //Revérification de sécurité anti-fraude concurrente pour s'assurer que le solde est toujours disponible
            if ($client->solde < $request->montant) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'Solde insufissant.'
                ], 400); // Code HTTP 400 :
            }

            // 1. Consommation immédiate du code OTP pour empêcher toute réutilisation malveillante
            $otpRecord->est_utilise = true;
            $otpRecord->save();

            // 2. Soustraction des fonds virtuel du client
            $client->solde -= $request->montant;
            $client->save();

            // 3. Génération de la référence unique de débit
            $referenceUnique = 'KP-RET-' .strtoupper(Str::random(10));

            // 4. Ecriture comptable dans la table 'transactions'
            $transaction = Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => $client->id,
                'destinataire_id' => null,
                'effectue_par_id' => $request->user()->id,
                'montant' => $request->montant,
                'frais' => 0.00,
                'type' => 'retrait',
                'statut' => 'complete',
            ]);

            // 5. Validation finale et écriture persistante dans PostgreSQL
            DB::commit();

            // 6. Notification de retrait envoyée intantanément
            try {
                $client->notify(new FactureTransactionNotification($transaction, 'expediteur'));
            } catch (\Exception $emailException) {
                Log::error('Échec d\'envoi de la notification de retrait pour la transaction ' . $referenceUnique . ' : ' . $emailException->getMessage());
            }

            return response()->json([
                'statut' => 'success',
                'message' => 'Retrait d\'espèce validé avec succés ! Argent remis au client.',
                'donnees' => [
                    'reference' => $transaction->reference,
                    'montant' => $transaction->montant,
                    'solde_restant' => $client->solde,
                ]
            ], 200); //Code HTTP 200 : OK
        }catch (\Exception $exception){
            DB::rollBack();
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Défaillance technique lors de la validation du retrait.',
                'erreur_technique' => $exception->getMessage()
            ], 500); // Code HTTP 500 :
        }
    }
}
