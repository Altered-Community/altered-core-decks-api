# Codebase Concerns

**Analysis Date:** 2026-05-12

---

## Technical Debt

**In-memory workaround in `BgaDeckController` bypasses real query:**
- Issue: `findBgaDecks` and `countBgaDecks` are fully implemented in `DeckRepository` (committed in git history at `6027d33`) but `BgaDeckController::collection()` falls back to `findAll()` with PHP-level pagination, surrounded by commented-out code.
- Files: `src/Controller/BgaDeckController.php` (lines ~52–67)
- Impact: All BGA collection requests load every deck into memory, ignore all filters (name, faction, format), and page results in PHP. This is a critical correctness and performance regression.
- Fix approach: Uncomment the `findBgaDecks` / `countBgaDecks` calls and delete the `$allDecks = findAll()` block.

**`DeckCollectionProvider` bypasses API Platform pagination:**
- Issue: `src/State/DeckCollectionProvider.php` returns a plain array from `->getResult()` rather than an `iterable` backed by `Paginator`. API Platform pagination metadata (totalItems, view links) will be absent or incorrect.
- Files: `src/State/DeckCollectionProvider.php`
- Impact: `GET /api/decks` responses may silently omit pagination. The `DeckCollectionNormalizer` expects a `Paginator` instance; with a plain array it will throw or produce a malformed response.
- Fix approach: Wrap the query result with the API Platform `Paginator` or delegate to the built-in `CollectionProvider` and apply a custom extension.

**`DeckCollectionNormalizer` uses `data` key instead of `member`:**
- Issue: `src/Serializer/DeckCollectionNormalizer.php` returns `['data' => $items, 'pagination' => ...]`. The project convention and CLAUDE.md specify `member` as the key.
- Files: `src/Serializer/DeckCollectionNormalizer.php` (line 43)
- Impact: Consumers expecting `member` (as documented in CLAUDE.md) will silently receive an empty list.
- Fix approach: Rename the key from `data` to `member` and drop the custom `pagination` wrapper — API Platform's default response already includes `totalItems`.

**`DeckStateProcessor` still holds the old `ValidationException` flow on `main`:**
- Issue: The checked-out `main` branch `src/State/DeckStateProcessor.php` throws a `ValidationException` on format errors. The newer git history at `6027d33` stores errors in `formatErrors` JSON column instead. These two designs are in conflict across the working tree vs. HEAD history.
- Files: `src/State/DeckStateProcessor.php`
- Impact: Unclear which behaviour is production-intended; the `formatErrors` column and `Deck::getFormatErrors()` exist in git history but are absent from the working-tree entity and processor.

**`AdminBgaController` uses session-based manual auth check instead of security layer:**
- Issue: `src/Controller/AdminBgaController.php` manually checks `$request->getSession()->has('admin_user_id')` instead of relying on Symfony's `#[IsGranted('ROLE_ADMIN')]` or the firewall.
- Files: `src/Controller/AdminBgaController.php`, `src/Controller/AdminDashboardController.php`
- Impact: Inconsistent with `AdminApiController` which correctly uses `#[IsGranted('ROLE_ADMIN')]`. Manual session checks are error-prone and can be bypassed if the session key is set through another code path.
- Fix approach: Replace session checks with `#[IsGranted('ROLE_ADMIN')]` attribute and rely on the Symfony firewall.

**`DeckCard` uses auto-increment integer primary key:**
- Issue: `src/Entity/DeckCard.php` uses `#[ORM\GeneratedValue]` (auto-increment int) while all other entities use UUID.
- Files: `src/Entity/DeckCard.php` (lines 15–17)
- Impact: Exposes sequential IDs externally; inconsistent identity strategy across the schema.

**`format` field on `Deck` is a free-text string with no enum constraint:**
- Issue: `src/Entity/Deck.php` stores format as `VARCHAR(50)` with no enum or validation constraint at the entity level. Valid values are only enforced at the validator factory layer.
- Files: `src/Entity/Deck.php` (line 78–79)
- Impact: Invalid format strings can be persisted (e.g. via direct PATCH). The `isPublic` filter also exposes this field as searchable, which means clients can query arbitrary strings.
- Fix approach: Use a PHP-backed enum for the `format` field or add an `#[Assert\Choice]` constraint.

**`SingletonFormatValidator` uses a hardcoded hero name list:**
- Issue: `src/Validator/Format/SingletonFormatValidator.php` embeds a `UNIQUE_LIMITS` constant with hero name strings. The same list is duplicated in `src/Controller/FormatController.php`.
- Files: `src/Validator/Format/SingletonFormatValidator.php` (lines 17–21), `src/Controller/FormatController.php` (lines 54–56)
- Impact: Adding a new hero requires changes in two places. Names are matched with `str_contains`, which means a hero named "sol" would also match "isolde".
- Fix approach: Extract hero limits to a shared config class or parameter; replace `str_contains` with exact-match comparison.

---

## Missing Features / Incomplete Work

**`AppFixtures` is an empty stub:**
- Files: `src/DataFixtures/AppFixtures.php`
- Impact: CI pipeline runs `doctrine:fixtures:load` but the fixture does nothing. Tests that depend on seed data will silently have empty state.
- Fix approach: Implement meaningful fixture data or document that fixtures are intentionally empty.

**`DeckRepository` in the working tree is missing all methods present in git HEAD:**
- Issue: The checked-out `src/Repository/DeckRepository.php` contains only `__construct`. Methods `findPublic`, `countPublic`, `findBgaDecks`, `countBgaDecks`, `findRecentAnonymized`, `countCreatedToday`, `countCreatedSince`, `countTotal` — all committed in later commits — are absent from the working tree.
- Files: `src/Repository/DeckRepository.php`
- Impact: `PublicDeckController`, `AdminDashboardController`, `AdminApiController`, `AdminBgaController`, and `BgaDeckController` all depend on these methods. They will fatal at runtime.

**`SandboxFormatValidator` comment describes a set whitelist that is not implemented:**
- Issue: The class docblock says "Only cards from the supported set whitelist" but `validateFormatRules` contains no whitelist check.
- Files: `src/Validator/Format/SandboxFormatValidator.php` (docblock line 7, body lines 22–44)
- Impact: Sandbox-format decks can include cards from any set.

**No rate limiting on deck creation:**
- Issue: `POST /api/decks` triggers an HTTP call to `altered-core` for every save. There is no throttling or concurrency control.
- Files: `src/State/DeckStateProcessor.php`, `src/Client/AlteredCoreClient.php`
- Impact: A burst of concurrent saves can saturate the downstream `altered-core` API. The `\Throwable` catch silently returns an empty `cardsData` array, so validation and stats are skipped rather than returning an error.

**`/admin/debug-token` endpoint leaks raw JWT to browser:**
- Issue: `src/Controller/AdminAuthController.php::debugToken()` is a debug-only route that renders the full access token in plain HTML.
- Files: `src/Controller/AdminAuthController.php` (lines 82–99)
- Impact: Route is registered unconditionally (not `when@dev`). Any admin who navigates there exposes their token in browser history.
- Fix approach: Remove this endpoint or restrict it to `dev` environment only.

---

## Security Concerns

**`error_log()` debug statement left in production authenticator:**
- Issue: `src/Security/KeycloakAuthenticator.php:106` calls `error_log('JWKS keys: ...')` on every token decode. This leaks JWKS key metadata to the system error log on every authenticated request.
- Files: `src/Security/KeycloakAuthenticator.php` (line 106)
- Fix approach: Remove or replace with a `$this->logger->debug(...)` call.

**`DEV_AUTH_ENABLED` flag is checked at runtime but not environment-locked:**
- Issue: `src/Controller/DevAuthController.php` returns 404 when `devAuthEnabled` is false, but nothing prevents `DEV_AUTH_ENABLED=true` from being set in a production `.env.local`. The dev auth flow signs tokens with `APP_SECRET` using HS256, bypassing Keycloak.
- Files: `src/Security/KeycloakAuthenticator.php` (lines 97–102), `src/Controller/DevAuthController.php`
- Impact: If `DEV_AUTH_ENABLED=true` leaks into production, any user who knows `APP_SECRET` can impersonate any user by crafting a JWT with `iss=dev`.
- Fix approach: Add a `when@dev` or `when@test` guard in `services.yaml` so the parameter is always `false` in `prod` environment, independent of `.env.local`.

**`GET /api/decks/{uuid}` is publicly accessible for any deck marked `isPublic`:**
- Issue: `src/State/DeckItemProvider.php` returns the deck when `$deck->getIsPublic()` is true without verifying the caller's identity at all. The security.yaml access control at `6027d33` allows `PUBLIC_ACCESS` for UUID-matching paths.
- Files: `src/State/DeckItemProvider.php` (lines 30–31), `config/packages/security.yaml`
- Impact: This is an intentional feature, but public decks expose full `deck:read:detail` payload including `deckCards`. Ensure this is the intended data exposure scope.

**`PATCH /api/decks/{id}` and `DELETE /api/decks/{id}` have no ownership check:**
- Issue: Neither the `Patch` nor `Delete` API Platform operations define a `security` expression or use a `Voter`. Any authenticated user can patch or delete any other user's deck if they know the UUID.
- Files: `src/Entity/Deck.php` (lines 43–48), `src/State/DeckStateProcessor.php`
- Impact: Critical IDOR (Insecure Direct Object Reference) vulnerability. A user can overwrite or delete another user's decks.
- Fix approach: Add `security: "is_granted('ROLE_USER') and object.getUser() == user"` to the `Patch` and `Delete` operations, or add ownership enforcement in `DeckStateProcessor::process()`.

**Admin dashboard session not protected against session fixation:**
- Issue: `src/Controller/AdminAuthController.php::callback()` calls `$request->getSession()->set(...)` after OAuth callback without first calling `$request->getSession()->migrate()`.
- Files: `src/Controller/AdminAuthController.php` (lines 76–79)
- Impact: Exposes the admin session to session fixation attacks.
- Fix approach: Call `$request->getSession()->migrate(true)` before setting `admin_user_id`.

**`countPublic` raw SQL constructs a WHERE clause via string interpolation:**
- Issue: In `DeckRepository::countPublic()` (git HEAD), `$heroFilter` is built as a raw string and appended to SQL. While the value itself is parameterized, the structural SQL string is dynamically composed.
- Files: `src/Repository/DeckRepository.php` method `countPublic` and `findPublic`
- Impact: Low risk currently (no user input flows directly into the SQL structure), but this pattern is fragile and one refactor away from an injection risk.
- Fix approach: Use QueryBuilder or ensure the conditional SQL fragments are static strings.

---

## Performance Concerns

**`BgaDeckController` loads all decks into memory for pagination:**
- Issue: `$this->deckRepository->findAll()` returns every `Deck` entity, then `array_slice` paginates in PHP.
- Files: `src/Controller/BgaDeckController.php` (lines ~51–67)
- Impact: With thousands of decks, this will exhaust memory and response time will grow linearly.

**`DeckNormalizer` makes an HTTP call to `altered-core` on every `GET /api/decks/{id}`:**
- Issue: `src/Serializer/DeckNormalizer.php` calls `$this->alteredCoreClient->getCardsByReferences(...)` during normalization. This is inside the serialization pass, making it invisible to the caller.
- Files: `src/Serializer/DeckNormalizer.php` (lines 47–48)
- Impact: Every GET on a deck with cards triggers a synchronous HTTP request. Cache TTL is 1 hour; cache misses are uncapped batch calls.

**`AlteredCoreClient` caching has a write-invalidate-rewrite race:**
- Issue: `src/Client/AlteredCoreClient.php` calls `$this->cache->get(...)` with a closure returning `null` to probe the cache, then after the batch fetch calls `$this->cache->delete($cacheKey)` followed immediately by `$this->cache->get(...)` with the real value. This delete-and-rewrite is not atomic.
- Files: `src/Client/AlteredCoreClient.php` (lines 40–78)
- Impact: Under concurrent requests, two calls may both see cache miss, both fetch from `altered-core`, then both call `delete + set`. This can cause double API calls and brief cache stampedes.
- Fix approach: Use `CacheInterface::get()` with a single callback that fetches from the API instead of separate probe and write calls.

**`DeckStateProcessor::mergeDeckCards` issues a `findBy(['deck' => $deck])` query on every PATCH:**
- Issue: On every deck update, `src/State/DeckStateProcessor.php::mergeDeckCards()` calls `$this->em->getRepository(DeckCard::class)->findBy(['deck' => $deck])` to load all existing cards.
- Files: `src/State/DeckStateProcessor.php` (line 190)
- Impact: Duplicate database round-trip. Doctrine already tracked these entities when the `Deck` was loaded; a proper approach would use the collection already in memory with `orphanRemoval: true`.

**No index on `deck.is_public` or `deck.is_draft`:**
- Issue: `findPublic` queries filter by `is_public = true AND is_draft = false` but migrations only create indexes on `user_id` and `format`.
- Files: `migrations/Version20260422162231.php`
- Impact: Full table scans on public deck queries as data grows.
- Fix approach: Add a composite index `(is_public, is_draft, created_at)`.

---

## Test Coverage Gaps

**Zero tests exist in the working tree:**
- Issue: There is no `tests/` directory present in the working tree. The CI workflow references `--testsuite Unit` and `--testsuite Integration` but these suites do not exist.
- Files: (absent)
- Impact: All code paths are completely untested. CI will fail or skip silently.
- Priority: High — security-critical code (authenticator, ownership checks) is untested.

**No test for IDOR on PATCH/DELETE:**
- The ownership gap in `Deck` PATCH/DELETE has no regression coverage. Even after a fix is introduced, the absence of a dedicated test means the bug can silently return.

**Format validators are untested:**
- `src/Validator/Format/` contains four validators with non-trivial branching logic (hero detection, rarity counting, faction checks). All branches are untested.

**`AlteredCoreClient` cache logic is untested:**
- The cache probe/delete/rewrite pattern is subtle and error-prone. No unit test validates correct behavior on cache hit, miss, or concurrent miss.

---

## Maintenance Concerns

**`composer.json` has no dev testing dependency (PHPUnit):**
- Issue: `require-dev` only lists `symfony/maker-bundle`. PHPUnit is absent.
- Files: `composer.json` (line 67–69)
- Impact: `php bin/phpunit` (referenced in CI) will fail with "command not found" unless PHPUnit is installed separately. The CI workflow calls `doctrine:fixtures:load` using `doctrine/doctrine-fixtures-bundle` which is also absent from `composer.json`.

**Git history contains many "debug" commits on `main`:**
- Commits `6027d33`, `0d17fc8`, `f6e0af6`, `9f615d9`, `e768df1`, `8c3f096`, `6d01929`, `a58bdaf`, `2fbf831` are all titled "debug".
- Impact: No meaningful history to trace when a regression was introduced. Suggests the codebase is in active exploratory development with production-quality commits not yet enforced.

**`isPublic` vs `getIsPublic` naming inconsistency:**
- Issue: `Deck::isPublic()` exists in the working tree (line 129) but git HEAD version uses `Deck::getIsPublic()` (called in `DeckItemProvider` at `$deck->getIsPublic()`). The two versions are out of sync.
- Files: `src/Entity/Deck.php`, `src/State/DeckItemProvider.php`
- Impact: `DeckItemProvider` will throw a "call to undefined method" at runtime with the working-tree entity.

**`DeckCard` `id` exposed in `deck:read:detail` group is missing from entity definition:**
- Issue: `DeckCard::$id` has no `#[Groups]` attribute but may appear in serialized output depending on normalizer configuration. `DeckCard` has no `deck:read:detail` group on the `id` field yet DeckCards appear nested in that group.
- Files: `src/Entity/DeckCard.php`

**`Deck::$stats` stores hero `name` as raw value from `altered-core`:**
- Issue: `stats['hero']['name']` is set from `$cardData['name'] ?? null` but the `name` field from `altered-core` can be either a string or a locale-keyed array (as handled in `DeckNormalizer`). The stored value could be an array serialized into the JSON column.
- Files: `src/State/DeckStateProcessor.php` (line 133)

---

## Priority

| Priority | Concern |
|----------|---------|
| **Critical** | IDOR: any authenticated user can PATCH/DELETE other users' decks |
| **Critical** | `DeckRepository` in working tree is missing all query methods — runtime fatals on multiple controllers |
| **Critical** | `BgaDeckController` uses `findAll()` in-memory pagination — ignores all filters, unbounded memory |
| **High** | `isPublic()` vs `getIsPublic()` naming mismatch between entity and `DeckItemProvider` — runtime fatal |
| **High** | `error_log()` debug statement in `KeycloakAuthenticator` leaks data on every request |
| **High** | `DEV_AUTH_ENABLED` has no production guard — HS256 bypass path reachable in prod |
| **High** | `/admin/debug-token` leaks JWT — not restricted to dev environment |
| **High** | Admin session fixation: `migrate()` not called after OAuth callback |
| **High** | PHPUnit and fixtures bundle absent from `composer.json` — CI cannot run |
| **Medium** | `DeckCollectionNormalizer` uses `data` key, not `member` — breaks API contract |
| **Medium** | `DeckCollectionProvider` returns array, not `Paginator` — pagination metadata broken |
| **Medium** | `SandboxFormatValidator` missing whitelist implementation despite docblock claim |
| **Medium** | `AlteredCoreClient` cache non-atomic delete-rewrite race |
| **Medium** | Missing composite index on `(is_public, is_draft, created_at)` |
| **Medium** | `Deck.format` is free-text string with no enum constraint |
| **Low** | `SingletonFormatValidator` hero name list duplicated in `FormatController` |
| **Low** | `AppFixtures` is an empty stub |
| **Low** | Rarity bucketing discards R2 into R in stats but formats distinguish R1/R2 |

---

*Concerns audit: 2026-05-12*
