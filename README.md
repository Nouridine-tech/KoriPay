# KoriPay

**KoriPay** est une plateforme de mobile money développée par **Kori Tech S.A** dans le cadre d'un projet de fin d'études (Licence, ISI Dakar). L'application permet à des clients d'envoyer et recevoir de l'argent, et à des agents/administrateurs de gérer des opérations de guichet (dépôt, retrait) ainsi que la supervision de la plateforme.

## Stack technique

- **Framework** : Laravel 13
- **Base de données** : PostgreSQL (production), SQLite `:memory:` (tests PHPUnit)
- **Authentification API** : Laravel Sanctum (tokens)
- **Documentation API** : Swagger / OpenAPI via `darkaonline/l5-swagger`

## Installation

Prérequis : PHP 8.2+, Composer, PostgreSQL, un serveur SMTP (ou un service comme Mailtrap pour le développement).

```bash
# 1. Cloner le projet et installer les dépendances
git clone <url-du-depot>
cd KoriPay-app
composer install

# 2. Configurer l'environnement
cp .env.example .env
php artisan key:generate
```

Dans `.env`, renseigner au minimum :

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=koripay
DB_USERNAME=postgres
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=...
MAIL_USERNAME=...
MAIL_PASSWORD=...
```

```bash
# 3. Créer les tables (base de développement, écrase les données existantes)
php artisan migrate:fresh

# 4. Générer la documentation Swagger
php artisan l5-swagger:generate

# 5. Démarrer le serveur local
php artisan serve
```

L'API est alors accessible sur `http://127.0.0.1:8000/api`, et la documentation interactive sur `http://127.0.0.1:8000/api/documentation`.

Pour les tests automatisés (SQLite en mémoire, aucune configuration supplémentaire requise) :

```bash
php artisan test
```

## Rôles utilisateurs

L'application repose sur trois rôles, stockés dans une seule table `users` (colonne `role`) :

| Rôle | Description |
|---|---|
| `client` | Utilisateur final : envoie/reçoit de l'argent, gère son profil |
| `agent` | Effectue les opérations de guichet (dépôt, retrait) pour le compte des clients |
| `admin` | Supervision complète : gestion des comptes, des transactions, création d'agents/admins |

L'autorisation par rôle est gérée par un middleware dédié (`role:client`, `role:admin,agent`, `role:admin`), appliqué au niveau des groupes de routes.

---

## Fonctionnalités

### 1. Authentification et sécurité du compte

- **Inscription autonome** (`/inscription`) : création d'un compte client avec PIN à 4 chiffres, question secrète optionnelle.
- **Liaison d'appareil** : chaque connexion est vérifiée par rapport à un appareil déjà enregistré (`device_id`). Un nouvel appareil déclenche un flux de vérification renforcée :
    1. Vérification croisée téléphone + email + PIN
    2. Envoi d'un code OTP à 6 chiffres par e-mail
    3. Validation de l'OTP puis liaison définitive de l'appareil
- **Connexion classique** (`/login`) : téléphone + PIN + identifiant d'appareil.
- **Récupération de PIN oublié**, sans PIN ni token nécessaire :
    1. Vérification d'identité par les habitudes de transaction (montant de la dernière opération, contact le plus fréquent) + question secrète optionnelle
    2. Génération d'un ticket d'autorisation temporaire (5 minutes, usage unique)
    3. Réinitialisation du PIN avec ce ticket
- **Déconnexion** : révocation du token Sanctum actif.

### 2. Protection anti-bruteforce

Deux couches de protection combinées sur toutes les routes publiques sensibles (`/login`, `/recuperation/verifier-identite`, `/recuperation/reinitialiser-pin`, `/login/nouvel-appareil/valider`, `/admin/retrait/confirmer`) :

- **Throttle par IP** (`throttle:5,1`) : 5 requêtes par minute maximum.
- **Verrouillage par compte** (table `tentatives_connexion`) : après 3 échecs consécutifs sur une fenêtre glissante de 5 minutes, le compte est suspendu 30 minutes — indépendamment de l'IP utilisée. Le compteur est **séparé par type d'action** (connexion, vérification d'identité, réinitialisation de PIN, nouvel appareil), sauf pour `/login` et l'étape 1 de liaison d'un nouvel appareil qui **partagent le même compteur**, puisqu'ils vérifient le même secret (le code PIN).

### 3. Opérations client

- **Transferts d'argent** entre clients, avec frais appliqués. Un transfert dépassant 50 000 FCFA déclenche une validation par OTP envoyé par e-mail.
- **Historique des transactions** du client connecté (liste + détail par référence).
- **Programme de fidélité** : consultation du solde de points, conversion en crédit.
- **Gestion du profil** : changement de PIN, configuration de la question secrète.

### 4. Opérations de guichet (agents et admins)

- **Dépôt d'argent** sur le compte d'un client, avec génération d'un reçu envoyé par e-mail.
- **Retrait d'espèces**, sécurisé par un code OTP envoyé au client (validité 5 minutes) avant décaissement.
- Chaque opération de guichet enregistre l'identité de l'agent/admin qui l'a exécutée (`effectue_par_id` sur la transaction), pour traçabilité.

### 5. Administration et supervision (admin uniquement)

- **Création de comptes admin/agent** — réservée aux administrateurs.
- **Gestion des comptes** (client ou agent) : suspension, gel (blocage des transferts sortants uniquement), réactivation. Un administrateur peut consulter tous les types de comptes (y compris d'autres admins), mais ne peut pas suspendre/geler/réactiver un compte admin — garde-fou contre l'auto-verrouillage du système.
- **Modification réglementaire de l'identité d'un client** (conformité KYC), après vérification physique des justificatifs.
- **Annulation d'une transaction** dans un délai de 7 jours, avec remboursement automatique de l'expéditeur si le destinataire dispose encore des fonds.

### 6. Traçabilité et audit

- **`effectue_par_id`** sur la table `transactions` : identifie l'agent/admin ayant exécuté un dépôt ou un retrait.
- **Journal d'audit** (`journaux_audit`) : enregistre chaque action administrative sensible (suspension, gel, réactivation, création de compte, annulation de transaction, modification d'identité), avec l'acteur, la cible concernée (relation polymorphe vers `User` ou `Transaction`), un motif optionnel, et l'horodatage.

### 7. Notifications par e-mail

- **Codes OTP** : retrait au guichet, transfert au-delà du seuil de sécurité.
- **Reçus de transaction** : dépôt, retrait, transfert (avec nom du destinataire), réception (avec nom de l'expéditeur) — chacun affichant le nouveau solde du compte.
- **Alerte de sécurité** : détection d'un nouvel appareil, avec code de validation.

Toutes les notifications rappellent explicitement de ne jamais communiquer un code OTP à qui que ce soit, y compris au support KoriPay.

---

## Architecture technique notable

- **Middleware `EnsureUserHasRole`** : centralise le contrôle d'accès par rôle au niveau du routeur plutôt que dans chaque contrôleur.
- **Trait `GereTentativesConnexion`** : logique réutilisable de verrouillage anti-bruteforce, partagée entre les contrôleurs d'authentification.
- **Trait `JournaliseAction`** : enregistrement centralisé des entrées du journal d'audit.
- **Transactions de base de données** (`DB::beginTransaction`) sur toutes les opérations financières critiques (dépôt, retrait, transfert, annulation), avec verrouillage pessimiste (`lockForUpdate`) pour éviter les conditions de course sur les soldes.
- **Documentation API** générée automatiquement via annotations Swagger (PHP Attributes), consultable sur `/api/documentation`.

## Endpoints API

Toutes les routes sont préfixées par `/api`. Le détail complet (paramètres, exemples, codes de réponse) est disponible sur `/api/documentation`.

### Routes publiques (aucun token requis)

| Méthode | Route | Description |
|---|---|---|
| POST | `/inscription` | Créer un compte client |
| POST | `/auth/verifier-appareil` | Vérifier si l'appareil est déjà lié au compte |
| POST | `/login` | Connexion classique (téléphone + PIN + appareil) |
| POST | `/recuperation/verifier-identite` | Étape 1 de récupération de PIN oublié |
| POST | `/recuperation/reinitialiser-pin` | Étape 2 : appliquer le nouveau PIN |
| POST | `/login/nouvel-appareil/initier` | Étape 1 de liaison d'un nouvel appareil |
| POST | `/login/nouvel-appareil/valider` | Étape 2 : valider l'OTP et lier l'appareil |

### Routes authentifiées communes (`auth:sanctum`)

| Méthode | Route | Description |
|---|---|---|
| GET | `/user` | Profil de l'utilisateur connecté |
| POST | `/logout` | Déconnexion (révocation du token) |

### Routes client (`role:client`)

| Méthode | Route | Description |
|---|---|---|
| POST | `/client/transfert/initier` | Initier un transfert d'argent |
| POST | `/client/transfert/confirmer` | Confirmer un transfert (avec OTP si > 50 000 FCFA) |
| GET | `/client/transactions` | Historique des transactions du client |
| GET | `/client/transactions/{reference}` | Détail d'une transaction |
| GET | `/client/fidelite/solde` | Solde de points de fidélité |
| POST | `/client/fidelite/convertir` | Convertir des points en crédit |
| POST | `/client/profil/changer-pin` | Changer le code PIN |
| POST | `/client/profil/question-secrete` | Configurer la question secrète |

### Routes opérations guichet (`role:admin,agent`)

| Méthode | Route | Description |
|---|---|---|
| POST | `/admin/depot` | Effectuer un dépôt sur le compte d'un client |
| POST | `/admin/retrait/initier` | Étape 1 : demander un retrait (envoi de l'OTP) |
| POST | `/admin/retrait/confirmer` | Étape 2 : valider l'OTP et décaisser |

### Routes administration (`role:admin`)

| Méthode | Route | Description |
|---|---|---|
| POST | `/admin/creer-admin` | Créer un compte admin ou agent |
| GET | `/admin/comptes/{type}` | Lister les comptes d'un type (`client`, `agent`, `admin`) |
| GET | `/admin/comptes/{type}/{id}` | Détail d'un compte et ses dernières transactions |
| PUT | `/admin/comptes/{type}/{id}/suspendre` | Suspendre un compte (`client` ou `agent`) |
| PUT | `/admin/comptes/{type}/{id}/geler` | Geler un compte (`client` ou `agent`) |
| PUT | `/admin/comptes/{type}/{id}/reactiver` | Réactiver un compte (`client` ou `agent`) |
| PUT | `/admin/client/modifier-identite` | Modifier le nom/prénom d'un client (KYC) |
| GET | `/admin/transactions` | Historique global de toutes les transactions |
| PUT | `/admin/transactions/{reference}/annuler` | Annuler une transaction (délai de 7 jours) |

## Structure du projet

### Contrôleurs (`app/Http/Controllers/`)

| Fichier | Description |
|---|---|
| `Auth/AuthController.php` | Inscription, vérification d'appareil, connexion, liaison d'un nouvel appareil (2 étapes), déconnexion |
| `Auth/RecuperationController.php` | Récupération de PIN oublié : vérification d'identité et réinitialisation |
| `Client/TransfertController.php` | Initiation et confirmation des transferts d'argent entre clients |
| `Client/TransactionController.php` | Consultation de l'historique et du détail des transactions du client |
| `Client/ProfilController.php` | Consultation du profil, changement de PIN, configuration de la question secrète |
| `Client/FideliteController.php` | Consultation du solde de points et conversion en crédit |
| `Admin/AdminController.php` | Création de comptes admin/agent, gestion des comptes (suspendre/geler/réactiver), supervision des transactions, KYC |
| `Admin/OperationGuichetController.php` | Opérations de guichet : dépôt et retrait (avec OTP) |
| `Controller.php` | Classe de base abstraite des contrôleurs Laravel |

### Middleware (`app/Http/Middleware/`)

| Fichier | Description |
|---|---|
| `EnsureUserHasRole.php` | Vérifie que l'utilisateur connecté possède l'un des rôles autorisés pour la route (alias `role` déclaré dans `bootstrap/app.php`) |

### Traits réutilisables (`app/Traits/`)

| Fichier | Description |
|---|---|
| `GereTentativesConnexion.php` | Logique de verrouillage anti-bruteforce (vérification de suspension, enregistrement d'échec, réinitialisation), paramétrée par type d'action |
| `JournaliseAction.php` | Enregistrement centralisé d'une entrée dans le journal d'audit (`journaux_audit`) |

### Modèles (`app/Models/`)

| Fichier | Description |
|---|---|
| `User.php` | Compte utilisateur (client, agent ou admin) et ses relations (transactions, fidélité, appareils, tentatives de connexion) |
| `Transaction.php` | Un mouvement financier : dépôt, retrait, transfert ou réception |
| `VerificationOtp.php` | Codes OTP et tickets temporaires à usage unique (retrait, transfert, nouvel appareil, réinitialisation PIN) |
| `TentativeConnexion.php` | Compteur d'échecs et suspension temporaire, par utilisateur et par type d'action |
| `JournalAudit.php` | Une entrée du journal d'audit, avec relation polymorphe vers sa cible (`User` ou `Transaction`) |
| `Fidelite.php` | Solde de points de fidélité d'un client |
| `UserDevice.php` | Appareil mobile lié à un compte utilisateur |

### Notifications (`app/Notifications/`)

| Fichier | Description |
|---|---|
| `CodeOtpNotification.php` | E-mail contenant un code OTP (retrait au guichet ou transfert au-delà du seuil) |
| `FactureTransactionNotification.php` | Reçu de transaction envoyé par e-mail (dépôt, retrait, transfert, réception) |
| `NouvelAppareilNotification.php` | Alerte de sécurité et code de validation lors de la détection d'un nouvel appareil |

### Migrations (`database/migrations/`)

| Fichier | Description |
|---|---|
| `..._create_users_table.php` | Table des utilisateurs (client/agent/admin), avec le rôle en `enum` |
| `..._create_personal_access_tokens_table.php` | Tokens d'authentification Sanctum |
| `..._create_transactions_table.php` | Table des transactions, avec `effectue_par_id` pour la traçabilité des opérations de guichet |
| `..._create_verification_otps_table.php` | Table des codes OTP et tickets temporaires |
| `..._create_fidelites_table.php` | Table des soldes de points de fidélité |
| `..._add_security_fields_to_users_table.php` | Ajout des champs de sécurité (question secrète, réponse secrète) aux utilisateurs |
| `..._create_user_devices_table.php` | Table des appareils liés aux comptes |
| `..._create_tentatives_connexion_table.php` | Table des compteurs anti-bruteforce, avec `type_action` pour séparer les compteurs par contexte |
| `..._create_journaux_audit_table.php` | Table du journal d'audit des actions administratives |

### Routes et configuration

| Fichier | Description |
|---|---|
| `routes/api.php` | Déclaration de toutes les routes API, organisées par groupe de rôle |
| `bootstrap/app.php` | Configuration de l'application, y compris l'alias du middleware `role` |

## Structure du projet

### Contrôleurs (`app/Http/Controllers/`)

| Fichier | Description |
|---|---|
| `Auth/AuthController.php` | Inscription, vérification d'appareil, connexion, liaison d'un nouvel appareil (initiation + validation OTP), déconnexion |
| `Auth/RecuperationController.php` | Récupération de PIN oublié : vérification d'identité (habitudes de transaction + question secrète) et réinitialisation du PIN |
| `Client/TransfertController.php` | Initiation et confirmation des transferts d'argent entre clients, avec OTP au-delà du seuil de sécurité |
| `Client/TransactionController.php` | Consultation de l'historique des transactions du client connecté |
| `Client/FideliteController.php` | Consultation du solde de points de fidélité et conversion en crédit |
| `Client/ProfilController.php` | Consultation du profil, changement de PIN, configuration de la question secrète |
| `Admin/OperationGuichetController.php` | Opérations de guichet : dépôt, retrait (initiation + confirmation OTP) |
| `Admin/AdminController.php` | Création de comptes admin/agent, gestion des comptes (lister, voir, suspendre, geler, réactiver), modification d'identité KYC, supervision et annulation des transactions |
| `Controller.php` | Classe de base abstraite des contrôleurs Laravel |

### Modèles (`app/Models/`)

| Fichier | Description |
|---|---|
| `User.php` | Comptes clients, agents et admins ; relations vers transactions, fidélité, appareils, tentatives de connexion, OTP |
| `Transaction.php` | Dépôts, retraits, transferts et réceptions ; relations vers expéditeur, destinataire et exécutant (`effectue_par_id`) |
| `VerificationOtp.php` | Codes OTP et tickets temporaires (retrait, transfert, nouvel appareil, réinitialisation de PIN) |
| `TentativeConnexion.php` | Compteurs anti-bruteforce, un enregistrement par utilisateur et par type d'action |
| `JournalAudit.php` | Entrées du journal d'audit des actions administratives, avec cible polymorphe (`User` ou `Transaction`) |
| `UserDevice.php` | Appareils liés à chaque compte utilisateur |
| `Fidelite.php` | Solde et historique de points de fidélité par client |

### Middleware (`app/Http/Middleware/`)

| Fichier | Description |
|---|---|
| `EnsureUserHasRole.php` | Vérifie que l'utilisateur connecté a l'un des rôles autorisés pour accéder à une route (alias `role`) |

### Traits (`app/Traits/`)

| Fichier | Description |
|---|---|
| `GereTentativesConnexion.php` | Logique réutilisable de verrouillage anti-bruteforce : vérification de suspension, enregistrement d'échec, réinitialisation du compteur |
| `JournaliseAction.php` | Enregistrement centralisé d'une entrée dans le journal d'audit (`journaux_audit`) |

### Notifications (`app/Notifications/`)

| Fichier | Description |
|---|---|
| `CodeOtpNotification.php` | E-mail contenant un code OTP (retrait au guichet ou transfert au-delà du seuil) |
| `FactureTransactionNotification.php` | Reçu de transaction par e-mail (dépôt, retrait, transfert, réception), avec contact et nouveau solde |
| `NouvelAppareilNotification.php` | Alerte de sécurité par e-mail lors de la détection d'un nouvel appareil |

### Migrations (`database/migrations/`)

| Fichier | Description |
|---|---|
| `..._create_users_table.php` | Table des comptes (`role` : `client`, `agent`, `admin`) |
| `..._add_security_fields_to_users_table.php` | Ajout des champs de sécurité (question secrète, réponse secrète, statut) |
| `..._create_user_devices_table.php` | Table des appareils liés aux comptes |
| `..._create_personal_access_tokens_table.php` | Table des tokens Sanctum |
| `..._create_transactions_table.php` | Table des transactions, avec `effectue_par_id` pour la traçabilité des opérations guichet |
| `..._create_verification_otps_table.php` | Table des codes OTP et tickets temporaires |
| `..._create_fidelites_table.php` | Table des comptes de fidélité |
| `..._create_tentatives_connexion_table.php` | Table des compteurs anti-bruteforce, avec `type_action` |
| `..._create_journaux_audit_table.php` | Table du journal d'audit des actions administratives |

## Modèle de données (tables principales)

| Table | Rôle |
|---|---|
| `users` | Comptes clients, agents et admins |
| `transactions` | Dépôts, retraits, transferts, réceptions |
| `verification_otps` | Codes OTP et tickets temporaires (retrait, transfert, nouvel appareil, réinitialisation PIN) |
| `tentatives_connexion` | Compteurs anti-bruteforce par utilisateur et par type d'action |
| `journaux_audit` | Journal des actions administratives sensibles |
| `user_devices` | Appareils liés à chaque compte |
| `fidelites` | Points de fidélité par client |
