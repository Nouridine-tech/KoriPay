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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Http\Request;

class TransfertController extends Controller
{
    /**
     * ETAPE 1 : INITIATIER UN TRANSFERT (Avec ou sans OTP selon le montant)
     */
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

        // Vérification du solde de l'expediteur
        if ($expediteur->solde < $totalA_Debiter) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Votre solde est insuffisant pour couvrir le transfert et les frais.'
            ], 400); // Code HTTP 400 :
        }

        //5. Analyse du seuil de sécurité (> 50 000 FCFA)
        if ($request->montant > 50000){
            //Génération du code OTP à 6 chiffres
            $codeOtp = (string) random_int(100000, 999999);

            //Enregistrement du jéton de sécurité
            VerificationOtp::create([
                'user_id' => $expediteur->id,
                'otp' => $codeOtp,
                'type_action' => 'transaction',
                'expire_a' => Carbon::now()->addMinutes(5),
                'est_utilise' => false,
            ]);

            // Envoi du code OTP PAr e-mail à l'expediteur
            $expediteur->notify(new CodeOtpNotification($codeOtp));
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

        // Vérification de l'existence et validité de l'OTP dans PostgreSQL
        $otpRecord = VerificationOtp::where('user_id', $expediteur->id)
            ->where('otp', $request->codeOtp)
            ->where('type_action', 'transaction')
            ->where('est_utilise', false)
            ->latest()
            ->first();
        if (!$otpRecord || $otpRecord->expire_a->isPast()) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Code OTP incorrect ou expired.'
            ], 400); // Code HTTP 400 :
        }

        //Consommation immédiate de l'OTP
        $otpRecord->est_utilise = true;
        $otpRecord->save();

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

        //Double vérification de sécurité sur le solde
        if ($expediteur->solde < $totalA_Debiter) {
            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Solde insuffisant.'
            ], 400); // Code HTTP 400 :
        }

        // Encapsulation dans transaction de base de données (Atomicité)
        DB::beginTransaction();
        try {
            // A. Débit de l'expéditeur
            $expediteur->solde -= $totalA_Debiter;
            $expediteur->save();

            // B. Crédit du destinataire
            $destinataire->solde += $montant;
            $destinataire->save();

            // C. Génération d'une référence unique pour l'opération
            $referenceUnique = 'KP-TX' .strtoupper(Str::random(10));
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
            // Facture de débit pour l'expéditeur (de type transfert)
            $expediteur->notify(new FactureTransactionNotification($transactionTransfert, 'expediteur'));

            // Facture de crédit pour le destinataire (de type reception)
            $destinataire->notify(new FactureTransactionNotification($transactionReception, 'destinataire'));

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
            // Annulation total en cas de crash technique
            DB::rollBack();

            return response()->json([
                'statut' => 'erreurs',
                'message' => 'Erreur technique lors du transfert de fonds.',
                'erreur_technique' => $exception->getMessage()
            ], 500); // Code HTTP 500 :
        }
    }
}
