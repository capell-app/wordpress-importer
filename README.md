# WordPress Importer

<!-- prettier-ignore-start -->

## What This Plugin Adds

WordPress Importer is an **Available**, **No schema impact** Capell package in the **Capell Operations** product group. It ships as `capell-app/wordpress-importer` and extends these surfaces: admin, console.

WordPress Importer reads WXR exports, builds an import preview, and imports WordPress content, authors, taxonomies, media references, and source metadata through Migration Assistant. It can also create old-permalink redirects when the URL Manager integration is available.

Operators can preview a WXR migration and run it from the package command, including supported media and permalink handling.

Evidence: [`src/Services/WxrReader.php`](src/Services/WxrReader.php), [`src/Actions/BuildWordPressImportPreviewAction.php`](src/Actions/BuildWordPressImportPreviewAction.php), [`src/Actions/ExecuteWordPressWxrImportAction.php`](src/Actions/ExecuteWordPressWxrImportAction.php), [`src/Actions/CreateWordPressPermalinkRedirectsAction.php`](src/Actions/CreateWordPressPermalinkRedirectsAction.php), [`src/Console/Commands/ImportWordPressWxrCommand.php`](src/Console/Commands/ImportWordPressWxrCommand.php), [`src/Actions/ImportWordPressMediaForPagesAction.php`](src/Actions/ImportWordPressMediaForPagesAction.php), [`tests/Unit/ImportWordPressWxrCommandExecuteTest.php`](tests/Unit/ImportWordPressWxrCommandExecuteTest.php), [`tests/Unit/ImportWordPressMediaForPagesActionTest.php`](tests/Unit/ImportWordPressMediaForPagesActionTest.php).

Status details:

- Status: Available
- Tier: premium
- Bundle: operations
- Composer package: `capell-app/wordpress-importer`
- Namespace: `Capell\WordPressImporter`
- Theme key: not applicable

## Why It Matters

**For developers:** WXR parsing, preview construction, execution, media import, and redirect creation are separated into testable services and Actions.

**For teams:** Migration teams can inspect the proposed move before execution and retain important source relationships and legacy URLs during the transition.

Evidence: [`src/Services/WxrReader.php`](src/Services/WxrReader.php), [`src/Actions/ExecuteWordPressWxrImportAction.php`](src/Actions/ExecuteWordPressWxrImportAction.php), [`tests/Unit/WxrReaderTest.php`](tests/Unit/WxrReaderTest.php), [`tests/Unit/WordPressWxrSpoolTest.php`](tests/Unit/WordPressWxrSpoolTest.php), [`src/Actions/BuildWordPressImportPreviewAction.php`](src/Actions/BuildWordPressImportPreviewAction.php), [`src/Actions/CreateWordPressPermalinkRedirectsAction.php`](src/Actions/CreateWordPressPermalinkRedirectsAction.php), [`tests/Unit/ImportWordPressWxrCommandExecuteTest.php`](tests/Unit/ImportWordPressWxrCommandExecuteTest.php).

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

![Illustrative migration assistant import flow with wordpress wxr available as an import source preview](docs/screenshots/wordpress-wxr-source-selection.png)

![Import session detail for a WordPress WXR import](docs/screenshots/wordpress-import-session.png)

- Illustrative migration assistant import flow with wordpress wxr available as an import source preview (frontend, required evidence).
- Import session detail for a WordPress WXR import (admin, required evidence).
- Completed WordPress import report (frontend, supplementary evidence).
- WordPress import validation errors (frontend, supplementary evidence).
- WordPress import rollback state (frontend, supplementary evidence).

## Technical Shape

- Service providers: `Capell\WordPressImporter\Providers\WordPressImporterServiceProvider`.
- Config files: `packages/wordpress-importer/config/wordpress-importer.php`.
- Extension contracts: `WordPressMediaHostResolver`.
- Listeners: `CreateWordPressRedirectsForCompletingImport`, `ImportWordPressMediaForCompletingImport`.
- Actions: `ApplyWordPressPreviewIdempotencyAction`, `BuildWordPressImportPreviewAction`, `CreateWordPressPermalinkRedirectsAction`, `ExecuteWordPressSpoolSessionAction`, `ExecuteWordPressWxrImportAction`, `ImportWordPressMediaForPagesAction`, `ReadWordPressWxrAction`, `ReauthorizeWordPressWxrSessionAction`, `ResolveWordPressImportedPageIdsAction`, `ResolveWordPressParentPagesAction`, `SpoolWordPressWxrAction`.
- Data objects: `ResolvedWordPressMediaEndpointData`, `WordPressImportPreviewData`, `WordPressPermalinkRedirectReportData`, `WordPressWxrReadData`, `WordPressWxrSpoolData`.
- Command signatures: `wordpress-importer:import`.
- Console command classes: `ImportWordPressWxrCommand`.
- Manifest contributions: `console-command: Capell\WordPressImporter\Manifest\WordPressImporterConsoleCommandContribution`, `health-check: Capell\WordPressImporter\Health\WordpressImporterHealthCheck`.
- Health checks: `Capell\WordPressImporter\Health\WordpressImporterHealthCheck`.

## Data Model

This package has no schema impact. It extends Capell through `console-command` contributions and `health-check` contributions instead of declaring package-owned tables.

## Install Impact

- Required packages: `capell-app/admin`, `capell-app/core`, `capell-app/migration-assistant`.
- Admin navigation: no admin page or resource contribution is declared.
- Admin/editor extensions: none declared.
- Permissions: none declared in `capell.json`.
- Public routes: none declared.
- Database changes: no package migrations declared.
- Config: `config/wordpress-importer.php`.
- Settings: no package settings declared.
- Queues or schedules: none declared.
- Cache tags: none declared.
- Commands: `wordpress-importer:import`.

## Common Pitfalls

- Keep required Capell packages on compatible v4 releases: `capell-app/admin`, `capell-app/core`, `capell-app/migration-assistant`.
- Review package configuration before production-like verification: `config/wordpress-importer.php`.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |

## Quick Start

1. Install the package: `composer require capell-app/wordpress-importer`.
2. Review `config/wordpress-importer.php` before enabling the package.
3. Open a verified package admin surface and confirm WordPress Importer is available.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Admin guide](docs/admin-guide.md)
- Configuration files: [`config/wordpress-importer.php`](config/wordpress-importer.php).
- [Troubleshooting](#troubleshooting)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Migration Assistant](../migration-assistant/README.md), [Url Manager](../url-manager/README.md), [Seo Suite](../seo-suite/README.md).
- Focused tests: `vendor/bin/pest packages/wordpress-importer/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
