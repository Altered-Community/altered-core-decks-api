# Altered Core Decks API

Used by users to CRUD their decks and manage access to them.

## Install

To install with docker go here: [Install with docker](docs/install-with-docker.md)

## Public deck listing

`GET /api/decks/public` lists decks that are public and not drafts. No authentication is required; when a
bearer token is sent, each deck carries `hasUpvoted` for that user.

| Query param     | Example                          | Effect                                                                                     |
|-----------------|----------------------------------|--------------------------------------------------------------------------------------------|
| `page`          | `2`                              | 1-based page number (default `1`)                                                          |
| `itemsPerPage`  | `24`                             | Page size, 1 to 1000 (default `30`)                                                        |
| `hero`          | `ALT_CORE_B_AX_1_C`              | Decks led by this hero, in any set or rarity                                               |
| `faction`       | `AX`                             | Decks whose hero belongs to this faction (uppercase code)                                  |
| `name`          | `aggro`                          | Deck name contains this text (case-sensitive)                                              |
| `cardName`      | `morgane`                        | Deck contains a card whose name contains this text (case-insensitive)                      |
| `cardReference` | `ALT_CORE_B_AX_4_C`              | Deck contains this exact card reference                                                    |
| `format`        | `standard`                       | Deck format code                                                                           |
| `legal`         | `true`                           | `true`/`1`: legal decks only. `false`/`0`: illegal decks only. Absent or any other value: no filter |
| `order[field]`  | `order[upvoteCount]=desc`        | Sort by `name`, `createdAt`, `updatedAt`, `upvoteCount` or `viewCount` (default `order[createdAt]=desc`) |

Filters combine with AND. `totalItems`, `lastPage`, `nextPage` and `previousPage` are computed with the same
filters as `member`. Example (illustrative values):

```
GET /api/decks/public?legal=true&faction=AX&itemsPerPage=24&order[upvoteCount]=desc

{ "member": [...], "totalItems": 412, "currentPage": 1, "lastPage": 18, "nextPage": 2, "previousPage": null }
```
