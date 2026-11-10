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
        'read_summary' => 'WordPress rows read from :filename: :count.',
        'omitted_summary' => 'Malformed page or post items omitted from this partial preview: :count. Diagnostics shown: :shown. Repair the export and preview it again.',
        'item_omitted' => 'Item :item skipped: :reason',
        'missing_id' => 'missing wp:post_id',
        'missing_title' => 'missing title',
        'invalid_item' => 'invalid item',
        'executed_summary' => 'Pages imported: :pages. Page URLs imported: :page_urls. Migration Assistant session: :session.',
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
