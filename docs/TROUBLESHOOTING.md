# Troubleshooting

## The reports are empty

Check **Forum Health → Background jobs** first. That page exists to answer this
exact question.

**Last run says Never.** Cron is not firing. phpBB's default cron is triggered by
page views and is unreliable on a quiet forum. Set up a system cron:

    */15 * * * * php /path/to/phpBB/bin/phpbbcli.php cron:run

**Jobs are running but coverage is low.** Nothing is wrong. Analysis is
incremental and a large forum takes time — see `PERFORMANCE.md` for expected
sweep times. The dashboard shows coverage as a percentage precisely so that
partial results are labelled as partial.

**State says Disabled.** Either the master switch or background analysis is off in
Settings.

## Community pages say there is not enough history

Community indicators compare a period with the one before it. Until roughly two
weeks of daily figures exist there is no baseline, and a percentage computed
against nothing would be an artefact of when you installed the extension rather
than a fact about your forum.

This resolves itself. It is not an error.

## A trend shows no percentage

Same cause, narrower scope: that particular metric has no comparable previous
period. The current figure is still shown.

## Duplicate detection finds nothing

**Check coverage.** A pair can only be found once both topics have been analysed.

**Check the threshold.** The default minimum is 55%. Lower it to 45% and see
whether results appear; below that, results are mostly noise.

**Check the window.** By default only topics from the last two years are compared.

## Duplicate detection finds too much

Raise the minimum similarity. 65% is a good second try. Everything you dismiss
stays dismissed — a pair you have ruled on is never re-proposed.

## The link report is empty

Link scanning is off by default. Switch it on in Settings, then wait for the job
to run. Discovery and checking are separate passes, so the first useful results
appear on the second or third run rather than the first.

## Links are marked "Refused for safety"

They point at private or internal network addresses. This is the protection
working as intended — see `SECURITY.md`. Do not switch it off on a forum where
untrusted people can post.

## An integration says "Installed, not connected"

The other extension is enabled, but no bridge implementing our provider interface
was found. This is the expected state until you write one; `INTEGRATIONS.md` has a
complete example. It is not a bug and not a version mismatch.

## An integration says "Degraded"

A provider is bound but failing, or reporting itself unavailable. Check the other
extension's own status first. Forum Health has already fallen back to native
analysis, so nothing is broken in the meantime.

Fix the underlying problem, then use **Check again** on the integrations page to
clear the failure count.

## AI is enabled but nothing uses it

Work down the four gates in order (see `AI.md`):

1. Is the specific capability switched on, not just the master switch?
2. Is the provider status **Working**?
3. Is the daily budget exhausted? The AI page shows usage.
4. Is the candidate threshold too high? AI is only asked about findings that
   already look plausible.

## An untranslated key appears on a page

Something like `FH_SOMETHING_SOMETHING` showing as raw text is a bug. Please
report it with the page and the key.

## Everything is slow after enabling the extension

Lower **topics per background run** and switch off link scanning, which is the
only job doing network I/O. `PERFORMANCE.md` has the full tuning list.

If page views themselves are slow, that is not this extension: no page view in
Forum Health triggers analysis. Check the two public features — related
discussions and the composing hint — which read prepared results but do read
something. Switching them off will confirm it either way in a minute.
