# Testing

**Analysis Date:** 2026-05-12

## Current State

PHPUnit 13.1 is installed (`phpunit/phpunit: ^13.1` in `composer.json` require-dev). The `tests/` directory exists with real tests.

**Test infrastructure:** `phpunit.xml.dist` — single suite scanning `tests/`, bootstrapped via `tests/bootstrap.php` (loads `.env` via `Dotenv::bootEnv`). `APP_ENV=test` is forced server-side.

**Fixtures:** `DoctrineFixturesBundle` installed. `src/DataFixtures/AppFixtures.php` exists but is empty — no seed data loaded yet.

**CI:** GitHub Actions at `.github/workflows/ci.yml` — runs on push/PR to `main` on a self-hosted runner (PHP 8.4 + pdo_pgsql). Runs migrations + fixtures load before tests.

## How to Run

```bash
# Via Makefile (sets APP_ENV=test automatically)
make test
make test c="--stop-on-failure"

# Via Docker directly
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --testsuite Unit
docker compose exec -e APP_ENV=test php bin/phpunit --testsuite Integration
```

Note: the CI config references `--testsuite Unit` and `--testsuite Integration`, but `phpunit.xml.dist` only defines a single suite named `Project Test Suite`. These flags will silently run all tests; add named suites to `phpunit.xml.dist` if per-suite execution is needed.

## Existing Test Files

### `tests/Api/DeckTest.php` — Integration, `WebTestCase`

HTTP integration tests for the `Deck` API resource. Uses `KernelBrowser` + `MockHttpClient` for the altered-core dependency.

| Test | Covers |
|------|--------|
| `testPatchIsPublicSaved` | PATCH `isPublic: true` persists correctly |
| `testFormatErrorsStoredNotThrown` | Format errors go to `formatErrors` field, not 422 |
| `testFormatErrorsNullWhenNoFormat` | No format → `formatErrors` is null |
| `testDeckSavedWhenAlteredCoreUnavailable` | altered-core 500 → deck still saves |
| `testFormatErrorsNullOnValidDeck` | Valid standard deck → null errors + stats populated |

Auth uses HS256 JWTs signed with a test secret (`$ecretf0rt3st_extended_for_hs256_tests`). The mock HTTP client is fetched from the container via service id `altered_core.mock_http_client`.

### `tests/Controller/BgaDeckControllerTest.php` — Integration, `WebTestCase`

HTTP tests for the BGA-specific endpoints (`/api/bga/decks`, `/api/bga/cards`).

| Area | Tests |
|------|-------|
| Collection auth | 401 without token |
| Collection shape | `hydra:member`, `hydra:view`, view keys |
| Item | 404 for unknown UUID, 200 + name/id for known |
| Card endpoint | 404 when core returns empty, required keys, faction/reference shape, elements shape, effects/cardElements structure, multi-effect sequences |

### `tests/Serializer/BgaDeckSerializerTest.php` — Unit, `TestCase`

Pure unit tests for `src/Serializer/BgaDeckSerializer.php`. Mocks `NormalizerInterface` only.

Covers: `collectionEntry()` with/without hero, faction extraction from reference parts, `adminRow()` with/without hero, `normalizeItem()` delegate call with correct groups, `normalizeCollection()` on multiple decks.

### `tests/Serializer/DeckNormalizerBgaTest.php` — Unit, `TestCase`

Pure unit tests for `src/Serializer/DeckNormalizer.php` in BGA view mode (`'view' => 'bga'` context). Stubs `NormalizerInterface`, `AlteredCoreClient`, `RequestStack`.

Covers: top-level output keys, faction/alterator derivation, `deckLegality` shape, card grouping by type (`expedition_permanent`/`landmark_permanent` → `permanent`), card entry shape (name, type, subTypes, typeline, mainFaction, illustrator, elements), element values, typeline construction, unique card detection (`_U_` in reference), `uniqueReduced` structure with single and multiple effects.

## Test Patterns

**Integration tests** extend `WebTestCase` and use a real Symfony kernel + database.

```php
protected function setUp(): void
{
    $this->client          = static::createClient();
    $this->alteredCoreMock = static::getContainer()->get('altered_core.mock_http_client');
    $this->alteredCoreMock->setResponseFactory(
        static fn(): MockResponse => new MockResponse('[]', ['http_code' => 200, ...])
    );
}
```

**Unit tests** extend `TestCase`. Use `createMock()` for strict mock expectations, `createStub()` for passive stubs.

**JWT generation in tests:** `Firebase\JWT\JWT::encode()` with the known test secret and HS256. Test subjects use per-test unique `sub` values (`'user-' . __FUNCTION__`) to avoid cross-test state.

## Coverage Gaps (Updated)

| Component | Risk | Status |
|-----------|------|--------|
| `src/Validator/Format/` — all 3 validators | High | No tests — complex branching |
| PATCH/DELETE ownership checks (IDOR) | Critical | No regression test after fix |
| `src/Security/KeycloakJwtDecoder.php` | High | No tests — security-critical |
| `src/State/DeckStateProcessor.php` | High | Covered only indirectly via integration tests |
| `src/Client/AlteredCoreClient.php` | Medium | No direct unit tests — cache probe/delete logic |
| `src/State/DeckCollectionProvider.php` | Medium | No direct tests — user-scoping, null user fallback |
| `src/DataFixtures/AppFixtures.php` | Low | Empty — no seed data for integration tests |

---

*Testing analysis: 2026-05-12*
