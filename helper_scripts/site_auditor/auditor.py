#!/usr/bin/env python3
"""
Site Auditor — crawls a Drupal site and exports audit_results.json + audit_results.csv.

Usage:
    python auditor.py --url https://clauding8-model-b.ddev.site
    python auditor.py --url https://clauding8-model-b.ddev.site --drush "ddev drush"
    python auditor.py --url https://local.ddev.site --compare https://live.example.com

--drush "ddev drush"  Seeds the URL queue from the Drupal path_alias table and
                      node entity list so every published page is audited, not
                      just those reachable by following links.
"""

import argparse
import asyncio
import json
import re
import subprocess
import sys
import time
from pathlib import Path
from urllib.parse import urljoin, urlparse

import pandas as pd
import yaml
from bs4 import BeautifulSoup
from playwright.async_api import async_playwright, TimeoutError as PlaywrightTimeoutError


# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

VIEWPORTS = [
    {"width": 375,  "height": 812,  "label": "375"},
    {"width": 768,  "height": 1024, "label": "768"},
    {"width": 1440, "height": 900,  "label": "1440"},
]

SCRIPT_DIR     = Path(__file__).parent
OUTPUT_JSON    = SCRIPT_DIR / "audit_results.json"
OUTPUT_CSV     = SCRIPT_DIR / "audit_results.csv"
SCREENSHOTS_DIR = SCRIPT_DIR / "screenshots"

AXE_CDN = "https://cdnjs.cloudflare.com/ajax/libs/axe-core/4.8.3/axe.min.js"

# Paths to skip — Drupal system/admin paths that are not public content
SKIP_PREFIXES = (
    "/admin", "/core", "/modules", "/themes", "/sites/default/files",
    "/user", "/update.php", "/install.php", "/cron", "/batch",
    "/search", "/node/add", "/media/add",
)
SKIP_EXTENSIONS = (
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".svg",
    ".pdf", ".doc", ".docx", ".xls", ".xlsx",
    ".css", ".js", ".woff", ".woff2", ".ttf",
    ".zip", ".gz", ".tar",
)

CSV_COLUMNS = [
    "category", "url", "status_code",
    "load_time_ms", "ttfb_ms", "request_count", "total_bytes",
    "seo_title", "seo_meta_description", "seo_canonical", "seo_h1", "seo_robots",
    "images_total", "images_missing_alt", "images_broken",
    "error_count", "responsive_issue_count", "a11y_violation_count", "cls",
]


# ---------------------------------------------------------------------------
# DDEV URL auto-detection
# ---------------------------------------------------------------------------

def detect_ddev_url() -> str | None:
    search = Path(__file__).resolve().parent
    for _ in range(6):
        candidate = search / ".ddev" / "config.yaml"
        if candidate.exists():
            with open(candidate) as f:
                cfg = yaml.safe_load(f)
            name = cfg.get("name")
            if name:
                return f"https://{name}.ddev.site"
        search = search.parent
    return None


# ---------------------------------------------------------------------------
# Categorisation
# ---------------------------------------------------------------------------

def categorise_path(path: str) -> str:
    """Derive a human-readable category from the first path segment."""
    parts = [p for p in path.strip("/").split("/") if p]
    if not parts:
        return "homepage"
    return parts[0]


# ---------------------------------------------------------------------------
# Drupal URL seeding via drush
# ---------------------------------------------------------------------------

def seed_urls_from_drush(drush_cmd: str, base_url: str) -> list[str]:
    """
    Query the Drupal database for all published path aliases and bare node
    paths, returning absolute URLs to seed the crawl queue.
    """
    urls: list[str] = []
    base = base_url.rstrip("/")

    # 1. All active path aliases
    alias_sql = "SELECT alias FROM path_alias WHERE langcode != 'und' AND status = 1"
    try:
        out = subprocess.check_output(
            f'{drush_cmd} sql:query "{alias_sql}"',
            shell=True, text=True, stderr=subprocess.DEVNULL
        )
        for line in out.splitlines():
            line = line.strip()
            if line and not line.startswith("alias"):   # skip header
                path = line if line.startswith("/") else "/" + line
                urls.append(base + path)
    except subprocess.CalledProcessError:
        print("  Warning: could not query path_alias table via drush.", flush=True)

    # 2. Published nodes that may have no alias (bare /node/NID)
    node_sql = "SELECT nid FROM node_field_data WHERE status = 1"
    try:
        out = subprocess.check_output(
            f'{drush_cmd} sql:query "{node_sql}"',
            shell=True, text=True, stderr=subprocess.DEVNULL
        )
        for line in out.splitlines():
            line = line.strip()
            if line and line.isdigit():
                urls.append(f"{base}/node/{line}")
    except subprocess.CalledProcessError:
        print("  Warning: could not query node table via drush.", flush=True)

    print(f"  Drush seeded {len(urls)} URLs.", flush=True)
    return urls


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def normalise_url(url: str) -> str:
    return url.rstrip("/")


def is_internal(href: str, base: str) -> bool:
    if not href:
        return False
    if href.startswith(("mailto:", "tel:", "javascript:", "#")):
        return False
    parsed = urlparse(href)
    base_parsed = urlparse(base)
    if parsed.netloc and parsed.netloc != base_parsed.netloc:
        return False
    return True


def should_skip(url: str) -> bool:
    path = urlparse(url).path.lower()
    if any(path.startswith(p) for p in SKIP_PREFIXES):
        return True
    if any(path.endswith(ext) for ext in SKIP_EXTENSIONS):
        return True
    return False


def path_of(url: str) -> str:
    p = urlparse(url)
    return p.path or "/"


def safe_filename(path: str) -> str:
    return re.sub(r"[^a-zA-Z0-9_-]", "_", path.strip("/")) or "root"


# ---------------------------------------------------------------------------
# Per-page audit
# ---------------------------------------------------------------------------

async def audit_page(
    page,
    url: str,
    base_url: str,
    compare_base: str | None,
) -> dict:
    result = {
        "url": path_of(url),
        "full_url": url,
        "category": categorise_path(path_of(url)),
        "status_code": None,
        "load_time_ms": None,
        "ttfb_ms": None,
        "request_count": 0,
        "total_bytes": 0,
        "seo": {},
        "images": {"total": 0, "missing_alt": 0, "broken": 0},
        "errors": [],
        "responsive_issues": [],
        "accessibility": [],
        "performance_vitals": {},
        "live_comparison": None,
    }

    js_errors: list[dict] = []

    def on_console(msg):
        if msg.type in ("error", "warning"):
            js_errors.append({"type": msg.type, "message": msg.text})

    def on_pageerror(err):
        js_errors.append({"type": "js_error", "message": str(err)})

    page.on("console", on_console)
    page.on("pageerror", on_pageerror)

    request_sizes: dict[str, int] = {}

    async def on_response(response):
        try:
            headers = await response.all_headers()
            cl = headers.get("content-length")
            request_sizes[response.url] = int(cl) if cl else 0
        except Exception:
            pass

    page.on("response", on_response)

    # ---- Navigate ----
    t0 = time.perf_counter()
    try:
        response = await page.goto(url, wait_until="networkidle", timeout=30_000)
    except PlaywrightTimeoutError:
        try:
            response = await page.goto(url, wait_until="domcontentloaded", timeout=15_000)
        except Exception as e:
            result["errors"].append({"type": "navigation_error", "message": str(e)})
            return result

    load_ms = int((time.perf_counter() - t0) * 1000)
    if response:
        result["status_code"] = response.status

    result["load_time_ms"] = load_ms
    result["request_count"] = len(request_sizes)
    result["total_bytes"] = sum(request_sizes.values())

    # ---- TTFB ----
    try:
        ttfb = await page.evaluate("""() => {
            const e = performance.getEntriesByType('navigation')[0];
            return e ? Math.round(e.responseStart - e.startTime) : null;
        }""")
        result["ttfb_ms"] = ttfb
    except Exception:
        pass

    # ---- CLS ----
    try:
        cls_value = await page.evaluate("""() => {
            return new Promise(resolve => {
                let cls = 0;
                const obs = new PerformanceObserver(list => {
                    for (const entry of list.getEntries())
                        if (!entry.hadRecentInput) cls += entry.value;
                });
                try { obs.observe({type: 'layout-shift', buffered: true}); } catch(e) {}
                setTimeout(() => { obs.disconnect(); resolve(cls); }, 1500);
            });
        }""")
        result["performance_vitals"]["cls"] = round(cls_value, 4) if cls_value is not None else None
    except Exception:
        pass

    # ---- SEO extraction ----
    html = await page.content()
    soup = BeautifulSoup(html, "lxml")

    title_tag    = soup.find("title")
    meta_desc    = soup.find("meta", attrs={"name": re.compile(r"^description$", re.I)})
    canonical_tag = soup.find("link", attrs={"rel": "canonical"})
    robots_tag   = soup.find("meta", attrs={"name": re.compile(r"^robots$", re.I)})
    h1_tags      = [t.get_text(strip=True) for t in soup.find_all("h1")]
    h2_tags      = [t.get_text(strip=True) for t in soup.find_all("h2")]

    og_tags = {}
    for tag in soup.find_all("meta", attrs={"property": re.compile(r"^og:", re.I)}):
        og_tags[tag.get("property", "")] = tag.get("content", "")

    structured_data = []
    for tag in soup.find_all("script", attrs={"type": "application/ld+json"}):
        try:
            structured_data.append(json.loads(tag.string or "{}"))
        except Exception:
            pass

    result["seo"] = {
        "title":            title_tag.get_text(strip=True) if title_tag else None,
        "meta_description": meta_desc.get("content") if meta_desc else None,
        "canonical":        canonical_tag.get("href") if canonical_tag else None,
        "robots":           robots_tag.get("content") if robots_tag else None,
        "h1":  h1_tags,
        "h2":  h2_tags[:5],
        "og":  og_tags,
        "structured_data": structured_data,
    }

    # ---- Image audit ----
    try:
        img_data = await page.evaluate("""() => {
            const imgs = Array.from(document.querySelectorAll('img'));
            return {
                total:       imgs.length,
                missing_alt: imgs.filter(i => !i.getAttribute('alt') && i.getAttribute('alt') !== '').length,
                broken:      imgs.filter(i => !i.complete || i.naturalWidth === 0).length,
            };
        }""")
        result["images"] = img_data
    except Exception:
        pass

    result["errors"].extend(js_errors)

    # ---- Responsive checks ----
    for vp in VIEWPORTS:
        await page.set_viewport_size({"width": vp["width"], "height": vp["height"]})
        try:
            await page.goto(url, wait_until="domcontentloaded", timeout=20_000)
        except Exception:
            continue

        try:
            overflow = await page.evaluate(
                "() => document.documentElement.scrollWidth > window.innerWidth"
            )
            fixed_overflow = await page.evaluate("""() => {
                for (const el of document.querySelectorAll('*')) {
                    if (window.getComputedStyle(el).position === 'fixed' &&
                        el.getBoundingClientRect().width > window.innerWidth)
                        return true;
                }
                return false;
            }""")
        except Exception:
            continue

        issues = []
        if overflow:       issues.append("horizontal_overflow")
        if fixed_overflow: issues.append("fixed_element_overflow")

        if issues:
            SCREENSHOTS_DIR.mkdir(exist_ok=True)
            fname = f"{safe_filename(path_of(url))}_{vp['label']}.png"
            try:
                await page.screenshot(path=str(SCREENSHOTS_DIR / fname), full_page=False)
            except Exception:
                fname = None
            for issue in issues:
                result["responsive_issues"].append({
                    "viewport": vp["label"],
                    "issue": issue,
                    "screenshot": f"screenshots/{fname}" if fname else None,
                })

    # Reset viewport
    await page.set_viewport_size({"width": 1440, "height": 900})

    # ---- Accessibility via axe-core ----
    try:
        await page.goto(url, wait_until="domcontentloaded", timeout=20_000)
        await page.add_script_tag(url=AXE_CDN)
        await page.wait_for_function("() => typeof window.axe !== 'undefined'", timeout=10_000)
        axe_results = await page.evaluate("""async () => {
            const r = await window.axe.run();
            return r.violations.map(v => ({
                id: v.id, impact: v.impact,
                description: v.description, nodes: v.nodes.length,
            }));
        }""")
        result["accessibility"] = axe_results
    except Exception as e:
        result["accessibility"] = [{"type": "axe_error", "message": str(e)}]

    # ---- Live comparison ----
    if compare_base:
        live_url = normalise_url(compare_base) + path_of(url)
        t_live = time.perf_counter()
        try:
            await page.goto(live_url, wait_until="networkidle", timeout=30_000)
            live_ms = int((time.perf_counter() - t_live) * 1000)
            result["live_comparison"] = {
                "live_url": live_url,
                "live_load_time_ms": live_ms,
                "delta_ms": live_ms - (result["load_time_ms"] or 0),
            }
        except Exception as e:
            result["live_comparison"] = {"error": str(e)}

    return result


# ---------------------------------------------------------------------------
# Link discovery helpers
# ---------------------------------------------------------------------------

async def discover_links(page, base_url: str) -> list[str]:
    """Extract all internal href links from the current page."""
    try:
        links = await page.evaluate("""() =>
            Array.from(document.querySelectorAll('a[href]')).map(a => a.href)
        """)
    except Exception:
        return []

    result = []
    for link in links:
        if not is_internal(link, base_url):
            continue
        # Strip fragment and query string
        clean = normalise_url(link.split("#")[0].split("?")[0])
        if not should_skip(clean):
            result.append(clean)
    return result


# ---------------------------------------------------------------------------
# Crawler
# ---------------------------------------------------------------------------

async def crawl(base_url: str, compare_base: str | None, drush_cmd: str | None) -> list[dict]:
    base_url = normalise_url(base_url)

    # Seed queue
    queue: list[str] = [base_url + "/"]

    if drush_cmd:
        print("Seeding URLs from Drupal database via drush…", flush=True)
        db_urls = seed_urls_from_drush(drush_cmd, base_url)
        queue.extend(db_urls)

    visited: set[str] = set()
    results: list[dict] = []

    async with async_playwright() as pw:
        browser = await pw.chromium.launch(headless=True, channel="chrome")
        context = await browser.new_context(
            user_agent="SiteAuditor/1.0 (Playwright)",
            ignore_https_errors=True,
        )
        page = await context.new_page()

        while queue:
            url = queue.pop(0)
            norm = normalise_url(url)
            if norm in visited:
                continue
            if should_skip(url):
                continue
            visited.add(norm)

            print(f"  [{len(visited):>3}] {path_of(url)}", flush=True)

            page_result = await audit_page(page, url, base_url, compare_base)
            results.append(page_result)

            # Discover additional links from this page
            new_links = await discover_links(page, base_url)
            for link in new_links:
                if normalise_url(link) not in visited:
                    queue.append(link)

        await browser.close()

    return results


# ---------------------------------------------------------------------------
# Export
# ---------------------------------------------------------------------------

def export_results(results: list[dict]) -> None:
    with open(OUTPUT_JSON, "w", encoding="utf-8") as f:
        json.dump(results, f, indent=2, ensure_ascii=False)
    print(f"\nJSON saved: {OUTPUT_JSON}")

    rows = []
    for r in results:
        seo  = r.get("seo", {})
        imgs = r.get("images", {})
        rows.append({
            "category":           r.get("category"),
            "url":                r.get("url"),
            "status_code":        r.get("status_code"),
            "load_time_ms":       r.get("load_time_ms"),
            "ttfb_ms":            r.get("ttfb_ms"),
            "request_count":      r.get("request_count"),
            "total_bytes":        r.get("total_bytes"),
            "seo_title":          seo.get("title"),
            "seo_meta_description": seo.get("meta_description"),
            "seo_canonical":      seo.get("canonical"),
            "seo_h1":             "; ".join(seo.get("h1") or []),
            "seo_robots":         seo.get("robots"),
            "images_total":       imgs.get("total", 0),
            "images_missing_alt": imgs.get("missing_alt", 0),
            "images_broken":      imgs.get("broken", 0),
            "error_count":        len(r.get("errors", [])),
            "responsive_issue_count": len(r.get("responsive_issues", [])),
            "a11y_violation_count": len([
                a for a in r.get("accessibility", []) if "id" in a
            ]),
            "cls": r.get("performance_vitals", {}).get("cls"),
        })

    df = pd.DataFrame(rows, columns=CSV_COLUMNS)
    # Sort by category then URL for easy scanning
    df.sort_values(["category", "url"], inplace=True)
    df.to_csv(OUTPUT_CSV, index=False)
    print(f"CSV saved:  {OUTPUT_CSV}")

    # Print a quick summary grouped by category
    print("\n--- Summary by category ---")
    summary = df.groupby("category").agg(
        pages=("url", "count"),
        ok=("status_code", lambda s: (s == 200).sum()),
        errors=("status_code", lambda s: (s != 200).sum()),
        avg_load_ms=("load_time_ms", "mean"),
        missing_meta=("seo_meta_description", lambda s: s.isna().sum()),
        broken_images=("images_broken", "sum"),
    ).reset_index()
    print(summary.to_string(index=False))


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main() -> None:
    parser = argparse.ArgumentParser(description="Drupal site auditor using Playwright.")
    parser.add_argument("--url", help="Base URL to audit (e.g. https://site.ddev.site)")
    parser.add_argument("--compare", metavar="LIVE_URL", help="Production base URL for timing comparison")
    parser.add_argument(
        "--drush", metavar="CMD",
        help='Drush command to seed URLs from DB, e.g. "ddev drush"'
    )
    args = parser.parse_args()

    base_url = args.url
    if not base_url:
        base_url = detect_ddev_url()
        if base_url:
            print(f"Auto-detected DDEV URL: {base_url}")
        else:
            print("ERROR: Could not detect DDEV URL. Pass --url explicitly.", file=sys.stderr)
            sys.exit(1)

    print(f"Starting audit of {base_url}")
    if args.compare:
        print(f"Comparing against:   {args.compare}")
    if args.drush:
        print(f"Drush command:       {args.drush}")

    results = asyncio.run(crawl(base_url, args.compare, args.drush))

    print(f"\nAudited {len(results)} pages.")
    export_results(results)


if __name__ == "__main__":
    main()
