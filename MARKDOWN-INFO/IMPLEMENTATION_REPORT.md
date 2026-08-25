# Implementation report

Forum Health & Intelligence 1.1.0 for phpBB 3.3.

## What was built

An analysis extension that reads a forum's existing public content and reports
what deserves attention: unanswered discussions ranked by readership, possible
duplicates with their reasons, links that no longer resolve, content that may
have been overtaken by events, replies that look like solutions, and how the
community — particularly its newcomers — is actually doing.

72 files. 34 services. 9 tables. 9 repositories. 5 background jobs. 17 ACP pages.
535 language keys in each of two languages. One public route.

## Scale

| Component | Count |
| --- | --- |
| PHP files | 74 |
| ACP templates | 17 |
| Migrations | 5 |
| Repositories | 9 |
| Analysis services | 22 |
| Cron tasks | 5 (+ shared base) |
| Test files | 9 |
| Documentation files | 14 |
| Configuration settings | ~95 |
| Permissions | 7 |

## Verification performed

Everything below was run, not assumed.

| Check | Result |
| --- | --- |
| `php -l` on every PHP file (PHP 8.3) | 74/74 pass |
| Every service class in `services.yml` exists on disk | 34/34 |
| Every `@service` reference resolves | no unresolved references |
| Every `%parameter%` is declared or is a phpBB core parameter | no unknown parameters |
| Constructor arity matches YAML arguments | no mismatches |
| EN/IT key parity, ACP file | 528 = 528, none missing either direction |
| Third-party service ids named by a bridge exist in that extension | 5/5 |
| Third-party classes and methods named by a bridge exist | 11/11 |
| No compile-time third-party reference in services.yml arguments | none |
| Bridge parsing against real response shapes | 14 cases pass |
| EN/IT key parity, common file | 11 = 11 |
| printf placeholder counts match across languages | 0 mismatches |
| Dynamically-constructed language keys present in both languages | 107/107 |
| Every `tpl_name` has a template file | 17/17 |
| SSRF classifier against blocked addresses | 19/19 refused |
| SSRF classifier against public addresses | 6/6 allowed |

Four defects were found and fixed by these checks: a stray parenthesis in a
repository method signature, inconsistent quoting in an alert repository method,
a template attempting to iterate a plain PHP array, which phpBB's template engine
cannot do, and provider discovery calling `findTaggedServiceIds()` on a container
that has no such method at runtime.

The last two share a shape worth noticing: both failed silently. The template
would have rendered an empty reasons list with no error; the discovery call was
guarded by `method_exists` and so returned an empty array forever instead of
throwing. Neither would have been visible in review or in casual use.

## The specification constraint, and how it was resolved

§4.2 required inspecting the real Meilisearch and AI Bots repositories rather
than inventing an integration surface. They were not available for the first
build, so the dependency was inverted: two provider interfaces, resolved from a
service tag or an administrator-supplied ID, naming no third-party symbol at all.

**Both repositories were subsequently supplied and read in full.** Two verified
bridges now ship inside the extension, so administrators of those two extensions
write nothing. The interfaces remain, so anything else still connects the
documented way.

Reading the real source corrected five things a bridge written from assumption
would have got wrong — most importantly that the Meilisearch index returns post
ids and not topic ids, which would have produced empty results on every call with
no error anywhere. It also exposed a real defect in this extension: tag-based
provider discovery used a container method that does not exist at runtime in
phpBB, failed silently, and was therefore dead on every board.

`IMPLEMENTATION_AUDIT.md` covers all of it.

## Decisions worth defending

**Nothing reaches outside the forum by default.** Link scanning, AI analysis, the
search integration and both member-facing features are off after installation.
Local analysis is on, because an extension that does nothing when enabled is a
support ticket.

**Age alone never flags content as outdated.** A second signal is always required.
This is why the freshness report is short and useful rather than a list of
everything old on the forum.

**Alerts are aggregated, not per item.** "27 discussions are unanswered" is one
alert. Twenty-seven alerts is a list nobody reads.

**Health scores show their arithmetic.** Every factor exposes its score, its
weight and the underlying figures. A weight of zero removes a factor from the
calculation *and* the explanation. Insufficient data produces "unavailable" with
a reason, never a confident 0/100.

**No percentage without a baseline.** Community pages say "no comparable period"
rather than computing a change against data that does not exist.

**The rule engine is deliberately weak.** Nine whitelisted fields, six operators,
one action, no parser, no `eval`. Unknown fields and operators fail closed. A
richer language would be arbitrary code execution behind an admin form.

**Redirects are followed manually and revalidated at every hop.** Validating only
the posted URL is the standard way SSRF protection is defeated.

**Human decisions survive retention.** A confirmed or dismissed duplicate is kept
indefinitely; deleting it would mean re-detecting it and asking again.

**The public endpoint is not probeable.** Feature-off, no-permission, bad-input
and nothing-found all return an identical empty response.

**No page view triggers analysis.** Ever. Reports read prepared results; if a
result does not exist yet, the feature does not appear.

## Known limitations

- Duplicate detection compares titles and metadata natively. Two discussions
  asking the same question in entirely different words need a search or AI
  integration to be connected.
- Community trends need roughly two weeks of daily figures before they mean
  anything. The pages say so rather than showing a number.
- Solution detection favours precision over recall: it finds clear cases and
  leaves ambiguous ones alone rather than guessing.
- A full first sweep of a very large forum takes days at default batch size. This
  is a deliberate trade against cron runs that exceed the time limit and never
  complete.
- The two public features depend on phpBB 3.3 template events being present; a
  heavily customised style may not fire them.

## Not verified here

Requires a running phpBB, which was not available:

- Functional tests need a configured phpBB test database.
- Migrations have not been executed against MySQL, PostgreSQL or SQLite.
- ACP template rendering has not been observed in a browser.

The unit tests need no database and can be run immediately.

## Suggested first steps after installation

1. Set up a system cron; phpBB's page-triggered cron is unreliable on a quiet
   board.
2. Watch **Forum Health → Background jobs** until coverage approaches 100%.
3. Read the dashboard, then open "why is this number?" before trusting any score.
4. Set the scoring weights to match what your forum is actually for — on a
   discussion community, set solution coverage to zero.
5. Leave link scanning off until you have read `SECURITY.md`.
6. Leave AI off until you have read `AI.md` and decided about `PRIVACY.md`.
