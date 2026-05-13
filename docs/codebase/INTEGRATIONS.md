# External Integrations

**Analysis Date:** 2026-05-12

## Databases

**PostgreSQL 16** (`postgres:16-alpine` in Docker):
- Connection: `DATABASE_URL` env var (DSN: `postgresql://user:pass@host:5432/dbname?serverVersion=16&charset=utf8`)
- ORM: Doctrine ORM 3.6 via `doctrine/doctrine-bundle`; driver `pdo_pgsql`
- Migrations: `migrations/`; naming strategy: `underscore`
- Docker volume: `database_data` (persistent)

## External APIs

**Altered Core Cards API:**
- Client: `src/Client/AlteredCoreClient.php`
- Call: `POST {ALTERED_CORE_URL}/api/cards/batch` — `{"references": [...], "locale": "fr"}`
- Config: `ALTERED_CORE_URL` (default `http://localhost:41309`)
- Cache: 1 hour per reference, key `card_{md5(ref_locale)}`

**Keycloak JWKS Endpoint:**
- Client: `src/Security/KeycloakAuthenticator.php`
- Call: `GET {KEYCLOAK_BASE_URL}/realms/{KEYCLOAK_REALM}/protocol/openid-connect/certs`
- Config: `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM`
- Cache: 1 hour, key `keycloak_jwks`

Both use `symfony/http-client`.

## Authentication

**Keycloak (OpenID Connect / JWT):**
- Stateless Bearer token auth on all `/api` routes
- Authenticator: `src/Security/KeycloakAuthenticator.php`
- Token validation: RS256 JWT via `firebase/php-jwt` v7 + Keycloak JWKS public keys
- User provisioning: JIT — `User` entity created/updated on first valid token; lookup by `keycloakId` (JWT `sub`)
- Firewall: `api` (stateless), pattern `^/api`

**Dev Auth Bypass:**
- Enabled by `DEV_AUTH_ENABLED=true`
- Accepts locally-signed HS256 JWTs (`iss=dev`) with `APP_SECRET`
- Token endpoint: `POST /api/dev/auth` (`src/Controller/DevAuthController.php`) — PUBLIC_ACCESS
- ⚠ Never enable in production (see `CONCERNS.md`)

## CORS

Config: `config/packages/nelmio_cors.yaml`
- Origins: `CORS_ALLOW_ORIGIN` regex
- Methods: GET, OPTIONS, POST, PUT, PATCH, DELETE
- Headers: `Content-Type`, `Authorization`
- Max age: 3600 s; applies to all paths (`^/`)

## Caching

| Layer | Adapter | TTL |
|-------|---------|-----|
| Doctrine query/result (prod) | APCu | per pool config |
| JWKS keys | Symfony Cache | 1 hour |
| Card data | Symfony Cache | 1 hour |
| Dev | Filesystem | — |

Redis and Memcached are commented-out options in `config/packages/cache.yaml` — not active.

## Messaging

- Mercure hub embedded in FrankenPHP/Caddy (`frankenphp/Caddyfile`); JWT auth via `MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_SUBSCRIBER_JWT_KEY`
- No active publish calls in `src/`; available for future real-time features
- No Symfony Messenger, RabbitMQ, or async queue

## Storage

- No external file/object storage
- Shared local data: `var/share/` (`APP_SHARE_DIR=var/share`), mounted as Docker volume

## Monitoring & Observability

- Error tracking: none (no Sentry, Bugsnag, etc.)
- Logging: Symfony Monolog (default config); ⚠ `error_log('JWKS keys: ...')` debug statement in `src/Security/KeycloakAuthenticator.php:106` — should be removed
- Metrics: FrankenPHP exposes `/metrics` (used by Docker `HEALTHCHECK`); no Prometheus scrape config
- APM/Tracing: none

## CI/CD

**Pipeline:** `.github/workflows/ci.yml`
- Trigger: push/PR to `main`
- Runner: `self-hosted`
- PHP matrix: `['8.4']`

Steps:
1. Install dependencies (`composer install`)
2. Prepare test DB: drop schema → migrate → load fixtures (`APP_ENV=test`, `DATABASE_URL` from `TEST_DATABASE_URL` secret)
3. `php bin/phpunit --testsuite Unit --no-coverage`
4. `php bin/phpunit --testsuite Integration --no-coverage`

**Deployment target:** Docker multi-stage image; `frankenphp_prod` stage on `debian:13-slim`.

## Environment Variables Summary

| Variable | Purpose |
|----------|---------|
| `APP_SECRET` | Symfony secret; HS256 key for dev auth |
| `DATABASE_URL` | PostgreSQL DSN |
| `KEYCLOAK_BASE_URL` | Keycloak server URL |
| `KEYCLOAK_REALM` | Keycloak realm name |
| `ALTERED_CORE_URL` | Altered Core cards API base URL |
| `CORS_ALLOW_ORIGIN` | Regex of allowed CORS origins |
| `DEV_AUTH_ENABLED` | `true` to enable dev JWT bypass (never in prod) |
| `MERCURE_PUBLISHER_JWT_KEY` | Mercure publisher auth |
| `MERCURE_SUBSCRIBER_JWT_KEY` | Mercure subscriber auth |

Secrets location: `.env.local` (gitignored) for local overrides; production secrets injected at deploy time.

---

*Integration audit: 2026-05-12*
