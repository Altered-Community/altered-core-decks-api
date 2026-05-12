# External Integrations

**Analysis Date:** 2026-05-12

## Databases

**PostgreSQL:**
- Version: 16 (Docker image `postgres:16-alpine`; default in `compose.yaml`)
- Connection: `DATABASE_URL` env var (DSN format: `postgresql://user:pass@host:5432/dbname?serverVersion=16&charset=utf8`)
- ORM/Driver: Doctrine ORM 3.6 via `doctrine/doctrine-bundle`; DBAL driver `pdo_pgsql`
- Naming strategy: `underscore` (snake_case columns)
- Mapping: PHP attributes on entities in `src/Entity/`
- Migrations: Doctrine Migrations Bundle 4.0; migration files in `migrations/`
- Docker volume: `database_data` (persistent)

## External APIs

**Altered Core Cards API:**
- Purpose: Fetches card data (name, stats, faction, type, translations) by card reference
- Endpoint called: `POST {ALTERED_CORE_URL}/api/cards/batch` with `{"references": [...], "locale": "fr"}`
- Client: `src/Client/AlteredCoreClient.php`
- Config: `ALTERED_CORE_URL` env var (default `http://localhost:41309`)
- Caching: per-reference cache for 1 hour via Symfony Cache; key format: `card_{md5(ref_locale)}`
- HTTP library: `symfony/http-client`

**Keycloak JWKS Endpoint:**
- Purpose: Fetches JSON Web Key Set to validate RS256 JWT bearer tokens
- Endpoint: `{KEYCLOAK_BASE_URL}/realms/{KEYCLOAK_REALM}/protocol/openid-connect/certs`
- Client: `src/Security/KeycloakAuthenticator.php`
- Config: `KEYCLOAK_BASE_URL` and `KEYCLOAK_REALM` env vars
- Caching: JWKS cached for 1 hour under key `keycloak_jwks`
- HTTP library: `symfony/http-client`

## Authentication / Auth Providers

**Keycloak (OpenID Connect / JWT):**
- Type: Stateless Bearer token authentication on all `/api` routes
- Mechanism: Custom Symfony authenticator at `src/Security/KeycloakAuthenticator.php`
- Token validation: RS256 JWT decoded via `firebase/php-jwt` v7 using public keys from Keycloak JWKS
- User provisioning: On first valid token, a `User` entity is created (JIT provisioning); subsequent requests update `email` and `username` from JWT claims (`email`, `preferred_username`)
- User store: `App\Entity\User` entity; looked up by `keycloakId` (JWT `sub` claim)
- Firewall: `api` firewall (stateless), pattern `^/api`

**Dev Auth Bypass (local only):**
- Enabled by `DEV_AUTH_ENABLED=true` in `.env`
- Accepts locally-signed HS256 JWTs with `iss=dev`, signed with `APP_SECRET`
- Endpoint: `POST /api/dev/auth` (handled by `src/Controller/DevAuthController.php`) — PUBLIC_ACCESS
- Intended for local development without a running Keycloak instance
- ⚠ Security risk: nothing prevents `DEV_AUTH_ENABLED=true` from being set in production (see `CONCERNS.md`)

## Storage

**File Storage:**
- No external file/object storage (no S3, no GCS)
- Symfony `var/share/` directory used for local shared data (`APP_SHARE_DIR=var/share`), mounted as a Docker volume (`/app/var/`)

**Caching Layer:**
- Default: Symfony filesystem cache adapter (dev)
- Production: APCu in-process cache for Doctrine query/result pools; see `config/packages/doctrine.yaml` (`when@prod`) and `config/packages/cache.yaml`
- Redis and Memcached are commented-out options in `config/packages/cache.yaml` — not currently active

## Messaging / Queues

**Mercure (Server-Sent Events):**
- Mercure hub is embedded in the FrankenPHP/Caddy server (module built into `dunglas/frankenphp` image)
- Config: `frankenphp/Caddyfile` — `mercure { ... }` block with JWT-based publisher/subscriber auth
- JWT keys: `MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY` env vars
- Current usage: Hub is configured and available but no active publish calls detected in `src/`; available for future real-time features

**Message Queue / Async:**
- No Symfony Messenger, RabbitMQ, Redis Streams, or any async queue currently configured

## CORS

**NelmioCorsBundle:**
- Config: `config/packages/nelmio_cors.yaml`
- Allowed origins: regex from `CORS_ALLOW_ORIGIN` env var (default: `localhost` / `127.0.0.1` any port)
- Allowed methods: GET, OPTIONS, POST, PUT, PATCH, DELETE
- Allowed headers: `Content-Type`, `Authorization`
- Max age: 3600 seconds
- Applies to all paths (`^/`)

## Webhooks & Callbacks

**Outgoing calls made by this application:**
- `POST {ALTERED_CORE_URL}/api/cards/batch` — card data fetch from `src/Client/AlteredCoreClient.php`
- `GET {KEYCLOAK_BASE_URL}/realms/{KEYCLOAK_REALM}/protocol/openid-connect/certs` — JWKS key fetch from `src/Security/KeycloakAuthenticator.php`

**Incoming webhooks:**
- None detected

## Monitoring & Observability

**Error Tracking:**
- Not detected (no Sentry, Bugsnag, Rollbar, or similar)

**Logging:**
- Symfony Monolog (standard Symfony logging stack, included transitively)
- Log format: default Symfony channel/handler setup; no custom `monolog.yaml` detected
- ⚠ Known issue: `error_log('JWKS keys: ...')` is present as a debug statement in `src/Security/KeycloakAuthenticator.php:106` — not structured, should be removed

**Metrics:**
- FrankenPHP exposes a `/metrics` endpoint (used by the Docker `HEALTHCHECK` probe at `http://localhost:2019/metrics`)
- No Prometheus scrape config or external metrics collector detected

**APM / Tracing:**
- Not detected

## CI/CD & Deployment

**Container Registry / CI:**
- No `.github/`, `.gitlab-ci.yml`, or CI pipeline files detected in the repository root
- Git hooks: configurable via `make install-hooks` (sets `core.hooksPath .githooks`)

**Deployment Target:**
- Docker (multi-stage image); production image is `frankenphp_prod` stage based on `debian:13-slim`
- Image prefix configurable via `IMAGES_PREFIX` env var

## Environment Configuration Summary

**Required env vars (minimum to run):**

| Variable | Purpose |
|----------|---------|
| `APP_SECRET` | Symfony secret (CSRF, tokens); also HS256 key in dev auth |
| `DATABASE_URL` | PostgreSQL connection DSN |
| `KEYCLOAK_BASE_URL` | Keycloak server URL |
| `KEYCLOAK_REALM` | Keycloak realm name |
| `ALTERED_CORE_URL` | Altered Core cards API base URL |
| `CORS_ALLOW_ORIGIN` | Regex of allowed CORS origins |
| `DEV_AUTH_ENABLED` | `true` to enable dev JWT bypass (never in production) |

**Secrets location:**
- `.env.local` (gitignored) for local overrides
- `.env.dev` for shared dev defaults
- Production secrets injected via environment at deploy time (not committed)

---

*Integration audit: 2026-05-12*
