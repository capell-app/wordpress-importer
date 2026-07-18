<?php

declare(strict_types=1);

return [
    'spool' => [
        'disk' => env('CAPELL_WORDPRESS_IMPORTER_DISK'),
        'path' => 'wordpress-importer/spools',
        'chunk_rows' => (int) env('CAPELL_WORDPRESS_IMPORTER_CHUNK_ROWS', 100),
        'max_stored_item_errors' => 100,
        'max_indexed_attachments' => 100000,
        'max_attachments_per_parent' => 1000,
        'max_attachment_index_bytes' => 100 * 1024 * 1024,
    ],
    'media' => [
        'timeout_seconds' => (int) env('CAPELL_WORDPRESS_IMPORTER_MEDIA_TIMEOUT', 20),
        'max_bytes' => (int) env('CAPELL_WORDPRESS_IMPORTER_MEDIA_MAX_BYTES', 50 * 1024 * 1024),
    ],
];
