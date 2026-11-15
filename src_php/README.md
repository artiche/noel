# Tirage Noel - API REST PHP

Version PHP (sans framework) du moteur de tirage, dans `src_php/`.

## Prerequis

- PHP 8.2+
- Extensions PHP `intl` et `mbstring` (normalisation des identifiants Unicode)
- Fichier `src_php/data/config.php` (template versionne, obligatoire)
- Fichier `src_php/data/config.local.php` (optionnel, non versionne, recommande pour les secrets)

## Lancer localement

Depuis la racine du repository:

```powershell
php -S 127.0.0.1:8080 -t src_php/public
```

Avant de lancer l'API, laissez `config.php` en template et creez
`src_php/data/config.local.php` pour les valeurs propres a l'environnement,
notamment les secrets. Ce fichier surcharge `config.php`.

### Deploiement dans un sous-dossier (`/noel/api`)

Configurer la cle `base_path` dans `data/config.php` avec le prefixe de l'API:

- `'base_path' => '/noel/api'`

Sur un hebergement Apache (ex: IONOS), activez le routage dans le `.htaccess` du dossier web de l'API:

```apache
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
La comparaison du login ignore la casse et les accents ; le login conserve
dans la session reste celui du fichier utilisateurs.

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

### `POST /logout`

Supprime le cookie de session du navigateur, y compris si la session a expire.
La reponse contient `data.authenticated: false`. Un JWT copie auparavant reste
valide jusqu'a son expiration : cet endpoint ne revoque pas les jetons.

### `GET /session`

Retourne `data.authenticated`, `data.isAdmin`, le `login` canonique, l'`email`
du compte et `participant`, le nom correspondant dans les contraintes ou
`null` en l'absence de correspondance. `participantKey` contient le nom
normalise sans casse ni accents, ou `null` si aucun participant n'est associe.
Le cookie JWT reste `HttpOnly` ; la
reponse ne contient ni jeton ni hash et ne doit pas etre mise en cache. Une
session absente ou invalide retourne `401`.

### `POST /account`

Modifie immediatement le compte authentifie, sans confirmation par email.
Le corps contient `currentPassword` et au moins un des champs `email` et
`newPassword`. L'email doit etre valide ; le nouveau mot de passe non vide ne
doit pas contenir de caractere NUL ni depasser 72 octets. L'utilisateur ne
peut modifier ni son login ni son role. Une reponse `403` avec le code
`invalid_current_password` indique un mot de passe actuel incorrect ; des
champs invalides renvoient `400`.

Un changement de mot de passe revoque les sessions precedentes et les liens
de reset, et fournit un nouveau cookie pour maintenir la session courante. Un
changement d'email conserve les sessions mais invalide les demandes de reset
en attente envoyees a l'ancienne adresse. La reponse contient
`data.updated: true` et ne doit pas etre mise en cache.

### Wishlists des participants

Configurer `wishlist_directory` dans un repertoire prive accessible en lecture
et ecriture par PHP, hors du repertoire public. Par defaut il s'agit de
`data/wishlists`, protege par le `.htaccess` de `data` sur Apache ; sur un autre
serveur, interdire explicitement tout acces HTTP direct a ce repertoire.
Les documents et leurs images sont stockes ensemble ; ce repertoire ne doit
pas etre publie ni versionne et doit etre inclus dans les sauvegardes privees.
Configurer aussi `wishlist_frontend_url` dans `config.local.php` avec l'URL
HTTPS de base de l'interface, par exemple `https://example.com/noel`, sans
query ni fragment. La configuration SMTP utilisee est celle des resets.

Les routes requierent une session valide dont le login correspond a un
participant actuel, sans tenir compte de la casse ni des accents :

- `GET /wishlist` renvoie le document personnel, sa revision et le
  destinataire du tirage le plus recent jusqu'a l'annee courante du serveur,
  ou `null` en l'absence d'attribution.
- `PUT /wishlist` recoit `ops` (document Quill Delta) et `revision`.
  La revision evite d'ecraser une modification concurrente (`409`).
- `GET /wishlist/recipient?target=...` retourne la wishlist du destinataire
  si, et seulement si, le demandeur est son donneur dans le dernier tirage.
  La cible est la cle normalisee fournie par l'API ; elle ne confere aucun
  droit. L'autorisation est recalculee pour chaque consultation.
- `POST /wishlist/notify` envoie au donneur du demandeur un lien cible vers
  l'interface. L'envoi n'est pas automatique ni limite ; une absence de
  tirage ou de compte donneur et les erreurs SMTP sont signalees.

Les images importees PNG, JPEG, GIF et WebP sont integrees au document : au
maximum 5 Mo (5 000 000 octets) par image et 20 Mo d'images par wishlist. Les URL d'images
externes doivent etre HTTPS ; elles sont chargees par le navigateur, pas par
le serveur. Le document n'accepte qu'un sous-ensemble de formats Quill et de
liens HTTP(S). L'API ne stocke ni ne rend de HTML arbitraire.
Pour ces tailles, autoriser des requetes JSON d'au moins 29 Mio dans le serveur
web/proxy et prevoir suffisamment de memoire PHP (512 Mio recommandes). La
limite applicative des requetes est de 29 Mio ; un `413` signale un depassement.
Les reponses wishlist ne doivent pas etre mises en cache.

La wishlist survit aux changements d'annee et a la suppression du seul compte.
La suppression du participant supprime sa wishlist et les images integrees ;
un changement de graphie equivalent conserve la wishlist. Les fichiers de
donnees sont synchronises avec le verrou utilisateur. Un lien ancien cesse de
donner acces des que le tirage pertinent ne l'autorise plus.

### Administration des comptes et participants

Toutes les routes `/admin/*` requierent un compte administrateur actif.
Les roles sont verifies depuis le fichier utilisateurs a chaque requete :
une promotion ou retrogradation prend effet immediatement, y compris pour
les sessions existantes. Le dernier administrateur ne peut etre ni supprime
ni retrograde.

- `GET /admin/users` retourne `users` (login, email, isAdmin) et `participants`
  (nom, login associe ou null, login suggere, destinataires interdits), sans
  hash ni mot de passe ; la reponse ne doit pas etre mise en cache.
- `POST /admin/users` prend `login`, `email`, `isAdmin` et eventuellement
  `participant`. Si ce dernier est fourni, son nom doit correspondre au
  login sans casse ni accents. Un mot de passe aleatoire non communique est
  genere et seul son hash est conserve.
- `PATCH /admin/users` prend `login` et au moins un champ parmi `email`,
  `isAdmin`, `password`. Le login n'est pas modifiable. Le mot de passe
  manuel ne doit pas depasser 72 octets ni contenir NUL.
- `DELETE /admin/users` prend `login`. La suppression revoque ses sessions et
  liens de reinitialisation, sans supprimer son participant ni ses tirages.
- `POST /admin/users/reset` prend `login` et envoie l'email de reset existant.
  La creation et l'envoi sont deux operations distinctes : si l'envoi echoue,
  le compte reste cree et l'envoi peut etre retente.
- `POST /admin/participants` prend `name` et ajoute un participant sans
  interdiction. `PATCH /admin/participants` prend `name` et au moins un des
  champs `newName` et `forbiddenRecipients`. Le nouveau nom doit etre
  equivalent a l'ancien sans casse ni accents ; les references des
  interdictions sont mises a jour. `DELETE /admin/participants` prend `name`
  et retire aussi ses references dans les interdictions, sans supprimer son
  compte ni modifier l'historique.

Les operations d'administration et les enregistrements de tirage partagent
le verrou utilisateur. La suppression d'un participant et celle de son compte
sont independantes : en cas d'echec de la seconde, la premiere demeure
effectuee. Un changement manuel de mot de passe revoque les sessions et liens
de reset precedents ; un changement d'email invalide les demandes de reset
adressees a l'ancien email.

### `POST /password/forgot`

Demande un reset de mot de passe pour un login donne.
Le login est compare sans tenir compte de la casse ni des accents.

Body JSON:

```json
{
  "login": "admin"
}
```

Comportement:
- `404` si le login n'existe pas
- `422` si l'email associe est invalide
- `200` + `data.passwordResetRequested=true` si la demande est creee
- envoi d'un email via PHPMailer (SMTP IONOS) contenant:
  - un mot de passe temporaire
  - un lien signe de confirmation (valable 1h par defaut)
- une nouvelle demande invalide les liens precedents du meme compte, meme si
  l'envoi du nouvel email echoue ; dans ce cas, effectuer une nouvelle demande

### `GET /password/confirm?token=...`

Valide le lien de confirmation de reset:
- verifie signature + expiration du token
- applique le nouveau hash mot de passe en base
- invalide toutes les demandes de reset et les sessions existantes du compte
- repond par redirection HTTP `302` vers la page de login

Les anciens liens emis avant l'ajout de la version de session ne sont plus
utilisables ; une nouvelle demande est necessaire.

### `GET /possible?year=2027[&seed=42]`

Retourne, pour chaque donneur, la liste des receveurs possibles tries par score.
Route protegee (JWT requis).

### `GET /draw/{year}`

Retourne le tirage enregistre pour l'annee demandee.
Route protegee (JWT requis).

- `200` si trouve
- `404` si absent

Le payload contient:
- `assignmentsByGiver`: map `donneur -> receveur`
- `lines`: tableau de phrases `donneur offre un cadeau a receveur`
- `assignments`: tableau ordonne contenant `giver`, `recipient` et leurs cles
  normalisees `giverKey`, `recipientKey` pour la comparaison des identites

Les noms proviennent de l'historique et conservent leur graphie d'origine,
independamment des participants actuels. Pour les futurs calculs, les
rencontres historiques sont rapprochees sans casse ni accents. Les collisions
ambigues dans une annee historique sont refusees.

### `POST /draw`

Calcule un tirage optimal.
Route protegee : JWT valide et role administrateur requis. Un utilisateur
authentifie non administrateur recoit `403`.

Exemple de body:

```json
{
  "year": 2027,
  "seed": 42,
  "save": false
}
```

- `save=true` enregistre l'annee dans l'historique (format `receveur -> donneur`)
- `409` si l'annee existe deja
- `422` si aucune solution valide n'existe

Les parametres `historyFile` et `constraintsFile` sont refuses : les fichiers
de tirage utilises sont ceux du serveur, pas des chemins choisis par le client.

## Fichiers JSON

L'API utilise les memes structures que la version .NET 10:

- `src_php/data/constraints.json`
- `src_php/data/history.json`
- `src_php/data/users.json` (login + hash mot de passe + email + `isAdmin`)
- `src_php/data/passwordResets.json` (demandes de reset en attente / consommees)

Les noms des participants et les logins applicatifs doivent etre uniques apres
suppression des accents et normalisation de la casse Unicode. Toute collision
est rejetee explicitement ; corriger les fichiers concernes avant deploiement.
L'association est calculee depuis les fichiers actuels et ne modifie pas les
droits d'acces ni les resultats du tirage.
La liste des participants peut etre vide ; les nouveaux calculs renvoient alors
`422` avec le code `no_participants`, tandis que les tirages historiques
restent consultables.

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

- `jwt_secret` (obligatoire): secret aleatoire de signature JWT HS256, au moins 32 octets ; les placeholders sont refuses
- `jwt_cookie_name` (obligatoire, valeur recommandee `noel_session`)
- `jwt_ttl_seconds` (obligatoire, valeur recommandee `1800`)

`isAdmin` est un booleen JSON. Un champ absent vaut `false` ; une valeur
presente non booleenne est rejetee. Attribuez explicitement `true` aux comptes
administrateurs dans le fichier utilisateurs de production sans ecraser ses
donnees existantes. Le role est inclus dans le JWT signe, sans l'email, mais
les droits effectifs sont toujours relus dans le fichier utilisateurs. Un
changement de role prend effet immediatement sans revoquer la session. Les
anciens jetons sans role sont refuses et necessitent une reconnexion.

Les chemins des fichiers utilisateurs et de reinitialisation sont definis
uniquement par la configuration serveur. Les parametres clients `usersFile`
et `passwordResetsFile` sont refuses sur les routes d'authentification.
Une version de session est associee au compte et verifiee a chaque requete
authentifiee. Confirmer un reset l'incremente et revoque donc tous les JWT
precedents du compte. Les anciens JWT sans version necessitent une reconnexion.
Un utilisateur existant sans `sessionVersion` commence a zero.
Les comptes crees par l'administration possedent un identifiant d'incarnation :
apres suppression et recreation d'un meme login, les anciens jetons et liens
de reset ne peuvent pas etre reutilises. Les comptes preexistants sans cet
identifiant continuent a fonctionner.

## Configuration reset password / SMTP

- `password_reset_ttl_seconds` (obligatoire, valeur recommandee `3600`)
- `password_reset_confirm_base_url` (obligatoire)
- `login_redirect_url` (obligatoire, valeur recommandee `/login`)
- `users_file_path` (obligatoire, valeur recommandee `data/users.json`)
- `password_resets_file_path` (obligatoire, valeur recommandee `data/passwordResets.json`)
- `smtp_host` (obligatoire, valeur recommandee `smtp.ionos.fr`)
- `smtp_port` (obligatoire, valeur recommandee `465`)
- `smtp_username` (obligatoire pour l'envoi de mail)
- `smtp_password` (obligatoire pour l'envoi de mail)
- `smtp_from_email` (obligatoire pour l'envoi de mail)
- `smtp_from_name` (obligatoire, valeur recommandee `Noel API`)

### Exemple minimal de `src_php/data/config.php`

```php
<?php

declare(strict_types=1);

return [
    'base_path' => '',
    'jwt_secret' => 'CHANGE_ME_JWT_SECRET',
    'jwt_cookie_name' => 'noel_session',
    'jwt_ttl_seconds' => 1800,
    'password_reset_ttl_seconds' => 3600,
    'password_reset_confirm_base_url' => 'https://example.com/api/password/confirm',
    'login_redirect_url' => '/login',
    'users_file_path' => 'data/users.json',
    'password_resets_file_path' => 'data/passwordResets.json',
    'smtp_host' => 'smtp.ionos.fr',
    'smtp_port' => 465,
    'smtp_username' => 'CHANGE_ME_SMTP_USERNAME',
    'smtp_password' => 'CHANGE_ME_SMTP_PASSWORD',
    'smtp_from_email' => 'CHANGE_ME_FROM_EMAIL@example.com',
    'smtp_from_name' => 'Noel API',
];
```

En mode strict, l'API ne lit plus `$_SERVER/getenv` pour ces cles: si le fichier
manque ou est invalide, elle renvoie une erreur `500` explicite.
La valeur `CHANGE_ME_JWT_SECRET` ne permet pas de demarrer l'API. Generez un
secret distinct pour chaque environnement, par exemple avec :

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Copiez le resultat dans `jwt_secret` de `config.local.php`, sans le publier ni
le committer. Si un secret de demonstration a ete utilise en production,
remplacez-le immediatement : cette rotation invalide tous les JWT et liens de
reset signes avec l'ancienne cle. Une longueur minimale ne garantit pas
l'aleatoire ; utilisez un generateur cryptographique.

### Variante recommandee: `config.php` + `config.local.php`

- `config.php` (versionne): valeurs par defaut + placeholders non sensibles
- `config.local.php` (non versionne): secrets reels
- ordre de chargement: base `config.php`, puis surcharge de `config.local.php` si present

Exemple de `src_php/data/config.local.php`:

```php
<?php

declare(strict_types=1);

return [
    'jwt_secret' => 'SECRET_REEL',
    'password_reset_confirm_base_url' => 'https://votre-domaine/noel/api/password/confirm',
    'smtp_username' => 'utilisateur-smtp',
    'smtp_password' => 'mot-de-passe-smtp',
    'smtp_from_email' => 'no-reply@votre-domaine',
];
```

Un template est fourni dans [config.local.php.example](/workspaces/noel/src_php/data/config.local.php.example).

## Tests

```powershell
php src_php/tests/run.php
```
