# PostgreSQL Privilege Separation

## Objectif

L'utilisateur `app` est actuellement propriétaire du schéma et dispose des droits DDL (TRUNCATE, DROP TABLE, CREATE TABLE...). Si une commande destructrice est exécutée avec ce rôle (ex : `doctrine:fixtures:load`), PostgreSQL ne peut pas bloquer l'opération.

L'objectif est de séparer en deux rôles distincts :

| Rôle | Droits | Utilisé par |
|------|--------|-------------|
| `app_rw` | SELECT, INSERT, UPDATE, DELETE | Application (runtime) |
| `app_migrate` | DDL complet (propriétaire du schéma) | Migrations uniquement |

## SQL de mise en place

À exécuter une fois par environnement (staging, production) en tant que superuser PostgreSQL :

```sql
-- Créer les rôles
CREATE ROLE app_migrate WITH LOGIN PASSWORD 'strong-password-here';
CREATE ROLE app_rw WITH LOGIN PASSWORD 'strong-password-here';

-- app_migrate est propriétaire du schéma public
ALTER SCHEMA public OWNER TO app_migrate;

-- Transférer la propriété des tables existantes
DO $$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public' LOOP
    EXECUTE 'ALTER TABLE public.' || quote_ident(r.tablename) || ' OWNER TO app_migrate';
  END LOOP;
END;
$$;

-- Transférer la propriété des séquences existantes
DO $$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public' LOOP
    EXECUTE 'ALTER SEQUENCE public.' || quote_ident(r.sequence_name) || ' OWNER TO app_migrate';
  END LOOP;
END;
$$;

-- Accorder les droits DML à app_rw sur toutes les tables et séquences existantes
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_rw;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_rw;

-- Appliquer automatiquement les droits sur les futures tables/séquences créées par app_migrate
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_rw;
ALTER DEFAULT PRIVILEGES FOR ROLE app_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO app_rw;
```

## Wiring applicatif

Deux variables d'environnement sont nécessaires :

```env
# Runtime (lecture/écriture — sans DDL)
DATABASE_URL=postgresql://app_rw:password@database:5432/app?serverVersion=16&charset=utf8

# Migrations (DDL complet)
DATABASE_MIGRATE_URL=postgresql://app_migrate:password@database:5432/app?serverVersion=16&charset=utf8
```

### entrypoint Docker

Dans `frankenphp/docker-entrypoint.sh`, remplacer :

```sh
php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
```

par :

```sh
DATABASE_URL="$DATABASE_MIGRATE_URL" php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing
```

### CI

Dans `.github/workflows/ci.yml`, la step `Prepare test database` utilise déjà `DATABASE_URL` — ajouter `DATABASE_MIGRATE_URL` dans les secrets GitHub et l'utiliser pour `schema:drop` et `migrations:migrate`.

## Effet

Même si `doctrine:fixtures:load` est lancé avec le rôle `app_rw`, PostgreSQL refusera `TRUNCATE` avec :

```
ERROR: permission denied for table deck
```

C'est une deuxième ligne de défense après le `FixtureProductionGuard` Symfony.
