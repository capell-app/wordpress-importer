<?php

declare(strict_types=1);

return [
    'import' => [
        'actor_not_found' => 'The selected import actor could not be found.',
        'target_site_not_found' => 'The selected import site could not be found.',
        'description' => 'Read a WordPress WXR export and build a Migration Assistant preview.',
        'path_required' => 'A WordPress WXR export path is required.',
        'path_missing' => 'WordPress export [:path] could not be found.',
        'option_required' => 'The :option option is required when executing a WordPress WXR import.',
        'invalid_wxr' => 'WordPress export [:path] is not a readable WXR XML export.',
        'target_label' => 'WordPress WXR import',
        'read_summary' => 'Read :count WordPress row(s) from :filename.',
        'executed_summary' => 'Imported :pages page(s) and :page_urls page URL(s). Migration Assistant session: :session.',
        'duplicate_source_identity' => 'Skipped because :source already exists in Capell.',
        'redirect_note' => 'Created from a WordPress WXR old permalink after import.',
        'columns' => [
            'rows' => 'Rows',
            'creates' => 'Creates',
            'skips' => 'Skips',
            'target' => 'Target',
        ],
    ],
];
