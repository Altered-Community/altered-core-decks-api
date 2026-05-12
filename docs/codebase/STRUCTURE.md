# Codebase Structure

**Analysis Date:** 2026-05-12

## Top-Level Layout

```
altered-core-decks-api/
├── bin/            # Symfony console entry point
├── config/         # Framework, package, and route configuration
├── docs/           # Project documentation (codebase maps, openapi.json)
├── frankenphp/     # FrankenPHP / Caddy server config
├── migrations/     # Doctrine Migrations (SQL schema history)
├── public/         # Web root — index.php + API Platform Scalar assets
├── src/            # Application source code
├── templates/      # Twig templates (homepage, admin UI, Scalar UI override)
├── var/            # Cache and logs (runtime, git-ignored)
├── vendor/         # Composer dependencies (git-ignored)
├── .githooks/      # Git hooks (pre-commit: auto-export OpenAPI spec)
├── compose.yaml / compose.override.yaml / compose.prod.yaml
├── Dockerfile      # FrankenPHP multi-stage image
├── Makefile        # Developer shortcuts (make up, make migrate, make openapi, …)
└── composer.json / symfony.lock
```

## src/ Layout

```
src/
├── Client/         # HTTP clients for external services
├── Command/        # Symfony console commands
├── Controller/     # Plain Symfony controllers (non-CRUD endpoints + admin UI)
├── DataFixtures/   # Doctrine fixtures (dev/test data)
├── Entity/         # ORM entities + API Platform resource definitions
├── OpenApi/        # API Platform OpenAPI decorator
├── Repository/     # All database query logic (DQL / raw SQL)
├── Security/       # JWT authenticator
├── Serializer/     # Custom normalizers and BGA serializer
├── Service/        # Shared services (KeycloakJwtDecoder)
├── State/          # API Platform State Providers and Processors
├── Validator/
│   └── Format/     # Game-rule validators (Strategy + Template Method)
└── Kernel.php
```

## Key Directories

| Directory | Purpose | Key files |
|-----------|---------|-----------|
| `src/Entity/` | ORM entities; **all API Platform config in PHP attributes** | `Deck.php`, `DeckCard.php`, `User.php` |
| `src/State/` | Custom read/write orchestration for API Platform operations | `DeckCollectionProvider.php`, `DeckItemProvider.php`, `DeckStateProcessor.php` |
| `src/Validator/Format/` | Per-format game-rule validation (Strategy + Template Method) | `AbstractDeckFormatValidator.php`, `*FormatValidator.php` |
| `src/Serializer/` | Post-normalization: BGA shaping, card enrichment, collection envelope | `BgaDeckSerializer.php`, `DeckNormalizer.php`, `DeckCollectionNormalizer.php` |
| `src/Security/` | Stateless JWT authentication only | `KeycloakAuthenticator.php` |
| `src/Service/` | Shared services used across layers | `KeycloakJwtDecoder.php` |
| `src/Client/` | HTTP communication with `altered-core` (1 h cache) | `AlteredCoreClient.php` |
| `src/Controller/` | Non-CRUD API endpoints, admin UI controllers | see controller list below |
| `src/Repository/` | All QueryBuilder / raw SQL — never inline elsewhere | `DeckRepository.php`, `UserRepository.php` |
| `src/Command/` | CLI commands | `PromoteAdminCommand.php` |
| `config/packages/` | Per-bundle YAML config | `api_platform.yaml`, `security.yaml`, `services.yaml` |
| `migrations/` | Doctrine schema history (always commit with entity changes) | `Version*.php` |
| `templates/admin/` | Admin UI Twig views (dashboard, BGA deck browser) | `dashboard.html.twig`, `bga/index.html.twig`, `bga/deck.html.twig` |

### Controllers quick reference

| Controller | Routes |
|-----------|--------|
| `BgaDeckController` | `GET /api/bga/decks`, `GET /api/bga/decks/{id}`, `GET /api/bga/cards/{ref}` |
| `MeController` | `GET /api/me` |
| `PublicDeckController` | `GET /api/decks/public` |
| `FormatController` | `GET /api/formats` |
| `DevAuthController` | `POST /api/dev/auth` (dev only) |
| `AdminAuthController` | `GET /admin/login`, `/admin/callback`, `/admin/logout`, `/admin/debug-token` |
| `AdminDashboardController` | `GET /admin/dashboard` |
| `AdminBgaController` | `GET /admin/bga`, `GET /admin/bga/{id}` |
| `AdminApiController` | `GET /api/admin/stats`, `GET /api/admin/decks` (ROLE_ADMIN) |
| `HomepageController` | `GET /` |

## Naming Conventions

- **PHP classes:** PascalCase with layer suffix (`Controller`, `Repository`, `Provider`, `Processor`, `Normalizer`, `Authenticator`, `Validator`, `Client`, `Command`)
- **Serialization groups:** `entity:context` — e.g. `deck:read`, `deck:read:detail`, `deck:write`
- **DB indexes:** `idx_<table>_<field>` — e.g. `idx_deck_user`
- **Cache keys:** `keycloak_jwks`, `card_<md5(reference_locale)>`

## Where to Add New Code

- **New CRUD API resource:** Entity in `src/Entity/`, repository in `src/Repository/`, state classes in `src/State/` (if custom logic), normalizer in `src/Serializer/` (if response shaping needed)
- **New non-CRUD API endpoint:** Plain controller in `src/Controller/`; add access control in `config/packages/security.yaml` if public; add to `OpenApiFactory` if it should appear in Scalar UI
- **New deck format:** `src/Validator/Format/NewFormatValidator.php` extending `AbstractDeckFormatValidator`; implement `getFormat()`, `getMinCards()`, `getMaxCards()`, `validateFormatRules()`; auto-tagged via `_instanceof` in `services.yaml`; also add to `FormatController`
- **New database query:** Add method to the relevant repository in `src/Repository/` — never build QueryBuilders in controllers, state classes, or normalizers
- **New migration:** `bin/console doctrine:migrations:diff` → file lands in `migrations/Version<timestamp>.php`
- **New service with explicit DI args:** Register under FQCN in `config/services.yaml`
- **New admin UI page:** Controller in `src/Controller/Admin*Controller.php`, template in `templates/admin/`; guard with `$request->getSession()->has('admin_user_id')`

---

*Structure analysis: 2026-05-12*
