# Installation

## Requirements

- phpBB 3.3.0 or later (not 4.x)
- PHP 7.2 or later
- Working cron

Nothing else. No Composer dependencies, no external services, no other
extensions.

## Installing

1. Copy the extension so that it sits at
   `ext/salvocortesiano/forumhealth/` in your phpBB installation.
2. In the ACP, go to **Customise → Manage extensions**.
3. Find **Forum Health & Intelligence** and click **Enable**.

The migrations create nine tables and about ninety-five configuration values.
On a large board the schema step may take a few seconds; it creates tables and
indexes but does not read your content.

## Cron

All analysis runs in the background. If cron never fires, the reports stay empty
and the extension appears broken while being entirely healthy.

phpBB's default cron is triggered by page views, which is adequate on a busy
board and unreliable on a quiet one. A system cron is better:

    */15 * * * * php /path/to/phpBB/bin/phpbbcli.php cron:run

Check **Forum Health → Background jobs** to see whether jobs are actually
running. That page exists precisely so you never have to guess.

## The first day

Analysis is incremental. It walks through your topics a batch at a time rather
than attempting the whole forum in one run, because the alternative is a cron
run that exceeds the PHP time limit and never completes at all.

Roughly what to expect with default settings and cron every fifteen minutes:

| Topics | Time to full coverage |
| --- | --- |
| 5,000 | around 1 hour |
| 50,000 | around 10 hours |
| 500,000 | around 4 days |

The dashboard shows coverage as a percentage throughout, so the numbers you see
early on are explicitly labelled as partial rather than presented as complete.

Community indicators need about two weeks of daily figures before trends mean
anything. Until then the pages say so instead of showing a percentage computed
against a baseline that does not exist.

## What is switched on after installation

**On:** content analysis, community analysis, duplicate detection, freshness and
solution detection, alerts.

**Off:** link scanning, AI analysis, the search integration, the member-facing
duplicate hint, related discussions.

The split is not arbitrary. Everything in the first list reads your own database.
Everything in the second either contacts something outside your server or changes
what members see, and neither should happen because you enabled an extension.

## Upgrading

Replace the files and click **Enable** again in the extension manager. New
migrations run automatically; existing analysis is preserved.

## Uninstalling

**Disable** stops all analysis and hides the ACP pages. Your data is kept, and
re-enabling picks up where it left off.

**Delete data** removes all nine tables and every configuration value. Admin log
entries the extension wrote are kept deliberately — see `PRIVACY.md`.
