<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: "1.0.0",
    title: "Documentation de l'API KoriPay",
    description: "Interface interactive pour tester les endpoints de la plateforme financière KoriPay (Projet L3 IAGE / ISI Dakar)."
)]
#[OA\Server(
    url: "http://127.0.0.1:8000/api/",
    description: "Serveur Local de Développement"
)]
#[OA\SecurityScheme(
    securityScheme: "sanctum",
    type: "http",
    scheme: "bearer",
    bearerFormat: "JWT",
    description: "Entrez votre jeton de sécurité (Token Sanctum) reçu lors du Login pour tester les routes protégées."
)]
abstract class Controller
{
    //
}
