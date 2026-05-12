# Code Conventions

**Analysis Date:** 2026-05-12

## Naming Conventions

**Classes:**
- PascalCase: `DeckStateProcessor`, `KeycloakAuthenticator`, `DeckFormatValidatorFactory`
- Controllers end in `Controller`: `FormatController`, `HomepageController`, `DevAuthController`
- Repositories end in `Repository`: `DeckRepository`, `UserRepository`, `DeckCardRepository`
- State providers end in `Provider`: `DeckCollectionProvider`
- State processors end in `Processor`: `DeckStateProcessor`
- Normalizers end in `Normalizer`: `DeckNormalizer`, `DeckCollectionNormalizer`
- Validators end in `Validator` or `ValidatorFactory`: `StandardFormatValidator`, `DeckFormatValidatorFactory`
- Interfaces end in `Interface`: `DeckFormatValidatorInterface`
- Abstract classes start with `Abstract`: `AbstractDeckFormatValidator`
- Client wrappers end in `Client`: `AlteredCoreClient`

**Methods:**
- camelCase throughout
- Boolean property getters use `is*()` for boolean state — NOT `getIs*()`
- Example: `isDraft()`, `isPublic()`, `isHero()`, `supportsNormalization()`
- Setters return `self` for fluent chaining: `setName(string $name): self`

**Variables:**
- camelCase: `$deckCards`, `$cardsData`, `$keycloakId`, `$heroName`
- Array results named with the plural of what they contain: `$errors`, `$references`, `$cards`
- Boolean flags: `$isNew`, `$devAuthEnabled`

**Files:**
- One class per file, filename matches class name exactly
- PHP files have no closing `?>` tag

**Constants:**
- `SCREAMING_SNAKE_CASE` for class constants: `ALREADY_CALLED`, `UNIQUE_LIMITS`
- Visibility declared explicitly: `private const ALREADY_CALLED = ...`

**Directories:**
- PascalCase for subdirectories under `src/`: `Entity/`, `Controller/`, `Repository/`, `State/`, `Validator/Format/`, `Serializer/`, `Security/`, `Client/`, `OpenApi/`

## Code Style

**Indentation:** 4 spaces (no tabs)

**Trailing commas:** Used on multi-line arrays and constructor argument lists — consistent throughout:
```php
public function __construct(
    private readonly EntityManagerInterface      $em,
    private readonly Security                   $security,
    private readonly AlteredCoreClient          $alteredCoreClient,
    private readonly DeckFormatValidatorFactory $validatorFactory,
    private readonly RequestStack               $requestStack,
    private readonly LoggerInterface            $logger,
) {}
```

**Alignment:** Properties in constructor injection are column-aligned when there are multiple with different type lengths.

**Single-line methods:** Simple one-liner getters/setters are written on one line:
```php
public function getId(): ?Uuid { return $this->id; }
public function getName(): string { return $this->name; }
public function setName(string $name): self { $this->name = $name; return $this; }
```

**Array formatting:** Multi-line arrays use trailing comma; alignment of `=>` used for readability in dense arrays.

**Blank lines:** One blank line between method definitions. No blank line after opening `{` or before closing `}`.

**Null coalescing:** `??` preferred over `isset()` checks for optional values.

**Type declarations:** Return types always declared. Nullable types use `?Type`. `mixed` used only where the type is genuinely variable.

**No closing PHP tag:** All PHP files omit the closing `?>`.

**`sprintf()` for message formatting:** All error/log messages with dynamic values use `sprintf()` — never string concatenation.

## PHP / Framework Patterns

### PHP 8.4 Constructor Property Promotion

All services, security classes, normalizers, and clients use constructor property promotion with `private readonly`:
```php
public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly CacheInterface      $cache,
    private readonly string              $alteredCoreUrl,
) {}
```
Never use the old `private $prop;` + `$this->prop = $prop` pattern.

### `readonly` by default on services

All constructor-injected dependencies are `readonly`. Value objects (entities) use mutable properties since Doctrine needs setters.

### `final` keyword

Services may be `final` but it is not uniformly applied (e.g. `KeycloakAuthenticator` extends `AbstractAuthenticator` so cannot be final). Apply `final` when there is no inheritance need.

### Enums

Not yet used — format codes (`'standard'`, `'nuc'`, `'singleton'`) are plain strings. The convention is to use PHP enums for fixed value sets; this is a gap to fill when refactoring.

### `match` expressions

Preferred over `switch` for exhaustive value mapping:
```php
return match ($rarity) {
    'U'          => 'U',
    'R1', 'R2'   => 'R',
    'E', 'EXALT' => 'E',
    default      => 'C',
};
```

### `\DateTimeImmutable`

Used for all timestamps, never `DateTime`.

### UUID IDs

Entities use `Symfony\Component\Uid\Uuid` with Doctrine's custom UUID generator strategy:
```php
#[ORM\Column(type: 'uuid', unique: true)]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
private ?Uuid $id = null;
```
Exception: `DeckCard` uses plain auto-increment `int` ID.

### `__invoke()` on single-action controllers

Controllers that handle a single route use `__invoke()`: `FormatController`, `DevAuthController`.

## API Platform Configuration — Attributes Only

All API Platform configuration lives on the entity via PHP attributes. Never use YAML for API Platform config.

```php
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['deck:read']],
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 1000,
            provider: DeckCollectionProvider::class,
        ),
        new Patch(
            normalizationContext:   ['groups' => ['deck:read']],
            denormalizationContext: ['groups' => ['deck:write']],
            processor: DeckStateProcessor::class,
        ),
    ],
    paginationItemsPerPage: 20,
)]
#[ApiFilter(SearchFilter::class, properties: ['format' => 'exact'])]
class Deck { ... }
```

Real example: `src/Entity/Deck.php` lines 27–58.

## Serialization Groups Naming

Convention: `entity:context`

| Group | Purpose |
|-------|---------|
| `deck:read` | Scalar fields — collection and base read |
| `deck:read:detail` | Extended detail — includes `deckCards` (single GET only) |
| `deck:write` | Denormalization (input) |

Groups are declared with `#[Groups([...])]` on entity properties, NOT in YAML.

**Note:** This codebase does not use the `entity:list` / `entity:read` split documented in `CLAUDE.md`. The `GetCollection` uses `deck:read` (same as single `Get`), relying on `DeckCollectionNormalizer` for the envelope shape instead of a lightweight group. When adding new resources, follow the `entity:list` / `entity:read` pattern.

## Format Convention — `application/json` Only

The project declares only the `json` format in `config/packages/api_platform.yaml`. Never add `jsonld`.

Collection responses use `data` + `pagination` + `links` keys (not `member` / `totalItems` / `hydra:member`). This is produced by `src/Serializer/DeckCollectionNormalizer.php`.

**Response shape:**
```json
{
    "data": [...],
    "pagination": { "totalItems": N, "itemsPerPage": N, "currentPage": N, "lastPage": N },
    "links": { "first": "...", "last": "...", "previous": null, "next": "..." }
}
```

Note: `CLAUDE.md` documents `member` / `totalItems` keys (from altered-core, a separate service). This decks API uses a custom normalizer producing `data` / `pagination` / `links`. Always check `src/Serializer/DeckCollectionNormalizer.php` as the source of truth.

## Thin Controllers

Controllers only orchestrate: validate input, call repository/service, return response. No query building, no business logic, no Doctrine calls in controllers.

Business logic belongs in:
- State Providers (`src/State/`) — query building and access control
- State Processors (`src/State/`) — write operations, validation orchestration
- Validators (`src/Validator/`) — format validation rules
- Clients (`src/Client/`) — external API calls

## State Providers and Processors

**Providers** implement `ProviderInterface` and handle read operations:
```php
class DeckCollectionProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable
    { ... }
}
```

**Processors** implement `ProcessorInterface` and handle write operations (Post, Patch, Delete).

Real example: `src/State/DeckStateProcessor.php` — orchestrates user assignment, card fetching, format validation, stat computation, and persistence.

**Do not build queries directly in providers** — delegate to repositories.

## Repository Pattern

All DQL/SQL queries live in `src/Repository/` classes extending `ServiceEntityRepository`. Controllers and providers call repository methods, never inline queries.

```php
// correct — query in repository
class UserRepository extends ServiceEntityRepository
{
    public function findByKeycloakId(string $keycloakId): ?User
    {
        return $this->findOneBy(['keycloakId' => $keycloakId]);
    }
}
```

**Note:** `DeckCollectionProvider` builds a `QueryBuilder` directly rather than delegating to `DeckRepository`. This violates the repository rule and should be corrected (see `CONCERNS.md`).

## Validator Pattern (Format Rules)

Strategy + Template Method:
- Interface: `src/Validator/Format/DeckFormatValidatorInterface.php`
- Abstract base: `src/Validator/Format/AbstractDeckFormatValidator.php` — shared validations (hero, deck size, faction, suspended/banned cards)
- Concrete validators: `StandardFormatValidator`, `NucFormatValidator`, `SingletonFormatValidator`
- Factory: `src/Validator/Format/DeckFormatValidatorFactory.php` — collects validators via `!tagged_iterator app.deck_format_validator`

When adding a new format:
1. Create `src/Validator/Format/NewFormatValidator.php` extending `AbstractDeckFormatValidator`
2. Implement `getFormat()`, `getMinCards()`, `getMaxCards()`, `validateFormatRules()`
3. The `_instanceof` block in `config/services.yaml` auto-tags it — no manual wiring needed

## Custom Normalizer Pattern

Normalizers use the "already called" guard to prevent infinite recursion:

```php
class DeckNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'DECK_NORMALIZER_ALREADY_CALLED';

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Deck
            && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;
        $data = $this->normalizer->normalize($object, $format, $context); // delegate
        // enrich $data...
        return $data;
    }
}
```

Real examples: `src/Serializer/DeckNormalizer.php`, `src/Serializer/DeckCollectionNormalizer.php`

## Service Wiring

- `autowire: true` and `autoconfigure: true` are global defaults in `config/services.yaml`
- Scalar constructor arguments (env vars) are wired explicitly in `services.yaml`:
  ```yaml
  App\OpenApi\OpenApiFactory:
      decorates: api_platform.openapi.factory
      arguments:
          $appEnv: '%kernel.environment%'
  ```
- Tagged iterators for plugin-style collections: `!tagged_iterator app.deck_format_validator`
- `_instanceof` block auto-tags all `DeckFormatValidatorInterface` implementations

## Logging

**Framework:** PSR-3 `LoggerInterface` (injected via constructor)

**Pattern:** Log errors at I/O boundaries with context array:
```php
$this->logger->error('AlteredCoreClient::getCardsByReferences failed', [
    'error'      => $e->getMessage(),
    'references' => $references,
]);
```

**Known issue:** `src/Security/KeycloakAuthenticator.php` line 106 contains a `error_log()` debug call left from development — remove it (see `CONCERNS.md`).

## Caching

Use `Symfony\Contracts\Cache\CacheInterface` with `$cache->get(key, closure)` pattern. TTL set via `$item->expiresAfter(seconds)` inside the closure:

```php
$this->cache->get('keycloak_jwks', function (ItemInterface $item) {
    $item->expiresAfter(3600);
    return $this->httpClient->request('GET', $this->jwksUrl)->toArray();
});
```

Real examples:
- `src/Security/KeycloakAuthenticator.php` — caches JWKS for 3600s
- `src/Client/AlteredCoreClient.php` — caches card data per reference+locale for 3600s

## Import Organization

**Order (as observed in this codebase):**
1. `ApiPlatform\*` — API Platform metadata and filters
2. `App\*` — internal classes (Repository, State, Entity, Validator, Client)
3. `Doctrine\*` — ORM mapping
4. `Symfony\*` — Symfony components
5. `Psr\*` — PSR interfaces
6. `Twig\*` — Twig components

No path aliases — uses PSR-4 autoloading with `App\` namespace root mapping to `src/`.

## Error Handling

- Throw `ApiPlatform\Validator\Exception\ValidationException` with a `ConstraintViolationList` to return 422 from processors
- Throw `Symfony\Component\Security\Core\Exception\AuthenticationException` from authenticators for 401
- Catch `\Throwable` at I/O boundaries (HTTP client calls) and log via `LoggerInterface`, returning safe fallback (`[]`)
- Use `JsonResponse` directly for authentication failure responses in security classes

## Comments & Documentation

**PHPDoc blocks** are used selectively — only on non-obvious methods or those with complex return types:
- `@return array<string, array>` on methods returning typed arrays
- `@param iterable<DeckFormatValidatorInterface>` on factory constructor

**Class-level docblocks:** Used on validator classes to summarize format rules.

**Inline comments:**
- Used for section separators: `// ── Helpers ────...`
- Used to explain non-obvious logic: dev auth detection, rarity parsing from card reference

**`@var` inline hints** used in processors/providers to hint type after security `getUser()` calls:
```php
/** @var Deck $data */
/** @var User $user */
```

No `@author`, `@since`, `@copyright` tags — no file-level headers.

---

*Convention analysis: 2026-05-12*
