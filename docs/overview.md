# WordPress Importer

WordPress Importer registers a WXR XML reader with Capell Migration Assistant.

The reader extracts WordPress posts and pages into a neutral import row shape that Migration Assistant can map, preview, validate, and hand to its own execution flow.

## Extracted Fields

- `source_identity`, `post_id`, `post_type`, `post_title`, `post_name`, `old_permalink`, `link`, `post_content`, `post_excerpt`, `post_status`, `post_date`, and `parent_id`.
- Author login from the WXR `dc:creator` field.
- Category and tag metadata.
- Inline and child attachment URL references when present.
- Flattened `media_urls` plus the first `featured_media_url`.
- `contains_gutenberg_blocks` and extracted shortcode names for later conversion decisions.

## Console Preview

`wordpress-importer:import {path} --json` reads a WXR export and emits the same Migration Assistant preview payload that the admin flow uses. This is intentionally preview-only: it is useful for migration audits, CI fixtures, and scripted source inspection, but it does not write Pages by itself.

## Boundary

This package only owns WordPress WXR parsing, source registration, metadata preservation, and the headless preview command. Migration Assistant owns field mapping, previews, validation, execution, import sessions, notifications, rollback reports, page URL restoration, and parent remapping.

`WxrReader::supportsPath()` stream-sniffs readable XML paths for WXR metadata. `WxrReader::supports()` does not claim extension-only XML, so generic XML stays owned by Migration Assistant's `XmlReader`; direct `WxrReader::read()` calls reject non-WXR XML instead of acting as a fallback parser.

## Installation Audit

- Composer package: `capell-app/wordpress-importer`
- Hard dependencies: `capell-app/admin`, `capell-app/core`, `capell-app/migration-assistant`, `ext-simplexml`
- Database impact: no package-owned migrations; Migration Assistant owns import session persistence
- Public frontend impact: none

In the isolated batch harness, Composer installed both `capell-app/migration-assistant` and `capell-app/wordpress-importer`. Capell extension installation required `capell-app/migration-assistant` to be installed before this package. After installation, admin routes came from Migration Assistant (`/admin/import-sessions`, `/admin/recovery-center/import-pages`, and `/admin/recovery-center/import-sites`); WordPress Importer itself contributes the WXR reader to the import source registry rather than its own route.

## Admin Surfaces

- No standalone Filament page, resource, route, or settings page is owned by this package.
- The visible workflow is the Migration Assistant import flow with WordPress WXR available as a source reader.
- Screenshot capture should focus on the Migration Assistant source selection/import flow with the WordPress WXR source present.

## Screenshot Coverage

See [screenshots.json](screenshots.json) for the screenshot contract. Final capture should seed or upload a small WXR file so the WordPress source selection and parsed-row preview are visible.
