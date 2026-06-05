<?php

declare(strict_types=1);

return [
    'import' => [
        'description' => 'Read a WordPress WXR export and build a Migration Assistant preview.',
        'path_required' => 'A WordPress WXR export path is required.',
        'path_missing' => 'WordPress export [:path] could not be found.',
        'invalid_wxr' => 'WordPress export [:path] is not a readable WXR XML export.',
        'read_summary' => 'Read :count WordPress row(s) from :filename.',
        'columns' => [
            'rows' => 'Rows',
            'creates' => 'Creates',
            'skips' => 'Skips',
            'target' => 'Target',
        ],
    ],
];
