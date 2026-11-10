# WordPress Importer reference

<!-- prettier-ignore-start -->

Developer reference for `capell-app/wordpress-importer`. Start with the [README](../README.md) for what the extension does and how to install it.

## Package Details

WordPress Importer is an **Available**, **No schema impact** Capell package in the **Capell Operations** product group. It ships as `capell-app/wordpress-importer` and extends these surfaces: admin, console.

- Status: Available
- Tier: free
- Bundle: operations
- Composer package: `capell-app/wordpress-importer`
- Namespace: `Capell\WordPressImporter`
- Theme key: not applicable

## Technical Shape

### Service providers

- `Capell\WordPressImporter\Providers\WordPressImporterServiceProvider`

### Config files

- `packages/wordpress-importer/config/wordpress-importer.php`

### Extension contracts

- `WordPressMediaHostResolver`

### Listeners

- `CreateWordPressRedirectsForCompletingImport`
- `ImportWordPressMediaForCompletingImport`

### Actions

- `ApplyWordPressPreviewIdempotencyAction`
- `BuildWordPressImportPreviewAction`
- `CreateWordPressPermalinkRedirectsAction`
- `ExecuteWordPressSpoolSessionAction`
- `ExecuteWordPressWxrImportAction`
- `ImportWordPressMediaForPagesAction`
- `ReadWordPressWxrAction`
- `ReauthorizeWordPressWxrSessionAction`
- `ResolveWordPressImportedPageIdsAction`
- `ResolveWordPressParentPagesAction`
- `SpoolWordPressWxrAction`

### Data objects

- `ResolvedWordPressMediaEndpointData`
- `WordPressImportPreviewData`
- `WordPressPermalinkRedirectReportData`
- `WordPressWxrReadData`
- `WordPressWxrSpoolData`

### Command signatures

- `wordpress-importer:import`

### Console command classes

- `ImportWordPressWxrCommand`

### Manifest contributions

- `console-command: Capell\WordPressImporter\Manifest\WordPressImporterConsoleCommandContribution`
- `health-check: Capell\WordPressImporter\Health\WordpressImporterHealthCheck`

### Health checks

- `Capell\WordPressImporter\Health\WordpressImporterHealthCheck`


## Data Model

This package has no schema impact. It extends Capell through `console-command` contributions and `health-check` contributions instead of declaring package-owned tables.

<!-- prettier-ignore-end -->
