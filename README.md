# WordPress Importer

WordPress WXR import source for Capell Migration Assistant.

## At A Glance

- Package: `capell-app/wordpress-importer`
- Namespace: `Capell\WordPressImporter\`
- Service providers: `packages/wordpress-importer/src/Providers/WordPressImporterServiceProvider.php`
- Capell dependencies: `capell-app/admin`, `capell-app/core`, `capell-app/migration-assistant`
- Third-party dependencies: `ext-simplexml`, `spatie/laravel-package-tools`

## Why It Helps Your Capell Workflow

- Adds WordPress WXR XML parsing as a source for the Migration Assistant workflow.
- Helps owners review WordPress content through Capell preview, validation, and mapping before Migration Assistant executes the import workflow.
- Gives developers a focused source package that keeps WordPress-specific parsing out of the core migration workflow.

## Best Used With

- [Migration Assistant](../migration-assistant/README.md)
- [Media Library](../media-library/README.md)
- [Blog](../blog/README.md)

## What It Adds

- Parses WordPress WXR XML through `ext-simplexml`; extension-only registry checks still select the reader for `.xml`, while direct path-aware probes can stream-sniff WXR files.
- Registers the source reader with Migration Assistant.
- Preserves source identity, old permalinks, taxonomy labels, author logins, media URL references, Gutenberg markers, and shortcode names in the preview payload.
- Adds `wordpress-importer:import {path} --json` for headless preview generation and scripted migration audits.
- Leaves import sessions, execution, media downloads, redirect creation, and rollback reporting to Migration Assistant and companion packages.

## Built With

This package makes its Composer dependencies visible because they are part of the value proposition, not just plumbing. When an upstream package has a public repository, its linked preview card points readers back to the maintainers so their work gets proper credit.

**Capell packages used here**

- [Capell Admin](https://github.com/capell-app/admin)
- [Capell Core](https://github.com/capell-app/core)
- [Capell Migration Assistant](../migration-assistant/README.md)

**Open-source packages used here**

- [PHP SimpleXML extension](https://www.php.net/manual/en/book.simplexml.php) - the PHP XML reader used to parse WordPress WXR export files.
- [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools) - Laravel package bootstrapping for config, migrations, commands, translations, and service provider setup.

**Linked package previews**

[![Spatie Laravel Package Tools GitHub preview](https://opengraph.githubassets.com/capell-readme/spatie/laravel-package-tools)](https://github.com/spatie/laravel-package-tools)

## Screens And Workflow

Screenshots are generated from [docs/screenshots.json](docs/screenshots.json) during package deployment.

## Code Map

| Area      | Path                                        | Purpose                                                           |
| --------- | ------------------------------------------- | ----------------------------------------------------------------- |
| Providers | `packages/wordpress-importer/src/Providers` | Registration, extension hooks, routes, migrations, and resources. |
| Resources | `packages/wordpress-importer/resources`     | Views, translations, assets, and package resources.               |
| Tests     | `packages/wordpress-importer/tests`         | Package-level Pest coverage.                                      |

## Extension Points

- Register Capell extension points, routes, migrations, settings, render hooks, and resources from service providers.

## Install Impact

- Requires `capell-app/migration-assistant`, `capell-app/core`, `capell-app/admin`, and `ext-simplexml`.
- Registers a WordPress WXR source reader with Migration Assistant.
- Registers the `wordpress-importer:import` console command for preview-only WXR parsing.
- Does not add migrations; Migration Assistant owns import sessions and rollback reports.

## Install And Setup

- Install with `composer require capell-app/wordpress-importer` in the host Capell application.
- Install `capell-app/migration-assistant` first; this package registers a source reader for that workflow.
- In this repository, verify package changes with `vendor/bin/pest`; do not use `php artisan`.

## Docs

- [docs index](docs/README.md)
- [credits-and-acknowledgements.md](docs/credits-and-acknowledgements.md)
- [overview.md](docs/overview.md)

## Testing

Run package tests from the repository root:

```bash
vendor/bin/pest packages/wordpress-importer/tests --configuration=phpunit.xml
```
