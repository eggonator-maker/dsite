# Site Auditor

A standalone Python crawler that audits a Drupal site for performance, SEO, accessibility and responsive layout issues. Produces `audit_results.json` and `audit_results.csv`, which can be imported into the **Route Manager** Drupal module for visualisation.

---

## Requirements

- Python 3.12+
- Google Chrome installed (Playwright uses `channel="chrome"`)

---

## Setup

```bash
cd helper_scripts/site_auditor

# Create virtual environment
python3 -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate

# Install Python dependencies
pip install -r requirements.txt

# Install Playwright browser binaries (first time only)
playwright install chrome
```

---

## Usage

```bash
# Basic crawl — auto-detects DDEV URL from .ddev/config.yaml
python auditor.py

# Explicit URL
python auditor.py --url https://clauding8-model-b.ddev.site

# Seed all published pages from the Drupal database (recommended for full coverage)
python auditor.py --url https://clauding8-model-b.ddev.site --drush "ddev drush"

# Compare local load times against production
python auditor.py --url https://local.ddev.site --compare https://live.example.com

# All options together
python auditor.py \
  --url https://clauding8-model-b.ddev.site \
  --drush "ddev drush" \
  --compare https://live.example.com
```

### CLI flags

| Flag | Description |
|------|-------------|
| `--url URL` | Base URL to crawl. If omitted, auto-detected from `.ddev/config.yaml` by walking up from the script directory (up to 6 levels). |
| `--drush CMD` | Drush command string (e.g. `"ddev drush"`). Seeds the crawl queue from `path_alias` and `node_field_data` tables so every published page is audited, not just those reachable by following links. |
| `--compare LIVE_URL` | Production base URL. For every audited path the script also fetches `LIVE_URL + path` and records the timing delta. |

---

## What it audits

For each page the script records:

### Performance
| Metric | How measured |
|--------|-------------|
| `load_time_ms` | Wall-clock time from `page.goto()` to `networkidle` |
| `ttfb_ms` | `performance.getEntriesByType('navigation')[0].responseStart` |
| `request_count` | Number of network responses during page load |
| `total_bytes` | Sum of `Content-Length` headers |
| `cls` | Cumulative Layout Shift via `PerformanceObserver` (1.5 s observation window) |

### SEO
- `<title>` text
- `<meta name="description">` content
- `<link rel="canonical">` href
- `<meta name="robots">` content
- All `<h1>` and first 5 `<h2>` texts
- All `<meta property="og:*">` tags
- All `<script type="application/ld+json">` blocks (JSON-LD structured data)

### Images
- Total `<img>` count
- Missing `alt` attribute count
- Broken images (not loaded / zero natural size)

### JavaScript errors
- Console `error` and `warning` messages
- Uncaught page errors

### Responsive layout (3 viewports)
Tested at **375 px**, **768 px**, and **1440 px**:
- `horizontal_overflow` — `document.documentElement.scrollWidth > window.innerWidth`
- `fixed_element_overflow` — any `position: fixed` element wider than the viewport

Screenshots are saved to `screenshots/` **only for pages with issues**.

### Accessibility (axe-core 4.8.3)
Injects axe-core via CDN and runs a full audit. Each violation is recorded with:
- `id` — axe rule ID (e.g. `color-contrast`)
- `impact` — `critical` / `serious` / `moderate` / `minor`
- `description` — human-readable description
- `nodes` — count of affected DOM nodes

### Live comparison (optional)
When `--compare` is used, the same path is fetched on the production URL. Result includes:
- `live_url`
- `live_load_time_ms`
- `delta_ms` — positive means production is slower

---

## Output files

All output is written to `helper_scripts/site_auditor/`.

### `audit_results.json`

Array of per-page objects:

```json
[
  {
    "url": "/about",
    "full_url": "https://site.ddev.site/about",
    "category": "about",
    "status_code": 200,
    "load_time_ms": 450,
    "ttfb_ms": 120,
    "request_count": 23,
    "total_bytes": 450000,
    "seo": {
      "title": "About Us | Site Name",
      "meta_description": "We are ...",
      "canonical": "https://site.ddev.site/about",
      "robots": null,
      "h1": ["About Us"],
      "h2": ["Our Team", "Our History"],
      "og": { "og:title": "About Us", "og:image": "https://..." },
      "structured_data": []
    },
    "images": { "total": 5, "missing_alt": 1, "broken": 0 },
    "errors": [{ "type": "js_error", "message": "Uncaught ReferenceError: foo" }],
    "responsive_issues": [
      { "viewport": "375", "issue": "horizontal_overflow", "screenshot": "screenshots/about_375.png" }
    ],
    "accessibility": [
      { "id": "color-contrast", "impact": "serious", "description": "...", "nodes": 3 }
    ],
    "performance_vitals": { "cls": 0.012 },
    "live_comparison": { "live_url": "https://live.example.com/about", "live_load_time_ms": 890, "delta_ms": -440 }
  }
]
```

### `audit_results.csv`

One row per page with these columns:

```
category, url, status_code,
load_time_ms, ttfb_ms, request_count, total_bytes,
seo_title, seo_meta_description, seo_canonical, seo_h1, seo_robots,
images_total, images_missing_alt, images_broken,
error_count, responsive_issue_count, a11y_violation_count, cls
```

Rows are sorted by `category` then `url`. A category summary is printed to stdout at the end of the run.

### `screenshots/`

PNG screenshots named `{path-slug}_{viewport}.png` — only created when a responsive issue is found on that page at that viewport.

---

## Skipped paths

The following are excluded automatically:

- Paths starting with: `/admin`, `/core`, `/modules`, `/themes`, `/sites/default/files`, `/user`, `/update.php`, `/install.php`, `/cron`, `/batch`, `/search`, `/node/add`, `/media/add`
- Static file extensions: images, PDFs, Office docs, CSS, JS, fonts, archives

---

## Importing results into Route Manager

Once `audit_results.json` is generated, import it into the Drupal module at:

**Administration → Route Manager → Import → Audit JSON tab**

This populates the `route_manager_audit` table and makes performance metrics, error counts, and SEO data visible in the listing page.

See the [Route Manager README](../../web/modules/custom/route_manager/README.md) for details.
