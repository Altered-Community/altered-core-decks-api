# Codebase Concerns

**Analysis Date:** 2026-05-12

---

## Security Vulnerabilities

### IDOR on GET /api/decks/{id} (Critical)

**What happens:** `GET /api/decks/{id}` uses the default API Platform `Get` operation with no custom provider. There is no ownership check. Any authenticated user who knows or guesses a UUID can fetch any deck, including private (`isPublic: false`) and draft decks owned by other users.

- Files: `src/Entity/Deck.php` (lines 35–38), `src/State/DeckCollectionProvider.php`
- Impact: Full data exposure — deck name, description, all cards (`deck:read:detail` group) for every deck in the system.
- Fix approach: Add a custom `GetDeckProvider` (or extend `DeckCollectionProvider`) that gates single-item GET behind an ownership check: if the deck's `user !== $currentUser` and `isPublic === false`, throw `AccessDeniedException`.

### IDOR on PATCH /api/decks/{id} (Critical)

**What happens:** `PATCH /api/decks/{id}` uses `DeckStateProcessor`. The processor sets the owner only when `$isNew === true`. For existing decks it only calls `setUpdatedAt()` and `mergeDeckCards()` — it never verifies that the deck belongs to the authenticated user. Any authenticated user can mutate any deck they can identify.

- Files: `src/State/DeckStateProcessor.php` (lines 34–43)
- Impact: Any authenticated user can rename, redescribe, or replace cards in another user's deck.
- Fix approach: At the top of the `process()` method's `else` branch, add: `if ($data->getUser()->getId() !== $this->security->getUser()->getId()) { throw new AccessDeniedException(); }`

### IDOR on DELETE /api/decks/{id} (Critical)

**What happens:** `Delete` operation (`src/Entity/Deck.php`) uses API Platform's default processor with no custom provider or voter. No ownership check exists anywhere in the delete path.

- Files: `src/Entity/Deck.php`
- Impact: Any authenticated user can delete any deck in the system.
- Fix approach: Add a Symfony `Voter` for the `Deck` resource that checks `deck.user === current_user` for all mutating operations, or add a `security` expression directly on the `Delete`, `Patch`, and `Get` operations: `security: "object.getUser() == user"`.

### No ownership Voter or security expression on any Deck operation (Critical)

**What happens:** No Symfony `Voter` class exists for the `Deck` resource. API Platform `security` expressions are not set on any operation in `src/Entity/Deck.php`. The project has no mechanism to enforce that mutating operations (Patch, Delete, Get of private decks) are restricted to the deck's owner.

- Files: `src/Entity/Deck.php`, `src/` (no Voter directory exists)
- Blocks: Safe public deck sharing — until ownership is enforced, you cannot safely expose public decks to unauthenticated users.
- Fix approach: Implement `src/Security/Voter/DeckVoter.php` with `DECK_VIEW` / `DECK_EDIT` / `DECK_DELETE` attributes, and add `security: "is_granted('DECK_EDIT', object)"` on `Patch` and `Delete` operations.

### `error_log()` debug statement left in production authenticator (High)

**What happens:** `src/Security/KeycloakAuthenticator.php` line 106 contains `error_log('JWKS keys: ' . json_encode(array_keys($jwks['keys'] ?? [])));`. This writes to PHP's error log on every authenticated API request in all environments.

- Files: `src/Security/KeycloakAuthenticator.php` (line 106)
- Impact: Log noise; JWKS key IDs (kid values) are emitted on every request.
- Fix approach: Remove the `error_log()` call entirely, or replace with `$this->logger->debug(...)` (inject `LoggerInterface` into the authenticator).

### Dev auth bypass uses `APP_SECRET` as signing key (High)

**What happens:** `DevAuthController` and `KeycloakAuthenticator` both use `APP_SECRET` (the Symfony framework secret) as the HS256 signing key for dev JWT tokens. If `DEV_AUTH_ENABLED=true` is accidentally set in production, any party who knows `APP_SECRET` can forge a token for any `sub` value and gain full authenticated access as any user.

- Files: `src/Controller/DevAuthController.php` (line 43), `src/Security/KeycloakAuthenticator.php` (lines 97–102)
- Impact: Full authentication bypass if `DEV_AUTH_ENABLED` is misconfigured in a non-dev environment.
- Fix approach: Add a hard guard in `KeycloakAuthenticator::decodeToken()` that refuses to process `iss=dev` tokens if `APP_ENV !== 'dev'`, independently of the `devAuthEnabled` flag.

### `DeckCollectionProvider` exposes all decks when unauthenticated (Low)

**What happens:** When `$currentUser` is `null` (anonymous request), the provider runs the query with no `WHERE` clause and returns all decks. The `access_control` rule `{ path: ^/api, roles: ROLE_USER }` in `security.yaml` prevents truly anonymous access today, but this is fragile: any relaxation of the firewall would silently leak all decks.

- Files: `src/State/DeckCollectionProvider.php` (lines 28–30), `config/packages/security.yaml`
- Impact: Currently harmless due to the firewall, but creates a latent data-leak bug.
- Fix approach: Change the `else` branch to throw `AccessDeniedException` rather than serving all decks.

---

## Tech Debt

### `DeckRepository` is empty — all queries bypass the repository layer

**What happens:** `src/Repository/DeckRepository.php` contains only the inherited constructor. `DeckCollectionProvider` and `DeckStateProcessor` build queries and call `getRepository()` / `createQueryBuilder()` directly on the `EntityManager`, violating the project's own convention that all queries live in repositories.

- Files: `src/Repository/DeckRepository.php`, `src/State/DeckCollectionProvider.php` (lines 35–40), `src/State/DeckStateProcessor.php` (line 190)
- Fix approach: Add `DeckRepository::findByUser(User $user): array` and `DeckRepository::findByIdAndUser(Uuid $id, User $user): ?Deck`, then update both State classes to delegate to them.

### `DeckCardRepository` is empty

**What happens:** `src/Repository/DeckCardRepository.php` contains only the inherited constructor. `DeckStateProcessor::mergeDeckCards()` calls `$this->em->getRepository(DeckCard::class)->findBy(['deck' => $deck])` inline.

- Files: `src/Repository/DeckCardRepository.php`, `src/State/DeckStateProcessor.php` (line 190)
- Fix approach: Add `DeckCardRepository::findByDeck(Deck $deck): array` and call it from the processor.

### `DeckCollectionProvider` bypasses API Platform pagination

**What happens:** `DeckCollectionProvider::provide()` returns a raw `array` from `getQuery()->getResult()` instead of an API Platform `Paginator` object. The `DeckCollectionNormalizer` only activates when `$data instanceof Paginator`, so it is never triggered for the deck collection.

- Files: `src/State/DeckCollectionProvider.php`, `src/Serializer/DeckCollectionNormalizer.php`
- Impact: `DeckCollectionNormalizer` is dead code; pagination metadata is never included in `/api/decks` responses.
- Fix approach: Return an API Platform `Paginator` from `DeckCollectionProvider`, or remove `DeckCollectionNormalizer` and accept the default API Platform collection envelope.

### Format rules hardcoded in two places

**What happens:** Format limits (min/max cards, rarity counts, hero-specific unique limits) are defined in both `src/Validator/Format/` validators and duplicated verbatim in `src/Controller/FormatController.php` as a hardcoded static JSON array. The hero unique-limit list can diverge silently.

- Files: `src/Controller/FormatController.php`, `src/Validator/Format/NucFormatValidator.php`, `src/Validator/Format/StandardFormatValidator.php`, `src/Validator/Format/SingletonFormatValidator.php`
- Fix approach: Expose a `getLimits(): array` method on `DeckFormatValidatorInterface` and drive `FormatController` from the factory/validators rather than a static array.

### `AlteredCoreClient` cache has a race-condition bug

**What happens:** In `src/Client/AlteredCoreClient.php`, cache is read with a closure that returns `null` for cache misses. The Symfony cache component stores `null` as a valid cached value; subsequent requests see `null` as cached and add the reference to `$missing` again. The delete-then-repopulate workaround is not atomic.

- Files: `src/Client/AlteredCoreClient.php` (lines 42–48, 74–78)
- Impact: Each uncached reference causes two cache writes per cold request instead of one; concurrent requests may both fetch from altered-core.
- Fix approach: Use `CacheInterface::getItem()` / `hasItem()` to check existence without writing a null value, or use a single cache `get()` callback.

### `Deck.format` is a free-text string with no enum constraint

**What happens:** `src/Entity/Deck.php` stores format as `VARCHAR(50)` with no enum or validation constraint at the entity level. Valid values are only enforced at the validator factory layer.

- Files: `src/Entity/Deck.php`
- Impact: Invalid format strings can be persisted via direct PATCH.
- Fix approach: Use a PHP-backed enum for the `format` field or add an `#[Assert\Choice]` constraint.

---

## Missing Features / Incomplete Work

### No public deck browsing for unauthenticated users

**What happens:** `isPublic` is a first-class field on `Deck` with a `SearchFilter`, but the entire `/api` path requires `ROLE_USER`. There is no endpoint that exposes public decks to non-authenticated callers, and no logic in `DeckCollectionProvider` that would serve public decks to others' sessions.

- Files: `config/packages/security.yaml`, `src/State/DeckCollectionProvider.php`
- Impact: The `isPublic` feature is currently non-functional — there is no way to view another user's public deck.

### No rate limiting on deck creation

**What happens:** `POST /api/decks` triggers an HTTP call to `altered-core` for every save. There is no throttling or concurrency control.

- Files: `src/State/DeckStateProcessor.php`, `src/Client/AlteredCoreClient.php`
- Impact: A burst of concurrent saves can saturate the downstream `altered-core` API. The `\Throwable` catch silently returns an empty `cardsData` array, so validation and stats are skipped rather than returning an error.

---

## Test Coverage Gaps

### Zero application tests (Critical)

**What happens:** No `tests/` directory exists. `phpunit/phpunit` is not in `composer.json` (neither `require` nor `require-dev`). `symfony/test-pack` is absent. Only `symfony/maker-bundle` exists as a dev dependency.

- Files: `composer.json`, project root
- Risk: All IDOR vulnerabilities described above, the `DeckCollectionProvider` pagination bug, and the dev-auth bypass could exist silently. Format validators have complex branching logic with no regression protection.
- Fix approach: Add `symfony/test-pack` to `require-dev`. Write functional tests for deck CRUD endpoints asserting ownership enforcement. Write unit tests for each format validator covering edge cases.

---

## Performance Risks

### N+1 card data fetch on deck collection

**What happens:** `DeckNormalizer::normalize()` (`src/Serializer/DeckNormalizer.php` line 48) calls `AlteredCoreClient::getCardsByReferences()` once per `Deck` in a collection response. For a page of 20 decks each with 40+ cards, this is up to 20 outbound HTTP calls at serialization time.

- Files: `src/Serializer/DeckNormalizer.php` (line 48), `src/Client/AlteredCoreClient.php`
- Current mitigation: Per-reference in-memory cache with 1-hour TTL reduces repeat calls.
- Fix approach: Batch all references across all decks in a collection before serialization begins, pre-warming the cache in a single HTTP call.

### JWKS fetched synchronously on every first request after TTL expiry

**What happens:** `KeycloakAuthenticator::getJwks()` fetches the Keycloak JWKS endpoint synchronously inside a Symfony cache closure. If Keycloak is slow or unreachable after the 1-hour TTL, every incoming request blocks.

- Files: `src/Security/KeycloakAuthenticator.php` (lines 112–120)
- Impact: Full API unavailability if Keycloak is temporarily unreachable at cache expiry.
- Fix approach: Use `CacheInterface::get()` with a `$beta` value greater than 1 (probabilistic early expiry / stale-while-revalidate).

### `DeckStateProcessor::mergeDeckCards` issues a redundant DB query on every PATCH

**What happens:** On every deck update, `mergeDeckCards()` calls `$this->em->getRepository(DeckCard::class)->findBy(['deck' => $deck])` to load all existing cards — a duplicate round-trip since Doctrine already tracked these entities when the `Deck` was loaded.

- Files: `src/State/DeckStateProcessor.php`
- Fix approach: Use the collection already in memory with `orphanRemoval: true` or delegate to a repository method that checks Doctrine's identity map.

---

## Priority

| Priority | Concern |
|----------|---------|
| **Critical** | IDOR: any authenticated user can PATCH/DELETE other users' decks |
| **Critical** | IDOR: any authenticated user can read private GET /api/decks/{id} |
| **Critical** | No Voter or security expression — no ownership enforcement mechanism |
| **Critical** | Zero test coverage |
| **High** | `error_log()` debug statement in `KeycloakAuthenticator` leaks data on every request |
| **High** | `DEV_AUTH_ENABLED` has no production guard — HS256 bypass path reachable in prod |
| **High** | PHPUnit absent from `composer.json` — tests cannot run |
| **Medium** | `DeckCollectionNormalizer` is dead code — `DeckCollectionProvider` returns array not `Paginator` |
| **Medium** | `AlteredCoreClient` cache non-atomic delete-rewrite race |
| **Medium** | `Deck.format` is free-text string with no enum constraint |
| **Medium** | Format rules duplicated in validators and `FormatController` |
| **Medium** | No rate limiting on deck creation |
| **Low** | `DeckCollectionProvider` serves all decks when `$currentUser` is null (firewall is only guard) |
| **Low** | `isPublic` feature non-functional — no public deck browsing endpoint |

---

*Concerns audit: 2026-05-12*
