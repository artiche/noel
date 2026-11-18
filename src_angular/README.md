# Tirages de Noël

Application Angular 22 et Angular Material pour consulter les tirages de l'API PHP.

## Développement

Node.js compatible avec Angular 22 (`^22.22.3`, `^24.15.0` ou `>=26.0.0`) et PHP 8.2+ sont nécessaires. Depuis la racine du dépôt, démarrer l'API :

```sh
php -d enable_post_data_reading=0 -S 127.0.0.1:8080 -t src_php/public src_php/public/index.php
```

Configurez auparavant `src_php/data/config.local.php` avec un `jwt_secret`
aléatoire d'au moins 32 octets et `base_path` vide ; voir la documentation PHP.

Dans un autre terminal :

```sh
cd src_angular
npm ci
npm start
```

Ouvrir **https://localhost:4200/** et accepter l'avertissement lié au certificat de développement auto-signé. Le proxy transmet `/api/**` à l'API en retirant le préfixe `/api` : par exemple, `/api/login` devient `/login`. Le cookie de session de l'API est `Secure`, `HttpOnly` et `SameSite=Strict`. Le secret ci-dessus est uniquement destiné au développement ; ne pas l'utiliser en production.

L'API locale doit être démarrée avec `base_path` vide dans sa configuration. Redémarrez aussi Angular après une modification du proxy. `https://localhost:4200/api/health` doit retourner un état `ok`.

## Production

L'interface et l'API doivent partager la même origine HTTPS. L'URL d'API est `api/` relative au `base href` de l'application. Par exemple, pour héberger l'application sous `/noel/` et l'API sous `/noel/api`, construire avec `npm run build -- --base-href /noel/`, servir les fichiers de `dist/noel/browser/` à cet emplacement et configurer `base_path` à `/noel/api` pour PHP. Le serveur doit acheminer les requêtes API vers PHP avant de rediriger les routes Angular inconnues vers `index.html`. Adapter le `base href`, le chemin de l'API et le proxy de développement si nécessaire.

La version de production inclut un manifest et un service worker Angular : elle
peut être installée depuis un navigateur compatible. Le service worker met en
cache les ressources de l'interface, pas les réponses de l'API ni les wishlists ;
une connexion reste nécessaire pour consulter ou modifier les données. Le
service worker n'est pas activé en développement. La réception depuis les menus
de partage Android dépend du support Web Share Target du navigateur installé ;
la réception iOS nécessite le Raccourci décrit ci-dessous.

Le menu utilisateur donne accès à **Clés de partage** : un participant peut
créer jusqu'à dix clés pour le Raccourci iOS, dont le secret n'est affiché
qu'une fois, et les révoquer. Les clés permettent uniquement de créer des
brouillons, à confirmer dans l'écran de partage. Un changement
ou une réinitialisation de mot de passe révoque toutes les clés : il faut alors
en créer une nouvelle et actualiser le Raccourci. Les anciens participants
peuvent toujours consulter et révoquer leurs clés.

### Partager vers la wishlist

Sur Android, installer l'application depuis un navigateur compatible, puis
la choisir dans le menu de partage pour envoyer une URL, du texte ou une
image PNG, JPEG, GIF ou WebP. Sur iOS, installer la PWA via Safari si souhaité ;
iOS ne propose pas Web Share Target pour les PWA, et le partage passe donc par
un Raccourci Apple :

1. Dans **Clés de partage**, créer une clé et la copier immédiatement. La
   conserver de façon privée : toute personne qui la possède peut créer des
   brouillons pour ce compte. Ne pas partager le Raccourci contenant la clé.
2. Dans Raccourcis, créer un raccourci et activer **Afficher dans la feuille
   de partage** ; accepter les URL, textes et images. Les intitulés des actions
   peuvent varier selon la version d'iOS.
3. Selon le type de l'entrée du raccourci, préparer un champ texte `url` pour
   une URL ou `text` pour un texte. Pour une image, préparer un seul fichier
   `image` PNG, JPEG, GIF ou WebP. Les champs absents peuvent être omis ; un
   texte peut aussi contenir une URL. En cas de sélection multiple, demander à
   l'utilisateur de choisir une image plutôt que d'en perdre silencieusement.
4. Ajouter **Obtenir le contenu de l'URL** : URL
   `https://example.com/noel/api/wish` adaptée à l'installation, méthode
   `POST`, en-tête `Authorization` valant `Bearer ` suivi de la clé, corps de
   type formulaire avec les champs préparés. Le client doit envoyer
   `multipart/form-data` avec une frontière générée automatiquement ; ne pas
   fixer manuellement l'en-tête `Content-Type`. Ne jamais mettre la clé dans
   l'URL. Pour éviter son enregistrement dans le Raccourci, la demander à
   chaque exécution ; sinon protéger l'accès au raccourci et à ses sauvegardes.
5. Traiter la réponse comme un dictionnaire : obtenir successivement la valeur
   de `data`, puis celle de `confirmationUrl`, et utiliser **Ouvrir les URL**.
   Si la requête échoue ou si la réponse ne contient pas cette URL, afficher
   une erreur et ne pas annoncer un succès. Tester le formulaire sur l'appareil :
   la disponibilité des types de fichiers et le format envoyé peuvent varier
   selon iOS. Le contrat exact figure dans le README PHP.

Le Raccourci crée seulement un brouillon ; l'ouverture peut avoir lieu dans
Safari plutôt que dans la PWA installée. Les sessions et stockages peuvent être
distincts : se connecter si nécessaire avec le compte propriétaire de la clé.
Le premier compte participant ouvrant un partage Android en devient
propriétaire. Dans `/partage`, vérifier ou modifier les champs, ajouter un
commentaire et confirmer ou annuler. L'image peut être conservée ou retirée.
Le texte partagé et le commentaire sont ajoutés ensemble en texte brut. Un
conflit de wishlist impose une revue avant une nouvelle confirmation.

Une connexion est indispensable : les brouillons restent sur le serveur et
expirent après sept jours. Le lien secret et les modifications textuelles sont
conservés temporairement dans le stockage de l'onglet pour permettre la
reconnexion ; l'image n'y est pas copiée. Ne pas fermer cet onglet avant la
confirmation, et ne pas communiquer le lien. Les partages multiples restent
distincts dans cet onglet. Si le stockage échoue, l'interface bloque la
reconnexion automatique et demande de réessayer.

Lorsqu'une URL est partagée sans titre, le serveur tente après authentification
de suggérer le titre OpenGraph ou, à défaut, le titre HTML. La source est
indiquée et le titre reste modifiable ; aucune image distante n'est importée.
L'URL est alors contactée par le serveur. L'utilisateur peut continuer sans
attendre et saisir un titre si la récupération échoue ou est désactivée.

La déconnexion expire le cookie du navigateur ; elle ne révoque pas un jeton copié. Voir la documentation de `../src_php/README.md` pour le fonctionnement et la configuration de l'API.

### Déploiement GitHub Actions vers IONOS

Le workflow [deploy.yml](../.github/workflows/deploy.yml) déploie les composants
modifiés sur l'environnement `dev` lors d'un push sur `main`. Dans **Actions >
Déploiement IONOS > Run workflow**, sélectionner `dev` ou `prod` et les
composants à déployer. Utiliser ce lancement manuel pour un premier déploiement
complet ou après modification des variables et secrets : leur modification ne
déclenche pas le workflow. Les déploiements vers un même environnement sont
sérialisés. Ne pas configurer `dev` et `prod` sur le même répertoire distant.

Créer les deux environnements dans **Settings > Environments**, puis renseigner
séparément les valeurs suivantes dans les secrets ou variables de chaque
environnement. On peut protéger `prod` par une validation GitHub.

| Secret GitHub | Contenu |
| --- | --- |
| `SFTP_HOST` | Nom d'hôte SFTP IONOS, sans protocole ni port ; port SSH 22. |
| `SFTP_USER` | Identifiant SFTP. |
| `SFTP_PWD` | Mot de passe SFTP. |
| `JWT_SECRET` | Secret aléatoire distinct par environnement, d'au moins 32 octets ; ne pas le modifier à chaque déploiement. |
| `SMTP_USERNAME` | Identifiant SMTP. |
| `SMTP_PASSWORD` | Mot de passe SMTP. |

| Variable GitHub | Contenu |
| --- | --- |
| `SFTP_HOST_KEY` | Ligne complète au format `known_hosts`, par exemple `sftp.example.com ssh-ed25519 AAAA...` ; le nom doit correspondre à `SFTP_HOST`. |
| `SFTP_REMOTE_ROOT` | Chemin absolu SFTP du répertoire **dédié à l'application**, par exemple `/sites/noel` ou `/` si le compte est enfermé dans ce répertoire. |
| `SITE_URL` | URL HTTPS publique de l'interface, par exemple `https://example.com` ou `https://example.com/noel`, sans requête ni fragment. |
| `SMTP_HOST` | Serveur SMTP, par exemple `smtp.ionos.fr`. |
| `SMTP_PORT` | Port SMTP, par exemple `465`. |
| `SMTP_FROM_EMAIL` | Adresse d'expédition autorisée par le serveur SMTP. |
| `SMTP_FROM_NAME` | Nom d'expéditeur, par exemple `Noel API`. |
| `SHARE_METADATA_ENABLED` | Facultatif : `false` pour désactiver les suggestions de titre ; défaut `true`. |
| `SHARE_METADATA_DNS_PHP` | Facultatif : `php` ou chemin absolu simple du binaire PHP CLI sur IONOS ; défaut `php`. |

Les paramètres JWT et SMTP sont requis pour déployer l'API ; un déploiement
du seul frontend nécessite seulement les paramètres SFTP et `SITE_URL`.
Les chemins ne doivent contenir ni espaces, ni segments `.` ou `..` ; le chemin
public ne doit pas contenir de caractères encodés. `SFTP_REMOTE_ROOT` désigne
le dossier côté SFTP, et non le chemin de l'URL : il doit correspondre au
document root IONOS ou au sous-dossier servi à l'URL `SITE_URL`. Vérifier
l'empreinte de la clé SSH auprès d'IONOS par un canal fiable avant d'ajouter la
ligne `known_hosts` ; ne pas accepter automatiquement une clé inconnue. Le
workflow refuse les clés d'hôte inconnues ou modifiées.

Le workflow teste l'API et Angular pour les composants sélectionnés, vérifie
les artefacts PWA construits pour le chemin public, construit Angular avec
Node 24, installe les dépendances Composer
de production, puis génère `api/data/config.local.php` sur le runner depuis ces
valeurs. Il configure automatiquement le chemin `/api`, les liens de mail et
la redirection vers `/connexion`, y compris pour un sous-dossier. Il ajoute
aussi aux `.htaccess` du frontend et de l'API leurs `RewriteBase` respectifs,
derives du chemin public de `SITE_URL`. Le fichier
local de développement n'est jamais copié. Les autres paramètres restent
définis dans le `config.php` versionné.

Au premier déploiement de l'API, les fichiers `constraints.json`,
`history.json`, `users.json` et `passwordResets.json` sont copiés du dépôt
**seulement s'ils sont absents**. Vérifier leur contenu et le compte
administrateur initial avant de lancer le workflow : ils ne constituent pas
nécessairement des données vierges. Les JSON existants, verrous, wishlists et
autres données de `api/data/` sont conservés. Le code de l'API et les fichiers
du frontend obsolètes sont supprimés de leurs répertoires respectifs ; aucun
fichier étranger à l'application ne doit se trouver dans le répertoire dédié.
La synchronisation du frontend exclut `api/`.

Aucune sauvegarde automatique n'est effectuée. Prévoir des sauvegardes privées,
testées et protégées des données et de la configuration avant déploiement ; le
transfert n'est pas atomique. PHP doit pouvoir lire la configuration et écrire
dans `api/data/` et ses sous-dossiers. IONOS doit fournir PHP 8.2 ou ultérieur,
les extensions `intl` et `mbstring`, Apache avec `mod_rewrite`, les `.htaccess`
actifs et HTTPS. Pour les wishlists, consulter également les limites de requête
et de mémoire dans la documentation PHP.

Après déploiement, vérifier `https://example.com/noel/api/health`, l'accès
direct à une route Angular comme `/noel/connexion`, l'authentification et
l'envoi de mail. Vérifier aussi que l'accès HTTP direct à
`/noel/api/data/config.local.php` et aux données est refusé. Remplacer
`/noel` par le chemin configuré, ou le supprimer pour une installation à la
racine.

Le rôle `isAdmin` est récupéré via `GET /api/session` après connexion et lors
d'une ouverture directe ; le JWT reste dans son cookie HttpOnly. L'état de
session est effacé à la déconnexion ou à l'expiration. Les administrateurs
accèdent à l'écran **Administration** depuis le bandeau pour gérer comptes,
participants et interdictions. Un compte peut être créé depuis un participant
avec un identifiant proposé sans accents ni majuscules. Le mot de passe initial
généré n'est pas communiqué ; un email de réinitialisation peut être demandé
à la création ou ultérieurement. La modification d'un compte s'ouvre dans une
fenêtre modale. Les autorisations sont toujours vérifiées
par l'API. Les sessions anciennes sans rôle nécessitent une reconnexion.
Depuis le menu utilisateur des tirages, la modification de son propre compte
s'effectue également dans une fenêtre modale.
Les suppressions dans l'administration demandent confirmation dans une modale.

Les noms des attributions sont mis en gras. Les lignes où le participant
connecté donne ou reçoit sont distinguées par leur couleur et un libellé,
selon les clés normalisées fournies par l'API. Déployer l'API mise à jour avant
cette version du frontend.

Les comptes associés à un participant disposent de l'écran **Wishlist**. La
wishlist personnelle est enregistrée explicitement et conservée d'une année à
l'autre. On peut y ajouter des images locales ou des images par URL HTTPS ;
ces dernières contactent leur serveur lorsque la wishlist est affichée.
La wishlist du destinataire est accessible depuis le tirage le plus récent ; l'accès est
limité au destinataire du dernier tirage jusqu'à l'année en cours. L'écran
affiche alors uniquement cette wishlist, en lecture seule. La wishlist personnelle
permet aussi l'envoi manuel d'un lien au donneur nommé du dernier tirage. Si
son attribution change, il faut recharger la page avant d'envoyer le lien.
En l'absence de donneur, l'envoi est désactivé. Le lien nécessite une
connexion et les droits sont revérifiés par l'API au moment de la lecture.
Quitter une wishlist modifiée dans l'application demande confirmation dans une
modale ; pour un rechargement ou une fermeture d'onglet, le navigateur affiche
sa confirmation native.
Déployer et configurer l'API wishlist avant cette version du frontend ; voir
la documentation PHP pour le stockage privé et la configuration SMTP/URL.

L'éditeur utilise Quill 2.0.3. L'avis GHSA-v3m3-f69x-jf25 concerne l'export
HTML de formules et vidéos : l'application n'exporte pas de HTML et l'API
rejette ces formats ; les documents sont échangés en Delta validé.

## Habillage et accessibilité

La police Playfair Display et les icônes Material sont chargées depuis Google Fonts ; le navigateur contacte donc les serveurs de Google. Une police locale de secours reste disponible si la police distante ne charge pas. Le tableau de chaque tirage s'affiche automatiquement avec une animation et des confettis. Si le système demande une réduction des animations, le résultat s'affiche immédiatement, sans confettis.

## Vérification

```sh
npm test -- --watch=false
npm run build -- --base-href /
npm run test:pwa -- /
npm run build -- --base-href /noel/
npm run test:pwa -- /noel/
php ../src_php/tests/run.php
php ../.github/scripts/test-prepare-deploy.php
```

Les tests PHP HTTP nécessitent l'extension cURL, `proc_open`, les liens
symboliques et un port loopback disponible ; ils utilisent des données
temporaires isolées. Les builds et tests locaux ne remplacent pas les essais
suivants sur appareils et hébergement réels :

- **Déploiement :** avant mise en production, sauvegarder et tester la
  restauration des données et de la configuration privées ; vérifier HTTPS,
  les accès directs à `/connexion`, `/partage` et `/api/health`, ainsi que le
  refus d'accès HTTP à `api/data/`. Répéter pour chaque chemin public utilisé,
  à la racine ou en sous-dossier.
- **IONOS :** vérifier l'application de `.user.ini` et de
  `enable_post_data_reading=0`, la transmission de `Authorization`, l'état
  HTTPS transmis à PHP, les limites d'upload et la mémoire. Programmer et
  surveiller le nettoyage des brouillons ; vérifier expiration après sept jours
  et suppression physique. Tester la disponibilité des métadonnées ou leur
  désactivation explicite ; voir le README PHP.
- **Android :** installer depuis Chrome compatible, puis partager une URL
  présente dans le texte, une image seule et un partage mixte. Vérifier la
  redirection, la reconnexion après expiration de session, les champs,
  l'annulation, la confirmation et l'absence de doublon après une nouvelle
  tentative. Sans réseau, vérifier qu'une erreur est affichée.
- **iOS :** essayer depuis Safari et Raccourcis les partages URL et image ;
  vérifier l'en-tête Bearer et la réponse, la reconnexion éventuelle, le refus
  avec un autre compte et la confirmation avec le compte propriétaire. La PWA
  installée et Safari peuvent avoir des sessions distinctes.
- **Cache et mises à jour :** contrôler que sessions, brouillons et wishlists
  ne sont pas servis depuis un cache privé ou le service worker ; déployer une
  nouvelle version et vérifier la notification et la mise à jour sans perte
  de modifications en cours. Tester aussi le passage réseau du POST Android.
