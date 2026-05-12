# Codebase Concerns

**Analysis Date:** 2026-05-12

---

## Security Vulnerabilities

### IDOR on PATCH /api/decks/{id} (Critical)

`DeckStateProcessor::process()` only sets the owner when `$isNew === true`. The `else` branch calls `setUpdatedAt()` and `mergeDeckCards()` with no ownership check. Any authenticated user can mutate another user's deck.

- Files: `src/State/DeckStateProcessor.php` (lines 37–40)
- Fix: At entry of the `else` branch, assert `$data->getUser()->getId() === $this->security->getUser()->getId()` or throw `AccessDeniedException`.

### IDOR on DELETE /api/decks/{id} (Critical)

The `Delete` operation in `src/Entity/Deck.php` uses the default API Platform processor. No `security` expression, no Voter, no custom provider governs it.

- Files: `src/Entity/Deck.php` (line 51)
- Fix: Add `security: "object.getUser() == user"` on the `Delete` operation, or implement `src/Security/Voter/DeckVoter.php`.

### `DEV_AUTH_ENABLED` has no production env guard (High)

`KeycloakJwtDecoder::decode()` accepts tokens with `iss=dev` whenever `$devAuthEnabled` is true. There is no check that `APP_ENV === 'dev'`. If `DEV_AUTH_ENABLED=true` leaks to production any party who knows `APP_SECRET` can forge a token for any `sub`.

- Files: `src/Service/KeycloakJwtDecoder.php` (lines 25–31)
- Fix: Add `&& $this->appEnv === 'dev'` (inject `kernel.environment`) to the `devAuthEnabled` guard.

### `DeckCollectionProvider` returns all decks when user is null (Low)

When `$currentUser` is not a `User` instance, the provider runs the query with no `WHERE` clause (`src/State/DeckCollectionProvider.php` line 30). The firewall currently blocks anonymous access, but any relaxation silently leaks all decks.

- Files: `src/State/DeckCollectionProvider.php` (lines 28–31)
- Fix: Replace the `else` branch with `throw new AccessDeniedException()`.

---

## Tech Debt

### IDOR on GET /api/decks/{id} — FIXED

`DeckItemProvider` now gates single-item GET: public decks are open; private decks require authentication and ownership match. This concern is resolved.

- Fixed in: `src/State/DeckItemProvider.php`

### `error_log()` in `KeycloakAuthenticator` — FIXED

The `error_log()` call has been removed. `KeycloakAuthenticator` now delegates all JWT work to `KeycloakJwtDecoder` and contains no debug output. Resolved.

### `DeckRepository` was empty — PARTIALLY FIXED

`DeckRepository` now has admin/stats query methods (`countCreatedToday`, `findPublic`, `findBgaDecks`, etc.). However `DeckCollectionProvider` and `DeckStateProcessor` still bypass the repository: the collection provider calls `$this->em->getRepository(Deck::class)->createQueryBuilder()` directly, and `mergeDeckCards` calls `$this->em->getRepository(DeckCard::class)->findBy()` inline.

- Files: `src/State/DeckCollectionProvider.php` (line 35), `src/State/DeckStateProcessor.php` (line 180)
- Fix: Add `DeckRepository::findByUser(User $user): array` and `DeckCardRepository::findByDeck(Deck $deck): array`; call them from the state classes.

### `DeckCollectionProvider` bypasses API Platform pagination

`provide()` returns a raw `array` from `getQuery()->getResult()`. `DeckCollectionNormalizer` only activates for `Paginator` instances, so it is dead code and pagination metadata is never included in `/api/decks` responses.

- Files: `src/State/DeckCollectionProvider.php`, `src/Serializer/DeckCollectionNormalizer.php`
- Fix: Return an API Platform `Paginator`, or remove `DeckCollectionNormalizer` and accept the default envelope.

### `AlteredCoreClient` cache has a write-null / delete-rewrite race

`getCardsByReferences()` calls `cache->get()` with a closure that returns `null` for misses — storing `null` as a cached value. The delete-then-repopulate workaround (lines 73–78) is not atomic: concurrent requests can both see `null` as cached, both fetch from altered-core, and both write. `getCardByReferences()` (lines 96–117) has the same pattern.

- Files: `src/Client/AlteredCoreClient.php` (lines 42–48, 73–78, 96–117)
- Fix: Check existence with `CacheInterface::hasItem()` / `getItem()` instead of storing `null`, or restructure to a single closure that performs the HTTP call.

### `Deck.format` is a free-text string with no enum constraint

`format` is stored as `VARCHAR(50)` with no enum or `#[Assert\Choice]` constraint at entity level. Validation only happens in the `DeckFormatValidatorFactory` layer.

- Files: `src/Entity/Deck.php` (line 82)
- Fix: Introduce a `DeckFormat` backed enum, or add `#[Assert\Choice(choices: ['standard', 'nuc', 'sandbox', 'singleton'])]`.

### Format rules duplicated in validators and `FormatController`

Min/max card counts, rarity limits, and hero-unique lists are defined in `src/Validator/Format/` and duplicated as a hardcoded static JSON array in `src/Controller/FormatController.php`.

- Files: `src/Controller/FormatController.php`, `src/Validator/Format/NucFormatValidator.php`, `src/Validator/Format/StandardFormatValidator.php`
- Fix: Add `getLimits(): array` to `DeckFormatValidatorInterface`; drive `FormatController` from the factory.

---

## Performance Risks

### N+1 outbound HTTP calls when serializing a deck collection

`DeckNormalizer::normalize()` calls `AlteredCoreClient::getCardsByReferences()` once per `Deck` in a collection response. For 20 decks with 40 cards each this can be up to 20 HTTP calls at serialization time.

- Files: `src/Serializer/DeckNormalizer.php` (line 48), `src/Client/AlteredCoreClient.php`
- Mitigation: Per-reference 1-hour cache reduces repeat calls. Fix: batch all references across the collection before serialization begins.

### JWKS fetched synchronously at cache expiry

`KeycloakJwtDecoder::getJwks()` fetches the Keycloak JWKS endpoint synchronously inside a cache closure. If Keycloak is slow at TTL expiry, every concurrent request blocks.

- Files: `src/Service/KeycloakJwtDecoder.php` (lines 37–43)
- Fix: Use `$beta > 1` on `CacheInterface::get()` for probabilistic early expiry (stale-while-revalidate).

### `mergeDeckCards` issues a redundant DB query on every PATCH

`DeckStateProcessor::mergeDeckCards()` calls `findBy(['deck' => $deck])` even though Doctrine already tracked those entities when the `Deck` was loaded.

- Files: `src/State/DeckStateProcessor.php` (line 180)
- Fix: Use the `orphanRemoval: true` collection already in memory, or delegate to `DeckCardRepository`.

---

## Test Coverage

### Tests present but coverage is narrow

PHPUnit is now in `composer.json` and a `tests/` directory exists with functional tests for deck CRUD (`tests/Api/DeckTest.php`), BGA serializer, and `BgaDeckController`. Coverage gaps remain:

- IDOR on PATCH / DELETE not tested — no test asserts that user B cannot mutate user A's deck.
- `DeckItemProvider` ownership path not covered.
- All format validators (`StandardFormatValidator`, `NucFormatValidator`, etc.) have no unit tests.
- `AlteredCoreClient` cache logic not tested.

- Files: `tests/Api/DeckTest.php`, `tests/Controller/BgaDeckControllerTest.php`
- Priority: Add IDOR regression tests before fixing the vulnerabilities.

---

## Priority

| Priority | Concern |
|----------|---------|
| **Critical** | IDOR: any authenticated user can PATCH other users' decks — no ownership check in `DeckStateProcessor` |
| **Critical** | IDOR: any authenticated user can DELETE any deck — no security expression on `Delete` operation |
| **High** | `DEV_AUTH_ENABLED` has no `APP_ENV` guard — HS256 bypass reachable in production if misconfigured |
| **High** | IDOR on PATCH/DELETE not covered by tests |
| **Medium** | `DeckCollectionProvider` returns all decks when `$currentUser` is null (firewall is only guard) |
| **Medium** | `DeckCollectionNormalizer` is dead code — provider returns array not `Paginator` |
| **Medium** | `AlteredCoreClient` null-cache / delete-rewrite race |
| **Medium** | `Deck.format` free-text string — no enum or Choice constraint |
| **Medium** | Format rules duplicated in validators and `FormatController` |
| **Low** | `DeckRepository` / `DeckCardRepository` partially bypassed in state classes |
| **Low** | JWKS synchronous fetch at TTL expiry can block all requests |
| **Low** | N+1 HTTP calls on deck collection serialization |

---

*Concerns audit: 2026-05-12*
