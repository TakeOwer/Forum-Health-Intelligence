# Forum Health & Intelligence

An analysis extension for phpBB 3.3. It reads what is already on your forum and
tells you what deserves your attention: popular questions nobody answered,
discussions that repeat each other, links that have stopped working, content
that may have been overtaken by events, and how newcomers are actually being
treated.

It does not moderate. Nothing in this extension edits, merges, moves, locks or
deletes a topic or a post. Every finding ends at a person who decides what to do.

## What it does

**Content health.** Unanswered discussions ranked by how many people read them.
Possible duplicates, with the reasons they were paired. External links that no
longer resolve. Content flagged as possibly outdated — never on age alone.
Replies that look like they solved a question.

**Community health.** Participation, registrations and response times compared
with the previous equivalent period. What happens to somebody posting for the
first time, which is the single figure that best predicts whether a forum grows.
Members who reply to other people, so they can be recognised.

**Alerts and recommendations.** Findings aggregated into a queue you can triage,
and a priority-ordered list of what to do next. You can add your own rules
through a form; there is no place to type code, by design.

**Health indicators.** Three numbers, each of which will show you its full
arithmetic on request. The weights are yours to set, and a weight of zero
removes a factor from the calculation entirely.

## What it needs

phpBB 3.3 and PHP 7.2 or later. Nothing else.

Two integrations are supported and both are optional: a search extension for
better duplicate discovery, and an AI extension for judgement calls that wording
alone cannot make. The extension is fully functional without either, and keeps
working if one is installed and then breaks.

Verified bridges for `salvocortesiano/meilisearch` and `salvocortesiano/aireply`
ship inside this extension — enable them in phpBB and switch the integration on,
with nothing to write. Anything else connects through a small bridge class. See
`INTEGRATIONS.md`.

## What it does not do

It never reads private messages. There is no code in this extension that opens
the private message tables; the setting on the privacy page exists only so the
answer is visible rather than assumed.

It makes no outbound request until you enable link scanning, which is off after
installation. It sends nothing to an AI provider until you enable AI analysis,
also off, and even then sends only titles and metadata unless you separately
allow post text.

It builds no profile of individual members. The community figures are aggregates.
The one page that names anybody counts public replies that are already visible on
every post.

## Where to start

- `INSTALL.md` — installing, and what happens on the first day
- `CONFIGURATION.md` — every setting and what it changes
- `INTEGRATIONS.md` — connecting an optional search or AI extension
- `PRIVACY.md` — exactly what is read, stored, sent and kept
- `SECURITY.md` — the security model, particularly around link scanning
- `PERFORMANCE.md` — what this costs on a large forum
- `TROUBLESHOOTING.md` — when the numbers do not appear
- `ARCHITECTURE.md` — how it is put together, for developers

## Licence

GPL-2.0. See `license.txt` in the extension root.
