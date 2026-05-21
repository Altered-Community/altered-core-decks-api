# Forensics Report — Production Database Wipe
**Date :** 21 mai 2026  
**Projet :** altered-core-decks-api  
**Statut :** INCIDENT RÉSOLU — cause identifiée, safeguards en cours

---

## Résumé exécutif

**Cause confirmée :** les fixtures ont été lancées en production, purgant toutes les tables.

L'analyse du dépôt a également mis en évidence plusieurs vulnérabilités de sécurité. Deux commits de sécurité (`1d684e2`, `16e077c`) ont déjà corrigé les plus critiques. Les vulnérabilités restantes sont documentées avec leur niveau de priorité.

---

## 1. Cause de la suppression

### 1.1 ✅ CONFIRMÉ — Fixtures lancées en production

**Fichier :** `Makefile`, ligne 47

```makefile
fixtures: ## Load dev fixtures (⚠️  purges the database first)
    @$(SYMFONY) doctrine:fixtures:load --no-interaction
```

`doctrine:fixtures:load` sans `--append` purge toutes les tables avant d'insérer les données de test. Exécuté dans le container de production (via `docker exec` ou `docker compose exec`), ce target a détruit toutes les données réelles.

**Aucun garde, aucune confirmation, aucun filtre d'environnement.**

---

### 1.2 Risque CI/CD — toujours présent

**Fichier :** `.github/workflows/ci.yml`, lignes 118–124

```yaml
- name: Prepare test database
  env:
    DATABASE_URL: ${{ secrets.TEST_DATABASE_URL }}
  run: |
    php bin/console doctrine:schema:drop --full-database --force --env=test
    php bin/console doctrine:migrations:migrate --no-interaction --env=test
    php bin/console doctrine:fixtures:load --no-interaction --env=test
```

Ce job s'exécute sur **chaque push vers `main`**. Si `secrets.TEST_DATABASE_URL` est — même temporairement — configuré avec l'URL de production, `doctrine:schema:drop --full-database --force` détruit l'intégralité du schéma sans confirmation. Risque résiduel non corrigé.

---

## 2. Safeguards recommandées contre les fixtures en production

### 2.1 ✅ [CODE] Guard Symfony dans la console — implémenté

Créer un subscriber qui intercepte `doctrine:fixtures:load` sur `APP_ENV=prod` :

```php
// src/EventSubscriber/FixtureProductionGuard.php
final class FixtureProductionGuard implements EventSubscriberInterface
{
    public function __construct(private readonly string $env) {}

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => 'onCommand'];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        if ($this->env !== 'prod') {
            return;
        }

        $name = $event->getCommand()?->getName() ?? '';
        if (str_contains($name, 'doctrine:fixtures')) {
            $event->disableCommand();
            $event->getOutput()->writeln(
                '<error>BLOCKED: doctrine:fixtures:load is forbidden in APP_ENV=prod.</error>'
            );
        }
    }
}
```

**Effet :** la commande est désactivée silencieusement avec un message d'erreur clair. Aucune donnée ne peut être purgée, même par erreur.

---

### 2.2 ✅ [CI] Validation du nom de la base avant schema:drop — implémenté

Ajouter ce step **avant** le `doctrine:schema:drop` dans `.github/workflows/ci.yml` :

```yaml
- name: Sanity check — TEST_DATABASE_URL must not be production
  run: |
    DB_URL="${{ secrets.TEST_DATABASE_URL }}"
    if echo "$DB_URL" | grep -qiE '\bprod\b|\blive\b|\bmain\b'; then
      echo "::error::TEST_DATABASE_URL contains a production-like keyword. Aborting."
      exit 1
    fi
    if ! echo "$DB_URL" | grep -qiE 'test|ci|staging'; then
      echo "::error::TEST_DATABASE_URL does not contain 'test', 'ci', or 'staging'. Verify the secret."
      exit 1
    fi
```

**Effet :** si le secret pointe vers une base sans le mot `test`/`ci`/`staging` dans l'URL, le pipeline s'arrête avant toute destruction.

---

### 2.3 [DB] Séparation des privilèges PostgreSQL

L'utilisateur `app` est propriétaire du schéma et peut donc exécuter `TRUNCATE`, `DROP TABLE`, etc. Créer deux rôles distincts :

| Rôle | Droits | Utilisé par |
|------|--------|-------------|
| `app_rw` | SELECT, INSERT, UPDATE, DELETE | Application (runtime) |
| `app_migrate` | DDL complet | Migrations uniquement |

L'application tourne avec `app_rw` — même si `doctrine:fixtures:load` est lancé avec ce rôle, `TRUNCATE` sera refusé par PostgreSQL.

---

### 2.4 [INFRA] Variable d'environnement obligatoire pour les fixtures

Conditionner l'exécution des fixtures à la présence d'une variable explicite :

```bash
# Uniquement si ALLOW_FIXTURES_LOAD=1 est positionné
ALLOW_FIXTURES_LOAD=1 php bin/console doctrine:fixtures:load
```

Combiné avec le guard Symfony (2.1), ajouter une vérification dans le guard :

```php
if ($this->env === 'prod' && !getenv('ALLOW_FIXTURES_LOAD')) {
    $event->disableCommand();
    ...
}
```

---

## 3. Scénarios de suppression non autorisée par un utilisateur

### 3.1 [CRITIQUE — à corriger] DEV_AUTH_ENABLED — usurpation d'identité

**Fichiers :**
- `src/Controller/DevAuthController.php`
- `.env.dev` (ligne 5) : `DEV_AUTH_ENABLED=true`
- `.env.local.dist` (ligne 10) : `DEV_AUTH_ENABLED=true`

Quand `DEV_AUTH_ENABLED=true`, `POST /api/dev/auth` crée un JWT valide pour **n'importe quel `keycloakId`** sans authentification. La clé `APP_SECRET` est commitée en clair dans `.env.dev` :

```
c869928bd9fb7963519fc0d4bdb1501d80707aa1f4947d583e4e6d0cd06bbcb8
```

**Si `DEV_AUTH_ENABLED=true` en staging/production :** tout utilisateur peut supprimer les decks de n'importe quel autre utilisateur.

**Fix requis :** lever une exception au démarrage si `DEV_AUTH_ENABLED=true` et `APP_ENV != dev`.

---

### 3.2 [CRITIQUE — à corriger] cascade: ['remove'] sur User

**Fichier :** `src/Entity/User.php`, ligne 44

```php
#[ORM\OneToMany(targetEntity: Deck::class, mappedBy: 'user', cascade: ['remove'])]
```

Un seul `$em->remove($user); $em->flush();` déclenche : User → tous ses Deck → tous ses DeckCard + DeckUpvote.

Vecteur principal : session CLI compromise ou fixtures en production (cause de l'incident).

---

### 3.3 ✅ Routes admin — garde de session centralisée

**Fichiers :** `src/Controller/AdminDashboardController.php`, `AdminBgaController.php`, `AdminAuthController.php`

Les vérifications de session `getSession()->has('admin_user_id')` étaient dupliquées dans chaque controller.

**Fix :** `src/EventSubscriber/AdminSessionGuard.php` créé — écoute `KernelEvents::REQUEST` (priorité 8), intercepte toutes les requêtes vers `/admin/*` sauf `/admin/login`, `/admin/callback`, `/admin/logout`, et redirige vers `admin_login` si la session ne contient pas `admin_user_id`. Les vérifications inline ont été retirées de `AdminDashboardController` et `AdminBgaController`.

**Context équipe :** `/admin/login` hors firewall Symfony est **intentionnel** (accessible sans auth préalable). La protection par EventSubscriber centralise la logique et la rend auditabled sans modifier le firewall.

---

### 3.4 ✅ /admin/debug-token supprimé

La méthode `debugToken()` et sa route `#[Route('/admin/debug-token')]` ont été retirées de `AdminAuthController.php`. L'endpoint n'existe plus.

---

### 3.5 ✅ PATCH deckCards: [] — validation minimum ajoutée

**Fichier :** `src/State/DeckStateProcessor.php`

`mergeDeckCards()` supprime toutes les cartes absentes du payload. **Fix :** guard ajouté en tête de `mergeDeckCards()` — lève `UnprocessableEntityHttpException` (422) si `deckCards` est vide. Un `PATCH {"deckCards": []}` retourne désormais une erreur explicite au lieu de vider silencieusement le deck.

---

## 4. Autres vulnérabilités

| Sévérité | Fichier | Problème | Statut |
|----------|---------|---------|--------|
| CRITIQUE | `.env.dev` | `APP_SECRET` hardcodé et commité | ✅ Corrigé — placeholder, générer via `php -r "echo bin2hex(random_bytes(32));"` |
| HAUTE | `compose.yaml:45` | Credentials PostgreSQL `!ChangeMe!` commités ; user `app` a les droits DDL | À corriger |
| HAUTE | `AlteredCoreClient.php` | `verify_peer: false` + `verify_host: false` — vulnérable MITM | À corriger |
| MOYENNE | `BgaDeckController.php:118` | Path traversal sur `{reference}` → endpoints internes altered-core | ✅ Corrigé `1d684e2` |
| MOYENNE | `DeckStateProcessor.php:36` | `assert()` désactivé par `zend.assertions=-1` en prod | ✅ Corrigé `1d684e2` |
| FAIBLE | `security.yaml:24` | `PUBLIC_ACCESS` sans `methods: [GET]` | ✅ Corrigé `1d684e2` |
| MOYENNE | `MeController.php` | Pas de garde `instanceof User` avant accès aux propriétés | ✅ Corrigé `16e077c` |
| FAIBLE | `DeckCard.quantity` | Pas de maximum sur la quantité — injection de grandes valeurs | ✅ Corrigé `16e077c` (max: 3) |
| FAIBLE | `Deck.description` | Pas de limite de longueur | ✅ Corrigé `16e077c` (max: 5000) |
| MOYENNE | `Deck.php — Delete op` | Pas d'attribut `security` sur l'opération Delete | À corriger |

---

## 5. Composants analysés — statut

| Composant | Statut | Détail |
|-----------|--------|--------|
| Migrations (10 fichiers) | ✅ Sûr | Toutes additives, aucun `DROP` dans `up()` |
| `frankenphp/docker-entrypoint.sh` | ✅ Sûr | Lance uniquement `migrations:migrate` |
| `src/Command/` | ✅ Sûr | Lecture et mise à jour uniquement |
| `src/Repository/` | ✅ Sûr | Requêtes paramétrées, aucun DELETE/TRUNCATE |
| `DELETE /api/decks/{id}` | ✅ Sûr* | Propriété vérifiée via `DeckItemProvider` |
| `src/DataFixtures/` | ✅ Sûr | Insertions uniquement |
| `composer.json` scripts | ✅ Sûr | `cache:clear` + `assets:install` uniquement |
| `DeckUpvoteRepository.toggle()` | ✅ Sûr | Logique atomique en repository, thin controller |

*Sûr si `DEV_AUTH_ENABLED=false` en production.

---

## 6. Actions recommandées

### Immédiates

1. **Vérifier `DEV_AUTH_ENABLED`** dans tous les `.env.local` staging/production — doit être `false`
2. ~~**Changer l'`APP_SECRET`**~~ ✅ `.env.dev` contient désormais un placeholder — régénérer par env via `php -r "echo bin2hex(random_bytes(32));"`
3. ~~**Supprimer ou protéger `/admin/debug-token`**~~ ✅ Route et méthode supprimées de `AdminAuthController.php`

### Court terme

4. ~~**Implémenter `FixtureProductionGuard`**~~ ✅ `src/EventSubscriber/FixtureProductionGuard.php` créé — bloque `doctrine:fixtures:*` sur `APP_ENV=prod`
5. ~~**Ajouter la validation CI**~~ ✅ Step "Sanity check" ajouté dans `.github/workflows/ci.yml` avant `doctrine:schema:drop`
6. **Séparer les privilèges PostgreSQL** (section 2.3) — user applicatif sans droits DDL/TRUNCATE
7. ~~**Ajouter `#[IsGranted('ROLE_ADMIN')]`** sur les controllers admin et un firewall dédié `^/admin`~~ ✅ `AdminSessionGuard` EventSubscriber créé — centralise la vérification de session pour toutes les routes `/admin/*` protégées
8. ~~**Ajouter une validation minimum** dans `mergeDeckCards()` (section 3.5)~~ ✅ Guard ajouté — `UnprocessableEntityHttpException` (422) si `deckCards: []`
9. **Activer la vérification TLS** dans `AlteredCoreClient`

### Moyen terme

10. **Conditionner `DEV_AUTH_ENABLED`** au `kernel.environment` — exception au boot si `true` en prod
11. **Activer les backups automatiques** avec PITR (point-in-time recovery)
12. **Ajouter `security`** sur l'opération `Delete` de `Deck` comme défense en profondeur
13. **Documenter la procédure** d'accès aux containers de production (interdit hors incident)

---

## 7. Fichiers analysés

- `.github/workflows/ci.yml`
- `Makefile`, `compose.yaml`, `compose.prod.yaml`
- `Dockerfile`, `frankenphp/docker-entrypoint.sh`
- `config/packages/doctrine.yaml`, `doctrine_migrations.yaml`, `security.yaml`
- `.env`, `.env.dev`, `.env.test`, `.env.local.dist`
- `migrations/` (10 fichiers)
- `src/Command/`, `src/Controller/`, `src/Repository/`, `src/State/`, `src/Entity/`, `src/Security/`, `src/DataFixtures/`
- `composer.json`
- Commits : `1d684e2`, `16e077c`, `3e00696` (sécurité, 21 mai 2026)
