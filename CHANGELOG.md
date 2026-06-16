# Changelog

All notable changes to `capell-app/wordpress-importer` will be documented in this file.

## Unreleased

- Normalized imported WordPress content by stripping Gutenberg block comments, replacing shortcodes with safe placeholders, and preserving raw WordPress content in `meta.wordpress.raw_content`.

### 2026-06-07

- Added post-execution WordPress media import for completed Migration Assistant page imports, including media attachment to created Pages, `meta.wordpress.imported_media`, and exact content URL rewrites from WordPress asset URLs to local media URLs.
- Added an XMLReader streaming WXR read path for exports larger than Migration Assistant's DOM safety cap while preserving item-level isolation.

### 2026-06-04

#### Fixed

- Restricted WXR selection to readable path probes so the prepended reader no longer claims generic XML inputs.
- Corrected remaining Migration Assistant naming copy.

### 2026-06-03

#### Changed

- Rewrote the marketplace summary, package description, and `composer.json` description to lead with the guided WordPress migration value proposition.
- Promoted the existing desktop and mobile marketplace hero images alongside the extension card.
- Corrected stale non-Migration Assistant copy to reference Migration Assistant.
- Added static WXR preview columns for source identity, old permalinks, media references, Gutenberg markers, and shortcode names.
- Added `wordpress-importer:import` for headless Migration Assistant preview generation.
- Expanded package keywords, docs, and marketplace screenshots to match the supported WordPress WXR workflow.

#### Fixed

- Replaced the stubbed `WordpressImporterHealthCheck` with real diagnostics for SimpleXML availability, WXR reader registration, and Migration Assistant reader contract compatibility.
- Kept non-WordPress XML imports on Migration Assistant's generic XML reader instead of falling back inside the WXR parser.

### Earlier

- Prepared package metadata and documentation for ongoing Capell 4.x package work.
