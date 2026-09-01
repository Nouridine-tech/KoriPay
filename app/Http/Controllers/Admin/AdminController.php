<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use App\Traits\JournaliseAction;

class AdminController extends Controller
{
    use JournaliseAction;

    /**
     * VERIFICATION DU ROLE ADMIN (Méthode privée réutilisable)
     * Evite de répéter la même vérification dans chaque fonction
     */
    private function verifierAdmin(Request $request): bool
    {
        return $request->user()->role === 'admin';
    }

    //======================================================
    // 1. CREATION D'UN NOUVEAU COMPTE ADMIN OU AGENT
    //======================================================

    #[OA\Post(
        path: "/admin/creer-admin",
        operationId: "adminCreerAdmin",
        summary: "Créer un nouveau compte administrateur ou agent",
        description: "Permet à un administrateur connecté de créer un nouveau compte admin ou agent. Seul un admin peut créer ce type de compte.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["nom", "prenom", "telephone", "email", "code_pin", "role"],
                properties: [
                    new OA\Property(property: "nom", type: "string", example: "Diop"),
                    new OA\Property(property: "prenom", type: "string", example: "Moussa"),
                    new OA\Property(property: "telephone", type: "string", example: "770000001"),
                    new OA\Property(property: "email", type: "string", example: "moussa.diop@koripay.com"),
                    new OA\Property(property: "code_pin", type: "string", example: "4321"),
                    new OA\Property(property: "role", type: "string", example: "agent", description: "Doit être 'admin' ou 'agent'"),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: "Compte créé avec succès"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 422, description: "Erreur de validation")
        ]
    )]


    public function creerAdmin(Request $request)
    {
        // Sécurité périmétrique du rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Validation des données
        $validator = Validator::make($request->all(), [
            'nom' => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'telephone' => ['required', 'string', 'unique:users,telephone'],
            'email' => ['required', 'email', 'unique:users,email'],
            'code_pin' => ['required', 'string', 'digits:4'],
            'role' => ['required', 'string', 'in:admin,agent'],
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validator->errors()
            ], 422); // Code HTTP 422 : Données non traitables
        }

        // Création du compte (admin ou agent selon ce qui a été envoyé)
        $compte = User::create([
            'nom' => $request->nom,
            'prenom' => $request->prenom,
            'telephone' => $request->telephone,
            'email' => $request->email,
            'code_pin' => Hash::make($request->code_pin),
            'solde' => 0.00,
            'role' => $request->role,
            'statut' => 'actif',
        ]);

        // Journalisation de la création du compte pour traçabilité
        $this->journaliser($request->user(), self::ACTION_CREATION_ADMIN, $compte);

        return response()->json([
            'statut' => 'success',
            'message' => 'Compte '. $request->role . ' créé avec succès.',
            'compte' => $compte
        ], 201); // Code HTTP 201 : Ressource créée
    }



    //======================================================
    // 2. VOIR TOUS LES COMPTES D'UN TYPE (CLIENT OU AGENT)
    //======================================================

    #[OA\Get(
        path: "/admin/comptes/{type}",
        operationId: "adminVoirTousLesComptes",
        summary: "Lister tous les comptes d'un type donné (client ou agent)",
        description: "Retourne la liste complète des comptes clients OU des comptes agents, selon le type demandé dans l'URL.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "type", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["client", "agent", "admin"]))
        ],
        responses: [
            new OA\Response(response: 200, description: "Liste des comptes retournée avec succès"),
            new OA\Response(response: 403, description: "Droits insuffisants")
        ]
    )]


    public function voirTousLesComptes(Request $request, string $type)
    {
        // Sécurité périmétrique sur le rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Récupération de tous les comptes de type demandé avec pagination
        $comptes = User::where('role', $type)
            ->select('id', 'nom', 'prenom', 'telephone', 'email', 'solde', 'statut', 'created_at')
            ->orderBy('created_at', 'desc')
        ->paginate(20); // 20 comptes par page

        return response()->json([
            'statut' => 'success',
            'donnees' => $comptes
        ], 200); // Code HTTP 200 : OK
    }



    //================================================================
    // 3. VOIR LES DETAILS D'UN COMPTE (CLIENT OU AGENT)
    //================================================================

    #[OA\Get(
        path: "/admin/comptes/{type}/{id}",
        operationId: "adminVoirUnCompte",
        summary: "Voir les informations détaillées d'un compte (client ou agent)",
        description: "Retourne toutes les informations d'un compte spécifique du type demandé.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "type", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["client", "agent", "admin"])),
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"), example: 2)
        ],
        responses: [
            new OA\Response(response: 200, description: "Informations du compte retournées"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Compte introuvable")
        ]
    )]

    public function voirUnCompte(Request $request, string $type, $id)
    {
        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Recherche du compte avec ses relations
        $compte = User::where('id', $id)
            ->where('role', $type)
            ->with('fidelite') // Charge les points de fidélité
        ->first();

        if (!$compte) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ucfirst($type) . ' introuvable.'
            ], 404); // Code HTTP 404 : Not found
        }

        // Récupération des 10 dernières transactions liées à ce compte
        $derniereTransactions = Transaction::where(function ($query) use ($id) {
            $query->where('expediteur_id', $id)
                ->orWhere('destinataire_id', $id);
        })->latest()->take(10)->get();

        return response()->json([
            'statut' => 'success',
            'donnees' => [
                'compte' => $compte,
                'derniereTransactions' => $derniereTransactions
            ]
        ], 200); // Code HTTP 200 : OK
    }



    //================================================================
    // 4. VOIR TOUTES LES TRANSACTIONS DE LA PLATEFORME
    //================================================================

    #[OA\Get(
        path: "/admin/transactions",
        operationId: "adminVoirToutesLesTransactions",
        summary: "Voir l'historique global de toutes les transactions",
        description: "Retourne l'ensemble des transactions effectuées sur la plateforme KoriPay, triées par date décroissante.",
        tags: ["Module Admin : Supervision"],
        security: [["sanctum" => []]],
        responses: [
            new OA\Response(response: 200, description: "Historique global retourné"),
            new OA\Response(response: 403, description: "Droits insuffisants")
        ]
    )]

    public function voirToutesLesTransactions(Request $request)
    {
        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Récupérations de toutes les transactions avec les noms des parties impliquées
        $transactions = Transaction::with([
            'expediteur:id,nom,prenom',
            'destinataire:id,nom,prenom',
        ])
            ->orderBy('created_at', 'desc')
        ->paginate(20); // 20 transactions par page

        return response()->json([
            'statut' => 'success',
            'donnees' => $transactions
        ], 200); // Code HTTP 200 : OK
    }



    //================================================================
    // 5. SUSPENDRE UN COMPTE (CLIENT OU AGENT)
    //================================================================

    #[OA\Put(
        path: "/admin/comptes/{type}/{id}/suspendre",
        operationId: "adminSuspendreCompte",
        summary: "Suspendre un compte (client ou agent)",
        description: "Bloque totalement l'accès au compte. Il ne peut plus se connecter ni effectuer aucune opération.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "type", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["client", "agent"])),
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"), example: 2)
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["motif"],
                properties: [
                    new OA\Property(property: "motif", type: "string", example: "Activité suspecte détectée sur le compte.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Compte suspendu avec succès"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Compte introuvable"),
            new OA\Response(response: 400, description: "Compte déjà suspendu")
        ]
    )]

    public function suspendreCompte(Request $request, string $type, $id)
    {
        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Validation du motif obligatoire
        $validateur = Validator::make($request->all(), [
            'motif' => ['required', 'string', 'max:255'],
        ]);
        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 : Entité non traitée
        }

        // Vérification si le compte existe
        $compte = User::where('id', $id)
            ->where('role', $type)
        ->first();
        if (!$compte) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ucfirst($type) . ' introuvable.'
            ], 404); // Code HTTP 404 : Not found
        }

        // Vérification si le compte est déjà suspendu
        if ($compte->statut === 'suspendu') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Ce compte est déjà suspendu.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Suspension du compte
        $compte->update([
            'statut' => 'suspendu'
        ]);

        // Révocation de tous ses tokens actifs pour forcer la déconnexion immédiate
        $compte->tokens()->delete();

        // Journalisation de la suspension d'un compte avec le motif fourni par l'admin
        $this->journaliser($request->user(), self::ACTION_SUSPENSION_COMPTE, $compte, $request->motif);

        return response()->json([
            'statut' => 'success',
            'message' => ucfirst($type) . ' suspendu avec succès.'
        ], 200); // Code HTTP 200 : OK
    }


    //================================================================
    // 6. GELER UN COMPTE (CLIENT OU AGENT)
    //================================================================

    #[OA\Put(
        path: "/admin/comptes/{type}/{id}/geler",
        operationId: "adminGelerCompte",
        summary: "Geler un compte (client ou agent)",
        description: "Bloque uniquement les transferts sortants du compte. Utilisé en cas de suspicion d'arnaque en cours.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "type", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["client", "agent"])),
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"), example: 2)
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["motif"],
                properties: [
                    new OA\Property(property: "motif", type: "string", example: "Signalement arnaque en attente de vérification.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Compte gelé avec succès"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Compte introuvable"),
            new OA\Response(response: 400, description: "Compte déjà gelé ou suspendu")
        ]
    )]

    public function gelerCompte(Request $request, string $type, $id)
    {
        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Validation du motif obligatoire
        $validateur = Validator::make($request->all(), [
            'motif' => ['required', 'string', 'max:255'],
        ]);
        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 : Entité non traitée
        }

        // Vérification si le compte existe
        $compte = User::where('id', $id)
            ->where('role', $type)
            ->first();
        if (!$compte) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ucfirst($type) . ' introuvable.'
            ], 404); // Code HTTP 404 : Not found
        }

        // Vérification si le compte est déjà suspendu
        if (in_array($compte->statut, ['gele', 'suspendu'])) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Ce compte est déjà gelé ou suspendu.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Gel du compte
        $compte->update([
            'statut' => 'gele'
        ]);

        // Journalisation du gel d'un compte avec le motif fourni par l'admin
        $this->journaliser($request->user(), self::ACTION_GEL_COMPTE, $compte, $request->motif);

        return response()->json([
            'statut' => 'success',
            'message' => ucfirst($type) . ' gelé avec succès.'
        ], 200); // Code HTTP 200 : OK
    }


    //================================================================
    // 7. RÉACTIVER UN COMPTE CLIENT
    //================================================================

    #[OA\Put(
        path: "/admin/comptes/{type}/{id}/reactiver",
        operationId: "adminReactiverCompte",
        summary: "Réactiver un compte suspendu ou gelé (client ou agent)",
        description: "Rétablit l'accès complet au compte après vérification et résolution du problème.",
        tags: ["Module Admin : Gestion Comptes"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "type", in: "path", required: true, schema: new OA\Schema(type: "string", enum: ["client", "agent"])),
            new OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"), example: 2)
        ],
        responses: [
            new OA\Response(response: 200, description: "Compte réactivé avec succès"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Compte introuvable"),
            new OA\Response(response: 400, description: "Compte déjà actif")
        ]
    )]

    public function reactiverCompte(Request $request, string $type, $id)
    {

        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Vérification si le compte existe
        $compte = User::where('id', $id)
            ->where('role', $type)
            ->first();
        if (!$compte) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ucfirst($type) . ' introuvable.'
            ], 404); // Code HTTP 404 : Not found
        }

        // Vérification si le compte est déjà actif
        if ($compte->statut === 'actif') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Ce compte est déjà actif.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Réactivation du compte
        $compte->update([
            'statut' => 'actif'
        ]);

        // Journalisation de la reactivation d'un compte avec le motif fourni par l'admin
        $this->journaliser($request->user(), self::ACTION_REACTIVATION_COMPTE, $compte);

        return response()->json([
            'statut' => 'success',
            'message' => ucfirst($type) . ' réactivé avec succès.'
        ], 200); // Code HTTP 200 : OK

    }


    //================================================================
    // 8. ANNULER UNE TRANSACTION (Maximum 7 jours aprés exécution)
    //================================================================

    #[OA\Put(
        path: "/admin/transactions/{reference}/annuler",
        operationId: "adminAnnulerTransaction",
        summary: "Annuler une transaction et rembourser l'expéditeur",
        description: "Permet à l'admin d'annuler une transaction dans un délai de 7 jours après son exécution, à condition que le destinataire dispose encore du montant sur son solde. L'expéditeur est automatiquement remboursé.",
        tags: ["Module Admin : Supervision"],
        security: [["sanctum" => []]],
        parameters: [
            new OA\Parameter(name: "reference", in: "path", required: true, schema: new OA\Schema(type: "string"), example: "KP-TX-ABC123")
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["motif"],
                properties: [
                    new OA\Property(property: "motif", type: "string", example: "Arnaque signalée par le client expéditeur.")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Transaction annulée et expéditeur remboursé"),
            new OA\Response(response: 400, description: "Transaction non annulable (délai dépassé, solde insuffisant, déjà annulée)"),
            new OA\Response(response: 403, description: "Droits insuffisants"),
            new OA\Response(response: 404, description: "Transaction introuvable")
        ]
    )]

    public function annulerTransaction(Request $request, $reference)
    {
        // Sécurité périmétrique de rôle
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Validation du motif obligatoire
        $validateur = Validator::make($request->all(), [
            'motif' => ['required', 'string', 'max:255'],
        ]);
        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 : Entité non traitée
        }

        //On cherche la transaction de type 'transfert' uniquement
        $transaction = Transaction::where('reference', $reference)
            ->where('type', 'transfert')
        ->first();

        if (!$transaction) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Transaction introuvable.'
            ], 404); // Code HTTP 404 : Not Found
        }

        // Vérification 1 : Transaction déjà annulée
        if ($transaction->statut === 'annule') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Cette transaction a déjà été annulée.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Vérification 2 : Délai de 7 jours dépassés
        if ($transaction->created_at->lt(now()->subDays(7))){
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Annulation impossible. Le délai de 7 jours après l\'exécution est dépassé.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Récupération des parties impliquées
        $expediteur = User::find($transaction->expediteur_id);
        $destinataire = User::find($transaction->destinataire_id);

        if (!$expediteur || !$destinataire) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Un des comptes impliqués dans la transaction est introuvable.'
            ], 404); // Code HTTP 404 : Not Found
        }

        // Vérification 3 : Le destinataire a-t-il encore le montant sur son solde ?
        if ($destinataire->solde < $transaction->montant) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Annulation impossible. Le destinataire ne dispose plus du montant suffisant sur son solde.'
            ], 400); // Code HTTP 400 : Bad Request
        }

        // Tout est valide -> Annulation atomique
        DB::beginTransaction();
        try {

            // Recharge avec verrou pour éviter la race condition
            $expediteur  = User::lockForUpdate()->find($transaction->expediteur_id);
            $destinataire = User::lockForUpdate()->find($transaction->destinataire_id);

            // Revérifier le solde APRÈS le verrou
            if ($destinataire->solde < $transaction->montant) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'Annulation impossible. Le destinataire ne dispose plus du montant suffisant sur son solde.'
                ], 400); // Code HTTP 400 : Bad Request
            }

            // A. Débit du destinataire (on reprend les fonds)
            $destinataire->solde -= $transaction->montant;
            $destinataire->save();

            // B. Remboursement de l'expéditeur (montant + Frais)
            $expediteur->solde += ($transaction->montant + $transaction->frais);
            $expediteur->save();

            // C. Marquage de la transaction comme annulée
            $transaction->update([
                'statut' => 'annule'
            ]);

            // D. Marquage de la transaction miroir (réception) comme annulée aussi
            Transaction::where('reference', $reference)
                ->where('type', 'reception')
                ->update([
                    'statut' => 'annule'
            ]);

            DB::commit();

            // E. Notification des deux parties (dans un try/catch isolé)
            try {
                Log::info("Transaction {$reference} annulée par admin {$request->user()->id}. Expéditeur remboursé.");
            } catch (\Exception $e){
                Log::error("Notification annulation échouée : ". $e->getMessage());
            }

            // Journalisation de l'annulation d'une transaction avec le motif fourni par l'admin
            $this->journaliser($request->user(), self::ACTION_ANNULATION_TRANSACTION, $transaction, $request->motif);

            return response()->json([
                'statut' => 'success',
                'message' => 'Transaction annulée avec succès. L\'expéditeur a été remboursé du montant et des frais.',
                'donnees' => [
                    'reference' => $reference,
                    'montant_rembourse' => $transaction->montant + $transaction->frais,
                    'nouveau_solde_expediteur' => $expediteur->solde,
                ]
            ], 200); // Code HTTP 200 : OK
        } catch (\Exception $e){
            DB::rollBack();
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Erreur technique lors de l\'annulation.',
            ], 500); // Code HTTP 500 : Erreur serveur
        }
    }




    //================================================================
    // 9. MODIFICATION RÉGLEMENTAIRE DE L'IDENTITÉ D'UN CLIENT (KYC)
    //===============================================================

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
        if (!$this->verifierAdmin($request)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Droits administratifs requis.'
            ], 403); //Code HTTP 403 : Non autorisé
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

        // Journalisation de la modification d'identité pour traçabilité (conformité réglementaire KYC)
        $this->journaliser($request->user(), self::ACTION_MODIFICATION_IDENTITE_CLIENT, $client);

        return response()->json([
            'statut' => 'success',
            'message' => 'Identité du client mise à jour avec succès conformément aux pièces justificatives.'
        ], 200);
    }
}
