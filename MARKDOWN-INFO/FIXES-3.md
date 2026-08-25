# Fix pack 3 — one file

`service/settings.php`. Copy over, refresh. No migration, no re-enable.

## The bug

`get_int()` destructures each bound as three values:

    list($min, $max, $default) = self::$bounds[$key];

When I added the AI bot setting in fix pack 1, I wrote it with two:

    'fh_ai_bot_id' => [0, 999999],

so `$default` had nothing to take, and PHP warned about the missing index 2.
Every one of the other 47 entries already had all three. Now:

    'fh_ai_bot_id' => [0, 999999, 0],

The default of 0 means "no bot chosen", which is what keeps AI analysis
unavailable until somebody picks one.

## Verified

All 48 bounds re-checked: each destructures into three values, and each clamps
correctly when fed a value far above its maximum and far below its minimum.

## Why it slipped through

`php -l` cannot see it — the file is syntactically perfect. My checks verified
service wiring, language keys and template variables, but nothing looked at the
*shape* of a data table that other code destructures positionally. Same failure
mode as the last round: I verified that things existed, not that they had the
form their consumer required.
