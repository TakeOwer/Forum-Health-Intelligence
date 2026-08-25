# -Forum Health & Intelligence

**An analysis extension for phpBB 3.3.** It reads what is already on your board
and tells you what deserves your attention: popular questions nobody answered,
discussions that repeat each other, links that have stopped working, content that
may be out of date, and how newcomers are actually being treated.

It reports. It never moderates.

[![phpBB](https://img.shields.io/badge/phpBB-3.3-blue)](https://www.phpbb.com/)
[![PHP](https://img.shields.io/badge/PHP-7.2%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0-green)](license.txt)
[![Languages](https://img.shields.io/badge/languages-en%20%7C%20it-lightgrey)](language/)

<!-- Replace with a real screenshot once you have one. This is the first thing
     anybody sees on the repository page, so it does more work than any
     paragraph below it. -->
![Dashboard](docs/screenshots/dashboard.png)

---

## Contents

- [Why](#why)
- [What it does not do](#what-it-does-not-do)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Cron](#cron)
- [First day](#first-day)
- [Configuration](#configuration)
- [Permissions](#permissions)
- [Optional integrations](#optional-integrations)
- [Privacy](#privacy)
- [Security](#security)
- [Performance](#performance)
- [Development](#development)
- [Translating](#translating)
- [Project status](#project-status)
- [Documentation](#documentation)
- [License](#license)

---

## Why

Every board accumulates two kinds of debt.

**Content debt:** questions nobody answered, the same question asked twenty
times, links that died three years ago, guides describing software that has moved
on.

**Community debt:** newcomers whose first post got no reply, regulars quietly
drifting away, response times creeping up.

Both are invisible day to day. Neither shows up in the statistics phpBB gives
you. You find out about them when somebody leaves.

This extension puts that debt on a page you can look at.

## What it does not do

This matters as much as the feature list, so it comes first.

- **It never moderates.** Nothing here edits, merges, moves, locks or deletes a
  topic or a post. Not behind a setting, not behind a permission. Every finding
  ends at a person who decides what to do about it.
- **It never reads private messages.** There is no code in this extension that
  queries the PM tables. The setting on the privacy page is fixed off and exists
  only so the answer is visible rather than something you take on trust.
- **It makes no outbound request** until you switch on link scanning, which is
  off after installation.
- **It sends nothing to any AI** until you switch that on too — also off — and
  even then only titles and metadata unless you separately allow post text.
- **It builds no profile of individual members.** The community figures are
  aggregates. The one page that names anybody counts public replies already
  visible on every post, and it is not shown on the public board.

## Features

<details>
<summary><b>Content health</b></summary>

| Report | What it surfaces |
| --- | --- |
| Unanswered discussions | No replies, ranked by readership. A question nobody read is obscure; one 800 people opened and nobody answered is a different problem. |
| Possible duplicates | Pairs, with the reasons they were matched. You confirm or dismiss; nothing is merged. |
| Broken links | URLs found in posts, with the topics containing them. Only called broken after repeated failures days apart — one timeout means nothing. |
| Potentially outdated | Age alone never flags anything; a second signal is always required. That is why the list is short and useful. |
| Detected solutions | Replies that appear to have resolved a question. |

</details>

<details>
<summary><b>Community health</b></summary>

| Report | What it surfaces |
| --- | --- |
| New member experience | How many first posts got a reply, how fast, and how many members came back a week later. If you read one page, read this one. |
| Activity trends | Active members, registrations, topics, posts and response time against the equivalent previous period. |
| Contributors | Members who answer other people's questions, so they can be recognised. Not a leaderboard, never public. |

</details>

<details>
<summary><b>Alerts, recommendations and rules</b></summary>

- **Alerts are aggregated, not per item.** "27 popular discussions are
  unanswered" is one alert. Twenty-seven alerts is a list nobody reads. A
  dismissed alert never returns for the same finding.
- **Recommendations** in priority order — what to do next if you have twenty
  spare minutes.
- **Your own rules**, built from dropdowns: *when views is at least 500 and is
  unanswered is 1, raise a high alert*. There is no place to type code anywhere
  in the rule editor, deliberately. See [Security](#security).

</details>

<details>
<summary><b>Health indicators that show their working</b></summary>

Three numbers: content, community, overall. Each will show you its full
arithmetic on request — every factor, its score, its weight, and the figures
behind it. Open *"Why this number?"* and you get the sum, not a verdict.

The weights are yours. A weight of zero removes a factor from the calculation
*and* from the explanation. On a discussion board rather than a support board,
set solution coverage to zero — discussions were never meant to have solutions,
and scoring them as though they should makes the number meaningless.

Where there is not enough data the page says so. It never shows a confident 0/100
to somebody who installed the extension an hour ago, and never prints a
percentage change against a baseline that does not exist.

</details>

## Requirements

| | |
| --- | --- |
| phpBB | 3.3.0 or later (not 4.x) |
| PHP | 7.2 or later |
| Other extensions | none |
| Cron | required — see below |

## Installation

```bash
cd phpBB/ext
mkdir -p salvocortesiano
cd salvocortesiano
git clone https://github.com/USER/REPO.git forumhealth
```

Or download a release and unpack so the files sit at
`ext/salvocortesiano/forumhealth/`.

Then: **ACP → Customise → Manage extensions → Forum Health &amp; Intelligence →
Enable**.

The migrations create nine tables and about ninety-five configuration values. On
a large board the schema step takes a few seconds; it creates tables and indexes
but does not read your content.

## Cron

All analysis runs in the background. **If cron never fires, the reports stay
empty and the extension looks broken while being entirely healthy.**

phpBB's page-triggered cron is adequate on a busy board and unreliable on a quiet
one. A system cron is much better:

```cron
*/15 * * * * php /path/to/phpBB/bin/phpbbcli.php cron:run
```

**ACP → Forum Health &amp; Intelligence → Background jobs** exists specifically so
you never have to guess whether this is working.

## First day

Analysis is incremental — it walks the topic table a batch at a time rather than
attempting the whole board in one run, because the alternative is a cron run that
exceeds the PHP time limit and never finishes at all.

| Topics | Time to full coverage |
| --- | --- |
| 5,000 | ~1 hour |
| 50,000 | ~10 hours |
| 500,000 | ~4 days |

Raise *Topics per background run* to speed this up; the constraint is your
database, not the extension. Coverage is shown as a percentage throughout, so
early figures are labelled partial rather than presented as complete.

Community trends need roughly two weeks of daily figures before they mean
anything. Until then the pages say so. That is not an error and it resolves
itself.

### What is on after installation

**On:** content analysis, community analysis, duplicate detection, freshness and
solution detection, alerts.

**Off:** link scanning, AI analysis, the search integration, the member-facing
duplicate hint, related discussions.

The split is not arbitrary. Everything in the first list reads your own database.
Everything in the second either contacts a server outside your board or changes
what your members see, and neither should happen just because you enabled an
extension.

## Configuration

Everything lives at **ACP → Forum Health &amp; Intelligence → Settings**. The
settings where the right value is not obvious:

| Setting | Default | Notes |
| --- | --- | --- |
| Topics per background run | 200 | Lower on shared hosting; 500–1000 on a dedicated server with a backlog. Affects speed only, never accuracy. |
| Minimum views (unanswered) | 100 | The most useful dial here. Raising it produces a shorter, more actionable list. |
| Minimum similarity (duplicates) | 55% | Below ~45% results are mostly noise: short titles sharing two common words will match. |
| Warn members while composing | off | If enabled, keep the threshold at 80%+. A wrong suggestion shown to somebody as they write costs far more than a miss. |
| Consider content old after | 24 months | Measured from the last reply, not the posting date. A thread people still add to is not stale. |
| Scoring weights | varies | All zero means no indicator can be calculated, and the page says so rather than showing a misleading number. |

Full reference: [`docs/CONFIGURATION.md`](docs/CONFIGURATION.md).

## Permissions

Seven separate permissions, so read access and spending money are not the same
grant:

| Permission | Grants |
| --- | --- |
| `a_fh_view` | View the reports |
| `a_fh_manage` | Act on findings (acknowledge, dismiss, resolve) |
| `a_fh_manage_content` | Change content analysis settings |
| `a_fh_manage_community` | Change community analysis settings |
| `a_fh_manage_integrations` | Connect and configure integrations |
| `a_fh_manage_ai` | Enable AI analysis and spend its budget |
| `a_fh_manage_rules` | Create and edit rules |

The AI permission is separate because that is where money gets spent. The
integrations permission is separate because binding a service is effectively
choosing what code runs inside the extension.

## Optional integrations

The extension is fully functional on a plain phpBB installation with nothing else
present, and keeps working if an integration is installed and then breaks.

Verified bridges ship inside the extension for two of them — nothing to write:

| Extension | What it adds | Setup |
| --- | --- | --- |
| [`salvocortesiano/meilisearch`](https://github.com/) | Finds duplicates wording alone cannot. *"Login fails after update"* and *"Can't sign in since upgrading"* share almost no words. Noticeable above ~50,000 topics. | Enable in phpBB, switch the search integration on, leave the service ID empty. |
| [`salvocortesiano/aireply`](https://github.com/) | Judgement calls: uncertain duplicate pairs, which reply solved a question, whether old content is now wrong. | Enable in phpBB, configure a bot, enter that bot's numeric ID on the AI page. |

Forum Health does not ask you for an API key. It borrows the provider, model,
endpoint and key from a bot already set up in AI Reply, because keeping one copy
of a secret is better than keeping two.

**Anything else** connects through a bridge class of thirty to sixty lines
implementing one of two documented interfaces —
[`docs/INTEGRATIONS.md`](docs/INTEGRATIONS.md) has both interfaces and a complete
worked example.

The integrations page distinguishes five states rather than two, including
*installed but not connected*, which looks identical to *not installed* from the
outside and needs a completely different response. Each state comes with a line
saying what to do next.

## Privacy

- **Reads:** topics and posts already public on your board, plus registration and
  posting timestamps.
- **Never reads:** private messages, email addresses, IP addresses, or anything
  else from the user table.
- **Stores:** derived figures in nine of its own tables. The daily history table
  contains no user IDs at all — a row says *"on this day there were 47 active
  posters"*, not who they were.
- **Sends nothing anywhere by default.**
- **Retention** is configurable. Two things are never removed by it: open alerts,
  because expiring something outstanding hides a problem rather than resolving
  it; and your own decisions, because deleting a duplicate pair you already ruled
  on means it gets re-detected and put back in front of you.

Analysis of deleted content is removed within a day whatever the retention
settings say. Full detail, including GDPR notes:
[`docs/PRIVACY.md`](docs/PRIVACY.md).

## Security

The link scanner fetches URLs written by your members, which makes it the
highest-risk feature here and the reason it ships disabled.

- **Private and internal addresses are refused** — 14 IPv4 and 7 IPv6 ranges,
  including `169.254.169.254`, the address returning instance credentials on
  several cloud providers. IPv4-mapped IPv6 is unwrapped and checked too, so
  `::ffff:127.0.0.1` does not sail through.
- **Redirects are followed manually and revalidated at every hop.** Validating
  only the posted URL is how this protection is normally defeated: an ordinary
  public host answers `302 Location: http://127.0.0.1/` and cURL follows it
  internally, after the check has already passed.
- **Only `http` and `https`.** Response bodies are never stored, parsed or
  executed.

The rule engine has no expression parser, no `eval`, no callable, and no way to
reach anything outside a whitelist of nine fields and six operators. A richer
rule language would be more useful and would also be arbitrary code execution
behind an administrative form; for *"alert me when views exceed 500"* the trade
is not close.

The one public endpoint checks permissions **per topic** against the forum each
result lives in, not once for the page, and returns an identical empty response
for *feature off*, *no permission*, *bad input* and *nothing found*, so it cannot
be used to probe which forums or topics exist.

**Reporting a vulnerability:** please report privately rather than in a public
issue, and allow reasonable time for a fix before disclosure.

## Performance

**No page view triggers analysis. Ever.** Everything expensive happens in cron.
The ACP pages read prepared results; the two optional public features read
prepared results. If a result does not exist yet, the feature does not appear
rather than computing one on the spot.

Every query is bounded by an indexed cursor or a `LIMIT`. The topic sweep uses a
cursor rather than `OFFSET`, because `OFFSET 400000` makes the database walk
400,000 rows to discard them and the cost grows as the sweep progresses.

For a board of 100,000 topics the metrics table is roughly 15 MB. The alerts
table stays small by design, because alerts are aggregated.

Tuning guidance: [`docs/PERFORMANCE.md`](docs/PERFORMANCE.md).

## Development

```
acp/            ACP modules and their descriptors
config/         services.yml, routing.yml, tables.yml
controller/     the single public endpoint
cron/task/      five background jobs
event/          the public-side listener
language/       en, it — kept at parity by a test
migrations/     schema, config, permissions, modules, seed data
repository/     all SQL lives here, and only here
service/        analysis, scoring, rules, integrations, security
adm/style/      17 ACP templates and the ACP stylesheet
styles/all/     public template events, stylesheet, JavaScript
tests/          unit and functional
docs/           full documentation
```

Four rules keep the layers honest, and they are what make claims like *"it never
reads private messages"* checkable rather than aspirational:

1. **Repositories are the only place SQL lives.** No service, module, task or
   listener builds a query — so there is exactly one layer to audit.
2. **Services contain the analysis** and know nothing about HTTP or templates.
3. **ACP modules assign template variables and nothing else.**
4. **Adapters are the only code that touches an optional integration**, and they
   never let a failure escape.

Architecture notes, including why the integration dependency is inverted:
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

### Tests

```bash
# Unit tests need no database
phpBB/vendor/bin/phpunit -c ext/salvocortesiano/forumhealth/phpunit.xml.dist --testsuite unit

# Functional tests need a configured phpBB test database
phpBB/vendor/bin/phpunit -c ext/salvocortesiano/forumhealth/phpunit.xml.dist
```

The most important test is `functional/integration_matrix_test.php`, covering all
nine combinations of the two optional integrations being absent, broken and
working. *"Works when the optional thing is missing"* is this extension's central
promise and the one that rots silently when nobody checks it.

### Contributing

Issues and pull requests welcome. Two things worth knowing before you open one:

- The language parity test fails the build if a key is added to one language and
  not the other, or if printf placeholders stop matching. Add both.
- New analysis must degrade to something useful when an optional integration is
  missing. That is not a style preference; it is the property the whole design
  exists to protect.

## Translating

English and Italian are complete: 541 keys each, verified automatically.

To add a language, copy `language/en/` to `language/<code>/` and translate. One
thing to know: **107 keys are built dynamically from constants at runtime**, so
they will not appear if you search the code for where a string is used. Translate
the whole file rather than only the keys you can find referenced.

Then extend `language_files()` in `tests/unit/language_completeness_test.php` so
your language is checked too.

## Project status

First public release. It has been through mechanical verification — every file
parses, every service resolves, every language key exists in both languages, the
address filter is tested against 25 addresses, both integration bridges are
tested against real response shapes — and it installs and runs on a live 3.3
board.

What it has **not** had is months of field testing across many boards.

Bug reports are genuinely welcome, particularly about **the analysis being wrong
rather than the code crashing**: a duplicate pair that is obviously not a
duplicate, or a health indicator that does not match what you know about your own
board, is much more useful than a stack trace.

## Documentation

| | |
| --- | --- |
| [INSTALL.md](docs/INSTALL.md) | Installing, and what happens on the first day |
| [CONFIGURATION.md](docs/CONFIGURATION.md) | Every setting and what it changes |
| [INTEGRATIONS.md](docs/INTEGRATIONS.md) | Connecting a search or AI extension |
| [MEILISEARCH.md](docs/MEILISEARCH.md) | The search integration specifically |
| [AI.md](docs/AI.md) | The AI integration, its four gates, and cost control |
| [PRIVACY.md](docs/PRIVACY.md) | What is read, stored, sent and kept |
| [SECURITY.md](docs/SECURITY.md) | Threat model, particularly link scanning |
| [PERFORMANCE.md](docs/PERFORMANCE.md) | What this costs on a large board |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | When the numbers do not appear |
| [ARCHITECTURE.md](docs/ARCHITECTURE.md) | How it is put together |
| [CHANGELOG.md](docs/CHANGELOG.md) | Version history |

## License

[GPL-2.0](license.txt), the same licence as phpBB itself.
