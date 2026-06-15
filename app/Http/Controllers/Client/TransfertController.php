<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use APP\Models\User;
use APP\Models\Transaction;
use APP\Models\VerificationOtp;
use APP\Models\Fidelite;
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
                'code' => $codeOtp,
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
}
