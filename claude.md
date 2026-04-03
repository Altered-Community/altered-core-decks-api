# CLAUDE.md — Altered Core

## Stack

- PHP 8.4 · Symfony 8 · Doctrine ORM · PostgreSQL
- API Platform 3 — format `application/json` only (no jsonld)
- Twig templates + Tailwind CSS (CDN)
- Tom Select for multi-select inputs

---

## Rules for Claude

### PHP 8.4 style — always

- Constructor property promotion + `readonly` by default on services and value objects
- Named arguments for clarity on multi-param calls
- Enums over class constants when the set of values is fixed
- Never write old-style `private $prop; public function __construct($prop) { $this->prop = $prop; }`

```php
// correct
final readonly class CardBatchController
{
    public function __construct(
        private CardRepository $cardRepository,
        private SerializerInterface $serializer,
    ) {}
}

// wrong
class CardBatchController
{
    private CardRepository $cardRepository;
    public function __construct(CardRepository $repo) {
        $this->cardRepository = $repo;
    }
}
```

### API Platform config — attributes only, never YAML

All API Platform configuration (operations, groups, filters, cache headers, pagination) goes on the entity via PHP attributes. Never add API Platform config to YAML files.

### Serialization groups naming

Convention: `entity:context` — always define both groups when adding a new API resource:

| Group         | Purpose                          |
|---------------|----------------------------------|
| `entity:list` | Lightweight — used in collection |
| `entity:read` | Full detail — used in single GET |

### Thin controllers

Controllers only orchestrate: validate input, call repository/service, return response.
No query building, no business logic, no Doctrine calls in controllers.

### Format is `application/json` only

The project declares only the `json` format in `api_platform.yaml`. Never add `jsonld`.
This is intentional and is why responses use `member` / `totalItems` (not `hydra:member`).

### Non-CRUD endpoints — plain Symfony controllers

For endpoints that don't map to standard CRUD operations (batch, stats, custom actions), use a plain Symfony controller with `#[Route]`, not a custom API Platform operation.

### Reuse joins in filters

Before creating a new join in a filter, check for an existing one with a `getOrJoin*()` helper. Never duplicate joins on the same association in the same query.

---

## API Platform conventions

### Paginated collection responses

Paginated endpoints return a collection. Always use `member` (not `data`, not `hydra:member`) to access items:

```javascript
// correct
const items = data['member'] ?? [];
const total = data['totalItems'] ?? 0;

// wrong
const items = data['hydra:member'] ?? [];
const items = data.data ?? [];
```

### Serialization groups

| Context     | Group        | Usage                                  |
|-------------|--------------|----------------------------------------|
| Collection  | `card:list`  | Lightweight — name, ref, faction badge |
| Single item | `card:read`  | Full detail including translations     |
| Batch POST  | `card:read`  | `/api/cards/batch` returns full detail |

### Pagination config

Both places required for `itemsPerPage` to be respected:

```yaml
# config/packages/api_platform.yaml
api_platform:
    defaults:
        pagination_client_items_per_page: true
        pagination_maximum_items_per_page: 1000
```

```php
// Entity attribute
new GetCollection(
    paginationClientItemsPerPage: true,
    paginationMaximumItemsPerPage: 1000,
)
```

### Batch endpoint for large reference arrays

When filtering by a list of references, use POST to avoid URL length limits:

```
POST /api/cards/batch
Content-Type: application/json

{ "references": ["ALT_CORE_B_AX_1_C", ...] }   // max 200
```

Returns a flat JSON array (no Hydra wrapper, no pagination).

---

## Doctrine conventions

### EAGER fetch and WITH condition restriction

Associations with `fetch: 'EAGER'` cannot use `WITH` conditions in `leftJoin()` / `join()`.
**Doctrine throws**: *"Associations with fetch-mode=EAGER may not be using WITH conditions"*

Fix: use a correlated EXISTS subquery instead of a WITH join:

```php
// wrong — crashes with EAGER
$qb->leftJoin("$cgAlias.translations", $tAlias, 'WITH', "$tAlias.locale = :loc");

// correct — correlated EXISTS subquery
$subDql = sprintf(
    'SELECT 1 FROM %s %s WHERE %s.cardGroup = %s AND %s.locale = :%s AND LOWER(%s.name) LIKE :%s',
    CardGroupTranslation::class, $tAlias,
    $tAlias, $cgAlias,
    $tAlias, $pLoc,
    $tAlias, $pName,
);
$qb->andWhere($qb->expr()->exists($subDql));
```

### N+1 on OneToMany collections

Add `fetch: 'EAGER'` to batch-load a collection in the same query:

```php
#[ORM\OneToMany(targetEntity: CardTranslation::class, mappedBy: 'card', fetch: 'EAGER')]
private Collection $translations;
```

Use `paginationFetchJoinCollection: false` on the GetCollection operation when EAGER collections are present, to avoid Doctrine count query issues.

---

## Repository conventions

All SQL / DQL queries live in Repository classes — never inline in controllers, providers, or filters.

```php
// correct — query in repository
class CardRepository extends ServiceEntityRepository
{
    public function findByReferences(array $references): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.reference IN (:refs)')
            ->setParameter('refs', $references)
            ->getQuery()
            ->getResult();
    }
}

// wrong — query outside repository
$cards = $em->createQueryBuilder()->from(Card::class, 'c')...->getResult();
```

Raw SQL (via `$this->getEntityManager()->getConnection()`) is acceptable in repositories for complex queries that DQL cannot express cleanly (e.g. jsonb operations, window functions).

Controllers and State Providers call repository methods — they do not build queries directly.

---

## Custom filters (API Platform AbstractFilter)

### Property array — indexed vs keyed

`#[ApiFilter(..., properties: ['field1', 'field2'])]` stores as **indexed** array `[0 => 'field1']`.
`#[ApiFilter(..., properties: ['field1' => 'ASC'])]` stores as **keyed** array `['field1' => 'ASC']`.

Always handle both:

```php
foreach ($this->properties ?? [] as $key => $prop) {
    $property = is_string($key) ? $key : (string) $prop;
    // ...
}
```

### Range operators on numeric fields

Pass `fieldName[gte]=5` in the query string. The value arrives as `['gte' => '5']`.
Handle in `filterProperty()`:

```php
if (is_array($value)) {
    $ops = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
    foreach ($ops as $key => $op) {
        if (isset($value[$key]) && $value[$key] !== '') {
            $p = $queryNameGenerator->generateParameterName($field . '_' . $key);
            $qb->andWhere("$alias.$field $op :$p")
               ->setParameter($p, (int) $value[$key]);
        }
    }
    return;
}
```

### Custom order filter through a joined entity

When ordering by a field on a related entity (e.g. `CardGroup` from the `Card` endpoint), override `apply()` and read `$context['filters']['order']` directly — the parent `apply()` doesn't know about the join.

---

## Entity structure (Altered domain)

```
Card  (one API resource per print)
 └─ cardGroup: CardGroup  (logical card — shared stats, type, faction)
     ├─ faction: Faction
     ├─ cardType: CardType
     ├─ subTypes: CardSubType[]
     ├─ translations: CardGroupTranslation[]   (locale → name)
     ├─ mainCost, recallCost
     └─ oceanPower, mountainPower, forestPower

Card
 ├─ set: Set
 ├─ rarity: Rarity
 ├─ reference: string  (unique, e.g. ALT_CORE_B_AX_1_C)
 └─ translations: CardTranslation[]  (locale → name, EAGER)
```

Filters on Card endpoint that target CardGroup fields go through `CardGroupAliasFilter` / `CardGroupOrderFilter`, which create (or reuse) a single `cardGroup` join.
---

## Response shape reference

```
GET  /api/cards                → { member: Card[], totalItems: N, view: {...} }
GET  /api/cards/{id}           → Card (card:read)
GET  /api/cards/reference/{r}  → Card (card:read)
POST /api/cards/batch          → Card[]  (flat array, card:read, max 200)
```
