<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Transaction;
use App\Models\VerificationOtp;
use App\Models\Fidelite;
use App\Notifications\CodeOtpNotification;
use App\Notifications\FactureTransactionNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class TransfertController extends Controller
{
    /**
     * ETAPE 1 : INITIATIER UN TRANSFERT (Avec ou sans OTP selon le montant)
     */

    #[OA\Post(
        path: "/client/transfert/initier",
        operationId: "clientInitierTransfert",
        summary: "Étape 1 : Initialiser un transfert de fonds",
        description: "Permet à un client connecté de transférer de l'argent vers un autre compte. Si le montant est inférieur ou égal à 50 000 FCFA, le transfert est immédiat. S'il dépasse 50 000 FCFA, un code OTP de confirmation est généré et envoyé par e-mail.",
        tags: ["Module Client : Transferts"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone_destinataire", "montant"],
                properties: [
                    new OA\Property(property: "telephone_destinataire", type: "string", example: "+221779876543", description: "Numéro de téléphone du bénéficiaire"),
                    new OA\Property(property: "montant", type: "number", example: 15000, description: "Montant en FCFA à transférer")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Opération traitée avec succès (soit transfert validé, soit OTP requis selon le montant)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "otp_requis"),
                        new OA\Property(property: "message", type: "string", example: "Sécurité : Votre transfert dépasse 50 000 FCFA. Un code OTP a été envoyé sur votre e-mail.")
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "Erreur de règle métier (Solde insuffisant, envoi à soi-même ou compte destinataire suspendu)",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "message", type: "string", example: "Opération impossible. Vous ne pouvez pas vous envoyer d'argent à vous-même.")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Destinataire introuvable",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "message", type: "string", example: "Aucun client KoriPay trouvé avec ce numéro.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Erreur de validation des champs requis",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            )
        ]
    )]

    public function initierTransfert(Request $request)
    {
        // 1. Récupération de l'expéditeur
        $expediteur = $request->user();

        // 2. Validation des données envoyées par l'application flutter
        $validateur = Validator::make($request->all(),[
            'telephone_destinataire' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:100'],
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreurs',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 :
        }

        // 3. Vérification de sécurité sur le destinataire
        if ($expediteur->telephone === $request->telephone_destinataire) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Opération impossible. Vous ne pouvez pas vous envoyer d\'argent à vous-même.'
            ], 400); // Code HTTP 400 :
        }

        $destinataire = User::where('telephone', $request->telephone_destinataire)->where('role', 'client')->first();
        if (!$destinataire) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Aucun client KoriPay trouvé avec ce numéro.'
            ], 404); // Code HTTP 404 :
        }

        if ($destinataire->statut === 'suspendu') {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Impossible d\'envoyer des fonds à ce compte car il suspendu.'
            ], 400); // Code HTTP 400 :
        }

        // 4. Calcul des frais 1%
        $frais = $request->montant * 0.01;
        $totalA_Debiter = $request->montant + $frais;

        // Vérification provisionnelle du solde de l'expediteur
        if ($expediteur->solde < $totalA_Debiter) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Votre solde est insuffisant pour couvrir le transfert et les frais.'
            ], 400); // Code HTTP 400 :
        }

        //5. Analyse du seuil de sécurité (> 50 000 FCFA)
        if ($request->montant >= 50000){
            //Génération du code OTP à 6 chiffres
            $codeOtp = (string) random_int(100000, 999999);

            //Enregistrement du jéton de sécurité
            VerificationOtp::create([
                'user_id' => $expediteur->id,
                'otp' => $codeOtp,
                'type_action' => 'transaction',
                'montant' => $request->montant,
                'telephone_destinataire' => $request->telephone_destinataire,
                'expire_a' => Carbon::now()->addMinutes(5),
                'est_utilise' => false,
            ]);

            // Envoi du code OTP PAr e-mail à l'expediteur
            $expediteur->notify(new CodeOtpNotification($codeOtp,'transfert'));
            return response()->json([
                'statut' => 'otp_requis',
                'message' => 'Sécurité : Votre transfert dépasse 50 000 FCFA. Un code OTP a été envoyé sur votre e-mail.'
            ], 200); //Code HTTP 200 : OK
        }

        // 6. Cas sans OTP
        return $this->executerLeTransfert($expediteur, $destinataire, $request->montant, $frais);
    }

    /**
     * ETAPE 2 : CONFIRMER LE TRANSFERT GROS MOONTANT (aprés la saisie de l'OTP)
     */

    #[OA\Post(
        path: "/client/transfert/confirmer",
        operationId: "clientConfirmerTransfert",
        summary: "Étape 2 : Confirmer et exécuter le transfert gros montant",
        description: "Permet de valider définitivement un transfert supérieur à 50 000 FCFA après la saisie du code OTP reçu par e-mail. Le système intègre un verrou anti-multi-clic et une vérification anti-fraude.",
        tags: ["Module Client : Transferts"],
        security: [["sanctum" => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ["telephone_destinataire", "montant", "codeOtp"],
                properties: [
                    new OA\Property(property: "telephone_destinataire", type: "string", example: "+221779876543"),
                    new OA\Property(property: "montant", type: "number", example: 60000),
                    new OA\Property(property: "codeOtp", type: "string", example: "123456", description: "Code OTP reçu par e-mail (6 chiffres)")
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: "Transfert validé et fonds transférés avec succès",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "success"),
                        new OA\Property(property: "message", type: "string", example: "Transfert effectué avec succès !")
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: "OTP incorrect/expiré OU tentative de falsification des données détectée",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "message", type: "string", example: "Code OTP incorrect ou expired.")
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: "Le bénéficiaire n'existe plus en base de données",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "message", type: "string", example: "Destinataire introuvable.")
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: "Champs requis manquants ou mal formatés",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "erreurs", type: "object")
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: "Défaillance ou conflit lors du verrouillage de la ligne OTP",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "statut", type: "string", example: "erreurs"),
                        new OA\Property(property: "message", type: "string", example: "Erreur technique lors de la validation de sécurité.")
                    ]
                )
            )
        ]
    )]

    public function confirmerTransfert(Request $request)
    {
        $expediteur = $request->user();

        //Définition de la validité des donnée pour eviter les injections SQL et autres
        $validateur = Validator::make($request->all(),[
            'telephone_destinataire' => ['required', 'string'],
            'montant' => ['required', 'numeric'],
            'codeOtp' => ['required', 'string', 'digits:6']
        ]);

        if ($validateur->fails()) {
            return response()->json([
                'statut' => 'erreurs',
                'erreurs' => $validateur->errors()
            ], 422); // Code HTTP 422 :
        }

        //Recherche du destinataire dans la base de donnée
        $destinataire = User::where('telephone', $request->telephone_destinataire)
            ->where('role', 'client')
            ->first();
        if (!$destinataire) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Destinataire introuvable.'
            ], 404); // Code HTTP 404 :
        }

        // Utilisation d'un verrou sur l'OTP pour bloquer le multi_clic
       DB::beginTransaction();
        try {
            // Vérification de l'existence et validité de l'OTP dans PostgreSQL
            $otpRecord = VerificationOtp::lockForUpdate()
                ->where('user_id', $expediteur->id)
                ->where('otp', $request->codeOtp)
                ->where('type_action', 'transaction')
                ->where('est_utilise', false)
                ->latest()
                ->first();
            if (!$otpRecord || $otpRecord->expire_a->isPast()) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreurs',
                    'message' => 'Code OTP incorrect ou expiré..'
                ], 400); // Code HTTP 400 :
            }

            // Verification de sécurité pour voir si quelqu'un a modifié le montant ou le numéro entre-temps
            if ((float) $otpRecord->montant !== (float) $request->montant || $otpRecord->telephone_destinataire !== $request->telephone_destinataire) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreurs',
                    'message' => 'Tentative de modification des données de transfert détectée.'
                ], 400); // Code HTTP 400 :
            }

            //Consommation immédiate de l'OTP
            $otpRecord->est_utilise = true;
            $otpRecord->save();

            DB::commit(); // Pour libérer le verrou sur l'OTP
        } catch (\Exception $exception) {
            DB::rollBack();
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Erreur technique lors de la validation de sécurité.'
            ], 500); // Code HTTP 500 :
        }

        //Calcul des frais pour la validation finale
        $frais = $request->montant * 0.01;

        //Lancement de l'exécution financière
        return $this->executerLeTransfert($expediteur, $destinataire, $request->montant, $frais);
    }

    /**
     * FONCTION PRIVEE DE TRAITEMENT FINANCIER (ExecuterLeTranfert)
     */
    private function executerLeTransfert(User $expediteur, $destinataire, $montant, $frais)
    {
        $totalA_Debiter = $montant + $frais;

        // Encapsulation dans la transaction de base de données (Atomicité)
        DB::beginTransaction();
        try {
            // Recharger les deux utilisateurs avec verrou anti-concurrence
            $expediteur = User::lockForUpdate()->find($expediteur->id);
            $destinataire = User::lockForUpdate()->find($destinataire->id);

            // Double vérification de sécurité sur le solde
            if ($expediteur->solde < $totalA_Debiter) {
                DB::rollBack();
                return response()->json([
                    'statut' => 'erreurs',
                    'message' => 'Solde insuffisant.'
                ], 400); // Code HTTP 400 :
            }

            // A. Débit de l'expéditeur
            $expediteur->solde -= $totalA_Debiter;
            $expediteur->save();

            // B. Crédit du destinataire
            $destinataire->solde += $montant;
            $destinataire->save();

            // C. Génération d'une référence unique pour l'opération
            $referenceUnique = 'KP-TX-' .strtoupper(Str::random(10));
            // D. Ecriture comptable 1 : La ligne de débit pour l'historique de l'expediteur
            $transactionTransfert = Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => $expediteur->id,
                'destinataire_id' => $destinataire->id,
                'montant' => $montant,
                'frais' => $frais,
                'type' => 'transfert',
                'statut' => 'complete',
            ]);

            // E. Ecriture comptable 2 : La ligne de crédit pour l'historique du destinantaire
            $transactionReception = Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => $expediteur->id,
                'destinataire_id' => $destinataire->id,
                'montant' => $montant,
                'frais' => 0.00,
                'type' => 'reception',
                'statut' => 'complete',
            ]);

            // F. Attribution automatique des points de fidélité (1000 FCFA transféré = 2 points gagnés
            $pointsGagnes = (int) floor($montant / 1000) * 2;

            if ($pointsGagnes > 0) {
                $compteFidelite = Fidelite::where('user_id', $expediteur->id)->first();
                if ($compteFidelite) {
                    $compteFidelite->solde_points += $pointsGagnes;
                    $compteFidelite->total_gains += $pointsGagnes;
                    $compteFidelite->save();
                }
            }

            // Validation définitive des mouvements dans PostgreSQL
            DB::commit();

            // G. Notifications double facturation par e-mail
            try {
                // Facture de débit pour l'expéditeur (de type transfert)
                $expediteur->notify(new FactureTransactionNotification($transactionTransfert, 'expediteur'));

                // Facture de crédit pour le destinataire (de type reception)
                $destinataire->notify(new FactureTransactionNotification($transactionReception, 'destinataire'));
            } catch (\Exception $exception) {
                Log::error('Notification échouée transaction ' . $referenceUnique . ' : ' . $exception->getMessage());
            }

            return response()->json([
                'statut' => 'success',
                'message' => 'Transfert effectué avec succès !',
                'donnees' => [
                    'reference' => $referenceUnique,
                    'montant_envoye' => $montant,
                    'frais' => $frais,
                    'nouveau_solde' => $expediteur->solde,
                    'points_fidelite_gagnes' => $pointsGagnes,
            ]
            ], 200); // Code HTTP 200 : OK
        } catch (\Exception $exception) {
            // Annulation totale en cas de crash technique
            DB::rollBack();

            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Erreur technique lors du transfert de fonds.',
                'erreur_technique' => $exception->getMessage()
            ], 500); // Code HTTP 500 :
        }
    }
}
