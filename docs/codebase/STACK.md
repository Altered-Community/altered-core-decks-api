# Technology Stack

**Analysis Date:** 2026-05-12

## Languages & Runtime

| Layer | Technology | Notes |
|-------|-----------|-------|
| Language | PHP 8.4+ | All application code under `src/` |
| Templates | Twig 3.24 | HTML templates under `templates/` (UI only, not API) |
| Runtime | FrankenPHP 1 (`dunglas/frankenphp:1-php8.5`) | Embedded Caddy server, worker mode enabled |
| Package manager | Composer 2 | Lockfiles: `composer.lock`, `symfony.lock` |

## Frameworks

| Package | Version | Purpose |
|---------|---------|---------|
| `symfony/framework-bundle` | 8.0.* | Core application framework |
| `api-platform/core` | ^4.3 | REST API from entity attributes; `application/json` only |
| `doctrine/orm` | ^3.6 | Entity mapping via PHP attributes |
| `doctrine/doctrine-bundle` | ^3.2 | Doctrine Symfony integration |
| `doctrine/doctrine-migrations-bundle` | ^4.0 | Database migrations in `migrations/` |
| `symfony/security-bundle` | 8.0.* | Auth firewall |
| `nelmio/cors-bundle` | ^2.6 | CORS headers for API routes |
| `symfony/http-client` | 8.0.* | Outbound HTTP (Keycloak JWKS, Altered Core API) |
| `firebase/php-jwt` | ^7.0 | RS256/HS256 JWT decode for token validation |
| `symfony/uid` | 8.0.* | UUID generation |
| `symfony/validator` | 8.0.* | Request/entity validation |
| `twig/twig` | ^3.24 | HTML templates (homepage/UI only) |

**Dev dependencies:**

| Package | Version | Purpose |
|---------|---------|---------|
| `phpunit/phpunit` | ^13.1 | Test runner |
| `symfony/browser-kit` | 8.0.* | HTTP kernel client for integration tests |
| `symfony/css-selector` | 8.0.* | CSS selector support in browser-kit |
| `doctrine/doctrine-fixtures-bundle` | ^4.3 | Database fixtures for tests |
| `symfony/maker-bundle` | ^1.67 | Code generation |

## Containerization

- Docker multi-stage: `frankenphp_base` → `frankenphp_dev` / `frankenphp_prod`
- Compose files: `compose.yaml`, `compose.override.yaml` (dev), `compose.prod.yaml`
- PHP extensions: `pdo_pgsql`, `apcu`, `opcache`, `intl`, `zip`; `xdebug` dev only

## Caching

- Symfony Cache backed by APCu in-process (filesystem in dev)
- JWKS keys: cached 1 hour (`keycloak_jwks`)
- Card data: cached 1 hour per reference (`card_{md5(ref_locale)}`)

## Testing

- PHPUnit 13.1 installed via `require-dev`; config: `phpunit.dist.xml`
- DoctrineFixturesBundle for test database seeding
- Test suites: `Unit`, `Integration` (see `.github/workflows/ci.yml`)
- Run: `make test` or `php bin/phpunit`

## Makefile Targets

```bash
make build                # docker compose build
make up                   # docker compose up --detach
make start                # build + up
make down                 # docker compose down --remove-orphans
make sh / make bash       # shell into php container
make composer c='...'     # run composer in container
make sf c='...'           # run bin/console in container
make cc                   # symfony cache:clear
make test                 # php bin/phpunit (APP_ENV=test)
make openapi              # export OpenAPI spec → docs/openapi.json
make install-hooks        # configure .githooks/
```

## Key Environment Variables

| Variable | Default | Purpose |
|----------|---------|---------|
| `APP_ENV` | `dev` | `dev` / `prod` / `test` |
| `APP_SECRET` | required | Symfony secret; HS256 signing key in dev |
| `DATABASE_URL` | required | PostgreSQL DSN |
| `KEYCLOAK_BASE_URL` | `http://localhost:8080` | Keycloak server URL |
| `KEYCLOAK_REALM` | `altered` | Keycloak realm name |
| `ALTERED_CORE_URL` | `http://localhost:41309` | Altered Core cards API base URL |
| `CORS_ALLOW_ORIGIN` | required | Allowed origins regex |
| `DEV_AUTH_ENABLED` | `false` | Enable local HS256 JWT bypass (dev only) |
| `SERVER_NAME` | `localhost` | Caddy server binding |
| `MERCURE_PUBLISHER_JWT_KEY` | required | Mercure publisher auth |
| `MERCURE_SUBSCRIBER_JWT_KEY` | required | Mercure subscriber auth |

---

*Stack analysis: 2026-05-12*
