# Changelog

All notable changes to this extension are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[semantic versioning](https://semver.org/).

## [1.1.0] — 2026-08-24

### Added

- Verified bridge to `salvocortesiano/meilisearch` 1.6, written against that
  extension's actual source. Searches opening posts only, honours both
  extensions' forum exclusions, and maps post ids to topics in SQL because the
  index does not return `topic_id`.
- Verified bridge to `salvocortesiano/aireply` 1.0.8. Borrows provider, model,
  endpoint and key from a bot the administrator names, so no second copy of an
  API key is stored. Asks for a JSON verdict and parses it strictly.
- `fh_ai_bot_id` setting naming the AI Reply bot to borrow credentials from.
- `post_repository::topic_ids_for_posts()`.
- Unit tests for both bridges' parsing, including prose-only and truncated model
  output, malformed search hits, and the failure classifier.

### Fixed

- **Provider discovery by service tag never worked.** It used
  `$container->findTaggedServiceIds()`, which does not exist on phpBB's dumped
  runtime container. Guarded by `method_exists`, it failed silently rather than
  fatally, so the feature was dead on every board while appearing correct.
  Replaced with two compile-time `phpbb\di\service_collection` instances.

### Changed

- A Meilisearch query rejected because the index settings were never applied is
  now logged distinctly from an unreachable server. The two look identical from
  the outside and need opposite responses.
- The "installed, not connected" advice now points at the built-in bridges for
  these two extensions instead of telling every administrator to write one.

## [1.0.0] — 2026-01-01

First release.

### Added

- Content health analysis: unanswered discussions, duplicate detection, link
  scanning, freshness signals, solution detection.
- Community health analysis: participation, response times, new member
  experience, newcomer retention, contributor observations.
- Alert queue with aggregated findings, triage, and automatic resolution when a
  finding stops being true.
- Priority-ordered recommendations.
- Administrator-defined rules built from fixed whitelists, with no expression
  parser and no code entry.
- Transparent health indicators: every factor exposes its score, its weight and
  the figures behind it; a weight of zero removes a factor entirely.
- Optional search integration through a provider interface and a bridge service.
- Optional AI integration through a provider interface, with four independent
  gates and a content-hash cache.
- Five background jobs with locking, cursors and bounded batches.
- Two optional public features, both off by default: a duplicate hint while
  composing, and related discussions under a topic.
- Seven granular administrative permissions.
- Full English and Italian translations, verified for key parity and placeholder
  consistency by an automated test.
- SSRF protection covering 14 IPv4 and 7 IPv6 ranges, with per-hop redirect
  revalidation.

### Notes

- Link scanning, AI analysis, the search integration and both public features are
  disabled after installation. Nothing contacts a server outside the forum, and
  nothing changes what members see, until an administrator decides.
- Private messages are never read. No code in this extension queries them.
