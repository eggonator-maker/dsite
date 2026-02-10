# Prices Block Performance Fix

## Problem

The `/preturi` page was taking **26+ seconds** to render on cold cache. Every request re-rendered from scratch, effectively making the page unusable under any real traffic.

### Root Cause

Prices were stored as a deeply nested tree of Drupal paragraph entities:

```
prices_block (block_content)
  └─ price_main_category ×2
       └─ price_subcategory ×13
            └─ price_service_group ×974
                 └─ price_item ×2,744
```

**Total: 3,733 paragraph entities.**

Drupal's entity API loaded each entity individually — querying base tables, revision tables, and field tables for every single one. This triggered thousands of database queries per page render. The resulting HTML was also 4.8 MB.

The paragraph structure made sense for content that editors manage in the CMS, but prices are **always imported programmatically** (via Drush or the admin import form) and never edited field-by-field. The editorial flexibility of paragraphs was providing no value while costing enormous rendering overhead.

---

## Solution

Replace paragraph entity storage with a **single JSON blob** persisted in the Drupal state API. The block template reads and renders from this JSON directly.

### How it works

**1. Import (`PriceImporterService::import()`)**

Instead of creating thousands of paragraph entities, the importer now:

1. Deletes any legacy paragraph entities (cleanup).
2. Serialises the full `ImportData` tree into a snake_case array and encodes it as JSON.
3. Stores the JSON with `\Drupal::state()->set('prices_block.json', $json)`.
4. Clears `field_main_categories` on the block content entity and calls `$block->save()` — this invalidates the standard `block_content:228` cache tags so the page cache is cleared.

```php
$this->state->set(self::STATE_KEY, json_encode($this->toArray($data), JSON_UNESCAPED_UNICODE));
$block->set('field_main_categories', []);
$block->save();
Cache::invalidateTags($block->getCacheTagsToInvalidate());
```

**2. Preprocess hook (`price_importer.module`)**

`price_importer_preprocess_block()` detects the `prices_block` bundle and injects the decoded JSON as a template variable:

```php
function price_importer_preprocess_block(array &$variables): void {
    $block_content = $variables['elements']['content']['#block_content'];
    if ($block_content->bundle() !== 'prices_block') return;

    $json = \Drupal::state()->get('prices_block.json', '');
    if ($json !== '') {
        $variables['prices_data'] = json_decode($json, TRUE);
    }
}
```

**3. Block template (`block--block-content--type--prices-block.html.twig`)**

The template was rewritten to iterate over `prices_data` — a plain PHP array — instead of traversing paragraph entity references (`.entity`, `.field_subcategories`, etc.). A Twig macro renders each price item.

```twig
{% for category in prices_data %}
  {% for subcategory in category.subcategories %}
    {% for group in subcategory.service_groups %}
      {% for item in group.items %}
        {{ macros.price_item(item) }}
      {% endfor %}
    {% endfor %}
  {% endfor %}
{% endfor %}
```

### JSON structure stored in state

```json
[
  {
    "name": "Laborator",
    "is_expanded": true,
    "subcategories": [
      {
        "name": "Lab Muntenia Medical Competences",
        "anchor_id": "group-lab-muntenia-medical-competences",
        "display_mode": "service_groups",
        "service_groups": [
          {
            "name": "Hemograma",
            "items": [
              { "display_name": "Hemoleucograma completa", "price": 25.0, "appointment_url": "" }
            ]
          }
        ],
        "direct_items": []
      }
    ]
  }
]
```

---

## Results

| Metric | Before | After |
|---|---|---|
| Cold render | ~26s | ~2.6s |
| Warm render | ~26s | ~0.5s |
| HTML size | 4.8 MB | 2.6 MB |
| Paragraph entities | 3,733 | 0 |
| DB queries per render | thousands | ~5–10 |

---

## Cache invalidation flow

1. Admin runs `drush price-import:api` (or uploads CSV).
2. Importer writes new JSON to `key_value` table (`prices_block.json`).
3. Block content entity is saved → invalidates `block_content:228`, `block_content_view` cache tags.
4. Any cached page/render entries tagged with those are cleared.
5. Next anonymous request re-renders from JSON (~2.6s) and the result is cached.

---

## Files changed

| File | Change |
|---|---|
| `web/modules/custom/price_importer/src/Service/PriceImporterService.php` | Replaced paragraph creation with JSON state storage; added `StateInterface` dependency |
| `web/modules/custom/price_importer/price_importer.services.yml` | Added `@state` argument to `price_importer.importer` |
| `web/modules/custom/price_importer/price_importer.module` | New — `hook_preprocess_block` injects `prices_data` |
| `web/themes/custom/nord_bootstrap_sass/templates/block/block--block-content--type--prices-block.html.twig` | Rewritten to render from `prices_data` array |

---

## Notes

- The paragraph field types (`price_main_category`, `price_subcategory`, etc.) still exist in the database schema and are harmless. They are simply no longer populated.
- If manual CMS editing of individual prices is ever needed in the future, the paragraph approach can be restored, but consider a custom table instead for datasets of this size.
- On DDEV (development), all Drupal cache backends are `NullBackend`, so the page cache never stores. On production with a real backend (database/Redis), the warm render will be served from page cache at ~50ms.
