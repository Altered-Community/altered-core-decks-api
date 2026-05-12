# Testing

**Analysis Date:** 2026-05-12

## Current State

**PHPUnit is NOT installed.** No `phpunit/phpunit` or `symfony/test-pack` in `composer.json` (neither `require` nor `require-dev`). `require-dev` contains only `symfony/maker-bundle`.

**No `tests/` directory exists.** The PSR-4 namespace `App\Tests\` is declared in `composer.json` autoload-dev, but the `tests/` directory has never been created and no test files of any kind exist in the repository.

**Coverage: 0%** — zero tests across all code.

The Makefile defines a `make test` target (`bin/phpunit`), but it will fail until PHPUnit is installed.

## Test Environment Configuration

Despite no tests existing, the framework configuration for the test environment is present:

**`config/packages/framework.yaml`:**
```yaml
when@test:
    framework:
        test: true
        session:
            storage_factory_id: session.storage.factory.mock_file
```

**`config/packages/security.yaml`:**
```yaml
when@test:
    security:
        password_hashers:
            Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface:
                algorithm: auto
                cost: 4
                time_cost: 3
                memory_cost: 10
```

**`composer.json` autoload-dev:**
```json
"autoload-dev": {
    "psr-4": {
        "App\\Tests\\": "tests/"
    }
}
```

## How to Set Up Testing

**Step 1 — Install PHPUnit:**
```bash
make composer c="require --dev symfony/test-pack"
```
This installs `phpunit/phpunit`, `symfony/phpunit-bridge`, and generates `phpunit.xml.dist`.

**Step 2 — Create the `tests/` directory structure:**
```
tests/
├── Unit/
│   └── Validator/
│       └── Format/
│           ├── NucFormatValidatorTest.php
│           ├── StandardFormatValidatorTest.php
│           └── SingletonFormatValidatorTest.php
└── Integration/
    └── State/
        └── DeckStateProcessorTest.php
```

**Step 3 — Run tests:**
```bash
make test                               # Run all tests (APP_ENV=test)
make test c="--group unit"              # Run tests with PHPUnit options
make test c="--stop-on-failure"         # Stop on first failure
```

Or directly via Docker:
```bash
docker compose exec -e APP_ENV=test php bin/phpunit
```

## How to Run

```bash
make test                          # Run all tests (APP_ENV=test)
make test c="--group unit"         # Run specific test group
make test c="--stop-on-failure"    # Stop on first failure
```

**Via Docker directly (Windows or without make):**
```bash
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --stop-on-failure
```

Note: `APP_ENV=test` must be set. The Makefile `test` target sets this automatically.

## Recommended Test Priorities

**1. Format validators — highest value, pure unit tests (no infrastructure):**

All three format validators have complex rule logic. Pure PHP objects, no Doctrine or HTTP needed.

```php
namespace App\Tests\Unit\Validator\Format;

use App\Entity\Deck;
use App\Validator\Format\StandardFormatValidator;
use PHPUnit\Framework\TestCase;

class StandardFormatValidatorTest extends TestCase
{
    private StandardFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new StandardFormatValidator();
    }

    public function testValidDeckPassesValidation(): void
    {
        $deck = $this->buildDeck(/* ... */);
        $cardsData = [/* mock data */];

        $errors = $this->validator->validate($deck, $cardsData);

        $this->assertEmpty($errors);
    }
}
```

**2. `DeckStateProcessor` helpers — unit testable with mocks:**

`computeStats()`, `getRarityFromReference()`, `mergeDeckCards()` are testable with mocked `EntityManagerInterface`. `validateFormat()` is testable with a mock `DeckFormatValidatorFactory`.

**3. `AlteredCoreClient` — mock HTTP client pattern:**

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$mockClient = new MockHttpClient([
    new MockResponse(json_encode([/* card data */])),
]);
$client = new AlteredCoreClient($mockClient, $cache, 'http://example.com');
```

**4. Integration tests — require database:**

- `src/State/DeckCollectionProvider.php` — needs a `KernelTestCase` with a test PostgreSQL instance
- `src/Security/KeycloakAuthenticator.php` — needs a mock HTTP client for JWKS endpoint

## Test Coverage Gaps (Priority)

| Component | Risk | Notes |
|-----------|------|-------|
| `src/Validator/Format/` — all 3 validators | High | Complex branching, no regression coverage |
| PATCH/DELETE ownership checks | Critical | IDOR vulnerability — must have regression test after fix |
| `src/Security/KeycloakAuthenticator.php` | High | Security-critical; dev auth bypass path untested |
| `src/State/DeckStateProcessor.php` | High | Full write pipeline, card fetch, stats computation |
| `src/Client/AlteredCoreClient.php` | Medium | Cache probe/delete/rewrite logic is subtle |
| `src/Serializer/DeckNormalizer.php` | Medium | Card enrichment logic |
| `src/State/DeckCollectionProvider.php` | Medium | User-scoping, null user fallback |

## CI/CD

**No CI/CD pipeline configured.** No `.github/workflows/`, no `.gitlab-ci.yml`, no Jenkinsfile, no Bitbucket pipelines found.

Tests are run manually via `make test` or Docker. There is no automated test execution on push or pull request.

---

*Testing analysis: 2026-05-12*
