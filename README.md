# WordPress Importer

<!-- prettier-ignore-start -->

## What This Plugin Adds

WordPress Importer is an **Available**, **No schema impact** Capell package in the **Capell Operations** product group. It ships as `capell-app/wordpress-importer` and extends these surfaces: admin, console.

Preview WordPress WXR posts and pages in Capell Migration Assistant with permalink, taxonomy, author, media-reference, Gutenberg, and shortcode metadata preserved.

After install, the package contributes admin-facing extension points. Docs gap: no concrete Filament resource or page was detected.

Status details:

- Status: Available
- Tier: premium
- Bundle: operations
- Composer package: `capell-app/wordpress-importer`
- Namespace: `Capell\WordPressImporter`
- Theme key: not applicable

## Why It Matters

**For developers:** The package gives developers package-owned service providers, Actions, and Data objects instead of pushing this behaviour into core or application code.

**For teams:** Preview WordPress WXR posts and pages in Capell with durable metadata ready for Migration Assistant mapping.

## Screens And Workflow

Screenshot contract: `docs/screenshots.json`.

- Migration Assistant import flow with WordPress WXR available as an import source (admin, required).
- Parsed WordPress WXR rows previewed in Migration Assistant (admin, required).
- Import session detail for a WordPress WXR import (admin, optional).

## Technical Shape

- Service providers: `Capell\WordPressImporter\Providers\WordPressImporterServiceProvider`.
- Listeners: `CreateWordPressRedirectsForCompletedImport`, `ImportWordPressMediaForCompletedImport`.
- Actions: `ApplyWordPressPreviewIdempotencyAction`, `BuildWordPressImportPreviewAction`, `CreateWordPressPermalinkRedirectsAction`, `ImportWordPressMediaForPagesAction`.
- Data objects: `ResolvedWordPressMediaEndpointData`, `WordPressImportPreviewData`, `WordPressPermalinkRedirectReportData`.
- Command signatures: `wordpress-importer:import`.
- Console command classes: `ImportWordPressWxrCommand`.
- Manifest contributions: `console-command: Capell\WordPressImporter\Manifest\WordPressImporterConsoleCommandContribution`, `health-check: Capell\WordPressImporter\Health\WordpressImporterHealthCheck`.
- Health checks: `Capell\WordPressImporter\Health\WordpressImporterHealthCheck`.

## Data Model

This package has no schema impact. It does not declare package-owned migrations or required tables.

Docs gap: document extension points here if the package delegates persistence to a host package.

## Install Impact

- Admin navigation: admin-facing extension points are declared, but no concrete Filament class was detected.
- Permissions: none declared in `capell.json`.
- Public routes: none detected in package route files.
- Database changes: no package migrations declared.
- Settings: no package settings declared.
- Queues or schedules: none detected in standard package paths.
- Cache tags: none declared.
- Commands: `wordpress-importer:import`.

## Common Pitfalls

- Run package commands from the host app; in this repository use `vendor/bin/pest` for package tests.
- Keep `composer.json`, `composer.local.json`, `capell.json`, docs, screenshots, and tests aligned when the package surface changes.

## Troubleshooting

| Symptom | Likely cause | Check | Fix |
| --- | --- | --- | --- |
| Package surface is missing after install | Provider or manifest is not loaded | Confirm `capell.json`, package `composer.json`, and provider registration | Reinstall the package, refresh Composer autoload, and clear host caches |
| Background work does not run | Queue worker or scheduled command is not active | Check package jobs, commands, and host scheduler configuration | Start the queue or scheduler, then run the focused command or package test |

## Quick Start

1. Install the package: `composer require capell-app/wordpress-importer`.
2. Run the required setup: no package migrations are declared; clear cached config and routes if the host app uses caches.
3. Verify the package provider is registered and the related frontend, command, or extension point is active.

## Next Steps

- [Package docs](docs/README.md)
- [Overview](docs/overview.md)
- [Screenshot contract](docs/screenshots.json)
- [Marketplace assets](docs/assets/marketplace/)
- [Capell content language plan](../../docs/CONTENT_LANGUAGE_PLAN.md)
- [Capell documentation design system](../../docs/DESIGN_SYSTEM.md)
- [Capell and package ERD notes](../../docs/erd/capell-and-package-erds.md)
- Related packages: [Migration Assistant](../migration-assistant/README.md), [Url Manager](../url-manager/README.md), [Seo Suite](../seo-suite/README.md).
- Focused tests: `vendor/bin/pest packages/wordpress-importer/tests --configuration=phpunit.xml`.

<!-- prettier-ignore-end -->
