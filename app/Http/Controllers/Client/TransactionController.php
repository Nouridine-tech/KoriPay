<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * 1. RECUPERATION DE L'HISTORIQUE COMPLET DU CLIENT CONNECTE
     */
    public function index(Request $request)
    {
        $client = $request->user();

        // Récupération de toutes les transactions ou le client est soit l'expéditeur, soit le destinataire
        $transactions = Transaction::where(function ($query) use ($client) {
            $query->where('expediteur_id', $client->id)
                ->orWhere('destinataire_id', $client->id);
        })
            ->latest() //Equivalent à "orderBy('created_at', 'desc')"
        ->paginate(15); //Pagination de 15 éléments par page pour économiser la bande passante mobile

        return response()->json([
            'statut' => 'success',
            'donnees' => $transactions,
        ], 200); // Code HTTP 200 : OK
    }

    /**
     * 2. RECUPERATION DES DETAILS D'UNE TRANSACTION SPECIFIQUE VIA SA REFERENCE
     */
    public function show(Request $request, $reference)
    {
        $client = $request->user();

        // Recherche de la transaction par sa référence unique globale
        $transaction = Transaction::where('reference', $reference)
            ->where(function ($query) use ($client) {
                $query->where('expediteur_id', $client->id)
                    ->orWhere('destinataire_id', $client->id);
            })
            ->first();

        if (!$transaction) {
            return response()->json([
                'statut' => 'erreur',
                'message' => ' Transaction introuvable ou vous n\'avez pas l\'autorisation d\'y accéder.'
            ], 404); // Code HTTP 404 : Not found
        }

        return response()->json([
            'statut' => 'success',
            'donnees' => $transaction,
        ], 200); // Code HTTP 200 : OK
    }
}
