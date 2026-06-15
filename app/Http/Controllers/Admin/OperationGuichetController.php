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
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class OperationGuichetController extends Controller
{
    /**
     * EFFECTUER UN DEPÔT D'ARGENT (Guichet Administration -> compte Client)
     */
    public function depot(Request $request)
    {
        //1. Sécurité : On verifie que l'utilisateur connecté est bien l'administrateur
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée. Seul l\'administrateur peut effectuer un dépôt.'
            ], 403); // Code HTTP 403 : Interdit
        }

        //2. Validation des données saisies dans la console Réact
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

        //3. Recherche du client bénéficiaire par son numéro de téléphone
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
        //4. Utilisateur d'une TRANSACTION DE BASE DE DONNEES( si l'envoi du mail plante, le solde du client n'est pas modifié (pas de fausse monnaie))
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
                'montant' => $request->montant,
                'frais' => 0.00,
                'type' => 'depot',
                'status' => 'complete',
            ]);

            //Validation définitive des écritures dans PostgreSQL
            DB::commit();

            //5. Envoi immédiat de la facture numérique par e-mail au client
            $client->notify(new FactureTransactionNotification($transaction, 'destinataire'));

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

    public function initierRetrait(Request $request)
    {
        // 1. Contrôle d'accés : Seul l'administrateur de guichet initier l'action
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée.'
            ], 403); //Code HTTP 403 : Interdit
        }

        //2. Validation des champs d'entrée reçus du backoffice React
        $validateur = Validator::make($request->all(), [
            'telephone' => ['required', 'string'],
            'montant' => ['required', 'numeric', 'min:500'],
        ]);

        if ($validateur->fails()) {
            return response()->json(['statut' => 'erreur',
                'erreurs' => $validateur->errors()
            ], 422); //Code HTTP 422 :
        }

        // 3. Recherche du client qui demande à faire un retrait
        $client = User::where('telephone', $request->telephone)->where('role', 'client')->first();
        if (!$client) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Aucun client avec ce numero.'
            ], 404); //Code HTTP 404 :
        }

        // 4. Vérification si le solde disponible couvre le retrait
        if ($client->solde < $request->montant) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Le solde du client est insuffisant pour effectuer ce retrait.'
            ], 400); // Code HTTP 400 : Requête erronée
        }

        // 5. Génération d'un code OTP aléatoire à 6 chiffres avec random_int
        $codeOtp = (string) random_int(100000, 999999);

        // 6. Enregistrement du jeton dans la table 'verification_otps'
        VerificationOtp::create([
            'user_id' => $client->id,
            'code' => $codeOtp,
            'type_action' => 'transaction',
            'expire_a' => Carbon::now()->addMinutes(5),
            'est_utilise' => false,
        ]);

        // 7. Expédition immédiate du code secret vers le mail du client
        $client->notify(new CodeOtpNotification($codeOtp));
        return response()->json([
            'statut' => 'success',
            'message' => 'Code OTP de validation généré et envoyé par e-mail au client.'
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * RETRAIT ETAPE 2 : VERIFICATION DE L'OTP, DEBIT ET FACTURATION
     */
    public function confirmerRetrait(Request $request)
    {
        // Contrôle d'accés admin initial
        if ($request->user()->role !== 'admin') {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Action non autorisée.'
            ], 403); // Code HTTP 403 : Interdit
        }

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
            ->where('code', $request->code_otp)
            ->where('type_action', 'transaction')
            ->where('est_utilise', false)
            ->latest() //Analyse en priorité du jeton le plus récent
            ->first();
        // Si aucun enregistrement ne correspond ou si le code est faux
        if (!$otpRecord) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Code OTP de validation incorrect ou déjà consommé.'
            ], 400); // Code HTTP 400 :
        }

        //Vérification de la validité temporelle grâce à Carbon
        if ($otpRecord->expire_a->isPast()) {
            return response()->json([
                'statut' => 'erreur',
                'message' => 'Ce code OTP a expiré. Veuillez en générer un nouveau.'
            ], 400); // Code HTTP 400 :
        }

        // 2. Traitement monétaire sécurisé encapsulé
        DB::beginTransaction();
        try {
            //Re-vérification de sécurité anti-fraude concurrente pour s'assurer que le solde est toujours disponible
            if ($client->solde < $request->montant) {
                return response()->json([
                    'statut' => 'erreur',
                    'message' => 'Solde insufissant.'
                ], 400); // Code HTTP 400 :
            }

            // Consommation immédiate du code OTP pour empêcher toute réutilisation malveillante
            $otpRecord->est_utilise = true;
            $otpRecord->save();

            //Soustraction des fonds virtuel du client
            $client->solde -= $request->montant;
            $client->save();

            //Génération de la référence unique de débit
            $referenceUnique = 'KP-RET-' .strtoupper(Str::random(10));

            //Ecriture comptable dans la table 'transactions'
            $transaction = Transaction::create([
                'reference' => $referenceUnique,
                'expediteur_id' => $client->id,
                'destinataire_id' => null,
                'montant' => $request->montant,
                'frais' => 0.00,
                'type' => 'retrait',
                'status' => 'complete',
            ]);

            //Validation finale et écriture persistante dans PostgreSQL
            DB::commit();

            // 3. Notification de retrait envoyée intantanément
            $client->notify(new FactureTransactionNotification($transaction, 'expediteur'));

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
