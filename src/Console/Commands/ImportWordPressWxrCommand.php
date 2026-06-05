<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Console\Commands;

use Capell\WordPressImporter\Actions\BuildWordPressImportPreviewAction;
use Illuminate\Console\Command;
use Override;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

final class ImportWordPressWxrCommand extends Command
{
    protected $signature = 'wordpress-importer:import
        {path : Absolute or relative path to the WordPress WXR export}
        {--json : Output the parsed Migration Assistant preview as JSON}';

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

        $importPreview = BuildWordPressImportPreviewAction::run($path);
        $result = $importPreview->readResult;
        $preview = $importPreview->preview;

        if ((bool) $this->option('json')) {
            $this->output->writeln(json_encode($preview->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return SymfonyCommand::SUCCESS;
        }

        $this->components->info((string) __('capell-wordpress-importer::commands.import.read_summary', [
            'count' => $result->count(),
            'filename' => $result->metadata['filename'] ?? basename($importPreview->path),
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
}
