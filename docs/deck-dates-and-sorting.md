# Deck dates and sorting

## Fields

| Field            | Null?                     | Set when                                  | Changed by upvote / view |
|------------------|---------------------------|-------------------------------------------|--------------------------|
| `createdAt`      | never                     | deck creation                             | no                       |
| `updatedAt`      | **until the first edit**  | every `PATCH /api/decks/{id}`             | no                       |
| `lastModifiedAt` | never                     | creation (= `createdAt`), then every edit (= `updatedAt`) | no           |

Invariant: `lastModifiedAt == updatedAt ?? createdAt`, always. Both are set by
`Deck::markModified()`, called from `DeckStateProcessor` on every write. Upvotes
(`POST /api/decks/{id}/upvote`) and views (`GET /api/decks/{id}` on a public deck) only
touch their counters. The `app:deck:check-set-legality` command recomputes legality
without moving either date, as before.

`lastModifiedAt` is read-only: it is in `deck:read`, never in `deck:write`, and a value
sent in a `POST` / `PATCH` body is ignored.

## Sorting

Use `order[lastModifiedAt]=asc|desc` to sort by "last activity". Prefer it over
`order[updatedAt]`: PostgreSQL puts `NULL`s first in a `DESC` sort, so with
`order[updatedAt]=desc` every never-edited deck, however old, lands on the first page.

| Route                    | `order[...]` fields                                                        | Default                              |
|--------------------------|----------------------------------------------------------------------------|--------------------------------------|
| `GET /api/decks/public`  | `lastModifiedAt`, `updatedAt`, `createdAt`, `name`, `upvoteCount`, `viewCount` | `createdAt` desc                 |
| `GET /api/decks`         | `lastModifiedAt` only (other keys are ignored, as before)                  | `updatedAt` desc (never-edited first) |

Every sort has `id` as a secondary key, in the same direction as the main key. Decks
with the same date (or count, or name) therefore always come back in the same order, so
walking `page=1..N` returns each matching deck exactly once.

```http
GET /api/decks/public?order[lastModifiedAt]=desc&itemsPerPage=30&page=2&format=standard
GET /api/decks?order[lastModifiedAt]=desc&faction=LY
```

`order[lastModifiedAt]` combines with every filter of each route (`hero`, `faction`,
`cardName`, `cardReference`, `name`, `format` on the public listing; `hero`, `faction`
on `/api/decks`).

## Storage

`deck.last_modified_at TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP`, added by
`Version20260926000000` and backfilled with `COALESCE(updated_at, created_at)`. The
public listing sort uses `idx_deck_public_last_modified (is_public, is_draft,
last_modified_at DESC, id DESC)`, which PostgreSQL scans forward for `desc` and
backward for `asc`. The same migration adds `id DESC` to `idx_deck_public_created`,
`idx_deck_public_upvote` and `idx_deck_public_view`, so the `id` tie-break is served by
the index too (no in-memory sort of large tie groups such as "0 upvotes"). The DB default only matters for rows inserted by code that doesn't
know the column (during a deploy); the application always writes the value.
