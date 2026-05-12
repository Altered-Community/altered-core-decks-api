<!-- refreshed: 2026-05-12 -->
# Architecture

**Analysis Date:** 2026-05-12

## System Overview

Altered Core Decks API is a stateless JSON REST API that allows authenticated users to create, manage, and validate trading-card decks for the Altered TCG game. It is built on Symfony 7/8 with API Platform 3, persisting data in PostgreSQL, and delegates card catalogue lookups to the external `altered-core` service (a sibling API).

## Architectural Pattern

**Layered + API Platform resource-centric.** The system uses Symfony's HTTP kernel as the outermost layer, API Platform as the resource/routing/serialization orchestrator, and splits domain logic into State Providers (reads), State Processors (writes), Normalizers (response shaping), and a Validator strategy hierarchy (format rules).

## System Diagram

```
┌──────────────────────────────────────────────────────────────────────┐
│                          HTTP Request                                │
└──────────────────────────────────────┬───────────────────────────────┘
                                       │
                         ┌─────────────▼──────────────┐
                         │  KeycloakAuthenticator      │  src/Security/
                         │  (JWT validation + user     │  KeycloakAuthenticator.php
                         │   auto-provision)           │
                         └─────────────┬──────────────┘
                                       │
              ┌────────────────────────┴─────────────────────────┐
              │                                                   │
  ┌───────────▼────────────┐                      ┌──────────────▼──────────────┐
  │  API Platform Router   │                      │  Symfony Router             │
  │  /api/decks (CRUD)     │                      │  /api/formats  GET          │
  │  Entity: Deck          │                      │  /api/dev/auth POST         │
  └───────────┬────────────┘                      │  /  GET (homepage)          │
              │                                   └─────────────────────────────┘
    ┌─────────┴──────────┐
    │                    │
┌───▼────────────┐  ┌────▼─────────────────────────────────────┐
│ DeckCollection │  │ DeckStateProcessor                        │
│ Provider       │  │  - assigns User on create                 │
│ (GET /decks)   │  │  - calls AlteredCoreClient for card data  │
│                │  │  - invokes DeckFormatValidatorFactory      │
│ Filters decks  │  │  - computes stats (hero, byRarity, total) │
│ to current user│  │  - persists via EntityManager             │
└───────┬────────┘  └────────────────┬──────────────────────────┘
        │                            │
┌───────▼────────────────────────────▼──────────────────────────┐
│                  Doctrine ORM / PostgreSQL                     │
│  Entities: Deck, DeckCard, User                               │
└───────────────────────────────────────────────────────────────┘
        │
┌───────▼───────────────────────────────────────────────────────┐
│              Custom Normalizers (response shaping)            │
│  DeckNormalizer          — enriches deck:read:detail with     │
│                            full card data from altered-core   │
│  DeckCollectionNormalizer — wraps Paginator in {data,         │
│                            pagination, links} envelope        │
└───────────────────────────────────────────────────────────────┘
        │
┌───────▼───────────────────────────────────────────────────────┐
│  External Service: AlteredCoreClient                          │
│  POST /api/cards/batch  (card catalogue, cached 1 h per ref)  │
└───────────────────────────────────────────────────────────────┘
```

## Component Responsibilities

| Component | Responsibility | File |
|-----------|----------------|------|
| `KeycloakAuthenticator` | Validates Bearer JWT (RS256 via Keycloak JWKS, or HS256 in dev mode); auto-provisions `User` on first login | `src/Security/KeycloakAuthenticator.php` |
| `Deck` entity | API resource definition (operations, filters, serialization groups, validators) | `src/Entity/Deck.php` |
| `DeckCard` entity | Join entity holding card reference + quantity; uniqueness enforced at DB level | `src/Entity/DeckCard.php` |
| `User` entity | Local mirror of Keycloak principal; owns decks | `src/Entity/User.php` |
| `DeckCollectionProvider` | Intercepts GET /api/decks; scopes results to authenticated user via QueryBuilder | `src/State/DeckCollectionProvider.php` |
| `DeckStateProcessor` | Handles POST/PATCH: assigns user, fetches card data, validates format, computes stats, persists | `src/State/DeckStateProcessor.php` |
| `AlteredCoreClient` | HTTP client wrapper for the upstream card catalogue API; caches per-reference for 1 h | `src/Client/AlteredCoreClient.php` |
| `DeckFormatValidatorFactory` | Tagged-service registry: maps format string → `DeckFormatValidatorInterface` | `src/Validator/Format/DeckFormatValidatorFactory.php` |
| `AbstractDeckFormatValidator` | Template-method base: hero check, deck size, faction mono, banned/suspended, delegates format-specific rules | `src/Validator/Format/AbstractDeckFormatValidator.php` |
| `NucFormatValidator` | NUC rules (no Unique cards, ≤15 R1, ≤3 R2) | `src/Validator/Format/NucFormatValidator.php` |
| `StandardFormatValidator` | Standard rules (≤3 Unique, ≤15 R1, ≤3 R2) | `src/Validator/Format/StandardFormatValidator.php` |
| `SingletonFormatValidator` | Singleton rules (1 per rarity, Unique limit keyed to hero name) | `src/Validator/Format/SingletonFormatValidator.php` |
| `DeckNormalizer` | Post-normalizes a single `Deck` on `deck:read:detail`: replaces `deckCards` with enriched `cards` array sourced from `AlteredCoreClient` | `src/Serializer/DeckNormalizer.php` |
| `DeckCollectionNormalizer` | Post-normalizes API Platform `Paginator` into `{data, pagination, links}` shape | `src/Serializer/DeckCollectionNormalizer.php` |
| `FormatController` | Plain Symfony controller; returns static format metadata at `GET /api/formats` | `src/Controller/FormatController.php` |
| `DevAuthController` | Issues HS256 JWT tokens for development; guarded by `DEV_AUTH_ENABLED` env flag | `src/Controller/DevAuthController.php` |
| `HomepageController` | Renders Twig landing page at `/` | `src/Controller/HomepageController.php` |

## Key Layers

**Security Layer:**
- Purpose: Authenticate every `/api/*` request (except `/api/dev/auth`, `/api/docs`, `/api/formats`)
- Location: `src/Security/`
- Pattern: Stateless; validates JWT, finds or creates the `User` record, sets the security token
- Depends on: `firebase/php-jwt`, Symfony Cache (JWKS TTL 1 h), `UserRepository`

**API Platform Resource Layer:**
- Purpose: Routing, deserialization, constraint validation, serialization for CRUD operations
- Location: `src/Entity/Deck.php` (attributes drive all API Platform config — no YAML)
- Operations: `GetCollection` (custom provider), `Get`, `Post` (custom processor), `Patch` (custom processor), `Delete`
- Serialization groups: `deck:read` (collection + write response), `deck:read:detail` (single GET, includes `deckCards`), `deck:write` (input)

**State Layer:**
- Purpose: Custom read/write logic beyond standard CRUD
- Location: `src/State/`
- `DeckCollectionProvider` — user-scoped collection query
- `DeckStateProcessor` — owns the full write pipeline: user assignment, external card fetch, format validation, stats computation, persistence

**Domain / Validation Layer:**
- Purpose: Encapsulate game-rule validation per format
- Location: `src/Validator/Format/`
- Pattern: Strategy + Template Method — `AbstractDeckFormatValidator` owns shared rules; concrete subclasses implement `validateFormatRules()`, `getMinCards()`, `getMaxCards()`
- Service wiring: all `DeckFormatValidatorInterface` implementations are auto-tagged `app.deck_format_validator` and injected as an iterable into `DeckFormatValidatorFactory`

**Serialization Layer:**
- Purpose: Response envelope shaping and card-data enrichment beyond what serialization groups alone provide
- Location: `src/Serializer/`
- Both normalizers use the `ALREADY_CALLED` guard pattern to prevent infinite loops with the delegating normalizer chain

**Client Layer:**
- Purpose: Isolate all HTTP communication with the upstream `altered-core` card catalogue
- Location: `src/Client/AlteredCoreClient.php`
- Caches each card reference result for 1 hour; uses batch POST endpoint to minimise HTTP round-trips

**Infrastructure Layer:**
- Purpose: Repositories, migrations, config
- Location: `src/Repository/`, `migrations/`, `config/`
- Repositories currently delegate to `ServiceEntityRepository`; `UserRepository` adds `findByKeycloakId()`

**Presentation Layer (non-API):**
- Purpose: Static developer-facing homepage
- Location: `src/Controller/HomepageController.php`, `templates/homepage/index.html.twig`
- Twig + Tailwind CSS CDN; not part of the JSON API surface

## Data Model

```
User
 ├─ id: Uuid (PK, auto-generated)
 ├─ keycloakId: string (unique — Keycloak sub claim)
 ├─ email: string|null
 ├─ username: string|null
 ├─ createdAt / updatedAt
 └─ decks: Deck[] (OneToMany, cascade remove)

Deck
 ├─ id: Uuid (PK, auto-generated)
 ├─ name: string (≤150, required)
 ├─ description: string|null
 ├─ format: string|null   ('standard' | 'nuc' | 'singleton')
 ├─ isPublic: bool
 ├─ isDraft: bool
 ├─ stats: json|null      ({totalCards, hero{reference,name,imagePath}, byRarity{C,R,U,E}})
 ├─ createdAt / updatedAt
 ├─ user: User (ManyToOne, FK indexed)
 └─ deckCards: DeckCard[] (OneToMany, cascade persist+remove, orphanRemoval)

DeckCard
 ├─ id: int (auto-increment)
 ├─ cardReference: string  (e.g. ALT_CORE_B_AX_1_C — validated by regex)
 ├─ quantity: smallint (1–3)
 └─ deck: Deck (ManyToOne, FK, ON DELETE CASCADE)
          unique constraint: (deck_id, card_reference)
```

Card data (faction, type, name, costs, powers, effects, rarity, isBanned, isSuspended) is **not stored locally** — it is fetched on demand from the `altered-core` service and cached.

## Data Flow

### Read: GET /api/decks

1. `KeycloakAuthenticator` validates JWT → finds/creates `User` (`src/Security/KeycloakAuthenticator.php`)
2. API Platform routes to `DeckCollectionProvider::provide()` (`src/State/DeckCollectionProvider.php`)
3. QueryBuilder filters by `deck.user = :currentUser`
4. Results returned as `Paginator`
5. `DeckCollectionNormalizer::normalize()` wraps in `{data, pagination, links}` (`src/Serializer/DeckCollectionNormalizer.php`)
6. Each `Deck` item normalised by `DeckNormalizer` — no enrichment because `deckCards` is absent in `deck:read` group
7. JSON response

### Write: POST/PATCH /api/decks

1. `KeycloakAuthenticator` validates JWT
2. API Platform deserialises body into `Deck` entity (groups: `deck:write`); Symfony validator runs `@Assert` constraints
3. `DeckStateProcessor::process()` invoked (`src/State/DeckStateProcessor.php`):
   a. POST: assigns `$data->setUser(...)` from security context
   b. PATCH: calls `mergeDeckCards()` to diff incoming vs. existing `DeckCard` rows
   c. If not draft: `AlteredCoreClient::getCardsByReferences()` → batch card fetch + 1 h cache (`src/Client/AlteredCoreClient.php`)
   d. If not draft: `DeckFormatValidatorFactory::getValidator($format)->validate($deck, $cardsData)` → throws `ValidationException` on rule violations (`src/Validator/Format/`)
   e. If not draft: `computeStats()` populates `deck.stats`
   f. `$em->persist($deck); $em->flush()`
4. Serialiser normalises the saved `Deck` (groups: `deck:read`); no card enrichment on write response
5. 201/200 JSON response

### Read: GET /api/decks/{id}

1. Auth as above
2. API Platform fetches `Deck` by UUID (standard Doctrine provider)
3. Normalisation uses `deck:read` + `deck:read:detail` groups — `deckCards` is included
4. `DeckNormalizer::normalize()` detects non-empty `deckCards` → calls `AlteredCoreClient` for enrichment → replaces `deckCards` array with enriched `cards` array including name, faction, costs, powers, effects (`src/Serializer/DeckNormalizer.php`)
5. JSON response

## Key Design Decisions

**API Platform attributes only, no YAML.**
All operation config (operations, filters, pagination, serialization groups) lives in PHP attributes on `src/Entity/Deck.php`. This keeps the resource contract co-located with the entity and avoids split configuration.

**`application/json` format only — no JSON-LD.**
`config/packages/api_platform.yaml` declares only the `json` format. This means collection responses use `member` / `totalItems` (not `hydra:member`). The `DeckCollectionNormalizer` overrides the default envelope to use `{data, pagination, links}` instead.

**Card data is not persisted locally.**
Only `cardReference` (the string key) is stored. All card metadata (name, faction, effects, rarity, bans) is fetched from `altered-core` on demand. This avoids a full card synchronisation job but makes write operations dependent on the upstream service.

**Draft mode bypasses validation and stats.**
When `isDraft = true`, `DeckStateProcessor` skips the `AlteredCoreClient` call, format validation, and stats computation. Stats are stored as `null`. This allows partial/in-progress decks without triggering external HTTP calls.

**Format validation uses Strategy + Template Method.**
`AbstractDeckFormatValidator` codifies the shared rules (hero, size, faction, bans). Each concrete validator (`NucFormatValidator`, `StandardFormatValidator`, `SingletonFormatValidator`) implements only its distinct rules. New formats require a new class tagged `app.deck_format_validator` — the factory picks it up automatically via tagged iterator.

**Keycloak JWT + auto-provisioning.**
`KeycloakAuthenticator` verifies RS256 tokens against the Keycloak JWKS endpoint (cached 1 h). A `User` row is created automatically on the first authenticated request. In development, a `DEV_AUTH_ENABLED` flag enables HS256 tokens issued by `DevAuthController` — no Keycloak instance required.

**User-scoped collection via custom Provider.**
`DeckCollectionProvider` replaces the default API Platform collection provider to always filter by the authenticated user. This is simpler and more explicit than a Doctrine extension filter.

**PATCH merges `DeckCard` rows rather than replacing.**
`DeckStateProcessor::mergeDeckCards()` diffs incoming vs. database cards by `cardReference`: updates quantities in-place, removes orphans, and inserts new entries. This preserves auto-increment IDs and avoids an orphan-removal delete-then-insert cycle.

## Anti-Patterns

### Building queries outside repositories

**What happens:** `DeckCollectionProvider` and `DeckStateProcessor` both call `$this->em->getRepository(Deck::class)->createQueryBuilder()` and `$this->em->getRepository(DeckCard::class)->findBy()` directly in service/state classes.
**Why it's wrong:** It violates the convention that all DQL/SQL lives in Repository classes, making queries harder to find and test.
**Do this instead:** Move the QueryBuilder logic into `DeckRepository` and `DeckCardRepository`; call named repository methods from State classes (pattern shown in `UserRepository::findByKeycloakId()`).

### Inline card-data caching logic in client

**What happens:** `AlteredCoreClient::getCardsByReferences()` contains a two-phase cache check (check → null sentinel → batch fetch → re-cache) using a `null` sentinel value to detect misses.
**Why it's wrong:** The null sentinel approach (cache a `null` then re-fetch) is fragile; a genuinely missing card and a cache miss are indistinguishable.
**Do this instead:** Use a dedicated cache key prefix with an explicit "not found" value, or use `CacheInterface::get()` with the miss callback directly populating real data from the batch response.

## Error Handling

**Strategy:** API Platform's exception listener converts exceptions to JSON error responses. `DeckStateProcessor` throws `ValidationException` (API Platform) when format rules fail — this produces a `422 Unprocessable Entity` with per-field violation messages. `KeycloakAuthenticator::onAuthenticationFailure()` returns a `401 Unauthorized` JSON response directly.

**Patterns:**
- Symfony constraint violations (`@Assert`) → API Platform automatically returns `422` with violation details
- `ValidationException` with a `ConstraintViolationList` → `422` with message per violation
- `AuthenticationException` in `KeycloakAuthenticator` → `401 {"error": "..."}`
- `AlteredCoreClient` exceptions are caught and logged; processing continues with an empty `cardsData` array (graceful degradation — stats and format validation become no-ops)

## Cross-Cutting Concerns

**Caching:** Symfony Cache (`CacheInterface`) — Keycloak JWKS cached 1 h (`keycloak_jwks`), card data cached 1 h per reference (`card_<md5(ref_locale)>`).
**Authentication:** Stateless Bearer JWT on all `/api/*` routes except `/api/dev/auth`, `/api/docs`, `/api/formats`.
**CORS:** `nelmio/cors-bundle` configured in `config/packages/nelmio_cors.yaml`.
**Logging:** PSR-3 `LoggerInterface` injected into `DeckStateProcessor`; used to log `AlteredCoreClient` failures.
**Validation:** Two layers — Symfony constraint annotations on entities (structural: not-blank, length, regex, range) and `DeckFormatValidatorFactory` game-rule validation in the processor (semantic: hero, deck size, faction, rarity limits, bans).

---

*Architecture analysis: 2026-05-12*
