# Configuration

Every setting lives at **Forum Health → Settings**, grouped by what it affects.
This document explains the ones where the right value is not obvious.

## General

**Enable Forum Health** is the master switch. Off means no analysis runs and no
page of the extension does any work — not "hides the menu".

**Topics per background run** (default 200) trades speed against server load. On
shared hosting, lower it. On a dedicated server with a large backlog, 500 to 1000
is reasonable. It does not affect the accuracy of anything, only how long a full
sweep takes.

**Forums to exclude** takes comma-separated IDs. Excluded forums are filtered in
the SQL, so they cost nothing rather than being fetched and discarded. Typical
candidates: a spam trap, a staff area, an archive nobody maintains.

## Unanswered discussions

**Unanswered after** (default 48 hours) and **minimum views** (default 50)
together decide what counts as a problem. The view threshold is the important
one: an unanswered question nobody read is not a failure of your community, it is
an obscure question. Raising it produces a shorter, more actionable list.

## Duplicate detection

**Minimum similarity** (default 55%) is the floor for storing a pair at all.
**High confidence** (default 75%) is where a pair becomes alert-worthy.

Lowering the floor finds more and gives you more to review. The relationship is
not linear — below about 45% the results are mostly noise, because short titles
sharing two common words will match.

**Same-forum bonus** (default 10%) reflects a real pattern: duplicates cluster
within a forum, because people ask the same question in the same place.

**Warn members while composing** is off by default and, if you enable it, keep
**minimum similarity to warn** high — 80% or above. A wrong suggestion shown to a
member as they write is worse than no suggestion at all, and the cost of a miss is
much lower than the cost of a false positive here.

## Link scanning

Off by default; this is the only feature that contacts servers outside your
forum. Read `SECURITY.md` before enabling it on a forum with untrusted posters.

**Links per run** (default 25) and **pause between requests** (default 500ms)
control how the scanner behaves toward other people's servers. The defaults are
deliberately polite. Raising them substantially, on a forum that links heavily to
one domain, starts to look like a small denial of service from that domain's
perspective.

**Failures before calling a link broken** (default 3) is why the report is
trustworthy. A single timeout means almost nothing; three consecutive failures
across separate runs, days apart, means something.

**Allow private and internal addresses** should stay off. See `SECURITY.md`.

## Freshness

**Consider content old after** (default 24 months) is measured from the last
reply, not from when the topic was posted. A discussion that people are still
adding to is not stale regardless of when it started.

Age alone never flags anything. A second signal is always required — a software
version reference that has moved on, or an AI judgement if you have enabled it.
This is why the report is short and useful rather than being a list of everything
old on your forum.

## Community

**Default comparison period** (default 30 days) is used everywhere trends appear.
Shorter periods are noisier; 7 days on a small forum will show alarming swings
that are just the weekend.

**First discussion should get a reply within** (default 24 hours) defines the
new-member experience figure. This is the setting most worth thinking about,
because the figure it produces is the best single predictor of whether a forum
grows.

## Health indicator weights

Every factor's weight is yours to set, and **zero removes it entirely** — from
the calculation and from the explanation.

The defaults suit a support forum. If you run a discussion community, set
**solution coverage** to zero: discussions were never meant to have solutions,
and scoring them as though they should makes the indicator meaningless.

If every weight is zero the indicator cannot be computed, and the page will say
so rather than showing a misleading number.

## AI

See `AI.md`. The short version: off by default, four independent gates, and post
text does not leave your server unless you separately allow it.

## Retention

Defaults keep closed alerts 90 days, duplicate pairs 180, link results 180, daily
metrics 365 and AI cache 30.

Daily metrics is the one to raise if you care about long-term trends: history
cannot reach further back than this, and the data is tiny — a few rows per day
with no user identifiers at all.

Your own decisions are never deleted by retention.
