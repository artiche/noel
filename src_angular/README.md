# Tirages de Noël

Application Angular 22 et Angular Material pour consulter les tirages de l'API PHP.

## Développement

Node.js compatible avec Angular 22 (`^22.22.3`, `^24.15.0` ou `>=26.0.0`) et PHP 8.2+ sont nécessaires. Depuis la racine du dépôt, démarrer l'API :

```sh
JWT_SECRET='un-secret-local' BASE_PATH='' php -S 127.0.0.1:8080 -t src_php/public src_php/public/index.php
```

Dans un autre terminal :

```sh
cd src_angular
npm ci
npm start
```

Ouvrir **https://localhost:4200/** et accepter l'avertissement lié au certificat de développement auto-signé. Le proxy transmet `/api/**` à l'API en retirant le préfixe `/api` : par exemple, `/api/login` devient `/login`. Le cookie de session de l'API est `Secure`, `HttpOnly` et `SameSite=Strict`. Le secret ci-dessus est uniquement destiné au développement ; ne pas l'utiliser en production.

L'API locale doit être démarrée sans préfixe `BASE_PATH`. Si elle utilisait auparavant `BASE_PATH=/api`, arrêtez-la et relancez-la avec la commande ci-dessus. Redémarrez aussi Angular après une modification du proxy. `https://localhost:4200/api/health` doit retourner un état `ok`.

## Production

L'interface et l'API doivent partager la même origine HTTPS. L'URL d'API est `api/` relative au `base href` de l'application. Par exemple, pour héberger l'application sous `/noel/` et l'API sous `/noel/api`, construire avec `npm run build -- --base-href /noel/`, servir les fichiers de `dist/noel/browser/` à cet emplacement et configurer `BASE_PATH=/noel/api` pour PHP. Le serveur doit acheminer les requêtes API vers PHP avant de rediriger les routes Angular inconnues vers `index.html`. Adapter le `base href`, le chemin de l'API et le proxy de développement si nécessaire.

La déconnexion expire le cookie du navigateur ; elle ne révoque pas un jeton copié. Voir la documentation de `../src_php/README.md` pour le fonctionnement et la configuration de l'API.

## Vérification

```sh
npm test -- --watch=false
npm run build
php ../src_php/tests/run.php
```
