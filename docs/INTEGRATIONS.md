# Integrations

Forum Health works entirely on its own. This document is about making it
sharper, not making it work.

## If you use Meilisearch or AI Reply, there is nothing to write

Verified bridges for these two extensions ship inside Forum Health:

| Extension | What you do |
| --- | --- |
| `salvocortesiano/meilisearch` | Enable it in phpBB, then switch the search integration on in **Forum Health → Integrations**. Leave the service ID field empty. |
| `salvocortesiano/aireply` | Enable it in phpBB, configure at least one bot, then in **Forum Health → AI analysis** enter that bot's numeric ID and switch AI on. |

Both bridges were written against those extensions' actual source, not against an
assumed API. The rest of this document is for connecting anything else.

### The AI Reply bot ID

Forum Health does not ask you for an API key. It borrows the provider, model,
endpoint and key from a bot you have already configured in AI Reply, because
keeping one copy of a secret is better than keeping two.

Find the bot's ID on AI Reply's own bots page and enter it in Forum Health's AI
settings. The bot must be **enabled** in AI Reply: one that has been switched off
there is left alone, since quietly using it from another extension would defeat
the point of that switch.

Forum Health sends its own system prompt, not the bot's personality — it asks for
a structured verdict, not a forum reply. Nothing it does posts anything anywhere.

### What each bridge actually does

**Meilisearch.** Searches opening posts only (`is_first_post = 1`), approved posts
only, honouring both Forum Health's excluded forums and the search extension's
own. The index returns post IDs — `topic_id` is filterable but not displayable —
so Forum Health maps them to topics in SQL, keeping the engine's relevance order.
Meilisearch's ranking score becomes the candidate confidence; on a server too old
to report one, position is used instead and the top hit is scored 90 rather than
100, because rank order alone does not justify full confidence.

**AI Reply.** Its providers return prose. Forum Health asks for a single JSON
object with a confidence, a verdict from a closed list, and a one-line reason, and
parses it strictly. If the model answers in prose anyway, the result is discarded
and native analysis is used. It also checks `supports_temperature()` before
sending that parameter, because OpenAI's reasoning models reject it with a 400.

## Why there is a bridge

Most extensions integrate by calling another extension's services directly. This
one does not, and the reason is worth stating plainly.

Forum Health does not assume the service names, class names or method signatures
of any third-party extension. Guessing them produces code that looks correct,
passes review, and fails at runtime on every installation — the worst possible
failure mode, because it is invisible until a real forum runs it.

The two built-in bridges avoid that by having been written against real source.
For anything else, the same discipline applies: you write the bridge, because you
can see both sides.

So the dependency is inverted. Forum Health defines two small interfaces
describing what it needs. You supply a bridge: a short class that implements the
interface and calls whatever the other extension actually provides. Forum Health
never names a third-party symbol; your bridge does, and you can see immediately
whether it is right.

A bridge is typically thirty to sixty lines.

## The two interfaces

### Search provider

`\salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface`

```php
interface provider_interface
{
    /** Whether the provider can serve requests right now. Must be cheap. */
    public function is_operational();

    /** A short description shown in the ACP. Must contain no credentials. */
    public function describe();

    /**
     * Topics similar to the given text.
     *
     * @param  string $text     Topic title, optionally with the first post.
     * @param  int    $limit    Maximum results.
     * @param  int    $exclude  Topic id to leave out, 0 for none.
     * @param  int    $forum_id Restrict to a forum, 0 for all.
     * @return array  Rows of ['topic_id' => int, 'score' => int 0-100].
     *                Empty means "no candidates", never an error.
     */
    public function find_similar_topics($text, $limit, $exclude = 0, $forum_id = 0);
}
```

### AI provider

`\salvocortesiano\forumhealth\service\integrations\ai\provider_interface`

```php
interface provider_interface
{
    public function is_operational();
    public function describe();

    /** Whether this provider offers a named capability. */
    public function supports($capability);

    /**
     * Perform one analysis.
     *
     * @param  string $capability One of the CAP_* constants.
     * @param  array  $payload    Structured input; contents depend on capability.
     * @return array|null [
     *                      'confidence' => int 0-100,
     *                      'verdict'    => string,
     *                      'summary'    => string,
     *                      'reference'  => int,
     *                    ]
     *                    or null when no analysis could be produced.
     */
    public function analyse($capability, array $payload);
}
```

The capabilities are `detect_duplicate`, `detect_solution`, `detect_outdated`,
`summarize_topic`, `extract_knowledge` and `detect_conflict`. A provider may support any subset; Forum Health only
asks for what `supports()` confirms.

## A complete worked example

Suppose you have a search extension installed whose service is
`acme.search.client` and which exposes `query($index, $text, $size)` returning
rows with `id` and `_score`. The bridge lives in your own small extension, or in
a site-specific one.

`ext/mysite/fhbridge/service/search_bridge.php`:

```php
<?php

namespace mysite\fhbridge\service;

use salvocortesiano\forumhealth\service\integrations\meilisearch\provider_interface;

/**
 * Adapts the Acme search client to what Forum Health asks for.
 *
 * Everything specific to Acme is in this file. If Acme changes its API, this is
 * the only thing that needs updating, and the failure will be here rather than
 * somewhere inside Forum Health.
 */
class search_bridge implements provider_interface
{
    /** @var \acme\search\client */
    protected $client;

    public function __construct($client)
    {
        $this->client = $client;
    }

    public function is_operational()
    {
        try {
            return $this->client->ping();
        } catch (\Throwable $e) {
            // Never let a health check throw: Forum Health treats an
            // exception here as "unavailable" anyway, but returning false
            // keeps the admin log quiet about something already known.
            return false;
        }
    }

    public function describe()
    {
        return 'Acme Search';
    }

    public function find_similar_topics($text, $limit, $exclude = 0, $forum_id = 0)
    {
        $rows = $this->client->query('topics', $text, $limit + 1);
        $out = [];

        foreach ($rows as $row) {
            $topic_id = (int) $row['id'];

            if ($topic_id === (int) $exclude) {
                continue;
            }

            $out[] = [
                'topic_id' => $topic_id,
                // Forum Health expects an integer 0-100. Normalise here
                // rather than hoping the scales already match.
                'score'    => (int) round(min(100, max(0, $row['_score']))),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
```

If your bridge needs a service from an extension that might not be installed,
take `@service_container` and resolve it at runtime with `has()` — never as a
declared argument. A compile-time reference to a missing service stops the whole
container from building, which takes the board offline rather than disabling one
feature. Both built-in bridges do exactly this.

`ext/mysite/fhbridge/config/services.yml`:

```yaml
services:
    mysite.fhbridge.search:
        class: mysite\fhbridge\service\search_bridge
        arguments:
            - '@acme.search.client'
        tags:
            # The tag lets Forum Health discover the bridge automatically.
            # Without it you can still point at the service by id in the ACP.
            - { name: salvocortesiano.forumhealth.search_provider }
```

Then either leave the service ID field empty in **Forum Health → Integrations**
and let discovery find it by tag, or type `mysite.fhbridge.search` into the field
and switch the integration on.

The status will change to **Working** once the extension confirms the service
exists and implements the interface. If it says **Installed, not connected**, the
service was not found; if it says **Degraded**, the service was found but
`is_available()` returned false or calls are throwing.

## What the five statuses mean

| Status | What happened | What to do |
| --- | --- | --- |
| **Not installed** | No relevant extension is present. | Nothing is wrong. Native analysis continues. Install one if you want the extra accuracy. |
| **Installed but disabled** | The extension exists but is disabled in phpBB. | Enable it in phpBB's extension manager first. |
| **Installed, not connected** | The extension is enabled but no bridge implementing our interface was found. | Write a bridge, as above. This is the common case, and the one this document exists for. |
| **Working** | Bound and responding. | Nothing. |
| **Degraded** | Bound, but failing or reporting itself unusable. | Check the other extension's own status. Forum Health has already fallen back to native analysis. |

The distinction between "not installed" and "installed, not connected" is
deliberate. They look identical from the outside and need completely different
responses, and collapsing them into "unavailable" is how an administrator ends up
reinstalling something that was never the problem.

## Failure behaviour

Every call into a provider is wrapped. An exception, a timeout, a malformed
return value or a provider that vanishes mid-request produces the same outcome:
the failure is counted, the status moves toward degraded, the admin log gets one
entry, and the analysis continues natively.

After repeated consecutive failures the integration is treated as degraded and
Forum Health stops calling it until the status is refreshed. This is not
punishment; it is to stop every cron run from waiting on a server that is down.

Nothing about a provider failure is ever shown to an ordinary member.
