# Testing

**Analysis Date:** 2026-05-12

## Test Setup

**Framework:**
- PHPUnit (invoked via `bin/phpunit`, the Symfony wrapper)
- No `phpunit.xml` or `phpunit.xml.dist` found in the project root — configuration is likely generated or absent
- No dedicated testing packages in `composer.json` beyond `symfony/maker-bundle` (dev dependency)
- No `symfony/test-pack`, `symfony/browser-kit`, `symfony/phpunit-bridge`, or `phpspec/prophecy` present

**Test environment configuration** (`config/packages/framework.yaml`):
```yaml
when@test:
    framework:
        test: true
        session:
            storage_factory_id: session.storage.factory.mock_file
```

**Security test configuration** (`config/packages/security.yaml`):
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

**Autoloading:**
```json
"autoload-dev": {
    "psr-4": {
        "App\\Tests\\": "tests/"
    }
}
```
The `tests/` namespace is configured — but no test files exist yet.

## Test Types

**Current state: No tests exist.**

There are no test files anywhere in the project outside of `vendor/`. The `tests/` directory referenced in `composer.json` does not exist on disk.

The Makefile defines a `test` target, indicating tests are intended to be run via `make test`, but the test suite has not been written yet.

## How to Run

**Via Makefile (Linux/Mac):**
```bash
make test                          # Run all tests (APP_ENV=test)
make test c="--group unit"         # Run tests with PHPUnit options
make test c="--stop-on-failure"    # Stop on first failure
```

**Via Docker directly (Windows or without make):**
```bash
docker compose exec -e APP_ENV=test php bin/phpunit
docker compose exec -e APP_ENV=test php bin/phpunit --stop-on-failure
```

**Directly inside the container:**
```bash
php bin/phpunit
php bin/phpunit --group unit --stop-on-failure
```

Note: `APP_ENV=test` must be set. The Makefile `test` target sets this automatically.

## Coverage

**No coverage tooling configured.** No Xdebug, PCOV, or `phpunit.xml` with coverage settings are present. No coverage reports are generated.

Current test coverage: **0%** — no tests exist.

## Test Conventions

No established test conventions yet. The following patterns are recommended based on what the codebase lends itself to:

**Candidate unit tests** (pure logic, no I/O):
- `src/Validator/Format/NucFormatValidator.php` — pure array/entity validation logic
- `src/Validator/Format/StandardFormatValidator.php` — same
- `src/Validator/Format/SingletonFormatValidator.php` — hero-based unique limit logic
- `src/Validator/Format/AbstractDeckFormatValidator.php` — shared helpers (deck size, faction, ban checks)
- `src/State/DeckStateProcessor.php` → `getRarityFromReference()`, `computeStats()`, `isHero()` — private methods testable via public entry points

**Candidate integration tests:**
- `src/Client/AlteredCoreClient.php` — HTTP client + cache interaction
- `src/Security/KeycloakAuthenticator.php` — JWT decode, user upsert flow
- `src/State/DeckStateProcessor.php` — full create/patch lifecycle

**Where to add test files:**
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

**Naming convention** (to follow when tests are written):
- Test class: `{ClassUnderTest}Test` in `App\Tests\{matching namespace}`
- Test methods: `test{WhatItTests}()` — e.g., `testValidateDeckSizeRejectsOversizedDeck()`

## CI/CD

**No CI/CD pipeline configured.** No `.github/workflows/`, no `.gitlab-ci.yml`, no `Jenkinsfile`, no Bitbucket pipelines found.

Tests are run manually via `make test` or Docker. There is no automated test execution on push or pull request.

---

*Testing analysis: 2026-05-12*
