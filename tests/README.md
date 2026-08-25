# Tests

The suite is split by what each kind of test can honestly verify.

`unit/` covers the logic that has no database or container behind it: text
normalisation, similarity scoring, SSRF address classification, rule
evaluation, health scoring arithmetic, and the AI cache key. These are the parts
where a mistake is silent — a scoring bug produces a plausible wrong number
rather than an error — so they are tested exhaustively rather than
representatively.

`functional/` covers the parts that only mean anything inside a running phpBB:
installation and uninstallation, migrations, permissions, the ACP pages, the
public endpoint, and the cron tasks. These extend phpBB's own test case classes
and need a configured test database.

Run from a phpBB installation with the test framework available:

    phpBB/vendor/bin/phpunit -c ext/salvocortesiano/forumhealth/phpunit.xml.dist

The unit tests alone need no database:

    phpBB/vendor/bin/phpunit -c ext/salvocortesiano/forumhealth/phpunit.xml.dist --testsuite unit

## What is deliberately not tested here

The Meilisearch and AI adapters are tested against a stub provider implementing
this extension's own interfaces, never against a real server or a real model.
Two reasons: a test that needs a search daemon is a test that gets skipped, and
more importantly the whole point of the provider interfaces is that the
extension does not know or care what is behind them. Testing against a stub is
testing the actual contract.

The integration matrix in `functional/integration_matrix_test.php` covers all
nine combinations of the two optional integrations being absent, present but
broken, and working — because "works when the optional thing is missing" is the
single most important behaviour this extension has, and it is exactly the one
that rots silently when nobody checks it.
