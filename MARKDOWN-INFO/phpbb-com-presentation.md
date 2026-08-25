# Presentation post for phpBB.com

Everything below the line is BBCode, ready to paste into the first post of a
topic in **Extensions in Development**
(`https://www.phpbb.com/community/viewforum.php?f=456`).

## Before you post

**Fill in the four placeholders.** They are marked `FILL IN` and the post reads
badly without them: download URL, GitHub URL, your phpBB.com username, and the
screenshot links.

**Pick the topic prefix from the dropdown**, not by typing it into the title.
That forum uses the Topic Prefixes extension. Given that this build has only just
started installing cleanly on a real board, `[BETA]` is the honest choice —
`[RC]` claims a level of field testing it has not had yet. Suggested title:

```
Forum Health & Intelligence
```

**Attach or link the screenshots.** phpBB.com allows attachments in posts; the
`[img]` tags below expect direct image URLs. Six are worth showing: the
dashboard, the unanswered report, the duplicates review, the alert queue, the
integrations page, and the settings page.

**A note on format.** phpBB.com's BBCode has no tables and no headings, so this
post uses `[size=150][b]…[/b][/size]` for section titles and `[list]` throughout.
Do not paste Markdown — it will render as literal asterisks.

**Before submitting to the Customisation Database** (a separate step from posting
here) the extension must pass EPV validation and be GPL-2.0. It is GPL-2.0
already. Run EPV against it first; the CDB queue will run it anyway.

---

```bbcode
[b]Extension Name:[/b] Forum Health & Intelligence
[b]Author:[/b] FILL IN (your phpBB.com username)
[b]Extension Description:[/b] Analyses your board's existing content and tells you what deserves your attention: popular questions nobody answered, discussions that repeat each other, links that have stopped working, content that may be out of date, and how newcomers are actually being treated. It reports; it never moderates.
[b]Extension Version:[/b] 1.1.0
[b]Requirements:[/b] phpBB 3.3.0+, PHP 7.2+, working cron. No other extension required.
[b]Extension Download:[/b] [url]FILL IN[/url]
[b]Github repository:[/b] [url]FILL IN[/url]
[b]Languages:[/b] en, it
[b]License:[/b] GPL-2.0

[size=150][b]What it is[/b][/size]

Every board accumulates two kinds of debt. Content debt: questions nobody
answered, the same question asked twenty times, links that died three years ago,
guides that describe software that has moved on. And community debt: newcomers
whose first post got no reply, regulars quietly drifting away, response times
creeping up.

Both are invisible day to day. Neither shows up in the statistics phpBB gives
you. You find out about them when somebody leaves.

This extension reads what is already on your board and puts that debt on a page
you can look at.

[size=150][b]What it does not do[/b][/size]

This matters as much as the feature list, so it comes first.

[list]
[*][b]It never moderates.[/b] Nothing in this extension edits, merges, moves, locks or deletes a topic or a post. Not behind a setting, not behind a permission. Every finding ends at a person who decides what to do about it.
[*][b]It never reads private messages.[/b] There is no code in the extension that queries the PM tables. The setting on the privacy page is fixed off and exists only so the answer is visible rather than something you have to take on trust.
[*][b]It makes no outbound request[/b] until you switch on link scanning, which is off after installation.
[*][b]It sends nothing to any AI[/b] until you switch that on too, also off — and even then only titles and metadata unless you separately allow post text.
[*][b]It builds no profile of individual members.[/b] The community figures are aggregates. The one page that names anybody counts public replies already visible on every post, and it is not shown on the public board.
[/list]

[size=150][b]Content health[/b][/size]

[list]
[*][b]Unanswered discussions[/b], ranked by how many people read them. A question nobody answered and nobody read is an obscure question; one that 800 people opened and nobody answered is a different problem.
[*][b]Possible duplicates[/b], shown as pairs with the reasons they were matched — similar wording, shared keywords, same forum. You confirm or dismiss; nothing is merged.
[*][b]Broken links[/b] found in posts, with the topics they appear in. A link is only called broken after repeated failures across separate runs, days apart. One timeout means nothing.
[*][b]Potentially outdated content.[/b] Age alone never flags anything — a second signal is always required, such as a software version reference that has moved on. This is why the list is short and useful instead of being everything old on your board.
[*][b]Detected solutions:[/b] replies that appear to have resolved a question, so you can mark them if you use a solved-topic extension.
[/list]

[size=150][b]Community health[/b][/size]

[list]
[*][b]New member experience.[/b] What happens to somebody posting here for the first time — how many got a reply, how fast, and how many came back a week later. If you read one page in this extension, read this one: it is the single figure that best predicts whether a board grows or dies.
[*][b]Activity trends[/b] against the equivalent previous period: active members, registrations, new topics, posts, time to first reply.
[*][b]Contributors:[/b] members who answer other people's questions, so they can be recognised. Not a leaderboard, not shown publicly.
[/list]

[size=150][b]Alerts, recommendations and rules[/b][/size]

[list]
[*][b]Alerts are aggregated, not per item.[/b] "27 popular discussions are unanswered" is one alert. Twenty-seven alerts is a list nobody reads. You acknowledge, resolve or dismiss; a dismissed alert never comes back for the same finding.
[*][b]Recommendations[/b] in priority order — what to do next if you have twenty spare minutes.
[*][b]Your own rules[/b], built from dropdowns: "when views is at least 500 and is unanswered is 1, raise a high alert". There is no place to type code anywhere in the rule editor, deliberately.
[/list]

[size=150][b]Health indicators that show their working[/b][/size]

Three numbers: content, community, overall. Each one will show you its full
arithmetic on request — every factor, its score, its weight, and the figures
behind it. Open "Why this number?" and you get the sum, not a verdict.

The weights are yours. A weight of zero removes a factor from the calculation
[i]and[/i] from the explanation. If you run a discussion board rather than a
support board, set solution coverage to zero — discussions were never meant to
have solutions, and scoring them as though they should makes the number
meaningless.

Where there is not enough data the page says so. It never shows a confident 0/100
to somebody who installed the extension an hour ago, and it never prints a
percentage change against a baseline that does not exist.

[size=150][b]Installation[/b][/size]

[list=1]
[*]Download and unpack so the files sit at [b]ext/salvocortesiano/forumhealth/[/b] in your phpBB directory.
[*]Go to [b]ACP → Customise → Manage extensions[/b].
[*]Find [b]Forum Health & Intelligence[/b] and click [b]Enable[/b].
[/list]

The migrations create nine tables and about ninety-five configuration values. On
a large board the schema step takes a few seconds; it creates tables and indexes
but does not read your content.

[size=150][b]Cron — read this part[/b][/size]

All analysis happens in the background. If cron never fires, the reports stay
empty and the extension looks broken while being entirely healthy.

phpBB's default cron is triggered by page views. That is adequate on a busy board
and unreliable on a quiet one. A system cron is much better:

[code]*/15 * * * * php /path/to/phpBB/bin/phpbbcli.php cron:run[/code]

[b]ACP → Forum Health & Intelligence → Background jobs[/b] exists specifically so
you never have to guess whether this is working. It shows the five jobs, when
each last ran, and how much of your board has been analysed so far.

[size=150][b]What to expect on the first day[/b][/size]

Analysis is incremental — it walks through your topics a batch at a time rather
than attempting the whole board in one run, because the alternative is a cron run
that exceeds the PHP time limit and never finishes at all.

With default settings and cron every fifteen minutes:

[list]
[*]5,000 topics — around an hour to full coverage
[*]50,000 topics — around ten hours
[*]500,000 topics — around four days
[/list]

Raise [i]Topics per background run[/i] to speed this up; the constraint is your
database, not the extension. The dashboard shows coverage as a percentage
throughout, so early figures are labelled as partial rather than presented as
complete.

Community trends need roughly two weeks of daily figures before they mean
anything. Until then the pages say so. That is not an error and it resolves
itself.

[size=150][b]What is switched on after installation[/b][/size]

[b]On:[/b] content analysis, community analysis, duplicate detection, freshness
and solution detection, alerts.

[b]Off:[/b] link scanning, AI analysis, the search integration, the member-facing
duplicate hint, related discussions.

The split is not arbitrary. Everything in the first list reads your own database.
Everything in the second either contacts a server outside your board or changes
what your members see, and neither should happen just because you enabled an
extension.

[size=150][b]Configuration[/b][/size]

Everything lives at [b]ACP → Forum Health & Intelligence → Settings[/b], grouped
by area. The settings where the right value is not obvious:

[list]
[*][b]Topics per background run[/b] (200). Lower on shared hosting; 500–1000 on a dedicated server with a backlog. Affects speed only, never accuracy.
[*][b]Minimum views[/b] for unanswered topics (100). The most useful dial in the extension. Raising it produces a shorter, more actionable list.
[*][b]Minimum similarity[/b] for duplicates (55%). Lowering it finds more and gives you more to review. Below about 45% the results are mostly noise, because short titles sharing two common words will match.
[*][b]Warn members while composing[/b] (off). If you turn this on, keep the warning threshold at 80% or above. A wrong suggestion shown to somebody as they write is worse than no suggestion — the cost of a false positive is much higher than the cost of a miss here.
[*][b]Consider content old after[/b] (24 months), measured from the last reply rather than the posting date. A discussion people are still adding to is not stale whenever it started.
[*][b]Scoring weights.[/b] Set to match what your board is actually for. All zero means no indicator can be calculated, and the page will say so rather than showing a misleading number.
[/list]

[size=150][b]Permissions[/b][/size]

Seven separate permissions, so read access and spending money are not the same
grant:

[list]
[*][b]a_fh_view[/b] — view the reports
[*][b]a_fh_manage[/b] — act on findings (acknowledge, dismiss, resolve)
[*][b]a_fh_manage_content[/b] — change content analysis settings
[*][b]a_fh_manage_community[/b] — change community analysis settings
[*][b]a_fh_manage_integrations[/b] — connect and configure integrations
[*][b]a_fh_manage_ai[/b] — enable AI analysis and spend its budget
[*][b]a_fh_manage_rules[/b] — create and edit rules
[/list]

The AI permission is separate because that is where money gets spent. The
integrations permission is separate because binding a service is effectively
choosing what code runs inside the extension.

[size=150][b]Optional integrations[/b][/size]

The extension is fully functional on a plain phpBB installation with nothing else
present, and it keeps working if an integration is installed and then breaks.

Verified bridges ship inside the extension for two of them, so there is nothing
to write:

[list]
[*][b]Meilisearch[/b] ([i]salvocortesiano/meilisearch[/i]) — finds duplicates that wording alone cannot: "Login fails after update" and "Can't sign in since upgrading" share almost no words. Noticeable above roughly 50,000 topics. Enable it in phpBB, then switch the search integration on and leave the service ID field empty.
[*][b]AI Reply[/b] ([i]salvocortesiano/aireply[/i]) — for the judgement calls wording cannot make: uncertain duplicate pairs, which reply actually solved a question, whether old content is now wrong. Enable it in phpBB, configure a bot, then enter that bot's numeric ID on the AI page.
[/list]

Forum Health does not ask you for an API key. It borrows the provider, model,
endpoint and key from a bot you have already set up in AI Reply, because keeping
one copy of a secret is better than keeping two.

Anything else connects through a small bridge class of thirty to sixty lines
implementing one of two documented interfaces. INTEGRATIONS.md has both
interfaces and a complete worked example.

The integrations page distinguishes five states rather than two, including
[i]installed but not connected[/i] — which looks identical to [i]not installed[/i]
from the outside and needs a completely different response. Each state comes with
a line saying what to do next.

[size=150][b]Privacy[/b][/size]

[list]
[*][b]Reads:[/b] topics and posts already public on your board, plus registration and posting timestamps.
[*][b]Never reads:[/b] private messages, email addresses, IP addresses, or anything else from the user table.
[*][b]Stores:[/b] derived figures in nine of its own tables. The daily history table contains no user IDs at all — a row says "on this day there were 47 active posters", not who they were.
[*][b]Sends nothing anywhere by default.[/b] Link scanning and AI analysis are the only features that make outbound requests and both ship disabled.
[*][b]Retention[/b] is configurable. Two things are never removed by it: open alerts, because expiring something outstanding hides a problem rather than resolving it; and your own decisions, because deleting a duplicate pair you already ruled on means it gets re-detected and put back in front of you.
[/list]

Analysis of deleted content is removed within a day whatever the retention
settings say. Uninstalling with [b]Delete data[/b] drops all nine tables and every
configuration value.

[size=150][b]Security[/b][/size]

The link scanner fetches URLs written by your members, which makes it the highest
risk feature in the extension and the reason it ships disabled.

[list]
[*][b]Private and internal addresses are refused[/b] — 14 IPv4 and 7 IPv6 ranges, including [i]169.254.169.254[/i], the address that returns instance credentials on several cloud providers. IPv4-mapped IPv6 addresses are unwrapped and checked too, so [i]::ffff:127.0.0.1[/i] does not sail through.
[*][b]Redirects are followed manually and revalidated at every hop.[/b] Validating only the posted URL is how this protection is normally defeated: a perfectly ordinary public host answers [i]302 Location: http://127.0.0.1/[/i] and cURL follows it internally, after the check has already passed.
[*][b]Only http and https.[/b] Response bodies are never stored, parsed or executed.
[*]A configurable pause between requests, so the scanner does not resemble an attack on a site you happen to link to often.
[/list]

The one public endpoint — the duplicate hint on the posting form — checks
permissions [i]per topic[/i] against the forum each result lives in, not once for
the page, and returns an identical empty response for "feature off", "no
permission", "bad input" and "nothing found", so it cannot be used to probe which
forums or topics exist.

[size=150][b]Performance[/b][/size]

[b]No page view triggers analysis. Ever.[/b] Everything expensive happens in cron.
The ACP pages read prepared results. The two optional public features read
prepared results. If a result does not exist yet, the feature simply does not
appear rather than computing one on the spot.

Every query is bounded by an indexed cursor or a LIMIT. The topic sweep uses a
cursor rather than OFFSET, because [i]OFFSET 400000[/i] makes the database walk
400,000 rows to discard them and the cost grows as the sweep progresses.

For a board of 100,000 topics the metrics table is roughly 15 MB. The alerts
table stays small by design, because alerts are aggregated.

[size=150][b]Troubleshooting[/b][/size]

[list]
[*][b]Reports are empty.[/b] Check [i]Background jobs[/i] first. "Last run: Never" means cron is not firing. Low coverage means it is working and has not got there yet.
[*][b]Community pages say there is not enough history.[/b] Correct behaviour. It needs about two weeks of daily figures before a comparison means anything.
[*][b]Duplicate detection finds nothing.[/b] Check coverage first, then lower the similarity threshold to 45% and see whether results appear.
[*][b]Too many duplicates.[/b] Raise it to 65%. Everything you dismiss stays dismissed.
[*][b]Links marked "Refused for safety".[/b] They point at private network addresses. That is the protection working. Do not switch it off on a board where untrusted people can post.
[*][b]An integration says "Installed, not connected".[/b] For Meilisearch and AI Reply the bridge is already built in, so check that extension's own configuration — for AI Reply, that a bot is selected and enabled.
[/list]

[size=150][b]Languages[/b][/size]

English and Italian, both complete: 541 keys each, verified by an automated test
that fails the build if a key is added to one language and not the other, or if
the printf placeholders stop matching.

Translations into other languages are very welcome. Copy [i]language/en/[/i] and
translate. One thing to know: 107 keys are built dynamically from constants at
runtime, so they will not show up if you search the code for where a string is
used — translate the whole file rather than only the keys you can find
referenced.

[size=150][b]Status and honesty about it[/b][/size]

This is a first public release. It has been through mechanical verification —
every file parses, every service resolves, every language key exists in both
languages, the address filter is tested against 25 addresses, both integration
bridges are tested against real response shapes — and it installs and runs on a
live 3.3 board.

What it has [i]not[/i] had is months of field testing across many boards, which is
exactly what posting here is for. Bug reports are genuinely welcome, particularly
about the analysis being wrong rather than the code crashing: a duplicate pair
that is obviously not a duplicate, or a health indicator that does not match what
you know about your own board, is much more useful to me than a stack trace.

[size=150][b]Screenshots[/b][/size]

[b]Dashboard[/b]
[img]FILL IN[/img]

[b]Unanswered discussions[/b]
[img]FILL IN[/img]

[b]Possible duplicates[/b]
[img]FILL IN[/img]

[b]Alerts[/b]
[img]FILL IN[/img]

[b]Integrations[/b]
[img]FILL IN[/img]

[b]Settings[/b]
[img]FILL IN[/img]
```
