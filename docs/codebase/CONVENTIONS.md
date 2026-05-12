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
- Boolean property getters use `is*()` / `isDraft()` for boolean state, NOT `getIsDraft()`
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
- PascalCase for subdirectories under `src/`: `Entity/`, `Controller/`, `Repository/`, `State/`, `Validator/Format/`, `Serializer/`, `Security/`, `Client/`

## Code Style

**Indentation:** 4 spaces (no tabs)

**Trailing commas:** Used on multi-line arrays and constructor argument lists — this is consistent throughout:
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

**Alignment:** Properties in constructor injection are column-aligned when there are multiple with different type lengths (observed in `DeckStateProcessor`, `KeycloakAuthenticator`).

**Single-line methods:** Simple one-liner getters/setters are written on one line:
```php
public function getId(): ?Uuid { return $this->id; }
public function getName(): string { return $this->name; }
public function setName(string $name): self { $this->name = $name; return $this; }
```

**Array formatting:** Multi-line arrays use trailing comma; alignment of `=>` used for readability in dense arrays (e.g., `FormatController`).

**Blank lines:** One blank line between method definitions inside a class. No blank line after opening `{` or before closing `}`.

**Null coalescing:** `??` preferred over `isset()` checks for optional values.

**Type declarations:** Return types always declared. Nullable types use `?Type`. `mixed` used only where the type is genuinely variable (API Platform interfaces).

**No closing PHP tag:** All PHP files omit the closing `?>`.

**`sprintf()` for message formatting:** All error/log messages with dynamic values use `sprintf()` — never string concatenation.

## PHP / Framework Patterns

**PHP 8.4 Constructor Property Promotion:**
All services, security classes, normalizers, and clients use constructor property promotion with `private readonly`:
```php
public function __construct(
    private readonly HttpClientInterface $httpClient,
    private readonly CacheInterface      $cache,
    private readonly string              $alteredCoreUrl,
) {}
```
Never use the old `private $prop;` + `$this->prop = $prop` pattern.

**`readonly` by default on services:**
All constructor-injected dependencies are `readonly`. Value objects (entities) use mutable properties since Doctrine needs setters.

**`final` not used:** No classes are marked `final` in the current codebase. Controllers extend `AbstractController`.

**Enums:** Not yet used (format codes like `'standard'`, `'nuc'`, `'singleton'` are plain strings). The CLAUDE.md mandates enums over class constants for fixed value sets — this is a gap to fill.

**`match` expressions:** Used for exhaustive value mapping in `getRarityFromReference()`:
```php
return match ($rarity) {
    'U'          => 'U',
    'R1', 'R2'   => 'R',
    'E', 'EXALT' => 'E',
    default      => 'C',
};
```

**Interfaces for extensibility:** The format validator system uses `DeckFormatValidatorInterface` + abstract base + concrete implementations. New formats are added by implementing the interface and the Symfony DI container auto-tags and injects them via `!tagged_iterator`.

**Service tagging pattern** (`config/services.yaml`):
```yaml
App\Validator\Format\DeckFormatValidatorFactory:
    arguments:
        - !tagged_iterator app.deck_format_validator

_instanceof:
    App\Validator\Format\DeckFormatValidatorInterface:
        tags: ['app.deck_format_validator']
```

**`__invoke()` on single-action controllers:**
Controllers that handle a single route use `__invoke()`: `FormatController`, `DevAuthController`.

**Strict null handling:** `?` nullables declared everywhere; `?? null` and `?? []` defaults used extensively.

**`\DateTimeImmutable`** used for all timestamps, never `DateTime`.

**UUID IDs:** Entities use `Symfony\Component\Uid\Uuid` with Doctrine's custom UUID generator strategy:
```php
#[ORM\Column(type: 'uuid', unique: true)]
#[ORM\GeneratedValue(strategy: 'CUSTOM')]
#[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
private ?Uuid $id = null;
```
Exception: `DeckCard` uses plain auto-increment `int` ID.

**Symfony Validator Constraints as attributes:**
Validation is declared directly on entity properties using `#[Assert\...]` attributes — never in separate validation config files.

## API / Serialization Conventions

**Serialization groups naming — `entity:context` pattern:**

| Group | Purpose |
|-------|---------|
| `deck:read` | Collection and base read — lightweight |
| `deck:read:detail` | Single GET — includes `deckCards` |
| `deck:write` | Input deserialization |

Groups are declared with `#[Groups([...])]` on entity properties, NOT in YAML.

**API Platform operations are attributes-only** on the entity class — never in YAML:
```php
#[ApiResource(
    operations: [
        new GetCollection(...),
        new Get(...),
        new Post(...),
        new Patch(...),
        new Delete(),
    ],
)]
```

**Format:** `application/json` only. No JSON-LD. No `hydra:member`. The project deliberately excludes `jsonld`.

**Collection response shape (DeckCollectionNormalizer):**
The collection endpoint returns a custom envelope — NOT the default API Platform shape:
```json
{
  "data": [...],
  "pagination": {
    "totalItems": N,
    "itemsPerPage": N,
    "currentPage": N,
    "lastPage": N
  },
  "links": {
    "first": "...",
    "last": "...",
    "previous": "...",
    "next": "..."
  }
}
```
Note: this uses `data` as the array key, not `member`. (CLAUDE.md specifies `member` — there is a discrepancy with the actual normalizer implementation.)

**Single deck response shape (DeckNormalizer):**
The `DeckNormalizer` enriches the response by fetching card data from `altered-core` and replacing `deckCards` with an enriched `cards` array containing name, faction, costs, powers, imagePath, and effects.

**Non-CRUD endpoints use plain Symfony controllers** with `#[Route]`:
- `GET /api/formats` → `FormatController`
- `POST /api/dev/auth` → `DevAuthController`

**Pagination settings declared in two places** (both required):
1. `config/packages/api_platform.yaml` — global defaults
2. `#[ApiResource(...)]` attribute on entity — per-resource overrides

**Filters declared as `#[ApiFilter]` attributes** on the entity, not YAML:
```php
#[ApiFilter(SearchFilter::class, properties: ['format' => 'exact', 'isPublic' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'name'])]
```

## Error Handling

**API Platform validation errors:**
Format validation errors are returned as `ConstraintViolation` objects wrapped in a `ConstraintViolationList` and thrown as `ValidationException`. API Platform handles the serialization to a 422 response automatically:
```php
$violations = new ConstraintViolationList();
foreach ($errors as $message) {
    $violations->add(new ConstraintViolation($message, $message, [], $deck, 'deckCards', null));
}
throw new ValidationException($violations);
```

**Authentication errors:**
`KeycloakAuthenticator::onAuthenticationFailure()` returns a plain `JsonResponse` with `{'error': '...'}` and HTTP 401.

**External service failures (AlteredCoreClient):**
Wrapped in try/catch `\Throwable`, logged via PSR-3 `LoggerInterface`, and the calling method gracefully returns an empty array — allowing the operation to continue in a degraded state (stats not computed, format not validated).

**HTTP errors in controllers:**
Symfony exceptions used (`NotFoundHttpException`) which API Platform maps to appropriate HTTP status codes.

**Validator return style:**
Format validators return `string[]` (list of error messages) — empty array = valid. No exceptions thrown from validators themselves.

## Comments & Documentation

**PHPDoc blocks** are used selectively — only on non-obvious methods or those with complex return types:
- `@return array<string, array>` on methods returning typed arrays
- `@param iterable<DeckFormatValidatorInterface>` on factory constructor
- `@return array{0: DeckCard|null, 1: DeckCard[]}` on tuple-returning methods

**Class-level docblocks:** Used on validator classes to summarize format rules (NUC, Standard, Singleton). Not used on entity or controller classes.

**Inline comments:**
- Used for section separators: `// ── Helpers ──────────────...`
- Used to explain non-obvious logic:
  - `// Check cache per reference`
  - `// Dev auth: accept local HS256 tokens (issuer = "dev"), controlled by DEV_AUTH_ENABLED`
  - `// Rarity is parsed from the card reference (parts[5]: C, R1, R2, U)`

**`@var` inline hints** used in processors/providers to hint type after security `getUser()` calls:
```php
/** @var Deck $data */
/** @var User $user */
```

**`// ── Section Name ──────...` pattern** used in `AbstractDeckFormatValidator` to visually group methods into sections within a file.

No `@author`, `@since`, `@copyright` tags — no file-level headers.

---

*Convention analysis: 2026-05-12*
