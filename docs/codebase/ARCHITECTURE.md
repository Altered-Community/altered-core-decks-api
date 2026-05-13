<!-- refreshed: 2026-05-12 -->
# Architecture

**Analysis Date:** 2026-05-12

## System Overview

Altered Core Decks API is a stateless JSON REST API (Symfony 7/8 + API Platform 3 + PostgreSQL) that lets authenticated users build and manage Altered TCG decks, exposes a BGA-compatible integration surface, and provides a session-based admin panel for moderation. Authentication uses Keycloak JWTs (RS256) for the API surface and a Keycloak PKCE flow for the admin UI. Card catalogue data is never stored locally — it is fetched on demand from the sibling `altered-core` service and cached for one hour.

## System Diagram

```
HTTP Client / Browser  (Authorization: Bearer <JWT>)
         │
         ▼
FrankenPHP  ·  public/index.php → Kernel.php
         │
  Security Firewall  ·  KeycloakAuthenticator  (src/Security/)
         │
   ┌─────┴──────────────────────────────────────┐
   │                    │                        │
   ▼                    ▼                        ▼
API Platform 3     Plain Symfony            Admin UI
/api prefix        Controllers              /admin/* (session-auth)
(entity attrs)     BgaDeckController        AdminAuthController
                   MeController             AdminDashboardController
                   PublicDeckController     AdminBgaController
                   FormatController         AdminApiController
                   DevAuthController
         │
   ┌─────┴──────────────────────┐
   │                            │
   ▼                            ▼
State Providers           State Processors
DeckCollectionProvider    DeckStateProcessor
DeckItemProvider          (user assign · card fetch
                           · format validate · stats)
         │                            │
         └──────────────┬─────────────┘
                        ▼
              Repository Layer
              DeckRepository · DeckCardRepository · UserRepository
              src/Repository/
                        │
                        ▼
                   PostgreSQL
              (deck · deck_card · user)
                        │
              ┌─────────▼──────────┐
              │  AlteredCoreClient │  POST /api/cards/batch
              │  (cached 1 h)      │  ← external altered-core API
              └────────────────────┘
```

## Component Table

| Component | File | Role |
|-----------|------|------|
| `KeycloakAuthenticator` | `src/Security/KeycloakAuthenticator.php` | Validates Bearer JWT (RS256/HS256); auto-provisions `User` on first login |
| `KeycloakJwtDecoder` | `src/Service/KeycloakJwtDecoder.php` | Extracted JWT decode logic (JWKS cache, dev HS256 path); shared by authenticator and admin auth |
| `Deck` | `src/Entity/Deck.php` | Primary API resource — all API Platform config in PHP attributes |
| `DeckCard` | `src/Entity/DeckCard.php` | Join entity: card reference + quantity |
| `User` | `src/Entity/User.php` | Keycloak mirror; `isAdmin` flag drives `ROLE_ADMIN` |
| `DeckCollectionProvider` | `src/State/DeckCollectionProvider.php` | User-scoped GET /api/decks |
| `DeckItemProvider` | `src/State/DeckItemProvider.php` | Ownership/public check on GET /api/decks/{id} (fixes IDOR) |
| `DeckStateProcessor` | `src/State/DeckStateProcessor.php` | Full POST/PATCH pipeline: assign user, fetch cards, validate format, compute stats, persist |
| `AlteredCoreClient` | `src/Client/AlteredCoreClient.php` | HTTP wrapper for upstream card catalogue; 1 h per-reference cache |
| `DeckFormatValidatorFactory` | `src/Validator/Format/DeckFormatValidatorFactory.php` | Tagged-service registry: format string → validator |
| `AbstractDeckFormatValidator` | `src/Validator/Format/AbstractDeckFormatValidator.php` | Template-method base: hero, size, faction, bans |
| `NucFormatValidator` | `src/Validator/Format/NucFormatValidator.php` | NUC rules (no Unique, ≤15 R1, ≤3 R2) |
| `StandardFormatValidator` | `src/Validator/Format/StandardFormatValidator.php` | Standard rules (≤3 Unique, ≤15 R1, ≤3 R2) |
| `SingletonFormatValidator` | `src/Validator/Format/SingletonFormatValidator.php` | Singleton rules (1 per rarity, Unique keyed to hero name) |
| `SandboxFormatValidator` | `src/Validator/Format/SandboxFormatValidator.php` | Sandbox rules (no Unique, ≤15 R1, ≤3 R2, set whitelist) |
| `BgaDeckSerializer` | `src/Serializer/BgaDeckSerializer.php` | Shapes deck data for BGA collection/item endpoints |
| `DeckNormalizer` | `src/Serializer/DeckNormalizer.php` | Enriches single Deck (`deck:read:detail`) with card data from `AlteredCoreClient` |
| `DeckCollectionNormalizer` | `src/Serializer/DeckCollectionNormalizer.php` | Wraps `Paginator` into `{data, pagination, links}` |
| `BgaDeckController` | `src/Controller/BgaDeckController.php` | BGA-compatible deck collection + item + card proxy endpoints |
| `MeController` | `src/Controller/MeController.php` | GET /api/me — current user profile |
| `PublicDeckController` | `src/Controller/PublicDeckController.php` | GET /api/decks/public — paginated public decks |
| `AdminAuthController` | `src/Controller/AdminAuthController.php` | Keycloak PKCE login/callback/logout for admin UI |
| `AdminDashboardController` | `src/Controller/AdminDashboardController.php` | Admin HTML dashboard (session-gated) |
| `AdminBgaController` | `src/Controller/AdminBgaController.php` | Admin BGA deck preview (session-gated) |
| `AdminApiController` | `src/Controller/AdminApiController.php` | ROLE_ADMIN JSON stats + deck list API |
| `PromoteAdminCommand` | `src/Command/PromoteAdminCommand.php` | CLI: `app:promote-admin <email>` |
| `OpenApiFactory` | `src/OpenApi/OpenApiFactory.php` | Adds Bearer scheme + dev auth path to Scalar UI |

## API Operations Reference

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/decks` | ROLE_USER | User-scoped, paginated; `DeckCollectionProvider` |
| GET | `/api/decks/{id}` | optional* | `DeckItemProvider` — public decks accessible without auth |
| POST | `/api/decks` | ROLE_USER | `DeckStateProcessor` — full write pipeline |
| PATCH | `/api/decks/{id}` | ROLE_USER | `DeckStateProcessor` — merge-patch |
| DELETE | `/api/decks/{id}` | ROLE_USER | Default Doctrine processor |
| GET | `/api/decks/public` | PUBLIC | `PublicDeckController` — paginated public decks, filterable by hero |
| GET | `/api/me` | ROLE_USER | `MeController` — current user profile |
| GET | `/api/formats` | PUBLIC | `FormatController` — static format metadata |
| GET | `/api/bga/decks` | optional* | `BgaDeckController` — BGA collection (hydra envelope) |
| GET | `/api/bga/decks/{id}` | PUBLIC | `BgaDeckController` — BGA item |
| GET | `/api/bga/cards/{ref}` | PUBLIC | `BgaDeckController` — BGA card proxy |
| POST | `/api/dev/auth` | PUBLIC | `DevAuthController` — dev HS256 JWT (DEV_AUTH_ENABLED only) |
| GET | `/admin/login` | PUBLIC | `AdminAuthController` — PKCE redirect to Keycloak |
| GET | `/admin/callback` | PUBLIC | `AdminAuthController` — PKCE callback; sets session |
| GET | `/admin/dashboard` | session | `AdminDashboardController` — HTML |
| GET | `/admin/bga` | session | `AdminBgaController` — BGA deck list HTML |
| GET | `/admin/bga/{id}` | session | `AdminBgaController` — BGA deck detail HTML |
| GET | `/api/admin/stats` | ROLE_ADMIN | `AdminApiController` — deck counts JSON |
| GET | `/api/admin/decks` | ROLE_ADMIN | `AdminApiController` — recent anonymized decks JSON |

\* `DeckItemProvider` allows unauthenticated access to public decks; BGA collection endpoint scopes to owner when a JWT is provided but accepts anonymous requests.

## Data Model

```
User
 ├─ id: Uuid (PK)
 ├─ keycloakId: string (unique)
 ├─ email / username / locale: string|null
 ├─ isAdmin: bool (default false)
 ├─ createdAt / updatedAt
 └─ decks: Deck[] (cascade remove)

Deck
 ├─ id: Uuid (PK)
 ├─ name: string (≤150)
 ├─ description: text|null
 ├─ format: string|null  ('standard'|'nuc'|'singleton'|'sandbox')
 ├─ isPublic: bool
 ├─ isDraft: bool
 ├─ stats: json|null     ({totalCards, hero{reference,name,imagePath}, byRarity{C,R,U,E}})
 ├─ formatErrors: json|null
 ├─ createdAt / updatedAt
 ├─ user: User (ManyToOne)
 └─ deckCards: DeckCard[] (cascade persist+remove, orphanRemoval)

DeckCard
 ├─ id: int (auto-increment)
 ├─ cardReference: string  (ALT_CORE_B_AX_1_C — validated by regex)
 ├─ quantity: smallint (1–3)
 └─ deck: Deck (ManyToOne, ON DELETE CASCADE)
          unique: (deck_id, card_reference)
```

Card metadata (faction, type, costs, powers, effects, rarity, bans) is not stored — fetched on demand from `altered-core` and cached.

## Key Data Flows

**GET /api/decks/{id}**
1. `KeycloakAuthenticator` validates JWT (or allows anonymous for public decks)
2. `DeckItemProvider` fetches deck; checks `isPublic` or owner match; throws 401/403/404 as needed
3. `DeckNormalizer` detects `deck:read:detail` group → calls `AlteredCoreClient` → replaces `deckCards` with enriched `cards` array

**POST/PATCH /api/decks**
1. JWT auth; API Platform deserialises body (groups: `deck:write`); Symfony constraints validated
2. `DeckStateProcessor`: assign user (POST) or merge DeckCard rows (PATCH)
3. If not draft: batch-fetch card data via `AlteredCoreClient` → format validate via `DeckFormatValidatorFactory` → compute stats
4. Persist; return `deck:read` response

**Admin UI login**
1. `AdminAuthController::login()` → PKCE challenge → redirect to Keycloak
2. Keycloak calls `/admin/callback` → exchange code → decode JWT via `KeycloakJwtDecoder`
3. Check `user.isAdmin`; store `admin_user_id` + `admin_access_token` in session
4. Subsequent admin routes check `session.has('admin_user_id')` manually

## Key Design Decisions

- **API Platform attributes only** — all operation/filter/pagination config lives on `src/Entity/Deck.php`; no API Platform YAML.
- **`application/json` only** — no JSON-LD; collections use `member`/`totalItems`; `DeckCollectionNormalizer` overrides to `{data, pagination, links}`.
- **Card data not persisted** — only `cardReference` stored; all metadata fetched from `altered-core` on demand. Draft mode skips this call entirely.
- **Strategy + Template Method for formats** — `AbstractDeckFormatValidator` owns shared rules; concrete validators implement only their distinct rules; auto-discovered via `app.deck_format_validator` tag.
- **`DeckItemProvider` fixes IDOR** — `Get` operation now uses a custom provider that enforces ownership or `isPublic` before returning a deck.
- **`KeycloakJwtDecoder` extracted as service** — shared between `KeycloakAuthenticator` (Bearer API auth) and `AdminAuthController` (session admin auth) to avoid duplicated JWKS logic.
- **Admin UI uses session auth** — the admin panel is a separate Twig surface with Keycloak PKCE; it does not go through the Symfony security firewall (manual `session.has()` guards in each controller action).
- **PATCH merges DeckCard rows** — `DeckStateProcessor::mergeDeckCards()` diffs by `cardReference` to update quantities in-place, avoiding orphan-removal delete/insert cycles.

## Security Flow

**API requests (Bearer JWT)**
1. `KeycloakAuthenticator` checks `Authorization: Bearer` header
2. Delegates decode to `KeycloakJwtDecoder` (JWKS RS256, or HS256 when `iss === "dev"` and `DEV_AUTH_ENABLED=true`)
3. `findOrCreateUser()` syncs `email`, `preferred_username`, `locale` from claims; flushes if new
4. Returns `SelfValidatingPassport`; security token set for request lifetime

**Admin UI (session)**
1. `/admin/login` → PKCE verifier stored in session → redirect to Keycloak
2. `/admin/callback` → token exchange → `KeycloakJwtDecoder::decode()` → check `user.isAdmin()`
3. `admin_user_id` written to session; each admin controller action checks `session.has('admin_user_id')`

**Access control (`config/packages/security.yaml`)**
- `PUBLIC_ACCESS`: `/api/dev/auth`, `/api/docs`, `/api/formats`
- `ROLE_USER`: `^/api` (all other API routes)
- `ROLE_ADMIN`: `^/api/admin` (enforced via `#[IsGranted]` on `AdminApiController`)
- Admin UI routes (`/admin/*`) are outside the API firewall; protected by manual session check only

## Error Handling

- Symfony constraint violations (`#[Assert\*]`) → API Platform returns `422` with per-field details automatically
- `ValidationException` with `ConstraintViolationList` (thrown by format validators) → `422`
- `AccessDeniedHttpException` / `NotFoundHttpException` from `DeckItemProvider` → `403` / `404`
- `KeycloakAuthenticator::onAuthenticationFailure()` → `401 {"error": "..."}`
- `AlteredCoreClient` failures → caught and logged; processing continues with empty `cardsData` (stats and validation become no-ops)

---

*Architecture analysis: 2026-05-12*
