# Search integration

Optional. Improves duplicate discovery on large forums.

## What it adds

Native duplicate detection compares normalised titles: shared terms, containment,
same-forum proximity. It works well and finds the obvious cases.

What it cannot do is match two discussions that ask the same question in
different words. "Login fails after update" and "Can't sign in since upgrading"
share almost no tokens. A search index that understands the body text, and has
its own relevance model, will connect them.

The difference is small on a forum of a few thousand topics and substantial past
about fifty thousand, which is why the extension only recommends installing one
above a size threshold.

## How it is used

The search provider is consulted as a **second source of candidates**, never as
the sole authority. A pair that both native analysis and the search index found is
scored higher than one found by either alone, and the reason is shown in the
report so you can see which signals contributed.

If the search provider is unavailable, slow, or throws, duplicate detection
continues natively. You lose some recall. Nothing breaks and no page errors.

## Connecting one

Forum Health does not call any search extension directly. You supply a small
bridge class implementing its provider interface. `INTEGRATIONS.md` contains the
interface definition and a complete worked example.

Once the bridge is registered, either let discovery find it by service tag or type
the service ID into **Forum Health → Integrations**, then switch the integration
on.

## Troubleshooting

**Status says "Installed, not connected".** The search extension is enabled but no
bridge implementing our interface was found. This is the expected state until you
write one; see `INTEGRATIONS.md`.

**Status says "Degraded".** The bridge is bound but calls are failing or
`is_available()` returns false. Check the search extension's own status first —
usually the daemon is down or the index has not been built.

**No change in results after connecting.** Duplicate detection only reconsiders a
topic when the background job reaches it. Give it a full sweep.
