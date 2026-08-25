# Architecture

For developers reading, extending or reviewing this extension.

## Layers

```
  ACP modules  ─┐                          ┌─ event listener
  cron tasks   ─┼─→  services  ─→  repositories  ─→  DBAL
  controller   ─┘         │
                          └─→  adapters  ─→  provider interfaces  ─→  (bridges)
```

Four rules keep the layers honest:

**Repositories are the only place SQL lives.** No service, module, task or
listener builds a query. This is what makes it possible to state, and check, that
nothing reads private messages: there is exactly one layer to audit.

**Services contain the analysis and know nothing about HTTP or templates.** They
return arrays; the presentation layer decides how to say it.

**ACP modules assign template variables and nothing else.** Any logic that feels
like it belongs in a module belongs in a service.

**Adapters are the only code that touches an optional integration**, and they
never let a failure escape.

## Directory layout

```
acp/                    ACP modules and their descriptors
config/                 services.yml, routing.yml, tables.yml
controller/             the single public endpoint
cron/task/              five background jobs
event/                  the public-side listener
language/{en,it}/       translations, kept at parity by a test
migrations/v10x/        schema, config, permissions, modules, seed data
repository/             all SQL
service/
  alerts/               alert manager, recommendation engine
  community/            community analysis
  content/              content analyser, duplicates, freshness, solutions, links
  integrations/         registry, adapters, provider interfaces
  rules/                rule engine
  scoring/              health indicators
  security/             URL validation
  text/                 normalisation and similarity
adm/style/              17 ACP templates and the ACP stylesheet
styles/all/             public template events, stylesheet, JavaScript
tests/{unit,functional} test suite
docs/                   this documentation
```

## Key design decisions

### Inverted integration dependency

Forum Health names no third-party service, class or method. It defines two
interfaces and consults a registry that resolves an administrator-supplied
service ID or a tagged service.

The reason is not elegance. Writing `$container->get('some.other.extension')`
against an API you have not read produces code that looks right and fails on
every real installation. Defining the contract you need, and letting somebody who
can see both sides connect them, moves the failure to a place where it is visible
in thirty lines rather than invisible in three thousand.

See `INTEGRATIONS.md` for the interfaces and a complete bridge.

### Five integration states, not two

The registry distinguishes not installed, installed but disabled, installed but
not connected, degraded, and working.

"Installed but not connected" is the state that justifies the whole scheme: from
the outside it looks identical to "not installed", it needs a completely
different response, and collapsing them into "unavailable" is how somebody ends
up reinstalling software that was never the problem.

### Cursor-based sweeps

Content analysis walks the topic table by ID cursor, storing where it stopped,
and wraps to the beginning at the end. Not `OFFSET`: `OFFSET 400000` makes the
database walk 400,000 rows to throw them away, so the cost grows as the sweep
progresses. A cursor stays flat.

Job state, including the cursor, lives in `fh_jobs`, which also holds the
advisory lock. The lock has a timeout so a fatal error mid-run cannot wedge a job
permanently.

### Alerts store keys, not text

An alert row holds a language key and a parameter array, rendered at display
time. This is what lets the same alert read correctly in English and Italian, and
what lets wording be fixed without a migration.

Alerts are deduplicated by a signature hash and auto-resolve when the underlying
finding stops being true. A dismissed alert never comes back for the same
finding: re-proposing something a person already ruled on wastes their time
twice.

### Alerts are aggregated

"27 popular discussions are unanswered" is one alert. Twenty-seven alerts is a
list nobody reads. The individual items live in the reports, which the alert
links to.

### Scores show their working

`health_score` returns, for every factor, its score, its weight and the figures
that produced it. The dashboard renders that as the "why is this number?" panel.

A weight of zero excludes a factor from both the arithmetic and the explanation,
rather than contributing a zero. Where there is not enough data the result is
`available => false` with a reason key, never a confident 0/100.

### The rule engine is deliberately weak

Nine whitelisted fields, six operators, one action, no parser, no `eval`, no
callable, maximum ten clauses. Unknown fields and operators fail closed.

A richer language would be more useful and would also be arbitrary code execution
behind an administrative form. For "alert me when views exceed 500" the trade is
not close.

### Manual redirect following

`CURLOPT_FOLLOWLOCATION` is off and each hop is revalidated. Validating only the
posted URL is the standard way SSRF protection gets defeated — a public host
answers `302 Location: http://127.0.0.1/` and cURL follows it internally, after
the check has already passed.

## Adding a new analysis

1. Add the SQL to the relevant repository, bounded by cursor or `LIMIT`.
2. Add the analysis to a service under `service/`. It must return arrays and
   must degrade to something useful when an optional integration is missing.
3. Wire the service in `config/services.yml`.
4. If it should raise alerts, add a method to `alert_manager` and a type
   constant. Aggregate; do not raise per item.
5. If it needs a report page, add a mode to the relevant ACP module and a
   template.
6. Add both language keys. The parity test will fail if you add only one.
7. Add settings to the `settings_module` schema — type and bounds are declared
   there, so validation comes with them.

## Adding a language

Copy `language/en/` to `language/<code>/` and translate. The completeness test
compares English and Italian specifically; extend `language_files()` in
`tests/unit/language_completeness_test.php` to cover a third.

Note that 107 keys are built dynamically from constants at runtime
(`FH_SEVERITY_*`, `FH_ALERT_TYPE_*`, `FH_INT_STATE_*`, `FH_JOB_*` and so on).
They will not appear in a search for `->lang('`, so translate the whole file
rather than only the keys you can find referenced.

## Testing

`tests/unit/` needs no database. `tests/functional/` extends phpBB's functional
test case.

The integration matrix test covers all nine combinations of the two optional
integrations being absent, broken and working. It is the most important test
here: "works when the optional thing is missing" is the extension's central
promise and the one that rots silently when nobody checks it.
