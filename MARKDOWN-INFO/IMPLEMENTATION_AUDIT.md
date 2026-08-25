# Implementation audit

This document records what was verified, what was found, and what changed as a
result. It was originally written to explain a constraint that could not be met;
that constraint has since been lifted, and it now records the inspection that
replaced the guesswork.

## Status: resolved

§4.2 required inspecting the real Meilisearch and AI Bots repositories rather
than inventing an integration surface. **Both were subsequently supplied and have
now been read in full:**

- `salvocortesiano/meilisearch` 1.6.0 — 34 files
- `salvocortesiano/aireply` 1.0.8-dev — 82 files

Two verified bridges now ship inside Forum Health. No administrator has to write
one for these two extensions. The provider interfaces remain, so any other search
or AI extension can still be connected the documented way.

## What the inspection changed

Reading the real source produced five concrete corrections. Each would have been
a silent failure in a bridge written from assumption.

### 1. Meilisearch does not return topic ids

The index sets `displayedAttributes` to `['post_id']`. `topic_id` is filterable
but not displayable, so asking for it back returns nothing — not an error, just an
absent field.

A bridge written on the reasonable assumption that a search for topics returns
topic ids would have produced an empty result on every call, with no error
anywhere. Forum Health now maps post ids to topic ids in SQL
(`post_repository::topic_ids_for_posts`), preserving the engine's relevance order.

### 2. The client returns `false`, it does not throw

`client::request()` returns `false` on any failure and exposes the reason through
`last_error()`. A bridge relying on `try`/`catch` would have treated every failure
as success and then read `['hits']` on a boolean.

### 3. Older servers reject two of the options worth sending

`locales` needs Meilisearch 1.10; `showRankingScore` needs a recent build. Both
come back as a flat failure. The extension's own backend already retries without
`locales`, and the bridge follows that pattern, dropping both and accepting a
slightly worse result rather than none.

### 4. AI Reply generates prose, not verdicts

Its providers take a system prompt and a conversation and return text. Forum
Health needs a confidence figure and a reason. The bridge therefore asks for a
single JSON object and parses it strictly: anything unparseable becomes `null`,
and the adapter falls back to native analysis. Salvaging a number out of prose
would produce a confident-looking result with nothing behind it.

### 5. Some models reject `temperature` outright

OpenAI's reasoning models answer a `temperature` parameter with HTTP 400. AI
Reply exposes `provider::supports_temperature($model)` precisely for this, and its
own ACP uses it to disable the field. The bridge calls it before setting the
parameter. Sending it blindly would have turned a working configuration into a
hard failure on the first request.

## A bug this work exposed in Forum Health itself

Tag-based provider discovery was written as:

```php
$this->container->findTaggedServiceIds($tag);
```

guarded by `method_exists`. phpBB's runtime container is a **dumped `Container`,
not a `ContainerBuilder`**, and has no such method. The guard meant the code
failed silently rather than fatally: discovery returned an empty array on every
board, always. The feature was dead in production while looking correct in review.

AI Reply gets this right, and reading it is how the defect surfaced. It collects
its providers with a `service_collection` at compile time:

```yaml
salvocortesiano.aireply.provider_collection:
    class: phpbb\di\service_collection
    arguments: ['@service_container']
    tags:
        - { name: service_collection, tag: aireply.provider }
```

Forum Health now does the same, with two collections injected into the registry.

## Why the bridges must not be wired in services.yml

Both bridges take `@service_container` and resolve the other extension's services
at runtime through `has()` and `get()`.

This resembles the service-locator anti-pattern and is a deliberate exception. A
declared argument such as `@salvocortesiano.meilisearch.indexer` is a
**compile-time** reference. On a board where that extension is not installed, the
container fails to build — which does not disable a feature, it takes the entire
forum offline.

The alternative, shipping each bridge as its own extension, returns the burden to
the administrator for no benefit. Runtime resolution with an explicit `has()`
check and a `method_exists()` sanity check on what comes back is the right trade.

## Verification performed

| Check | Result |
| --- | --- |
| Third-party service ids named by a bridge exist in that extension's services.yml | 5/5 |
| Third-party classes and methods named by a bridge exist in its source | 11/11 |
| No compile-time third-party reference in config/services.yml arguments | none |
| Bridges reach third-party services only through guarded container access | both |
| Meilisearch response parsing against real response shapes | 5 cases pass |
| AI output parsing, including prose-only and truncated JSON | 9 cases pass |
| `php -l` across the extension | 73/73 |
| Service wiring: classes, references, parameters, constructor arity | clean |
| EN/IT parity | 528 = 528, placeholders match |

## Still not verified

These need a running phpBB and either a live Meilisearch server or real AI
credentials:

- Migrations executed against MySQL, PostgreSQL and SQLite.
- A real Meilisearch query against a real index.
- A real generation call through AI Reply to OpenAI or Gemini.
- ACP template rendering in a browser.

The bridges' pure logic — response parsing, score conversion, deduplication, JSON
extraction, payload validation — is covered by unit tests needing none of the
above. What remains unverified is transport, not logic.
