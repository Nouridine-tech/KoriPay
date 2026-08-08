<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\VerificationOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Carbon\Carbon;

class RecuperationController extends Controller
{
    /**
     * ETAPE 1 : AUDIT DE SECURITE (Questions sur les transactions + Question secrète)
     * Cette route est EXCLUSIVEMENT accessible depuis un appareil déjà connu.
     * Si l'appareil est inconnu, Flutter redirige automatiquement vers le flux
     * de liaison d'un nouvel appareil (AuthController) et non ici.
     *
     * Le système pose 2 niveaux de questions :
     *    - Niveau 1 (Obligatoire) : Questions sur les habitudes de transaction
     *    - Niveau 2 (Facultatif)  : Question secrète si elle a été configurée.
     *
     *  Si tout est validé, un ticket temporaire est généré pour autoriser
     *  la réinitialisation du code PIN à l'étape suivante.
     */

    #[OA\Post(
        path: "/recuperation/verifier-identite",
        operationId: "recuperationVerifierIdentite",
        summary: "PIN oublié Étape 1 : Audit des habitudes financières",
        description: "Vérifie l'identité du client via ses habitudes de transactions et sa question secrète. Accessible uniquement depuis un appareil déjà enregistré. En cas de succès, retourne un ticket d'autorisation valide 5 minutes.",
        tags: ["Récupération de compte"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "device_id", "montant_derniere_tx", "contact_frequent"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "771111111"),
                    new OA\Property(property: "device_id", type: "string", example: "f7b129a0-c3bc"),
                    new OA\Property(property: "montant_derniere_tx", type: "number", example: 15000, description: "Montant exact de la toute dernière transaction"),
                    new OA\Property(property: "contact_frequent", type: "string", example: "771234567", description: "Numéro ou nom du contact avec qui vous avez le plus de transactions (envoi ET réception)"),
                    new OA\Property(property: "reponse_secrete", type: "string", example: "Dakar", nullable: true, description: "Requis uniquement si une question secrète a été configurée")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Identité confirmée, ticket d'autorisation généré"),
            new OA\Response(response: 400, description: "Réponses incorrectes ou appareil non autorisé"),
            new OA\Response(response: 404, description: "Compte introuvable")
        ]
    )]

    public function verifierIdentite(Request $request)
    {
        // 1. Validation des champs obligatoires
        $validator = Validator::make($request->all(), [
            'telephone'           => ['required', 'string'],
            'device_id'           => ['required', 'string'],
            'montant_derniere_tx' => ['required', 'numeric'],
            'contact_frequent'    => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validator->errors(),
            ], 422); // Code HTTP 422 : Données non traitables
        }

        // 2. Recherche du compte client
        $user = User::where('telephone', $request->telephone)->first();
        if (!$user) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Aucun compte KoriPay trouvé avec ce numéro.'
            ], 404); // Code HTTP 404 : Not found
        }

        // 3. SECURITE CRITIQUE : L'appareil doit absolument être connu
        $appareilConnu = $user->devices()->where('device_id', $request->device_id)->exists();
        if (!$appareilConnu) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Cet appareil n\'est pas reconnu. Veuillez d\'abord lier votre appareil avant de réinitialiser votre PIN.'
            ], 400); // Code HTTP 400 : Accès refusé
        }

        // 4. NIVEAU 1 : Vérification du montant de la dernière transaction
        // On cherche la dernière opération où le client est impliqué (envoi ou réception)
        $derniereTx = Transaction::where(function ($query) use ($user) {
            $query->where('expediteur_id', $user->id)
                ->orWhere('destinataire_id', $user->id);
        })->latest()->first();

        // Si le client n'a aucune transaction ou si le montant ne correspond pas -> refus
        if (!$derniereTx || (float)$derniereTx->montant !== (float)$request->montant_derniere_tx) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Le montant de votre dernière opération est incorrecte.'
            ], 400); // Code HTTP 400 : Accès refusé
        }

        // 5. NIVEAU 1 — Vérification du contact le plus fréquent
        // On cherche le contact avec qui l'utilisateur a le plus de transactions (envoi ET réception)
        // Le CASE WHEN permet d'identifier l'autre personne dans les deux cas :
        // - Si l'utilisateur est expéditeur → le contact est le destinataire
        // - Si l'utilisateur est destinataire → le contact est l'expéditeur
        $contactFrequent = Transaction::where(function ($query) use ($user) {
            $query->where('expediteur_id', $user->id)
                ->orWhere('destinataire_id', $user->id);
        })
            ->where('type', 'transfert') // On considère uniquement les transferts entre clients
            ->selectRaw('
                CASE
                    WHEN expediteur_id = ? THEN destinataire_id
                    ELSE expediteur_id
                END as contact_id,
                count(*) as total
            ', [$user->id])
            ->groupBy('contact_id')
            ->orderByDesc('total')
            ->first();

        // Si le client n'a jamais fait de transfert → on passe cette vérification
        // et on se repose uniquement sur la question secrète (niveau 2)
        if ($contactFrequent) {

            // Si le client a des transactions mais n'a rien envoyé -> refus
            if (!$request->filled('contact_frequent')) {
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'Veuillez renseigner le contact avec lequel vous échangez le plus.'
                ], 400); // Code HTTP 400 : Accès refusé
            }

            // On récupère les informations du contact identifié
            $contact = User::find($contactFrequent->contact_id);

            // On accepte soit le numéro de téléphone soit le nom du contact
            $telephoneContact = $contact->telephone ?? null;
            $nomContact       = strtolower($contact->nom ?? '');
            $prenomContact    = strtolower($contact->prenom ?? '');
            $reponseClient    = strtolower($request->contact_frequent ?? '');

            if ($reponseClient !== $telephoneContact && $reponseClient !== $nomContact && $reponseClient !== $prenomContact) {
                return response()->json([
                    'statut'  => 'erreur',
                    'message' => 'Le contact avec lequel vous avez le plus de transactions est incorrect.'
                ], 400); // Code HTTP 400 : Accès refusé
            }
        }

        // 6. NIVEAU 2 : Question secrète (uniquement si le client l'a configurée)
        if (!empty($user->question_secrete)) {
            // Si la question secrète est configurée mais que le client n'a rien envoyé
            if (!$request->filled('response_secrete')) {
                return response()->json([
                    'statut' => 'question_secrete_requise',
                    'question' => $user->question_secrete,
                    'message' => 'Veuillez répondre à votre question secrète pour continuer.'
                ], 400); // Code HTTP 400 : Accès refusé
            }

            // Vérification de la réponse (insensible à la casse grâce strtolower)
            if (!Hash::check(strtolower($request->response_secrete), $user->response_secrete)) {
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'La réponse à votre question secrète est incorrecte.'
                ], 400); // Code HTTP 400 : Accès refusé
            }
        }

        // 7. Toutes les vérifications sont passées -> Génération du ticket d'autorisation
        // Ce ticket est un OTP temporaire qui autorise uniquement la réinitialisation du PIN
        $ticket = (string) random_int(100000, 999999);

        VerificationOtp::create([
            'user_id'     => $user->id,
            'otp'         => $ticket,
            'type_action' => 'autorisation_changement_pin',
            'expire_a'    => Carbon::now()->addMinutes(5),
            'est_utilise' => false,
        ]);

        return response()->json([
            'statut'              => 'success',
            'message'             => 'Identité confirmée avec succès. Vous pouvez maintenant définir votre nouveau code PIN.',
            'ticket_autorisation' => $ticket
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * ETAPE 2 : REINITIALISATION DU CODE PIN
     */

    #[OA\Post(
        path: "/recuperation/reinitialiser-pin",
        operationId: "recuperationReinitialiserPin",
        summary: "PIN oublié Étape 2 : Réinitialiser le code PIN",
        description: "Applique le nouveau code PIN après validation du ticket d'autorisation obtenu à l'étape 1. Le ticket est à usage unique et expire dans 5 minutes.",
        tags: ["Récupération de compte"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone", "ticket", "nouveau_pin", "nouveau_pin_confirmation"],
                properties: [
                    new OA\Property(property: "telephone", type: "string", example: "771111111"),
                    new OA\Property(property: "ticket", type: "string", example: "482910", description: "Ticket reçu à l'étape 1"),
                    new OA\Property(property: "nouveau_pin", type: "string", example: "4321", description: "Nouveau code PIN à 4 chiffres"),
                    new OA\Property(property: "nouveau_pin_confirmation", type: "string", example: "4321", description: "Confirmation du nouveau code PIN")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Code PIN réinitialisé avec succès"),
            new OA\Response(response: 400, description: "Ticket invalide ou expiré"),
            new OA\Response(response: 422, description: "Erreur de validation")
        ]
    )]

    public function reinitialiserPIN(Request $request)
    {
        // 1. Validation des champs
        $validator = Validator::make($request->all(), [
            'telephone'   => ['required', 'string'],
            'ticket'      => ['required', 'string', 'size:6'],
            'nouveau_pin' => ['required', 'string', 'digits:4', 'confirmed'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validator->errors(),
            ], 422); // Code HTTP 422 : Données non traitables
        }

        // 2. Recherche du compte client
        $user = User::where('telephone', $request->telephone)->first();
        if (!$user) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Compte introuvable.'
            ], 404); // Code HTTP 404 : Not found
        }

        // 3. Vérification du ticket d'autorisation en base de données
        $ticket = VerificationOtp::where('user_id', $user->id)
            ->where('otp', $request->ticket)
            ->where('type_action', 'autorisation_changement_pin')
            ->where('est_utilise', false)
            ->latest()
            ->first();

        if (!$ticket || $ticket->expire_a->isPast()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Le ticket d\'autorisation est invalide ou a expiré. Veuillez recommencer depuis l\'étape 1.'
            ], 400); // Code HTTP 400 : Accès refusé
        }

        // 4. Consommation immédiate du ticket pour empêcher toute réutilisation
        $ticket->update(['est_utilise' => true]);

        // 5. Mise à jour sécurisée du code PIN (haché en base de données)
        $user->update([
            'code_pin' => Hash::make($request->nouveau_pin)
        ]);

        return response()->json([
            'statut' => 'success',
            'message' => 'Votre code PIN a été réinitialisé avec succès. Vous pouvez maintenant vous connecter.'
        ], 200); // code HTTP 200 : OK
    }
}
