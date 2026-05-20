# WordPress Importer

WordPress Importer registers a WXR XML reader with the Capell Migration AIOrchestrator.

The reader extracts WordPress posts and pages into a neutral import row shape that MigrationAssistant can map, preview, validate, and execute.

## Extracted Fields

- `post_id`, `post_type`, `post_title`, `post_name`, `link`, `post_content`, `post_excerpt`, `post_status`, `post_date`, and `parent_id`.
- Author login from the WXR `dc:creator` field.
- Category and tag metadata.
- Attachment URL references when present.

## Boundary

This package only owns WordPress WXR parsing and source registration. MigrationAssistant owns field mapping, previews, validation, execution, import sessions, notifications, and rollback reports.

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
