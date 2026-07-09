<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Console\Commands;

use Capell\MigrationAssistant\Actions\Imports\ExecuteExternalPageImportAction;
use Capell\WordPressImporter\Actions\BuildWordPressImportPreviewAction;
use Illuminate\Console\Command;
use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ImportWordPressWxrCommand extends Command
{
    protected $signature = 'wordpress-importer:import
        {path : Absolute or relative path to the WordPress WXR export}
        {--json : Output the parsed Migration Assistant preview (or execution result) as JSON}
        {--execute : Execute the WXR preview into Capell pages, page URLs, media, and redirects}
        {--site-id= : Target Capell site id (required with --execute)}
        {--layout-id= : Target layout id for imported pages (required with --execute)}
        {--type-id= : Target page type/blueprint id for imported pages (required with --execute)}
        {--language-id= : Target language id (defaults to the target site language)}';

    protected $description = 'Read a WordPress WXR export and build a Migration Assistant preview.';

    #[Override]
    public function getDescription(): string
    {
        return (string) __('capell-wordpress-importer::commands.import.description');
    }

    public function handle(): int
    {
        $path = $this->argument('path');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.path_required'));
        }

        if ((bool) $this->option('execute')) {
            return $this->executeImport($path);
        }

        $importPreview = BuildWordPressImportPreviewAction::run($path);
        $result = $importPreview->readResult;
        $preview = $importPreview->preview;

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($preview->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return SymfonyCommand::SUCCESS;
        }

        $this->components->info((string) __('capell-wordpress-importer::commands.import.read_summary', [
            'count' => $result->count(),
            'filename' => $result->metadata['filename'] ?? basename((string) $importPreview->path),
        ]));

        foreach ($preview->errors as $error) {
            $this->components->warn($error);
        }

        $this->table(
            [
                (string) __('capell-wordpress-importer::commands.import.columns.rows'),
                (string) __('capell-wordpress-importer::commands.import.columns.creates'),
                (string) __('capell-wordpress-importer::commands.import.columns.skips'),
                (string) __('capell-wordpress-importer::commands.import.columns.target'),
            ],
            [[count($preview->rows), $preview->creates, $preview->skips, $preview->target]],
        );

        return $preview->errors === [] ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
    }

    private function executeImport(string $path): int
    {
        $importPreview = BuildWordPressImportPreviewAction::run($path);

        $defaultPageAttributes = [
            'site_id' => $this->requiredIntOption('site-id'),
            'layout_id' => $this->requiredIntOption('layout-id'),
            'blueprint_id' => $this->requiredIntOption('type-id'),
        ];

        $languageId = $this->optionalIntOption('language-id');

        if ($languageId !== null) {
            $defaultPageAttributes['language_id'] = $languageId;
        }

        $result = ExecuteExternalPageImportAction::run(
            $importPreview->preview,
            $defaultPageAttributes,
            sourceFilename: basename((string) $importPreview->path),
            targetLabel: 'WordPress WXR import',
        );

        $isSuccess = $result->report->errors === [];

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode([
                'session' => [
                    'id' => $result->session->getKey(),
                    'status' => $result->session->status->value,
                ],
                'report' => [
                    'pages_created' => $result->report->pagesCreated,
                    'page_urls_created' => $result->report->pageUrlsCreated,
                    'errors' => $result->report->errors,
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $isSuccess ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
        }

        $this->components->info((string) __('capell-wordpress-importer::commands.import.executed_summary', [
            'pages' => $result->report->pagesCreated,
            'page_urls' => $result->report->pageUrlsCreated,
            'session' => (string) $result->session->getKey(),
        ]));

        foreach ($result->report->errors as $error) {
            $this->components->error($error);
        }

        return $isSuccess ? SymfonyCommand::SUCCESS : SymfonyCommand::FAILURE;
    }

    private function requiredIntOption(string $option): int
    {
        $value = $this->optionalIntOption($option);

        if ($value === null) {
            throw new RuntimeException((string) __('capell-wordpress-importer::commands.import.option_required', [
                'option' => '--' . $option,
            ]));
        }

        return $value;
    }

    private function optionalIntOption(string $option): ?int
    {
        $value = $this->option($option);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
