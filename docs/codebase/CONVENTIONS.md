# Code Conventions

**Analysis Date:** 2026-05-12

## Naming Patterns

| Type | Convention | Examples |
|------|-----------|---------|
| Classes | PascalCase | `DeckStateProcessor`, `KeycloakJwtDecoder` |
| Controllers | `*Controller` | `FormatController`, `HomepageController` |
| Repositories | `*Repository` | `DeckRepository`, `UserRepository` |
| State providers | `*Provider` | `DeckCollectionProvider` |
| State processors | `*Processor` | `DeckStateProcessor` |
| Normalizers | `*Normalizer` | `DeckNormalizer`, `DeckCollectionNormalizer` |
| Validators | `*Validator` / `*ValidatorFactory` | `StandardFormatValidator`, `DeckFormatValidatorFactory` |
| Interfaces | `*Interface` | `DeckFormatValidatorInterface` |
| Abstract classes | `Abstract*` | `AbstractDeckFormatValidator` |
| Clients | `*Client` | `AlteredCoreClient` |
| Methods | camelCase | `isDraft()`, `setName()`, `supportsNormalization()` |
| Boolean getters | `is*()` not `getIs*()` | `isDraft()`, `isPublic()`, `isHero()` |
| Setters | return `self` for fluency | `setName(string $name): self` |
| Constants | `SCREAMING_SNAKE_CASE` | `ALREADY_CALLED`, `UNIQUE_LIMITS` |
| Directories | PascalCase | `Entity/`, `State/`, `Validator/Format/` |

Files: one class per file, filename matches class name exactly, no closing `?>` tag.

## PHP 8.4 Style

### Constructor property promotion — always

```php
// correct
final readonly class KeycloakJwtDecoder
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface      $cache,
        private string              $jwksUrl,
    ) {}
}

// wrong — never
class KeycloakJwtDecoder
{
    private HttpClientInterface $httpClient;
    public function __construct(HttpClientInterface $httpClient) {
        $this->httpClient = $httpClient;
    }
}
```

All constructor-injected dependencies are `readonly`. Entities use mutable properties (Doctrine needs setters).

### `match` over `switch`

```php
return match ($rarity) {
    'U'          => 'U',
    'R1', 'R2'   => 'R',
    'E', 'EXALT' => 'E',
    default      => 'C',
};
```

### Enums — current gap

Format codes (`'standard'`, `'nuc'`, `'singleton'`) are still plain strings. Use PHP enums for fixed value sets when refactoring.

### Other type rules

- `\DateTimeImmutable` for all timestamps, never `DateTime`
- Return types always declared; nullable as `?Type`; `mixed` only when genuinely variable
- `??` preferred over `isset()` for optional values
- `sprintf()` for dynamic message formatting, never string concatenation

### UUID IDs

```php
#[ORM\Column(type: 'uuid', unique: true)]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
private ?Uuid $id = null;
```

Exception: `DeckCard` uses plain auto-increment `int` ID.

### Single-action controllers

Controllers handling a single route use `__invoke()`: `FormatController`, `DevAuthController`.

## Code Style

- 4-space indentation, no tabs
- Trailing commas on multi-line arrays and constructor lists
- Column-align constructor injection properties when type lengths differ
- One-liner getters/setters on a single line: `public function getId(): ?Uuid { return $this->id; }`
- One blank line between methods; no blank line after `{` or before `}`

## API Platform Configuration — Attributes Only

All API Platform config (operations, filters, pagination, groups) lives on the entity via PHP attributes. Never use YAML for API Platform config.

```php
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['deck:read']],
            paginationClientItemsPerPage: true,
            paginationMaximumItemsPerPage: 1000,
            provider: DeckCollectionProvider::class,
        ),
    ],
)]
#[ApiFilter(SearchFilter::class, properties: ['format' => 'exact'])]
class Deck { ... }
```

Real example: `src/Entity/Deck.php` lines 27–58.

## Serialization Groups

Convention: `entity:context`. Groups declared with `#[Groups([...])]` on entity properties, not in YAML.

| Group | Purpose |
|-------|---------|
| `deck:read` | Scalar fields — collection and base read |
| `deck:read:detail` | Extended detail — includes `deckCards` (single GET only) |
| `deck:write` | Denormalization (input) |

Note: `GetCollection` uses `deck:read` (same as single `Get`), relying on `DeckCollectionNormalizer` for the envelope shape. When adding new resources, prefer the `entity:list` / `entity:read` split.

## Format Convention — `application/json` Only

The project declares only the `json` format in `config/packages/api_platform.yaml`. Never add `jsonld`.

Collection responses use `data` + `pagination` + `links` (not `member` / `hydra:member`). Source of truth: `src/Serializer/DeckCollectionNormalizer.php`.

```json
{
    "data": [...],
    "pagination": { "totalItems": N, "itemsPerPage": N, "currentPage": N, "lastPage": N },
    "links": { "first": "...", "last": "...", "previous": null, "next": "..." }
}
```

## Thin Controllers

Controllers only orchestrate: validate input, call repository/service, return response. Business logic belongs in:

- State Providers (`src/State/`) — query building and access control
- State Processors (`src/State/`) — write operations, validation orchestration
- Validators (`src/Validator/`) — format validation rules
- Clients (`src/Client/`) — external API calls

## Repository Pattern

All DQL/SQL queries live in `src/Repository/`. Controllers and providers call repository methods, never inline queries. Raw SQL via `$this->getEntityManager()->getConnection()` is acceptable for complex queries (jsonb, window functions).

```php
class UserRepository extends ServiceEntityRepository
{
    public function findByKeycloakId(string $keycloakId): ?User
    {
        return $this->findOneBy(['keycloakId' => $keycloakId]);
    }
}
```

Known violation: `src/State/DeckCollectionProvider.php` builds a `QueryBuilder` directly — should be delegated to `DeckRepository` (see `CONCERNS.md`).

## Validator Pattern

Strategy + Template Method under `src/Validator/Format/`:

- Interface: `DeckFormatValidatorInterface.php`
- Abstract base: `AbstractDeckFormatValidator.php` — shared validations (hero, size, faction, banned cards)
- Concrete: `StandardFormatValidator`, `NucFormatValidator`, `SingletonFormatValidator`
- Factory: `DeckFormatValidatorFactory.php` — collects via `!tagged_iterator app.deck_format_validator`

Adding a new format: create `NewFormatValidator` extending `AbstractDeckFormatValidator`, implement `getFormat()`, `getMinCards()`, `getMaxCards()`, `validateFormatRules()`. The `_instanceof` block in `config/services.yaml` auto-tags it.

## Normalizer Pattern

Normalizers use the "already called" guard to prevent infinite recursion:

```php
class DeckNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;
    private const ALREADY_CALLED = 'DECK_NORMALIZER_ALREADY_CALLED';

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Deck && !($context[self::ALREADY_CALLED] ?? false);
    }

    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;
        $data = $this->normalizer->normalize($object, $format, $context);
        // enrich $data...
        return $data;
    }
}
```

Real examples: `src/Serializer/DeckNormalizer.php`, `src/Serializer/DeckCollectionNormalizer.php`.

## Error Handling

- Throw `ValidationException` with `ConstraintViolationList` to return 422 from processors
- Throw `AuthenticationException` from authenticators for 401
- Catch `\Throwable` at I/O boundaries (HTTP calls), log via `LoggerInterface`, return safe fallback (`[]`)

## Logging & Caching

**Logging:** PSR-3 `LoggerInterface` injected via constructor. Log at I/O boundaries with a context array: `$this->logger->error('msg', ['error' => $e->getMessage()])`.

**Caching:** `CacheInterface::get(key, closure)` with `$item->expiresAfter(seconds)`. See `src/Security/KeycloakJwtDecoder.php` (JWKS, 3600s) and `src/Client/AlteredCoreClient.php` (card data, 3600s).

## Comments

- PHPDoc selectively: `@return array<string, array>` on complex return types, `@param iterable<T>` on factory constructors
- `@var` inline hints after `getUser()` calls: `/** @var User $user */`
- Section separators: `// ── Helpers ───...`
- No `@author`, `@since`, `@copyright` tags

---

*Convention analysis: 2026-05-12*
