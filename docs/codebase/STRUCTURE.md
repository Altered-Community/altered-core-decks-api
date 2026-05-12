# Codebase Structure

**Analysis Date:** 2026-05-12

## Top-Level Layout

```
altered-core-decks-api/
├── bin/                    # Symfony console entry point (bin/console)
├── config/                 # Framework, package, and route configuration
│   ├── packages/           # Per-bundle YAML config
│   │   ├── api_platform.yaml          # Format (json only), pagination defaults, cache headers
│   │   ├── cache.yaml                 # Symfony Cache pool config
│   │   ├── doctrine.yaml              # Doctrine DBAL + ORM config, naming strategy
│   │   ├── doctrine_migrations.yaml   # Migrations config
│   │   ├── framework.yaml             # Symfony framework secret, session
│   │   ├── nelmio_cors.yaml           # CORS config (CORS_ALLOW_ORIGIN env var)
│   │   ├── security.yaml              # Firewall, authenticator, access_control
│   │   ├── twig.yaml                  # Twig bundle
│   │   ├── validator.yaml             # Validator
│   │   └── dev/
│   │       └── api_platform.yaml      # Dev overrides: enable Scalar UI, HTML docs format
│   ├── routes/             # Route loaders (api_platform, framework, security)
│   ├── bundles.php         # Registered Symfony bundles
│   ├── routes.yaml         # Top-level route import (controllers)
│   ├── services.yaml       # DI container: autowire defaults + explicit argument bindings
│   └── preload.php         # OPcache preload script
├── docs/                   # Project documentation
│   └── codebase/           # Architecture and convention documents (this folder)
├── frankenphp/             # FrankenPHP / Caddy server config
│   └── conf.d/             # Extra Caddyfile snippets and PHP ini overrides
├── migrations/             # Doctrine Migrations (SQL schema history)
├── public/                 # Web root — index.php + API Platform Scalar/Swagger assets
│   └── bundles/
│       └── apiplatform/    # API Platform web assets (Scalar UI, fonts; committed)
├── src/                    # Application source code (see detail below)
├── templates/              # Twig templates
│   ├── homepage/
│   │   └── index.html.twig # Developer landing page (Tailwind CDN)
│   └── bundles/
│       └── ApiPlatformBundle/
│           └── SwaggerUi/
│               └── index.html.twig  # Custom Scalar UI override (auto-fetches dev JWT)
├── var/                    # Cache, logs (git-ignored, runtime only)
├── vendor/                 # Composer dependencies (git-ignored)
├── .githooks/              # Git hooks (pre-commit: auto-export OpenAPI spec)
├── compose.yaml            # Docker Compose base (PostgreSQL + app)
├── compose.override.yaml   # Local dev overrides (port bindings, Xdebug)
├── compose.prod.yaml       # Production Compose overrides
├── Dockerfile              # FrankenPHP-based multi-stage image
├── Makefile                # Developer shortcuts (make up, make migrate, make openapi, …)
├── composer.json           # PHP dependencies + autoload config
├── composer.lock           # Locked dependency versions
├── symfony.lock            # Symfony recipe versions
├── docs/openapi.json       # Generated OpenAPI spec (auto-committed by pre-commit hook)
└── CLAUDE.md               # Project conventions for AI assistance
```

## src/ Directory Detail

```
src/
├── Client/
│   └── AlteredCoreClient.php        # HTTP client for the upstream card catalogue API
├── Controller/
│   ├── DevAuthController.php        # POST /api/dev/auth — issues dev JWT tokens
│   ├── FormatController.php         # GET /api/formats — static format metadata
│   └── HomepageController.php       # GET / — renders Twig landing page
├── Entity/
│   ├── Deck.php                     # API resource + ORM entity; owns all API Platform config
│   ├── DeckCard.php                 # ORM entity; card reference + quantity for a deck
│   └── User.php                     # ORM entity + UserInterface; Keycloak user mirror
├── OpenApi/
│   └── OpenApiFactory.php           # Decorator: adds Bearer scheme + dev auth endpoint to OpenAPI spec
├── Repository/
│   ├── DeckCardRepository.php       # Extends ServiceEntityRepository (no custom methods yet)
│   ├── DeckRepository.php           # Extends ServiceEntityRepository (no custom methods yet)
│   └── UserRepository.php           # Adds findByKeycloakId(string): ?User
├── Security/
│   └── KeycloakAuthenticator.php    # JWT authenticator; auto-provisions User on first login
├── Serializer/
│   ├── DeckCollectionNormalizer.php # Wraps Paginator → {data, pagination, links}
│   └── DeckNormalizer.php           # Enriches single Deck with card data from AlteredCoreClient
├── State/
│   ├── DeckCollectionProvider.php   # Custom ProviderInterface: user-scoped GET /api/decks
│   └── DeckStateProcessor.php       # Custom ProcessorInterface: full write pipeline
├── Validator/
│   └── Format/
│       ├── AbstractDeckFormatValidator.php  # Template-method base (shared rules)
│       ├── DeckFormatValidatorFactory.php   # Tagged-service registry: format → validator
│       ├── DeckFormatValidatorInterface.php # Contract: getFormat(), validate()
│       ├── NucFormatValidator.php           # NUC format rules
│       ├── SingletonFormatValidator.php     # Singleton format rules
│       └── StandardFormatValidator.php      # Standard format rules
└── Kernel.php                               # Symfony application kernel
```

## Key Directories

**`src/Entity/`**
- Purpose: Doctrine ORM entities and API Platform resource definitions
- All API Platform configuration (operations, filters, serialization groups, pagination, processors/providers) is declared via PHP attributes directly on the entity class
- Never add API Platform config to YAML files
- Key files: `Deck.php` (the primary API resource), `DeckCard.php`, `User.php`

**`src/State/`**
- Purpose: API Platform State Providers and State Processors — the layer where custom read/write orchestration lives
- Providers handle collection reads requiring custom logic (user scoping); processors handle writes requiring external calls, validation, and stats computation
- This is where business orchestration belongs — not in controllers or repositories

**`src/Validator/Format/`**
- Purpose: Game-rule validation per deck format
- Strategy pattern: each format is a separate class implementing `DeckFormatValidatorInterface`
- Template method: `AbstractDeckFormatValidator` holds all shared rules (hero, deck size, faction mono, ban checks); subclasses only implement `validateFormatRules()`, `getMinCards()`, `getMaxCards()`
- Adding a new format: create a new class extending `AbstractDeckFormatValidator`, implement the three methods — it is auto-discovered via the `app.deck_format_validator` tag

**`src/Serializer/`**
- Purpose: Post-processing API Platform's default normalization output
- `DeckNormalizer` fires on `Deck` instances when `deck:read:detail` group is present; fetches card enrichment from `AlteredCoreClient` and replaces the `deckCards` array with a richer `cards` array
- `DeckCollectionNormalizer` fires on `Paginator` instances; produces a custom pagination envelope
- Both use the `ALREADY_CALLED` context flag guard to prevent infinite delegation loops

**`src/Security/`**
- Purpose: Authentication only
- `KeycloakAuthenticator` handles all JWT validation; supports both RS256 (production, JWKS) and HS256 (dev mode with `DEV_AUTH_ENABLED=true`)

**`src/Client/`**
- Purpose: Isolate HTTP communication with the upstream `altered-core` card catalogue service
- Caches each card reference for 1 hour using Symfony Cache
- All calls to `altered-core` must go through this client — never call the upstream API directly from processors or normalizers

**`src/OpenApi/`**
- Purpose: Decorate the API Platform OpenAPI factory
- `OpenApiFactory` adds the Bearer security scheme globally and injects the `/api/dev/auth` endpoint path into the spec in dev environment only
- Registered as a decorator in `config/services.yaml`

**`src/Repository/`**
- Purpose: All database query logic — QueryBuilder and raw SQL must live here, never in controllers, providers, or processors
- Currently lightweight; `DeckRepository` and `DeckCardRepository` extend `ServiceEntityRepository` without custom methods. Query logic in `DeckCollectionProvider` and `DeckStateProcessor` should be migrated here

**`config/packages/`**
- `api_platform.yaml` — format (`json` only), pagination defaults, cache headers
- `security.yaml` — firewall rules, access control list, `KeycloakAuthenticator` registration
- `doctrine.yaml` — PostgreSQL DSN, ORM attribute mapping, prod cache pools
- `services.yaml` — explicit DI bindings for `KeycloakAuthenticator`, `DevAuthController`, `AlteredCoreClient`, `OpenApiFactory` (decorator), `DeckFormatValidatorFactory` (tagged iterator)

**`migrations/`**
- Doctrine Migrations files; each is a `Version<timestamp>.php`
- Current schema: `Version20260422162231.php` (initial tables: deck, deck_card, user), `Version20260422215237.php` (adds `is_draft` column)

**`templates/`**
- `homepage/index.html.twig` — developer landing page with Tailwind CSS CDN and API endpoint documentation
- `bundles/ApiPlatformBundle/SwaggerUi/index.html.twig` — custom Scalar UI override; in dev environment auto-fetches a JWT from `/api/dev/auth` and pre-populates the Bearer auth field

## Key Files

| File | Role |
|------|------|
| `src/Entity/Deck.php` | Central API resource; all API Platform operations, filters, serialization groups defined here via PHP attributes |
| `src/State/DeckStateProcessor.php` | Full write pipeline for POST/PATCH: user assignment, external card fetch, format validation, stats computation, persistence |
| `src/State/DeckCollectionProvider.php` | User-scoped GET /api/decks — replaces default API Platform collection provider |
| `src/Security/KeycloakAuthenticator.php` | Stateless JWT authentication; user auto-provisioning |
| `src/Client/AlteredCoreClient.php` | All HTTP calls to the upstream card catalogue; 1 h per-reference cache |
| `src/OpenApi/OpenApiFactory.php` | Bearer security scheme + dev auth endpoint in Scalar UI |
| `src/Validator/Format/AbstractDeckFormatValidator.php` | Template-method base for all format validators |
| `src/Serializer/DeckNormalizer.php` | Enriches single-deck response with card metadata from altered-core |
| `src/Serializer/DeckCollectionNormalizer.php` | Custom `{data, pagination, links}` collection envelope |
| `config/services.yaml` | DI wiring for security, client, OpenApiFactory decorator, and format validator factory |
| `config/packages/security.yaml` | Firewall, access control, authenticator registration |
| `config/packages/api_platform.yaml` | JSON-only format, pagination defaults |
| `migrations/Version20260422162231.php` | Initial schema (deck, deck_card, user tables) |
| `templates/bundles/ApiPlatformBundle/SwaggerUi/index.html.twig` | Scalar UI template override |

## Module Organization

The codebase is organized by **technical layer** within a single `App` namespace, not by feature/module. All deck-related classes span multiple directories (`Entity/`, `State/`, `Serializer/`, `Validator/Format/`, `Repository/`).

The single aggregate entity is `Deck`, owned by `User`, with `DeckCard` as a value-like child entity.

## Where to Add New Code

**New API endpoint (CRUD resource):**
- Entity with `#[ApiResource]` attributes: `src/Entity/NewResource.php`
- Repository: `src/Repository/NewResourceRepository.php`
- State provider (if custom read logic): `src/State/NewResourceProvider.php`
- State processor (if custom write logic): `src/State/NewResourceProcessor.php`
- Custom normalizer (if response shaping beyond groups): `src/Serializer/NewResourceNormalizer.php`

**New non-CRUD endpoint (batch, stats, action):**
- Plain Symfony controller with `#[Route]`: `src/Controller/NewActionController.php`
- Do NOT use a custom API Platform operation for non-CRUD actions
- Add access control in `config/packages/security.yaml` if the endpoint should be public
- If the endpoint should appear in the OpenAPI spec/Scalar UI, add it in `src/OpenApi/OpenApiFactory.php`

**New deck format:**
- Create `src/Validator/Format/NewFormatValidator.php` extending `AbstractDeckFormatValidator`
- Implement `getFormat(): string`, `getMinCards(): int`, `getMaxCards(): int`, `validateFormatRules()`
- The class is auto-tagged `app.deck_format_validator` via the `_instanceof` block in `config/services.yaml` — no other registration needed
- Add the format to `FormatController` static response (`src/Controller/FormatController.php`)

**New database query:**
- Add a method to the relevant repository in `src/Repository/`
- Do not build QueryBuilders in State classes, controllers, or normalizers

**New migration:**
- Run `bin/console doctrine:migrations:diff` to generate
- File lands in `migrations/Version<timestamp>.php`

**New Symfony service with explicit DI arguments (env vars, tagged iterators):**
- Register in `config/services.yaml` under the service's FQCN

**New serialization customization:**
- Add a normalizer to `src/Serializer/NewNormalizer.php`
- Implement `NormalizerInterface` + `NormalizerAwareInterface`, use `ALREADY_CALLED` flag to prevent recursion

## Special Directories

**`var/`:**
- Runtime-generated files (cache, logs, sessions)
- Committed: No (`.gitignore`)

**`vendor/`:**
- Composer-managed PHP dependencies
- Committed: No

**`public/bundles/`:**
- Web assets published from Symfony bundles (`bin/console assets:install`)
- Committed: Yes (API Platform Scalar/Swagger assets are checked in)

**`migrations/`:**
- Doctrine schema migration history
- Committed: Yes (always commit alongside entity changes)

## Naming Conventions

**PHP classes:** PascalCase, descriptive suffix matching layer (`Controller`, `Repository`, `Provider`, `Processor`, `Normalizer`, `Authenticator`, `Validator`, `Client`)

**Serialization groups:** `entity:context` format — e.g. `deck:read`, `deck:read:detail`, `deck:write`

**Database indexes:** named `idx_<table>_<field>` — e.g. `idx_deck_user`, `idx_deck_format`

**Cache keys:** `keycloak_jwks` (JWKS), `card_<md5(reference_locale)>` (card data)

**Route names:** `api_<resource>` for plain Symfony routes — e.g. `api_formats`, `api_dev_auth`

---

*Structure analysis: 2026-05-12*
