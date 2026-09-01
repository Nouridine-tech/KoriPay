<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Cette méthode permet de verifier automatiquement le rôle du user connecté pour pouvoir
     *ses autorisations à uniquement ce qu'il a le droit de faire.
     * $request : la requête HTTP entrante (contient l'utilisateur connecté via Sanctum)
     *  $next    : une fonction qui représente "la suite" du traitement (le contrôleur, etc.)
     *  ...$roles : les rôles autorisés, passés depuis la route. Ex: role:admin,agent
     *              donnera $roles = ['admin', 'agent']
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Si l'utilisateur n'existe pas ou si son rôle n'est pas dans la liste autorisée
        if (!$user || !in_array($user->role, $roles, true)) {
            return response()->json([
                'statut' => 'erreur',
                'message' => "Action non autorisée. Cette opération nécessite l'un des rôle suivants : " . implode(', ', $roles) . '.'
            ], 403); // Code HTTP 403 : Interdit
        }

        // Si le rôle est valide : on laisse la requête continuer son chemin normal
        return $next($request);
    }
}
