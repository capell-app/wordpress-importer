# WordPress Importer — Improvement & Growth Plan

> Package: capell-app/wordpress-importer · Kind: package · Tier: premium · Product group: Capell Operations · Bundle: operations · Status: Draft

## 1. Snapshot

WordPress Importer is a thin adapter: it registers one `WxrReader` (`src/Services/WxrReader.php`) into Migration Assistant's `ImportSourceRegistry` (prepended ahead of MA's own `XmlReader`) so `.xml` uploads are sniffed for a WordPress WXR namespace and parsed into MA's neutral `ExternalImportReadResult` row shape. The entire package is three PHP files — the reader, a Spatie-package-tools service provider (`src/Providers/WordPressImporterServiceProvider.php`), and a near-empty health-check class (`src/Health/WordpressImporterHealthCheck.php`). It declares **no migrations, no settings, no permissions, no Filament resources, no commands** (`capell.json` `database`/`commands`/`settings`/`permissions` all empty); surfaces are `["admin","console"]` but it contributes no admin UI or console command of its own — those surfaces are entirely Migration Assistant's. Relationship to migration-assistant: hard `requires` dependency; WordPress Importer is a pure **consumer/extension** of MA contracts (`ImportSourceReader`, `ExternalImportReadResult`, `SafeXmlLoader`, `XmlReader`) and owns none of the import session, preview, execution, or rollback machinery.

Current marketplace summary (verbatim): _"WordPress Importer adds WordPress WXR XML parsing to the Capell Migration Assistant workflow."_ Screenshot count in `capell.json.marketplace.screenshots`: **1** (`docs/assets/marketplace/extension-card.jpg`). Mismatch: `docs/screenshots.json` references **3 different** PNG paths (`docs/screenshots/wordpress-wxr-source-selection.png`, `…-preview.png`, `wordpress-import-session.png`) that **do not exist on disk**; the only real assets are `extension-card.jpg`, `hero-desktop.jpg`, `hero-mobile.jpg`.

## 2. Improvements (existing functionality)

1. **Make the Migration Assistant registry path-aware for WXR selection** — why: `WxrReader::supportsPath()` can now stream-sniff full readable XML paths and refuse generic XML, and the package-owned preview Action rejects non-WXR XML before building a WordPress preview. MA's current `ImportSourceRegistry` still passes only `pathinfo(..., PATHINFO_EXTENSION)` to reader contracts, so this reader must still claim extension-only `xml` to preserve the existing WXR upload path until MA can pass a path or source descriptor — `src/Services/WxrReader.php`, `src/Actions/BuildWordPressImportPreviewAction.php`, migration-assistant registry — M.
2. **Stop double-loading the file** — why: direct `WxrReader::read()` calls now parse non-WXR XML once and build the generic XML fallback from the in-memory `SimpleXMLElement`, while `supports()` uses a streaming WXR probe where a readable path is available. Solving extension-only registry selection cleanly requires a shared source descriptor or parsed-document handoff in Migration Assistant — `src/Services/WxrReader.php`, migration-assistant registry — S.
3. **Surface CDATA/excerpt edge cases as columns consistently** — why: `columnsFor()` derives columns from the union of row keys (`src/Services/WxrReader.php:147-150`), but every row always has the same fixed keys from `rowFromItem()`, so the union logic is dead complexity; a static column list would be clearer and cheaper — `src/Services/WxrReader.php` — S.
4. **Health check is a stub that asserts nothing** — why: `WordpressImporterHealthCheck` implements only `compatibleCapellApiVersion()` returning `'^4.0'` (`src/Health/WordpressImporterHealthCheck.php`), yet `capell.json` advertises it as `severity: critical` with label _"surfaces, providers, and install health are discoverable by Diagnostics."_ It cannot detect a failed reader registration, a missing `ext-simplexml`, or MA contract drift. Add real probes (registry contains a `WxrReader`; `ext-simplexml` loaded) — `src/Health/WordpressImporterHealthCheck.php` — M.
5. **Pin PHP to 8.4 to match Capell baseline** — why: `composer.json` requires `php: ^8.3` while the Capell skill mandates PHP 8.4-compatible code; align to avoid shipping on an unsupported runtime — `composer.json:7` — S.
6. **README "Built With" omits the hard `ext-simplexml` requirement from its dependency narrative while listing it elsewhere** — minor doc consistency; ensure the parser dependency is stated once authoritatively — `README.md` / `composer.json:8` — S.

## 3. Missing Features (gaps)

The package advertises capabilities `wordpress-importer`, `wordpress-importer-admin`, `wordpress-importer-console` (`capell.json`), but in practice delivers only WXR-to-row parsing. Against WordPress-import norms:

- **Done/Shipped for preview/console: extracted WordPress fields now map into explicit `meta.wordpress.*` metadata.** `BuildWordPressImportPreviewAction` owns the WXR-specific field mapping for the package's headless preview/console path, so categories, tags, author login, media URLs, old permalinks, IDs, Gutenberg/shortcode signals, and related WordPress metadata no longer fall into generic `meta.imported.*`. This does not yet solve Migration Assistant execution, taxonomy/author resolution, media ingest, or admin mapping UI. Evidence: `ImportWordPressWxrCommand` delegates to the Action, and `WxrReaderTest` asserts explicit `meta.wordpress.*` output with no `meta.imported` fallback. — `src/Actions/BuildWordPressImportPreviewAction.php`, `src/Data/WordPressImportPreviewData.php`, `src/Console/Commands/ImportWordPressWxrCommand.php`, `tests/Unit/WxrReaderTest.php`
- **No execution path for external reader rows at all.** `ExternalImportReadResult` rows are only consumed by `ExternalImportPreviewBuilder::build()` (preview), which emits `action:'create'` descriptors but is wired to nothing that writes Pages — `StartPageImportAction` operates on `$package->payload` (a zip package), not external rows (migration-assistant `src/Actions/Imports/StartPageImportAction.php:51`). So a WXR import can be _previewed_ but there is no evidence it can be _executed into Pages_. This is the single biggest functional gap and must be confirmed with MA owners.
- **Media import not performed.** Only the first `wp:attachment_url` is captured as a `{url,title}` reference (`src/Services/WxrReader.php:130-141`); nothing downloads it into Spatie media or rewrites in-content image URLs. Table-stakes for WP migration.
- **Gutenberg blocks / shortcodes passed through verbatim.** `post_content` is stored raw (`src/Services/WxrReader.php:93`); `<!-- wp:* -->` block comments and `[shortcode]` markup land untouched in `meta.content`. No converter to Capell components/blocks. Major differentiator.
- **No redirect preservation.** WXR `link` is extracted but unused; no integration with `url-manager` to map old WP permalinks → new Capell URLs. High-value for SEO retention.
- **Custom post types dropped.** `read()` hard-filters to `['page','post']` (`src/Services/WxrReader.php:42`); CPTs, attachments-as-posts, nav menus, and comments are skipped. No configurability.
- **Author mapping is a raw login string** (`dc:creator`), not resolved to a Capell user. No mapping UI.
- **Comments not imported** (norm for blog migrations; ties to the blog package).
- **No console command** despite the `console`/`wordpress-importer-console` capability — nothing in `commands` (`capell.json`) and no `Console/` dir. A headless `wordpress-importer:import <file>` would justify the console surface.

## 4. Issues / Risks

- **Capability/manifest mismatch (false advertising).** `capabilities[]` claims admin + console surfaces and `surfaces:["admin","console"]`, but the package ships no admin contribution and no command. `contributes: []`. Either implement or downscope the manifest — `capell.json`.
- **Health check overstated as `critical` but inert.** See §2.4. A critical-severity check that probes nothing is worse than none — it signals green while registration could be broken — `src/Health/WordpressImporterHealthCheck.php`, `capell.json`.
- **Screenshot manifest is broken.** `docs/screenshots.json` lists 3 PNGs that don't exist; `capell.json` lists 1 JPG. Marketplace rendering will 404 or under-represent — `docs/screenshots.json`, `capell.json`.
- **Memory / streaming on large exports.** Both `SafeXmlLoader::loadFile` and the WXR parse build a full `SimpleXMLElement` DOM in memory, capped at 50MB (migration-assistant `src/Support/Xml/SafeXmlLoader.php` `DEFAULT_MAX_BYTES`). Real WordPress exports routinely exceed this; there is no `XMLReader`-style streaming pull-parser. A 60MB export throws rather than imports. No chunking/`<wp:wxr_version>`-aware pagination — `src/Services/WxrReader.php:27`.
- **Malformed-WXR resilience is thin.** `read()` throws `RuntimeException` if `channel->item` is absent (`src/Services/WxrReader.php:35`); individual malformed items are not individually skipped/reported — one bad node can abort the whole parse. No per-row error collection (MA's preview builder collects row errors, but the reader can fail before it).
- **DOCTYPE/XXE: covered upstream, not owned.** Security rests entirely on MA's `SafeXmlLoader` (DOCTYPE rejection, `LIBXML_NONET`, null entity loader). The package test `rejects WordPress WXR imports with doctype declarations` (`tests/Unit/WxrReaderTest.php:95`) asserts this, which is good — but if MA ever relaxes `SafeXmlLoader`, this package silently inherits the regression. Add a contract/regression test pinning the expectation.
- **Idempotency / resumability: none.** No de-dup on `post_id` or slug; re-running a WXR import would create duplicate Pages (assuming an execution path exists). No resume token.
- **Test coverage gaps.** `tests/Unit/WxrReaderTest.php` covers registration, WP posts/pages read, generic-XML delegation for the reader, package-owned preview rejection for generic XML, and DOCTYPE rejection. **Not covered:** CPT filtering, multi-attachment items (only first is read — untested), empty/missing `wp:` namespace fallback path, oversize-file rejection, malformed item, category-vs-tag domain disambiguation under odd `domain` attrs, the health check, the manifest↔asset consistency. No Feature/Integration test proving an end-to-end WXR→Page outcome.
- **Performance budget is nominal.** `capell.json.performance.adminQueryBudget: 40`, `frontendRenderBudgetMs: 0`, `cacheable:false` — reasonable for a console/parse package, but there's no test or benchmark enforcing parse-time on a representative export.
- **i18n.** Reader exception strings (`src/Services/WxrReader.php:24,35`) are hardcoded English `sprintf`s, not translated — acceptable for developer-facing exceptions, but admin-surfaced errors should be translatable per Capell conventions.
- **CHANGELOG is a placeholder** — single "Unreleased" bullet (`CHANGELOG.md:5-7`); no shipped version history despite `version: 4.x-dev`.

## 5. Marketplace & Selling

**Critique.** Current `summary` and composer `description` are functionally identical and developer-jargon ("WXR XML parsing to the Capell Migration Assistant workflow") — they describe a mechanism, not an outcome, and bury the buyer benefit (escape WordPress). Earlier copy also leaked stale non-Migration Assistant naming into composer and docs; keep this package consistently framed around Migration Assistant.

**Improved 1-sentence summary:**

> Migrate your WordPress site into Capell — import posts, pages, and media from a standard WXR export, preview every change, and roll back safely.

**Improved 3–4 sentence description:**

> WordPress Importer turns a standard WordPress WXR export into a guided Capell migration. It reads your posts and pages, maps WordPress fields onto Capell's page schema, and hands them to Migration Assistant for preview, validation, mapping, and one-click rollback — so you see exactly what will change before anything is written. Built to extend rather than replace, it slots into the Operations bundle alongside Migration Assistant and pairs with URL Manager to preserve your old permalinks and SEO equity. The fastest, lowest-risk path off WordPress and onto Capell.

(Note: the description should only promise media import / URL preservation **after** §3 gaps are closed; today it would overstate.)

**Screenshot/media gaps.** Reconcile `docs/screenshots.json` (3 missing PNGs) with `capell.json` (1 JPG). Produce the three real screenshots the JSON already names: WXR source selection, parsed preview, import session — these are the exact funnel-proof images a buyer wants. Add the existing `hero-desktop.jpg`/`hero-mobile.jpg` to the manifest (they exist but aren't referenced in `capell.json.marketplace`).

**Pricing/tier/bundle positioning.** Tier `premium`, bundle `operations`, license `paid`/`first-party`/priority support (`capell.json.commercial`). WordPress import is a **top-of-funnel acquisition driver** — it is often the reason a prospect evaluates Capell at all. Consider positioning the _parser_ as a low-friction lead-in (even bundling read/preview free) and monetising the _execution + media + redirect_ layer as the premium hook, maximising trial-to-paid conversion. Keep it inside the Operations bundle so it cross-sells the bundle.

**Cross-sell (deps + Extension Suites).** Hard dep on **migration-assistant** (the engine — always co-sold). Natural attach: **url-manager** (preserve WP permalinks → redirects; today `link` is parsed but unused — the integration is half-built), **seo-suite** (redirect opportunity reports already consume `suggestedTargetUrl`), **media-library** (download `wp:attachment_url` into Spatie media). Frame as a "WordPress Migration Suite."

**Differentiators / value props / target buyer.** Differentiators (once §3 is delivered): Gutenberg-block→Capell-component conversion, permalink/redirect preservation, media ingest, author mapping. Value props: lower switching cost, no SEO loss, full preview + rollback. Target buyer: agencies and site owners migrating off WordPress; the developer evaluating Capell as a WP replacement.

**Keywords/tags (8–12):** wordpress, wxr, migration, importer, cms-migration, content-migration, posts, pages, gutenberg, permalinks, seo-redirects, capell. (Composer currently lists only 5: `capell`, `cms`, `laravel`, `wordpress`, `migration` — `composer.json:38-44`.)

## 6. Prioritized Roadmap

| Item                                                                                  | Bucket | Effort | Impact                | Section ref |
| ------------------------------------------------------------------------------------- | ------ | ------ | --------------------- | ----------- |
| Confirm/build an execution path from external WXR rows → Pages (with MA owners)       | Now    | L      | Critical              | §3          |
| Done/Shipped for preview/console: Map WP categories/tags/author/media instead of dumping to `meta.imported.*`. Evidence: `BuildWordPressImportPreviewAction` applies package-owned WXR mappings into `meta.wordpress.*`, and `WxrReaderTest` covers categories, tags, author login, media URLs, featured media, and no `meta.imported` fallback. | Done | M | High | §2.1, §3 |
| Make Migration Assistant registry path-aware for WXR reader selection                 | Now    | M      | High                  | §2.1, §2.2  |
| Reconcile screenshots (`screenshots.json` ↔ `capell.json`) and add real images        | Now    | S      | High                  | §4, §5      |
| Keep Migration Assistant naming consistent in description/docs                        | Now    | S      | Med                   | §5          |
| Implement real health-check probes (registry + ext-simplexml)                         | Now    | M      | Med                   | §2.4, §4    |
| Pin PHP to ^8.4; refresh keywords; rewrite marketplace summary/description            | Now    | S      | Med                   | §2.5, §5    |
| Media import: download `wp:attachment_url` into Spatie media + rewrite content URLs   | Next   | L      | High                  | §3          |
| Permalink → redirect preservation via url-manager integration                         | Next   | M      | High                  | §3, §5      |
| Per-item error isolation + idempotent re-import (de-dup on `post_id`/slug)            | Next   | M      | Med                   | §4          |
| Streaming `XMLReader` parser for exports > 50MB                                       | Next   | L      | Med                   | §4          |
| Headless `wordpress-importer:import` console command (justify console surface)        | Next   | M      | Med                   | §3, §4      |
| Add Feature/Integration test: end-to-end WXR → Page outcome                           | Next   | M      | High                  | §4          |
| Gutenberg block + shortcode → Capell component conversion                             | Later  | L      | High (differentiator) | §3, §5      |
| Custom post types, comments, nav-menu import (configurable)                           | Later  | L      | Med                   | §3          |
| Downscope or implement the `admin`/`console` capabilities to remove manifest mismatch | Later  | S      | Med                   | §4          |
