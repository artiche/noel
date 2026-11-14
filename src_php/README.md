# Tirage Noel - API REST PHP

Version PHP (sans framework) du moteur de tirage, dans `src_php/`.

## Prerequis

- PHP 8.2+
- Variable d'environnement `JWT_SECRET` (obligatoire pour les routes protegees)

## Lancer localement

Depuis la racine du repository:

```powershell
set JWT_SECRET=change-me
php -S 127.0.0.1:8080 -t src_php/public
```

### Deploiement dans un sous-dossier (`/noel/api`)

Configurer la variable d'environnement `BASE_PATH` avec le prefixe de l'API:

- `BASE_PATH=/noel/api`

Sur un hebergement Apache (ex: IONOS), vous pouvez definir `BASE_PATH` et activer le routage dans le `.htaccess` du dossier web de l'API:

```apache
SetEnv BASE_PATH /noel/api

RewriteEngine On
RewriteBase /noel/api/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [QSA,L]
```

Avec cette valeur:
- requete `GET /noel/api/health` route vers l'endpoint `/health`
- requete `GET /noel/api/draw/2026` route vers `/draw/2026`

## Endpoints

### `GET /health`

Retourne l'etat de l'API.

### `POST /login`

Authentifie un utilisateur et renvoie un cookie de session JWT.

Body JSON:

```json
{
  "login": "admin",
  "password": "noel123"
}
```

Reponse:
- `200` + cookie `noel_session` (`HttpOnly`, `Secure`, `SameSite=Strict`, expiration 30 min)
- `401` si identifiants invalides

### `GET /possible?year=2027[&seed=42][&historyFile=...][&constraintsFile=...]`

Retourne, pour chaque donneur, la liste des receveurs possibles tries par score.
Route protegee (JWT requis).

### `GET /draw/{year}[?historyFile=...&constraintsFile=...]`

Retourne le tirage enregistre pour l'annee demandee.
Route protegee (JWT requis).

- `200` si trouve
- `404` si absent

Le payload contient:
- `assignmentsByGiver`: map `donneur -> receveur`
- `lines`: tableau de phrases `donneur offre un cadeau a receveur`

### `POST /draw`

Calcule un tirage optimal.
Route protegee (JWT requis).

Exemple de body:

```json
{
  "year": 2027,
  "seed": 42,
  "save": false,
  "historyFile": "data/history.json",
  "constraintsFile": "data/constraints.json"
}
```

- `save=true` enregistre l'annee dans l'historique (format `receveur -> donneur`)
- `409` si l'annee existe deja
- `422` si aucune solution valide n'existe

## Fichiers JSON

L'API utilise les memes structures que la version .NET 10:

- `src_php/data/constraints.json`
- `src_php/data/history.json`
- `src_php/data/users.json` (logins + hash mots de passe)

### Securiser le dossier `data` (recommande en production)

Le dossier `data` contient des informations sensibles (utilisateurs/hash et historique). Il doit idealement rester hors webroot. Si ce n'est pas possible sur l'hebergement, ajoutez un `.htaccess` dans `src_php/data/` pour bloquer l'acces HTTP direct:

```apache
Require all denied
```

Si votre hebergement Apache est plus ancien:

```apache
Order allow,deny
Deny from all
```

## Configuration auth

- `JWT_SECRET` (obligatoire): secret de signature JWT HS256
- `JWT_COOKIE_NAME` (optionnel, defaut `noel_session`)
- `JWT_TTL_SECONDS` (optionnel, defaut `1800`)

## Tests

```powershell
php src_php/tests/run.php
```
