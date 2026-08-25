# Security

## Threat model

An analysis extension has three properties that make it worth thinking about
carefully.

It **fetches URLs written by members**, which makes it a potential
request-forgery engine pointed at your own infrastructure.

It **has an administrative interface with write actions**, which makes it a
target for cross-site request forgery and for privilege confusion between
different levels of administrator.

It **can send forum content to a third party**, which makes an accidental default
a data leak rather than a bug.

Everything below follows from those three.

## Link scanning and request forgery

This is the highest-risk feature in the extension, which is why it ships
disabled.

The danger is specific. Anybody who can post can write a URL. If the scanner
fetched it naively, a member could point it at `http://127.0.0.1:8080/admin`, at a
database on a private subnet, or at `http://169.254.169.254/`, the address that
returns cloud instance credentials on several major providers. The forum's own
server would make the request, from inside your network, with whatever access
that position implies.

### What the validator refuses

The address is resolved and the resulting IP checked before any connection is
made. Refused ranges:

**IPv4:** `0.0.0.0/8`, `10.0.0.0/8`, `100.64.0.0/10` (carrier-grade NAT),
`127.0.0.0/8`, `169.254.0.0/16` (link-local, including cloud metadata),
`172.16.0.0/12`, `192.0.0.0/24`, `192.0.2.0/24`, `192.88.99.0/24`,
`192.168.0.0/16`, `198.18.0.0/15`, `198.51.100.0/24`, `203.0.113.0/24`,
`224.0.0.0/4` (multicast), `240.0.0.0/4` and `255.255.255.255`.

**IPv6:** `::`, `::1`, `fc00::/7` (unique local), `fe80::/10` (link-local),
`ff00::/8` (multicast), `2001:db8::/32`, `64:ff9b::/96`, and IPv4-mapped
addresses, which are unwrapped and then checked against the IPv4 list — otherwise
`::ffff:127.0.0.1` would sail straight through.

### Redirects

`CURLOPT_FOLLOWLOCATION` is off. Redirects are followed manually, one at a time,
and **every hop is revalidated**: scheme, host and resolved IP.

This matters more than it might appear. Validating only the posted URL is the
most common way this protection is defeated: a member posts a perfectly ordinary
public address whose server responds `302 Location: http://127.0.0.1/`. With
cURL following redirects internally, the check has already passed and the request
goes through. Following them by hand is slower and is the only way to be sure.

The redirect limit is configurable and defaults to 3.

### Other constraints

- Only `http` and `https`. `file://`, `gopher://`, `dict://` and everything else
  is refused at the scheme check.
- A `HEAD` request first; `GET` only when `HEAD` is not supported.
- Response bodies are not stored, parsed or executed. Only the status code and
  the final URL are recorded.
- A configurable pause between requests, so the scanner does not resemble an
  attack on a site you happen to link to a lot.
- Timeouts are bounded and repeated failures are required before a link is
  called broken.

### If you switch the protection off

`fh_link_allow_private_hosts` exists because a few forums genuinely link to an
intranet. Enabling it disables the range checks. Do not enable it on a forum
where untrusted people can post.

## The public endpoint

One route is reachable by ordinary members: the duplicate hint used by the
posting form. It is checked in this order, and does nothing until all of it
passes:

1. The feature is enabled.
2. The session is valid.
3. The member can read *and post in* the target forum.
4. The link hash matches, tying the request to this session's posting form.
5. The title is between 8 and 250 characters.

Then, for every result, the member's read permission is checked **against the
forum that particular topic lives in** — not once for the page. Related topics
frequently live elsewhere, and a single up-front check would leak the existence of
titles in forums the member cannot see.

The response is deliberately identical for "feature off", "no permission",
"malformed input" and "nothing found": a bare `{"found": false, "topics": []}`.
Distinguishing them would turn the endpoint into a way to probe which forums and
topics exist.

The response contains titles and URLs only. No confidence score, no reasons, no
analysis vocabulary.

## Administrative actions

Every state-changing action requires a valid form token (`check_form_key`), and
every module checks its permission before doing anything, including before
rendering.

Seven separate permissions rather than one, so that viewing reports, acting on
findings, changing analysis settings, connecting integrations, spending AI budget
and writing rules can be granted independently:

`a_fh_view`, `a_fh_manage`, `a_fh_manage_content`, `a_fh_manage_community`,
`a_fh_manage_integrations`, `a_fh_manage_ai`, `a_fh_manage_rules`.

The AI permission is separate because that is where money gets spent. The
integrations permission is separate because binding a service ID is effectively
choosing what code runs inside the extension.

## Rules

The rule engine has no expression parser, no `eval`, no callable, and no way to
reach anything outside a fixed whitelist of nine fields, six operators and one
action. A rule is entered through selects, not typed.

This is a deliberate limitation. A richer rule language would be more useful and
would also be a way to execute arbitrary logic through an administrative account.
For a feature whose job is to say "alert me when views exceed 500", the trade is
not close.

Unknown fields and unknown operators fail closed — an unrecognised operator
returns false rather than defaulting to equality, so a corrupted rule matches
nothing instead of everything.

## Database access

Every query goes through phpBB's DBAL with proper escaping. There is no string
concatenation of user input into SQL anywhere in the extension. Integer inputs are
cast; array inputs go through `sql_in_set`; the free-text fields that exist
(ignored domains, ignored patterns) are matched in PHP, never interpolated.

## Output

Titles and other stored strings are output through the template engine's escaping.
The one place JavaScript inserts text into the page, it uses `createElement` and
`textContent`; `innerHTML` does not appear anywhere in the extension's JavaScript.

## AI and data leaving the server

Four independent gates must all pass before a single AI call is made: the master
AI switch, the specific capability switch, the daily budget, and the cache. A
fifth, `fh_privacy_send_content_to_ai`, decides whether post text is included or
only titles and metadata.

Turning that fifth setting on or off is written to phpBB's admin log at every
verbosity level.

## Reporting a vulnerability

Please report privately rather than in a public topic, and allow reasonable time
for a fix before disclosure.
