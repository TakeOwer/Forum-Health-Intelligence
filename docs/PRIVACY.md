# Privacy

This document describes exactly what Forum Health reads, stores, sends and keeps.
It is written to be checkable: every claim here corresponds to something you can
find in the code or the database.

## What it reads

**Topics and posts that are already public on your forum.** Titles, post text,
timestamps, view counts, reply counts, author IDs, forum IDs.

**User registration and posting timestamps**, to work out whether somebody is
posting for the first time and whether newcomers come back.

That is the complete list.

## What it never reads

**Private messages.** There is no code in this extension that queries the private
message tables. Not behind a setting, not behind a permission, not in a code path
that happens to be disabled. The `fh_privacy_analyse_pms` setting exists solely so
that the answer is visible on the privacy page rather than something an
administrator has to take on trust; it is fixed off and is not readable from the
form even by crafting a request.

**Email addresses, IP addresses, passwords, or anything else from the user table**
beyond registration time and the username needed to display the contributors page.

**Posts in forums you have excluded.** Excluded forums are filtered at the query
level, in the repository, not skipped later in PHP.

## What it stores

All of it in the extension's own tables, all of it derived from the above:

| Table | Contents |
| --- | --- |
| `fh_topic_metrics` | Per-topic figures: normalised title, view and reply counts, whether unanswered, freshness and solution confidence |
| `fh_topic_relations` | Pairs of topic IDs that may be duplicates, with a confidence and the reasons |
| `fh_links` / `fh_link_occurrences` | External URLs found in posts, their state, and which posts contain them |
| `fh_alerts` | Findings, as a language key plus parameters — not rendered text |
| `fh_metrics_history` | One row per metric per day: counts and averages, never individuals |
| `fh_rules` | Your own rules |
| `fh_ai_cache` | Results of AI analyses, keyed by a hash of the content examined |
| `fh_jobs` | Background job bookkeeping |

`fh_metrics_history` is worth calling out: it is the only long-lived historical
data, and it contains no user IDs at all. A row says "on this day there were 47
active posters", not who they were.

## What leaves your server

**Nothing, by default.**

Two features can make outbound requests, and both are off after installation.

### Link scanning

When enabled, requests external URLs that appear in posts, to see whether they
still resolve. The remote server sees your forum's IP address and a user agent
identifying the extension. It does not see who posted the link, which topic it is
in, or anything else about your forum.

Addresses on private and internal networks are refused entirely — see
`SECURITY.md` for the full list — unless you deliberately switch that protection
off.

### AI analysis

When enabled, sends material to whatever AI provider you have connected. Forum
Health does not choose that provider and does not know where it sends data; that
is determined by the extension you bridged to, and its privacy terms apply.

What gets sent depends on one further setting:

- **`fh_privacy_send_content_to_ai` off (the default):** only topic titles,
  timestamps and numeric metadata.
- **On:** the text of public posts, for the specific analyses you have enabled.

Private messages are not sent under any setting.

Changing this setting is recorded in phpBB's admin log at every verbosity level,
including the quietest, because it is the one change with consequences outside
your server.

## What members see

Two optional public features, both off by default:

- A hint while composing a new topic that an existing discussion may cover the
  question. It shows only topic titles the member already has permission to read,
  checked per topic against the forum each one lives in.
- A list of related discussions under a topic, subject to the same per-topic
  permission check.

No analysis result, confidence score, alert or health indicator is ever shown to
an ordinary member. The contributors page is not published anywhere.

## Retention

Derived data expires on a schedule you control in the settings. Defaults:

| Data | Default retention |
| --- | --- |
| Closed alerts | 90 days |
| Unreviewed duplicate pairs | 180 days |
| Link results | 180 days |
| Daily metrics | 365 days |
| AI cache | 30 days |

Two things are never removed by retention:

**Open alerts**, because expiring something still outstanding would hide a
problem rather than resolve it.

**Your decisions.** A duplicate pair you confirmed or dismissed is kept
indefinitely. Deleting it would mean the pair gets re-detected and put back in
front of you, which wastes your time and quietly discards a judgement you already
made.

Analysis of deleted content is removed within a day, whatever the retention
settings say. If a topic goes, its metrics, relations and link records go with it.

## Removing everything

Uninstalling the extension through phpBB's extension manager drops every table
listed above and removes every configuration value. Nothing is left behind in
phpBB's own tables except the admin log entries the extension wrote, which are
kept deliberately: they are a record of administrative actions, and silently
erasing an audit trail on uninstall would be the wrong default.

## GDPR notes

If you operate under GDPR or similar regimes:

- The lawful basis for the aggregate analysis is normally legitimate interest in
  maintaining the forum. It processes data already public on your board.
- The contributors page processes identifiable data. Its content is derived
  entirely from public posts, but if you would rather not have it, switch off
  `fh_privacy_user_metrics`.
- A data subject erasure request handled through phpBB's own user deletion
  removes the posts, and the cleanup job then removes the derived analysis within
  a day. `fh_metrics_history` needs nothing: it holds no identifiers.
- If you enable AI analysis, you are sending data to a processor of your own
  choosing. That relationship, and any agreement it requires, is between you and
  that provider.
