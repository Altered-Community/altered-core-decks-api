# Altered Core Decks API

Used by users to CRUD their decks and manage access to them.

## Install

To install with docker go here: [Install with docker](docs/install-with-docker.md)

## Deck authors

Public decks expose their author as `user.username`: the Keycloak `pseudo`
claim, never `preferred_username` (the realm uses the email as username).
It needs `pseudo` in the access token (mapper `pseudo` of the `profile` client
scope, "Add to access token" on) and the `profile` scope in the client's token.
`username` is updated on each authenticated request that carries `pseudo`.

To fill every author at once, without waiting for them to log in again:

```sh
php bin/console app:users:sync-pseudos --dry-run   # counts only
php bin/console app:users:sync-pseudos
```

The command reads the `pseudo` attribute through the Keycloak admin API with
the client credentials of `KEYCLOAK_CLIENT_ID` / `KEYCLOAK_CLIENT_SECRET`. That
client needs "Service accounts" enabled and the realm-management `view-users`
role. It prints counts only.
