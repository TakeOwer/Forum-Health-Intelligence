# Fix pack 2 — unrendered placeholders

Seventeen files. Copy over the installation, preserving the structure. **No
migration change, so no need to disable and re-enable** — refresh the ACP page.

## What went wrong

`{L_FH_COVERAGE}` prints a language string *verbatim*. If that string contains a
printf placeholder, the placeholder is what appears on screen:

    Analizzate: %1$s discussioni (circa il %2$d%% del forum)

A string with placeholders has to be composed in PHP with
`$this->language->lang('KEY', $arg)` and assigned as a template variable. I had
written seventeen such keys and printed all of them raw.

Every one is now rendered in its module and the templates reference the rendered
variable instead. Three of them were fixed differently: `Usate oggi: %d`,
`Disponibili oggi: %d` and `Risultati in cache: %d` sit *underneath* the number
they describe, so the number never belonged inside the string. They are now plain
labels.

## The second, separate bug

The settings page showed `FH_UNANSWERED_MAX_AGE_DAYS_DESC` as literal text.

The settings form builds its language keys from the configuration key name:
`fh_unanswered_max_age_days` becomes `FH_UNANSWERED_MAX_AGE_DAYS_DESC`. I had
named the description `FH_UNANSWERED_MAX_AGE_DESC` — dropping `_DAYS`. Renamed in
both languages.

I checked all 61 settings keys the same way; this was the only mismatch.

## How both were found

Not by looking at the screenshots. Two scripts:

- every `{L_KEY}` in the 17 templates, cross-referenced against the language
  files, flagging any whose string contains `%s`, `%d` or `%1$s` — 17 hits;
- every settings schema key, checking that both `KEY` and `KEY_DESC` exist in
  English *and* Italian — 1 hit.

Then a third pass confirming no template variable was left dangling by the
renames: 102 block variables and every page-level variable still resolve.

## Why my earlier checks missed this

My verification asked *"does every key referenced exist?"* — and every one of
these did exist. It never asked whether a key was being used in a way that could
render it. A defined key printed in the wrong context passes an existence check
perfectly.

That check is now part of the suite rather than something I ran once by hand.
