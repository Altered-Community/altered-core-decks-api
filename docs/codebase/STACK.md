# Technology Stack

**Analysis Date:** 2026-05-12

## Languages

**Primary:**
- PHP 8.4+ — all application code under `src/`

**Templates:**
- Twig 3 — HTML templates under `templates/` (homepage and UI views only)

## Runtime

**Environment:**
- FrankenPHP 1 (based on `dunglas/frankenphp:1-php8.5` Docker image) — PHP application server with embedded Caddy web server
- Worker mode enabled: requests handled by persistent PHP workers (no cold-start per request)

**Web Server:**
- Caddy (embedded in FrankenPHP) — TLS termination, HTTP/3, Mercure hub, Vulcain support
- Config: `frankenphp/Caddyfile`

**Package Manager:**
- Composer 2 (installed in Docker image via `install-php-extensions @composer`)
- Lockfile: `composer.lock` and `symfony.lock` present and committed

## Frameworks

**Core:**
- Symfony 8.0 (`symfony/framework-bundle` v8.0.8) — full application framework

**API Layer:**
- API Platform 4.3 (`api-platform/core` v4.3.3) — REST API generation from entity attributes
- Format: `application/json` only (no JSON-LD/Hydra)

**ORM:**
- Doctrine ORM 3.6 (`doctrine/orm` v3.6.3) — entity mapping via PHP attributes
- Doctrine Bundle 3.2 (`doctrine/doctrine-bundle` v3.2.2)
- Doctrine Migrations Bundle 4.0 (`doctrine/doctrine-migrations-bundle` v4.0.0)

**Templating:**
- Twig 3.24 (`twig/twig` v3.24.0) — used for `templates/` (homepage / UI only, not API responses)
- Tailwind CSS — loaded via CDN in `templates/base.html.twig` (no build pipeline, no npm)
- Tom Select — multi-select inputs, CDN-loaded

**Security:**
- Symfony Security Bundle 8.0 (`symfony/security-bundle` v8.0.8)
- Custom `KeycloakAuthenticator` at `src/Security/KeycloakAuthenticator.php`

**HTTP Client:**
- Symfony HTTP Client 8.0 (`symfony/http-client`) — used for Keycloak JWKS fetch and Altered Core API calls

**CORS:**
- NelmioCorsBundle 2.6 (`nelmio/cors-bundle` v2.6.1) — CORS headers for API routes

**UID:**
- `symfony/uid` 8.0 — UUID generation

**Validation:**
- `symfony/validator` 8.0

## Key Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| `api-platform/core` | ^4.3 (installed 4.3.3) | REST API from entity attributes |
| `doctrine/orm` | ^3.6 (installed 3.6.3) | Database ORM |
| `doctrine/doctrine-bundle` | ^3.2 (installed 3.2.2) | Doctrine Symfony integration |
| `doctrine/doctrine-migrations-bundle` | ^4.0 (installed 4.0.0) | Database migrations |
| `firebase/php-jwt` | ^7.0 (installed 7.0.5) | JWT decode for Keycloak token validation |
| `nelmio/cors-bundle` | ^2.6 (installed 2.6.1) | CORS handling |
| `symfony/asset` | 8.0.* | Asset versioning helpers (required by API Platform Twig template) |
| `symfony/http-client` | 8.0.* | Outbound HTTP calls (Keycloak JWKS, Altered Core API) |
| `symfony/security-bundle` | 8.0.* (installed 8.0.8) | Auth firewall |
| `symfony/uid` | 8.0.* | UUID support |
| `symfony/validator` | 8.0.* | Request/entity validation |
| `twig/twig` | ^3.24 (installed 3.24.0) | HTML templates |

**Dev only:**

| Package | Version | Purpose |
|---------|---------|---------|
| `symfony/maker-bundle` | ^1.67 | Code generation (`make:entity`, etc.) |

## Build & Tooling

**Containerization:**
- Docker with multi-stage build: `frankenphp_base` → `frankenphp_dev` / `frankenphp_prod` targets
- Dockerfile at `Dockerfile`; Compose files: `compose.yaml`, `compose.override.yaml` (dev), `compose.prod.yaml`

**Makefile Targets:**

```bash
make build                # docker compose build
make up                   # docker compose up --detach
make start                # build + up
make down                 # docker compose down --remove-orphans
make sh                   # shell into php container
make bash                 # bash into php container
make composer c='...'     # run composer command in container
make sf c='...'           # run bin/console command in container
make cc                   # symfony cache:clear
make test                 # run phpunit (APP_ENV=test)
make openapi              # export OpenAPI spec → docs/openapi.json
make install-hooks        # configure .githooks/ (git config core.hooksPath .githooks)
```

**PHP Extensions (installed in Docker image):**
- `pdo_pgsql` — PostgreSQL driver
- `apcu` — in-process key-value cache (used as Symfony cache adapter)
- `opcache` — bytecode cache
- `intl` — internationalization
- `zip` — archive support
- `xdebug` — dev image only

**Caching:**
- Symfony Cache component backed by APCu (filesystem in dev; pooled for Doctrine query/result cache in prod)
- JWKS from Keycloak cached for 1 hour (`KeycloakAuthenticator`)
- Altered Core card data cached per reference for 1 hour (`AlteredCoreClient`)

**Testing:**
- PHPUnit NOT currently installed. No `phpunit/phpunit` or `symfony/test-pack` in `composer.json`.
- `make test` target exists but will fail — see `TESTING.md` for setup instructions.

## Environment

**Development:**
- Docker required; `compose.override.yaml` mounts source into container with live file watching (`FRANKENPHP_WORKER_CONFIG=watch`)
- Xdebug available (`XDEBUG_MODE=develop` by default)
- Dev auth bypass available via `DEV_AUTH_ENABLED=true` + local HS256 JWT

**Production:**
- Distroless-style `debian:13-slim` final image (`frankenphp_prod` stage)
- Composer autoloader dumped with `--classmap-authoritative`
- `APP_ENV=prod` baked in
- Opcache preload enabled (configured via `20-app.prod.ini`)
- Runs as `www-data` user

**Key Environment Variables:**

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_ENV` | `dev` | `dev` / `prod` / `test` |
| `APP_SECRET` | (required) | Symfony secret key; HS256 signing key in dev |
| `DATABASE_URL` | (required) | PostgreSQL DSN |
| `KEYCLOAK_BASE_URL` | `http://localhost:8080` | Keycloak server URL |
| `KEYCLOAK_REALM` | `altered` | Keycloak realm name |
| `ALTERED_CORE_URL` | `http://localhost:41309` | Altered Core cards API base URL |
| `CORS_ALLOW_ORIGIN` | (required) | CORS allowed origins regex |
| `DEV_AUTH_ENABLED` | `false` | Enable local HS256 JWT bypass |
| `SERVER_NAME` | `localhost` | Caddy server binding |
| `MERCURE_PUBLISHER_JWT_KEY` | (required) | Mercure publisher auth |
| `MERCURE_SUBSCRIBER_JWT_KEY` | (required) | Mercure subscriber auth |

---

*Stack analysis: 2026-05-12*
