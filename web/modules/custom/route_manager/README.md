# Route Manager

A Drupal 11 admin module for managing per-route access control, SEO overrides, performance audit visualisation, and CSV/JSON import-export.

---

## Requirements

- Drupal 11
- Modules: `path_alias` (core), `metatag ^2.2`

---

## Installation

```bash
ddev drush en route_manager -y
ddev drush updb -y     # runs schema migrations if updating an existing install
ddev drush cr
```

Navigate to **Administration → Route Manager** (`/admin/route-manager`).

---

## Permissions

| Permission | Description |
|------------|-------------|
| `administer route manager` | Full access to all Route Manager admin pages |

Grant it at **Administration → People → Roles**.

---

## Admin pages

| URL | Purpose |
|-----|---------|
| `/admin/route-manager` | Route listing with search, filter, access labels, performance data, and SEO indicators |
| `/admin/route-manager/{id}/edit` | Per-route SEO and access settings form (opens as modal dialog from the listing) |
| `/admin/route-manager/import` | Import a CSV of route settings or a JSON audit file from the Python auditor |
| `/admin/route-manager/export` | Download `route_manager_export.csv` with all current settings |

---

## Route listing

The listing page shows all routes discovered from three sources:

1. **Stored settings** — paths saved in the `route_manager_settings` table
2. **Path aliases** — all active aliases from the `path_alias` table
3. **Static named routes** — all Drupal routes without dynamic parameters (`{id}` etc.)

Routes are grouped by their first path segment (e.g. `/doctors`, `/services`) and collapsed into `<details>` panels. The count of routes per group is shown in the panel header.

### Columns

| Column | Description |
|--------|-------------|
| Path | URL path with SEO indicator badges (see below) |
| Access | Effective access label |
| Status | HTTP status code from the last crawl audit (green < 300, red ≥ 400) |
| Load time | Page load time in ms from the last crawl audit; highlighted red when > 2 000 ms |
| JS errors | Count of JavaScript console errors/warnings captured during audit |
| Resp. issues | Count of responsive layout issues (horizontal overflow, fixed-element overflow) |
| Actions | View / Edit / Hide or Make public |

### Filtering and search

- **Search box** — filters by path substring (case-insensitive)
- **Dropdown** — show All / Anonymous (public) / Admin only (hidden)

### Row actions

- **View** — opens the frontend path in a new browser tab
- **Edit** — opens the settings form in a modal dialog (720 px wide); saves without leaving the listing page
- **Hide / Make public** — one-click toggle that immediately updates `is_public` for the path

---

## Access labels

Each route displays one of the following access labels:

| Label | Meaning |
|-------|---------|
| `Anonymous` | Explicitly set to public via Route Manager |
| `Admin only` | Explicitly hidden via Route Manager (returns 403 for non-admins) |
| `Anonymous (default)` | Inheriting the global default (public); no explicit override set |
| `Admin only (default)` | Inheriting the global default (restricted); no explicit override set |
| `Admin only (route)` | Native Drupal route requirements block anonymous access (e.g. `_is_admin`, `_permission`, `_user_is_logged_in`, entity-create access) — no override needed |

The global default is **public** unless changed in `route_manager.settings` config.

### Access control enforcement

The `RouteAccessSubscriber` event subscriber fires on every request. If a path matches a `route_manager_settings` record with `is_public = 0` and the current user is not an administrator, a 403 response is returned immediately.

---

## SEO indicators

Each path cell shows small colour-coded badges derived from three sources (merged in priority order):

1. **Route Manager overrides** — values saved directly in this module's edit form
2. **Crawl audit data** — SEO fields extracted by the Python auditor and imported via JSON
3. **Entity defaults** — for node-backed paths: flags inferred from `metatag.metatag_defaults.node` config and the `node__body.body_summary` column

| Badge | Meaning | Green when… |
|-------|---------|-------------|
| `T` | Title | A `<title>` tag is present / configured |
| `D` | Meta description | A `<meta name="description">` is present or the node has a non-empty body summary |
| `H1` | H1 heading | An `<h1>` was found during the last crawl audit |
| `OG` | Open Graph tags | At least one `og:*` meta tag is present / configured |

A grey badge means the tag is absent or not configured. Badges are only shown when at least one data source has information for that path.

---

## Per-route settings form

Open via **Actions → Edit** (modal) or directly at `/admin/route-manager/{id}/edit?path=/your-path`.

### Fields

**Path** — URL path (e.g. `/about`). Required.

**Route name** — Optional Drupal machine route name (e.g. `entity.node.canonical`).

**Access Control**
- Inherit global default
- Public (accessible to everyone)
- Hidden (returns 403 for non-admins)

**SEO Overrides**
- Page title override — replaces the HTML `<title>` tag
- Meta title — overrides `metatag` title
- Meta description
- Canonical URL
- Robots meta (e.g. `noindex, nofollow`)

**Open Graph** (collapsible)
- OG Title, OG Description, OG Image URL

Saved overrides are injected into the page via `hook_metatags_attachments_alter()`, fully compatible with the MetaTags module.

---

## CSV export and import

### Export

Click **Export CSV** on the listing page to download `route_manager_export.csv`.

Columns:

```
route_name, path, is_public, page_title,
meta_title, meta_description,
og_title, og_description, og_image,
canonical, robots
```

`is_public` values: `1` = public, `0` = hidden, blank = inherit default.

### Import (CSV tab)

Go to `/admin/route-manager/import` → **CSV** tab.

Upload an edited export file. The importer:
- Requires a header row matching the export columns
- Upserts into `route_manager_settings` by path (insert if new, update if exists)
- Reports a per-row error summary for any validation failures (missing path, bad `is_public` value, etc.)

**Workflow:**
1. Export CSV → open in spreadsheet
2. Edit `is_public`, SEO fields, etc.
3. Import CSV → verify changes in the listing

---

## Audit JSON import

Go to `/admin/route-manager/import` → **Audit JSON** tab.

Upload `audit_results.json` produced by the [Python auditor](../../helper_scripts/site_auditor/README.md).

The importer upserts each page into the `route_manager_audit` table and displays a summary:

- Pages imported
- Total JS errors across all pages
- Total responsive layout issues

After import, the listing page shows:
- HTTP status codes (green/red)
- Load times (highlighted if > 2 000 ms)
- JS error counts
- Responsive issue counts
- Live SEO badge data (title, description, H1, OG)

---

## Database schema

### `route_manager_settings`

| Column | Type | Description |
|--------|------|-------------|
| `id` | serial | Primary key |
| `route_name` | varchar(255) | Optional Drupal route machine name |
| `path` | varchar(2048) | URL path (unique) |
| `is_public` | int | 1 = public, 0 = hidden, NULL = inherit |
| `page_title_override` | varchar(512) | Replaces HTML `<title>` |
| `metatag_overrides` | blob | Serialised array of metatag field overrides |
| `updated` | int | Unix timestamp of last update |

### `route_manager_audit`

| Column | Type | Description |
|--------|------|-------------|
| `id` | serial | Primary key |
| `path` | varchar(2048) | URL path |
| `audit_date` | int | Unix timestamp of the crawl |
| `status_code` | int | HTTP status code |
| `load_time_ms` | int | Page load time in milliseconds |
| `ttfb_ms` | int | Time to first byte in milliseconds |
| `request_count` | int | Number of sub-requests |
| `total_bytes` | int | Total transferred bytes |
| `seo_data` | blob | JSON object with title, description, h1, og, etc. |
| `errors` | blob | JSON array of JS errors/warnings |
| `responsive_issues` | blob | JSON array of viewport layout issues |
| `comparison_data` | blob | JSON object with live comparison timing |

---

## Full workflow

1. Run the Python auditor to generate `audit_results.json` and `audit_results.csv`
2. Import `audit_results.json` via `/admin/route-manager/import` → Audit JSON tab
3. Review the listing — spot slow pages, JS errors, responsive issues, missing SEO
4. Use the **Edit** modal to add SEO overrides for important paths
5. Use **Hide** to restrict any paths that should not be public
6. Export CSV, edit in bulk, re-import via CSV tab
7. Re-run the auditor periodically and re-import to track changes over time
