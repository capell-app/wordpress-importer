<?php

declare(strict_types=1);

use Capell\WordPressImporter\Support\WordPressAttachmentIndex;

it('stores bounded attachment records outside the in-memory WXR row index', function (): void {
    $index = new WordPressAttachmentIndex(maximumAttachments: 2, maximumAttachmentsPerParent: 1, maximumBytes: 1024);

    try {
        $index->add('10', 'https://example.test/one.jpg', 'One');

        expect($index->forParent('10'))->toBe([
            ['url' => 'https://example.test/one.jpg', 'title' => 'One'],
        ])->and($index->forParent('missing'))->toBe([])
            ->and($index->count())->toBe(1)
            ->and(fn () => $index->add('10', 'https://example.test/two.jpg', 'Two'))
            ->toThrow(RuntimeException::class, 'per-parent attachment index limit');
    } finally {
        $index->close();
    }
});

it('caps the disk-backed attachment index by encoded bytes', function (): void {
    $index = new WordPressAttachmentIndex(maximumAttachments: 10, maximumAttachmentsPerParent: 10, maximumBytes: 10);

    try {
        expect(fn () => $index->add('10', 'https://example.test/one.jpg', 'One'))
            ->toThrow(RuntimeException::class, 'attachment index byte limit')
            ->and($index->count())->toBe(0);
    } finally {
        $index->close();
    }
});
