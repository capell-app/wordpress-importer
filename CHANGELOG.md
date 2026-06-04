# Changelog

All notable changes to `capell-app/wordpress-importer` will be documented in this file.

## Unreleased

### 2026-06-03

#### Changed

- Rewrote the marketplace summary, package description, and `composer.json` description to lead with the guided WordPress migration value proposition.
- Promoted the existing desktop and mobile marketplace hero images alongside the extension card.
- Corrected stale Migration AIOrchestrator copy to reference Migration Assistant.
- Added static WXR preview columns for source identity, old permalinks, media references, Gutenberg markers, and shortcode names.
- Added `wordpress-importer:import` for headless Migration Assistant preview generation.
- Expanded package keywords, docs, and marketplace screenshots to match the supported WordPress WXR workflow.

#### Fixed

- Replaced the stubbed `WordpressImporterHealthCheck` with real diagnostics for SimpleXML availability, WXR reader registration, and Migration Assistant reader contract compatibility.
- Avoided double-loading non-WordPress XML imports by building the generic XML fallback from the already parsed document.

### Earlier

- Prepared package metadata and documentation for ongoing Capell 4.x package work.
