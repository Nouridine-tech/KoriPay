<?php

namespace  App\Traits;

use App\Models\TentativeConnexion;
use App\Models\User;
use InvalidArgumentException;
use Illuminate\Http\JsonResponse;

trait GereTentativesConnexion
{

    // Constantes représentant les types d'action valides, pour eviter les fautes de frappe
    public const ACTION_LOGIN = 'login';
    public const ACTION_VERIFICATION_IDENTITE = 'verification_identite';
    public const ACTION_REINITIALISATION_PIN = 'reinitialisation_pin';
    public const ACTION_NOUVEL_APPAREIL = 'nouvel_appareil';

    // Liste de toutes les valeurs valides, utilisée pour la validation interne
    private const TYPES_VALIDES = [
        self::ACTION_LOGIN,
        self::ACTION_VERIFICATION_IDENTITE,
        self::ACTION_REINITIALISATION_PIN,
        self::ACTION_NOUVEL_APPAREIL,
    ];

    /**
     * Vérifie que le type d'action fourni fait bien partie de la liste autorisée.
     *  Lève une exception si ce n'est pas le cas (protection contre les fautes de frappe).
     */
    private function validationTypeAction(string $typeAction): void
    {
        if (!in_array($typeAction, self::TYPES_VALIDES, true)) {
            throw new InvalidArgumentException("Type d'action de tentative de connexion invalide : {$typeAction}");
        }
    }

    /**
     * Methode qui verifie si le compte est actuellement suspendu suite à trop de tentatives échouées
     */
    protected function verifierSuspension(User $user, string $typeAction): ?JsonResponse
    {
        // On s'assure que le type est valide avant toute requête
        $this->validationTypeAction($typeAction);

        // On récupère l'enregistrement de tentatives lié à cet utilisateur et propre à ce type d'action
        $tentative = $user->tentativesConnexion()->where('type_action', $typeAction)->first();

        //Si une suspension existe et n'est pas encore terminée
        if ($tentative && $tentative->suspendu_jusqu_a && now()->lt($tentative->suspendu_jusqu_a)) {
           //On calcul le temp restant en minutes et on renvoi une réponse Json
            $minutesRestantes = (int) now()->diffInMinutes($tentative->suspendu_jusqu_a);
            return response()->json([
                'statut' => 'erreur',
                'message' => "Trop de tentatives échouées. Compte temporairement suspendu. Réessayer dans {$minutesRestantes} minute(s).",
            ], 403); // Cote HTTP 403 : Interdit
        }
        // Aucune suspension active, on laisse passer
        return null;

    }

    /**
     * Enregistre une tentative échouée pour cet utilisateur.
     *  Suspend automatiquement le compte après 3 échecs consécutifs (fenêtre de 5 minutes).
     *  $messageEchec : le début du message d'erreur, spécifique au contexte (PIN, identité, etc.)
     */

    protected function enregistrerEchec(User $user,string $typeAction, string $messageEchec): JsonResponse
    {
        // On s'assure que le type est valide avant toute requête
        $this->validationTypeAction($typeAction);

        // On récupère l'enregistrement existant ou on en crée un nouveau
        $tentative = TentativeConnexion::firstOrCreate([
            // Filtre sur l'utilisateur concerné
            'user_id' => $user->id,
            // Filtre sur le type d'action concerné
            'type_action' => $typeAction,
        ]);

        // Si la dernière tentative date de plus de 5 minutes, on remet le compteur à 0
        if ($tentative->derniere_tentative && now()->diffInMinutes($tentative->derniere_tentative) > 5) {
            $tentative->tentatives = 0;
        }

        // On incrémente le compteur d'échecs
        $tentative->tentatives += 1;

        // On enregistre l'heure de cette tentative
        $tentative->derniere_tentative = now();

        // Si on atteint 3 échecs consécutifs
        if ($tentative->tentatives >= 3) {
            // On fixe la date de fin de suspension
            $tentative->suspendu_jusqu_a = now()->addMinutes(30);

            //On remet le compteur à 0
            $tentative->tentatives = 0;

            //On enregistre les changements en bas de données
            $tentative->save();

            return response()->json([
                'statut' => 'erreur',
                'message' => "Trop de tentatives échouées. Votre compte est temporairement suspendu pendant 30 minute.",
            ], 403); // Code HTTP 403 : Interdit
        }

        // on enregistre le compteur mise à jour en bd
        $tentative->save();

        // On calcule le nombre de tentatives restantes avant suspension
        $tentativeRestantes = 3 - $tentative->tentatives;

        return response()->json([
            'statut' => 'erreur',
            'message' => "{$messageEchec} {$tentativeRestantes} tentative(s) restante(s) avant la suspension temporaire."
        ], 401); // Code HTTP 401 : Non autorisé

    }

    /**
     * Réinitialise le compteur de tentatives après une reussite
     */
    protected function reinitialiserTentatives(User $user, string $typeAction): void
    {
        // On s'assure que le type est valide avant toute requête
        $this->validationTypeAction($typeAction);

        // Récupère l'enregistrement propre à ce type d'action
        $tentative = $user->tentativesConnexion()->where('type_action', $typeAction)->first();

        if ($tentative) {
            // S'il existe un enregistrement, on le met à jour
            $tentative->update([
                'tentatives' => 0,
                'derniere_tentative' => null,
                'suspendu_jusqu_a' => null,
            ]);
        }

    }
}
