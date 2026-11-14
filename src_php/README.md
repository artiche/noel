# Tirage Noel - API REST PHP

Version PHP (sans framework) du moteur de tirage, dans `src_php/`.

## Prerequis

- PHP 8.2+

## Lancer localement

Depuis la racine du repository:

```powershell
php -S 127.0.0.1:8080 -t src_php/public
```

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
