# Performance

## The rule

No page view triggers analysis. Ever.

Everything expensive happens in cron. The ACP pages read prepared results. The two
public features read prepared results. If a result does not exist yet, the feature
does not appear rather than computing one on the spot.

This is the single most important design decision in the extension, and it is why
the reports are sometimes slightly behind rather than always instant.

## What each job costs

| Job | Interval | Typical work |
| --- | --- | --- |
| Content analysis | 15 min | One batch of topics (default 200) |
| Community analysis | 1 hour | Almost nothing; real work once per day |
| Link scanning | 30 min | Discovery batch plus check batch (default 25 links) |
| Alert generation | 30 min | Reads stored analysis only |
| Cleanup | 24 hours | Bounded deletes, 500 rows per category |

Community analysis runs hourly but skips a day already recorded, so on twenty-three
of twenty-four runs it does nothing measurable.

Cleanup runs even when background analysis is off. Data that is already stored
still ages, and retention is a promise rather than a feature you toggle.

## Full sweep times

With default settings and cron every fifteen minutes:

| Topics | Full sweep |
| --- | --- |
| 5,000 | ~1 hour |
| 50,000 | ~10 hours |
| 500,000 | ~4 days |

Raise **topics per background run** to shorten this. 1000 per run brings 500,000
topics down to under a day. The constraint is your database, not the extension.

After the first sweep the pass is cheap: most topics are unchanged and skipped by
timestamp comparison before any analysis happens.

## Query design

Every query is bounded by an indexed cursor or a `LIMIT`. There is no query in
this extension that can return an unbounded result set, and no code path that
loads a whole table into memory.

The scan pattern is a cursor over topic ID rather than `OFFSET`, because `OFFSET
400000` makes the database walk 400,000 rows to discard them, and the cost grows
as the sweep progresses. A cursor stays flat.

Indexes are created to serve the specific access patterns of the reports rather
than defensively on every column: an unused index is write cost with no read
benefit.

## Database growth

Rough figures for a forum of 100,000 topics:

- `fh_topic_metrics`: one row per topic, ~150 bytes. About 15 MB.
- `fh_topic_relations`: depends entirely on your thresholds. Typically a few
  thousand rows.
- `fh_links`: one row per distinct URL. Highly variable.
- `fh_metrics_history`: a handful of rows per day. Negligible.
- `fh_alerts`: aggregated, so tens of rows, not thousands.

The alerts table stays small by design. Alerts are aggregated — "27 topics are
unanswered" is one row — because a row per finding would be both a storage problem
and an unusable interface.

## Tuning for a struggling server

1. Lower **topics per background run** to 50.
2. Switch off link scanning; it is the only job doing network I/O.
3. Exclude archive forums nobody maintains.
4. Raise the minimum view thresholds, which shortens the reports and the work.
5. Set unused scoring weights to zero — a factor with weight zero is not computed.

## Tuning for a large, healthy server

1. Raise **topics per background run** to 1000.
2. Use a system cron every five minutes rather than phpBB's page-triggered cron.
3. Raise **links per run**, but keep the inter-request pause; that one is about
   other people's servers, not yours.
