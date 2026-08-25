# Fix pack — Forum Health & Intelligence

Ten files. Copy them over an existing installation, preserving the directory
structure, then **disable and re-enable** the extension in
*Customise → Manage extensions* so the corrected migration runs.

If the failed install left a partial state, use **Delete data** first, then
enable again. The failure happened in `m3_permissions`, after the tables and
configuration were created, so nothing needs repairing by hand.

| File | Status |
| --- | --- |
| `migrations/v10x/m3_permissions.php` | changed — fixes the install failure |
| `language/en/info_acp_forumhealth.php` | new |
| `language/it/info_acp_forumhealth.php` | new |
| `language/en/common.php` | changed |
| `language/it/common.php` | changed |
| `event/listener.php` | changed |
| `config/services.yml` | changed |
| `acp/base_module.php` | changed |
| `adm/style/event/acp_overall_header_head_append.html` | new |
| `styles/all/template/event/overall_header_head_append.html` | new |

---

## 1. The install failure

**`ROLE_ADMIN_USER_AND_GROUPS` does not exist.** I invented that role name. The
real phpBB role is `ROLE_ADMIN_USERGROUP`. `permission.permission_set` against a
role that is not there throws, and the migrator aborts mid-way — which is exactly
what the error screen reported.

Fixing the name alone would not have been enough. A board can rename or delete
any default role, and the same exception would come back on somebody else's
installation. So the migration now reads `acl_roles` first and skips any role
that is absent.

The reasoning behind that: a convenience default is worth far less than
installing successfully. If a role is missing, the seven permissions still exist
and can be granted by hand; if the migration throws, nothing works at all.
Founders keep full access regardless, because phpBB grants them everything by
definition.

## 2. The ACP menu showed raw keys

phpBB builds its administration menu **before any module runs**, and it loads
only `info_acp_*.php` files to do it. My module titles were in
`acp_forumhealth.php`, which is loaded once a page is already open — by which
point the menu has been drawn.

The result would have been a menu reading `ACP_FH_TITLE`, `ACP_FH_DASHBOARD` and
so on. Both `meilisearch` and `aireply` ship an `info_acp_*` file; comparing
against them is how this surfaced. Now added in both languages.

## 3. The permission masks showed raw keys

Extensions must announce their permissions through the `core.permissions` event,
or the ACP permission pages list them as bare strings like `a_fh_view` with no
description. `aireply` does this and I had not.

The descriptions also had to move into `common.php`. phpBB's permission module
never loads another extension's ACP language file, so keys that live only in
`acp_forumhealth.php` cannot resolve there. `common.php` is loaded on every
request via `core.user_setup`, so that is where they belong. They remain in the
ACP file too — the duplication is deliberate and harmless.

## 4. Cron tasks had no name

phpBB identifies a cron task by the name set through `set_name`, declared in
`services.yml`:

```yaml
calls:
    - [set_name, [salvocortesiano.forumhealth.cron.content]]
```

Without it a task cannot be addressed by `phpbbcli cron:run` and is not properly
identified by the cron manager. All five tasks now declare it. Again, both of
your extensions do this correctly.

## 5 and 6. Neither stylesheet was ever loaded

`adm/style/forumhealth.css` and `styles/all/theme/forumhealth.css` existed and
nothing pointed at either. Every `.fh-` rule was dead: the ACP pages would have
rendered as unstyled tables.

- **Public:** a new `overall_header_head_append.html` event using
  `<!-- INCLUDECSS -->`, the same mechanism already used for the JavaScript.
- **ACP:** there is no `INCLUDECSS` in the ACP, so the URL is now computed in
  `base_module::assign_common()` from phpBB's own `$phpbb_root_path` and emitted
  by a new ACP header event.

I built the ACP path in PHP rather than writing `{ROOT_PATH}ext/...` in the
template. Two reasons: the path comes from a variable phpBB guarantees rather
than one I assumed is assigned in the ACP, and because the variable is only set
while one of this extension's pages is rendering, the stylesheet does not load
across the rest of the administration panel.

---

## What was checked and found clean

- All nine tables: every index and primary key references a declared column, and
  every column type is one phpBB recognises.
- Seed data columns match the schema exactly.
- Every registered module/mode pair matches its `*_info.php` file.
- Every template variable used by the 17 ACP templates is assigned somewhere,
  including the ones built by string concatenation.
- 76 PHP files parse; 38 services resolve with correct constructor arity;
  EN/IT parity holds at 540 keys with matching placeholders.

## A pattern worth noting

Five of these six defects share a shape: phpBB requires an explicit declaration
where I assumed a convention. None would have appeared in a code review, and only
the first one failed loudly. The other five would have produced a menu of raw
keys, unlabelled permissions, unnameable cron tasks and unstyled pages — all
things that look like someone else's problem until you go looking.

Reading `meilisearch` and `aireply` is what surfaced four of them. They were
already doing it correctly.
