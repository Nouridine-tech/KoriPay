<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Fidelite;
use App\Models\TentativeConnexion;
use App\Models\User;
use App\Models\VerificationOtp;
use App\Notifications\NouvelAppareilNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    /**
     * 0. INSCRIPTION AUTONOME DU CLIENT (Application Mobile Futter)
     */

    #[OA\Post(
        path: "/inscription",
        operationId: "authInscription",
        summary: "Inscription d'un nouveau client",
        tags: ["Authentification"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["prenom", "nom", "telephone", "email", "code_pin"],
                properties: [
                    new OA\Property(property: "prenom", type: "string", example: "Mamadou"),
                    new OA\Property(property: "nom", type: "string", example: "Diallo"),
                    new OA\Property(property: "telephone", type: "string", example: "+221771234567"),
                    new OA\Property(property: "email", type: "string", format: "email", example: "mamadou.diallo@isi.sn"),
                    new OA\Property(property: "code_pin", type: "string", example: "1234"),
                    new OA\Property(property: "question_secrete", type: "string", example: "Votre ville natale ?", nullable: true),
                    new OA\Property(property: "response_secrete", type: "string", example: "Dakar", nullable: true)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Compte créé avec succès"),
            new OA\Response(response: 422, description: "Erreur de validation")
        ]
    )]

    public function inscription(Request $request)
    {
        // Validation des données entrantes pour éviter les bugs et injections
        $validated = Validator::make($request->all(), [
            'prenom' => ['required', 'string', 'max:255'],
            'nom' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'unique:users,telephone'], //Pour eviter les doublons de comptes
            'email' => ['required', 'email', 'unique:users,email'], //Indispensable pour les e-factures
            'code_pin' => ['required', 'string', 'digits:4'],
            'question_secrete' => ['nullable', 'string', 'max:255'],
            'response_secrete' => ['nullable', 'string', 'max:255', 'required_with:question_secrete'], //Pour le rendre dependant de la QS
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
            'statut' => 'actif',
            // Champs de sécurité facultatifs dès l'inscription pour la reinitialisation
            'question_secrete' => $request->question_secrete ?? null,
            'response_secrete' => $request->response_secrete ? Hash::make(strtolower($request->response_secrete)) : null,
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
     * 1. VERIFICATION CROISEE COMPTE / APPAREIL (Au démarrage de flutter)
     */
    #[OA\Post(
        path: "/auth/verifier-appareil",
        operationId: "authCheckDeviceUserMatch",
        summary: "Étape 0 : Vérifier la liaison entre le numéro et cet appareil",
        tags: ["Authentification"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "device_id"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "771111111"),
                    new OA\Property(property: "device_id", type: "string", example: "f7b129a0-c3bc")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Appareil déjà lié, afficher écran PIN normal"),
            new OA\Response(response: 202, description: "Nouvel appareil détecté, rediriger vers authentification forte"),
            new OA\Response(response: 404, description: "Numéro de téléphone inconnu")
        ]
    )]

    public function verifierEmpreinteAppareil(Request $request)
    {
        $validateur = Validator::make($request->all(), [
            'telephone' => 'required|string',
            'device_id' => 'required|string',
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 : Données non traitables
        }

        //1. On cherche d'abord si le client existe dans la BD
        $user = User::where('telephone', $request->telephone)->first();

        if (!$user) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Aucun compte KoriPay trouvé avec ce numéro de téléphone.'
            ], 404); //Code HTTP 404: Not Found
        }

        //2. LOGIQUE LOGICIELLE : On vérifie si cet appareil est enregistré pour ce compte précis
        $liaisonExiste = $user->devices()->where('device_id', $request->device_id)->exists();

        // LOGIQUE SI NOUVELLE APPAREIL : On bloque le passage immédiat
        if (!$liaisonExiste) {
            return response()->json([
                'statut' => 'nouvelle_appareil',
                'message' => 'Appareil inconnu pour ce compte. Redirection automatique vers l\'interface de liaison.'
            ], 202); // Code HTTP 202 : Requête acceptée, mais nécessite un traitement (Liaison fort)
        }

        // LOGIQUE SI APPAREIL CONNU : Feu vert, Flutter peut afficher la simple saisie du code PIN
        return response()->json([
            'statut' => 'success',
            'message' => 'Appareil validé. Autorisation de charger l\'écran de saisie du code PIN.'
        ], 200); //Code HTTP 200 : OK
    }

    /**
     * 2. CONNEXION DU CLIENT OU DE L'ADMIN (Protection Anti-Force Brute)
     */
    #[OA\Post(
        path: "/login",
        operationId: "authLoginNormal",
        summary: "Connexion classique avec protection anti-force brute",
        description: "Permet d'ouvrir une session utilisateur. Intègre un algorithme glissant sur 5 minutes limitant les échecs consécutifs. Au bout de 3 tentatives infructueuses, le compte est verrouillé automatiquement pendant 30 minutes.",
        tags: ["Authentification"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "code_pin", "device_id"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "771111111", description: "Numéro de téléphone unique servant d'identifiant"),
                    new OA\Property(property: "code_pin", type: "string", example: "1234", description: "Code secret d'accès à 4 chiffres"),
                    new OA\Property(property: "device_id", type: "string", example: "f7b129a0-c3bc", description: "Identifiant matériel unique de l'appareil extrait par Flutter")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Authentification réussie, jeton de session émis et timestamps matériels mis à jour",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Connexion réussie avec succès."),
                        new OA\Property(property: "token", type: "string", example: "1|kori_token_session..."),
                        new OA\Property(property: "user", type: "object", description: "Payload complet de l'utilisateur connecté")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Identifiants invalides (Le message indique de manière dynamique le nombre de tentatives restantes)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Code PIN incorrect. 2 tentative(s) restante(s) avant la suspension temporaire.")
                    ]
                )
            ),
            new OA\Response(
                response: 403,
                description: "Accès interdit (Compte suspendu définitivement par l'admin ou bloqué temporairement pendant 30 minutes)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "message", type: "string", example: "Trop de tentatives échouées. Votre compte est temporairement suspendu pendant 30 minutes.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation (Champs requis manquants ou mal formatés)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreur"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            )
        ]
    )]

    public function login(Request $request)
    {
        //Validation basique des champs requis
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'code_pin' => ['required', 'string'],
            'device_id' => ['required', 'string'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); //Code HTTP 422 Entité non traitée
        }

        // Recherche de l'utilisateur par son numéro de téléphone
        $user = User::where('telephone', $request->telephone)->first();

        // SECURITE : Vérification de la suspension temporaire automatique
        // Avant la vérification du pin pour éviter les ataques par forces brute
        if ($user) {
            $tentative = $user->tentativeConnexion;

            if ($tentative && $tentative->suspendu_jusqu_a && now()->lt($tentative->suspendu_jusqu_a)) {
                // Calcul du temps restant en minutes
                $minutesRestantes = (int)now()->diffInMinutes($tentative->suspendu_jusqu_a);
                return response()->json([
                    'statut' => 'erreur',
                    'message' => "Trop de tentatives échouées. Compte temporairement suspendu. Réessayez dans {$minutesRestantes} minute(s).",
                ], 403); // CodeHTTP 403 : Interdit
            }
        }

        //Sécurité : On vérifie si l'utilisateur existe ET si son code PIN correspond au hash stocké
        if (!$user || !Hash::check($request->code_pin, $user->code_pin)) {

            // On ne gère le compteur que si l'utilisateur existe (téléphone correct, PIN incorrect)
            if($user) {
                // Récupération ou création de l'enregistrement de tentatives
                $tentative = TentativeConnexion::firstOrCreate(['user_id' => $user->id]);

                // LOGIQUE DES TENTATIVES INSTANTANEES :
                // Si la dernière tentative date de plus de 5 minutes -> on remet le compteur à 0
                // Seules les tentatives rapides et consécutives déclenche la suspension
                if ($tentative->derniere_tentative && now()->diffInMinutes($tentative->derniere_tentative) > 5) {
                    $tentative->tentatives = 0;
                }

                // Incrémentation du compteur + horodatage de cette tentative
                $tentative->tentatives += 1;
                $tentative->derniere_tentative = now();

                // Après 3 tentatives consécutives -> suspension automatique de 30 minutes
                if ($tentative->tentatives >= 3) {
                    $tentative->suspendu_jusqu_a = now()->addMinutes(30);
                    $tentative->tentatives = 0; // Remise à zéro du compteur
                    $tentative->save();

                    return response()->json([
                        'statut' => 'erreur',
                        'message' => "Trop de tentatives échouées. Votre compte est temporairement suspendu pendant 30 minutes.",
                    ], 403); // Code HTTP 403 : Interdit
                }

                $tentative->save();

                //On informe le client du nombre de tentatives restantes
                $tentativesRestantes = 3 - $tentative->tentatives;
                return response()->json([
                    'statut' => 'erreur',
                    'message' => "Code PIN incorrect. {$tentativesRestantes} tentative(s) restante(s) avant la suspension temporaire."
                ], 401); // Code HTTP 401 : Non autorisé
            }

            //Téléphone introuvable
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Numero ou code PIN incorrect.'
            ], 401); //Code HTTP 401 : Non autorisé
        }

        // PIN correct -> Réinitialisation du compteur de tentatives
        $tentative = $user->tentativeConnexion;
        if ($tentative) {
            $tentative->update([
                'tentatives' => 0,
                'derniere_tentative' => null,
                'suspendu_jusqu_a' => null,
            ]);
        }

        //Vérification de l'état du compte (Sécurité contre les fraudes)
        if ($user->statut === 'suspendu'){
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Votre compte est suspendu. Veillez contacter le support KoriPay'
            ], 403); //Code HTTP 403 : Interdit
        }

        // Sécurité 2 (Anti-contournement) : On revérifie si l'appareil est bien lié à cet utilisateur
        $liaisonValide = $user->devices()->where('device_id', $request->device_id)->exists();
        if (!$liaisonValide) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Accès refusé. Cet appareil doit faire l\'objet d\'une double authentification.'
            ], 401); // Code HTTP 401 : Non autorisé
        }

        // Mise à jour de la date de dernière connexion sur cet appareil
        $user->devices()->where('device_id', $request->device_id)
            ->update(['derniere_connexion_le' => now()]);

        //Nettoyage : On supprime ses anciens tokens pour éviter l'accumulation
        $user->tokens()->delete();

        // Génération du nouveau Jeton d'accés pour maintenir sa session active
        $token = $user->createToken('kori_token_session')->plainTextToken;

        return response()->json([
            'statut' => 'success',
            'message' => 'Connexion réussi avec succès.',
            'token' => $token,
            'user' => $user,
        ], 200); //Code HTTP 200: OK
    }

    /**
     * 3. - ÉTAPE 1 : ENREGISTRER UN NOUVEL APPAREIL (Vérification identité globale + Envoi OTP)
     */
    #[OA\Post(
        path: "/login/nouvel-appareil/initier",
        operationId: "authNouvelAppareilInitier",
        summary: "Liaison Étape 1 : Vérification croisée (KYC) et envoi de l'OTP de sécurité",
        tags: ["Authentification"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "code_pin", "email"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "771111111"),
                    new OA\Property(property: "code_pin", type: "string", example: "1234"),
                    new OA\Property(property: "email", type: "string", example: "mamadou.diallo@isi.sn")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Données valides, OTP expédié par mail"),
            new OA\Response(response: 401, description: "Informations d'identité invalides")
        ]
    )]

    public function initierNouvelAppareil(Request $request)
    {
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'code_pin' => ['required', 'string'],
            'email' => ['required', 'string'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors(),
            ], 422); //Code HTTP 422 : Entités non traités
        }

        // SECURITE MAXIMUM (Vérification croisée) : Pour lier l'appareil, il faut obligatoirement fournir
        // le Téléphone ET l'E-mail associés à la ligne, en plus du bon code PIN.
        $user = User::where('telephone', $request->telephone)
            ->where('email', $request->email)
            ->first();
        if (!$user || !Hash::check($request->code_pin, $user->code_pin)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Les informations d\'identité fournies ne correspondent pas.'
            ], 401); //Code HTTP 401 : Entités non traitées
        }

        // Génération du code OTP secret à 6 chiffres
        $codeOtp = (string) random_int(100000, 999999);

        // Sauvegarde de l'action dans la BD
        VerificationOtp::create([
            'user_id' => $user->id,
            'otp' => $codeOtp,
            'type_action' => 'nouvel_appareil',
            'expire_a' => Carbon::now()->addMinutes(5),
            'est_utilise' => false
        ]);

        //Déclenchement de la toute nouvelle notification e-mail dédiée !
        $user->notify(new NouvelAppareilNotification($codeOtp));

        return response()->json([
            'statut' => 'success',
            'message' => 'Identité confirmée. Un code de sécurité temporaire a été envoyé sur votre e-mail.'
        ], 200); //Code HTTP 200 : OK
    }

    /**
     * 3. - ÉTAPE 2 : VALIDATION DE L'OTP + LIAISON ET CONNEXION FINALE
     */

    #[OA\Post(
        path: "/login/nouvel-appareil/valider",
        operationId: "authNouvelAppareilValider",
        summary: "Liaison Étape 2 : Valider le code OTP et lier l'appareil",
        tags: ["Authentification"],
        requestBody: new OA\RequestBody(required: true,content:
            new OA\JsonContent(required: ["telephone", "code_otp", "device_id"],
                properties: [new OA\Property(property: "telephone", type: "string", example: "771111111"),
                    new OA\Property(property: "code_otp", type: "string", example: "123456"),
                    new OA\Property(property: "device_id", type: "string", example: "f7b129a0-c3bc"),
                    new OA\Property(property: "device_name", type: "string", example: "Infinix Note 40")])),
        responses: [new OA\Response(response: 200, description: "Appareil enregistré pour ce compte, token généré"),
            new OA\Response(response: 401, description: "Code OTP invalide ou expiré")]
    )]
    public function validerNouvelAppareil(Request $request)
    {
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'code_otp' => ['required', 'digits:6'],
            'device_id' => ['required', 'string'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors(),
            ], 422); // Code HTTP 422 : Entités non traitées
        }

        $user = User::where('telephone', $request->telephone)->first();
        if (!$user){
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Utilisateur introuvable'
            ],404);// Code HTTP 404 : Not Found
        }

        // Recherche du jeton OTP en base de données
        $otpRecord = VerificationOtp::where('user_id', $user->id)
            ->where('otp', $request->code_otp)
            ->where('type_action', 'nouvel_appareil')
            ->where('est_utilise', false)
            ->latest()->first();
        if (!$otpRecord || $otpRecord->expire_a->isPast()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Code OTP invalide ou expiré.'
            ],401); //Code HTTP 401 : Entités non traitées
        }

        // On marque le code comme consommé
        $otpRecord->update(['est_utilise' => true]);

        //Action COMPTABLE Clé : On enregistre définitivement la machine pour ce compte
        $user->devices()->create([
            'device_id' => $request->device_id,
            'device_name' => $request->device_name ?? 'Smartphone Client',
            'derniere_connexion_le' => now()
        ]);

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

    /**
     * 3. DÉCONNEXION DE L'UTILISATEUR (Révocation du Jeton Sanctum)
     */

    #[OA\Post(
        path: "/logout",
        operationId: "authLogout",
        summary: "Déconnexion de l'utilisateur connecté",
        description: "Révoque et supprime définitivement le jeton d'accès (Token Sanctum) actuellement utilisé pour cette session.",
        tags: ["Authentification"],
        security: [["sanctum" => []]], // Cette ligne force l'icône de cadenas sur Swagger
        responses: [
            new OA\Response(
                response: 200,
                description: "Déconnexion réussie et jeton révoqué",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "boolean", example: true),
                        new OA\Property(property: "message", type: "string", example: "Déconnexion réussie. Jeton révoqué avec succès.")
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: "Non authentifié (Token manquant, expiré ou invalide)"
            )
        ]
    )]

    public function logout(Request $request)
    {
        // Récupère l'utilisateur connecté grâce au jeton et supprime son token actuel
        $request->user()->currentAccessToken()->delete();

        // Retourne un message de confirmation en JSON
        return response()->json([
            'statut' => 'success',
            'message' => 'Déconnexion réussie. Jeton révoqué avec succès.'
        ], 200);
    }

}
