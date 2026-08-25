# AI analysis

Optional, off by default, and useless without a provider you connect yourself.

## What it is for

Most of what this extension does needs no AI. Counting unanswered topics, finding
broken links, comparing periods and matching titles are all better done with
ordinary code: cheaper, faster, deterministic, and explainable.

AI is used for the small number of judgements where wording genuinely is not
enough:

- **Uncertain duplicates.** Two titles scoring in the middle band — similar
  enough to be suspicious, not similar enough to be sure. This is where a
  semantic reading adds something a token comparison cannot.
- **Which reply solved it.** Native detection handles the clear cases, where the
  author says "that worked". The unclear ones are a judgement call.
- **Whether content is out of date.** Distinguishing "old and still correct" from
  "old and now wrong" needs reading, not arithmetic.
- **Drafting a guide** from several discussions that keep asking the same thing.
- **Spotting contradictions** between discussions that give different answers.

## The four gates

Every AI call passes all four, in order. Any one of them closing means no request
is made and nothing is spent.

1. **The master switch and the capability switch.** Both must be on. Turning off
   the master switch guarantees no AI request from anything in this extension.
2. **The cache.** Results are keyed by content hash, analysis type, provider and
   a configuration version. Unchanged content is never re-analysed. On a
   steady-state forum this is where most of the savings come from.
3. **The budget.** A daily limit on analyses. When exhausted, everything falls
   back to native analysis until the next day, silently.
4. **The candidate threshold.** AI is asked only about findings that already look
   plausible. Below the configured confidence, a finding is treated as noise and
   costs nothing. This is what stops the extension from spending a budget
   confirming that two unrelated topics are unrelated.

## What is sent

With **`fh_privacy_send_content_to_ai` off** — the default — only topic titles,
timestamps and numeric metadata.

With it **on**, the text of public posts, for the specific capabilities you have
enabled.

Private messages are never sent under any setting. Excluded forums are never
sent. The setting change itself is written to the admin log at every verbosity
level, because it is the one change with consequences outside your server.

Forum Health does not choose your AI provider and does not know where the data
goes. That is determined by the extension you bridged to, and its terms apply.

## What comes back

Suggestions for review. Nothing an AI produces is published, applied, or acted on
automatically. A drafted guide is a draft. A judgement that a topic is outdated
puts it on a list; it does not edit, hide or label the topic.

Every finding that came from AI is labelled as such in the reports, so you always
know which conclusions had a model behind them.

## Cost control

Start with the budget low — the default is 200 analyses per day — and watch the
usage figure on the AI page for a week. The cache hit rate climbs steadily after
the first full sweep, because the forum's back catalogue stops changing.

If costs are still higher than you want, raise the candidate threshold before
lowering the budget. A higher threshold means fewer, better-targeted questions; a
lower budget means the same questions asked until the money runs out and then
arbitrary silence.
