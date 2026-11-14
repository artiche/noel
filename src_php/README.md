# Tirage Noel - API REST PHP

Version PHP (sans framework) du moteur de tirage, dans `src_php/`.

## Prerequis

- PHP 8.2+

## Lancer localement

Depuis la racine du repository:

```powershell
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

### `GET /possible?year=2027[&seed=42][&historyFile=...][&constraintsFile=...]`

Retourne, pour chaque donneur, la liste des receveurs possibles tries par score.

### `GET /draw/{year}[?historyFile=...&constraintsFile=...]`

Retourne le tirage enregistre pour l'annee demandee.

- `200` si trouve
- `404` si absent

Le payload contient:
- `assignmentsByGiver`: map `donneur -> receveur`
- `lines`: tableau de phrases `donneur offre un cadeau a receveur`

### `POST /draw`

Calcule un tirage optimal.

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

## Tests

```powershell
php src_php/tests/run.php
```
