<?php

declare(strict_types=1);

/*
 * Writes theme-fixture-site.xml: the WordPress export themes are proved against with real imported content.
 * It holds 30 Gutenberg posts (with tables, galleries, quotes, lists and images), 5 pages, 3 authors, 12 terms and a
 * featured image on every post. Output is deterministic, so regenerating it only changes the file when this script does.
 *
 * Run: php packages/wordpress-importer/workbench/fixtures/generate-theme-fixture-site.php
 */

const FIXTURE_HOST = 'https://fixture-site.example.test';

$authors = [
    ['login' => 'amara', 'email' => 'amara@fixture-site.example.test', 'name' => 'Amara Okafor'],
    ['login' => 'tomas', 'email' => 'tomas@fixture-site.example.test', 'name' => 'Tomás Lindqvist'],
    ['login' => 'priya', 'email' => 'priya@fixture-site.example.test', 'name' => 'Priya Raman'],
];

$categories = [
    'field-notes' => 'Field notes',
    'case-studies' => 'Case studies',
    'guides' => 'Guides',
    'company-news' => 'Company news',
    'interviews' => 'Interviews',
    'opinion' => 'Opinion',
];

$tags = [
    'sustainability' => 'Sustainability',
    'design' => 'Design',
    'operations' => 'Operations',
    'community' => 'Community',
    'research' => 'Research',
    'hiring' => 'Hiring',
];

$subjects = [
    'Rebuilding the harbour workshop', 'What a year of four-day weeks taught us', 'Measuring the footprint of a print run',
    'A field guide to quoting small jobs', 'Inside the night shift at the depot', 'Why we stopped chasing every lead',
    'Notes from the regional makers fair', 'Designing forms people finish', 'The case for slower publishing',
    'How the archive team catalogues photographs', 'Lessons from replacing a legacy booking system', 'Pricing repairs honestly',
    'An interview with our first apprentice', 'Planning a site visit that runs on time', 'Reading the data behind seasonal demand',
    'What customers ask before they call', 'Keeping a supplier list that stays current', 'A short history of the old mill',
    'Writing estimates customers understand', 'The tools we retired this year', 'Hiring for judgement, not just skill',
    'Running a community repair day', 'Choosing materials that age well', 'What our complaints taught us',
    'A week with the surveying team', 'Making accessibility part of every brief', 'How we plan a newsletter issue',
    'Turning a quiet month into a project month', 'The questions we ask at handover', 'Ten years at the same address',
];

$paragraphs = [
    'Most of the work happens before anyone picks up a tool. We walk the site, write down what we see, and agree what done looks like with the person paying for it.',
    'The first draft of any plan is wrong in small ways. We budget a morning to find those small ways before they become expensive ones.',
    'Customers rarely remember the price a year later. They remember whether we turned up when we said we would and whether we left the place tidy.',
    'We keep a shared notebook for every job. It records what changed, who agreed to it and why, so nobody has to rely on memory at the end.',
    'Numbers help, but only when they answer a question someone actually asked. We start with the question and work back to the measurement.',
    'Good handovers are boring. Everything is labelled, every warranty is in one folder, and the customer knows who to call if something goes wrong.',
    'A long article has to earn its length. Each section answers one question, and the headings let a busy reader skip straight to theirs.',
    'We test every change with the people who will live with it. Their objections usually point at a real problem we had not noticed.',
];

/**
 * @param  list<string>  $paragraphs
 * @return array{0: string, 1: list<int>} Gutenberg markup and the attachment ids it shows.
 */
function postBody(int $index, array $paragraphs, int $firstAttachmentId): array
{
    $paragraph = static fn (int $offset): string => '<!-- wp:paragraph --><p>' . $paragraphs[($index + $offset) % count($paragraphs)] . '</p><!-- /wp:paragraph -->';
    $attachmentIds = [];
    $blocks = [
        $paragraph(0),
        '<!-- wp:heading --><h2 class="wp-block-heading">What we set out to do</h2><!-- /wp:heading -->',
        $paragraph(1),
        $paragraph(2),
        '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Agree the scope in writing</li><!-- /wp:list-item --><!-- wp:list-item --><li>Check access and parking the day before</li><!-- /wp:list-item --><!-- wp:list-item --><li>Photograph the finished work</li><!-- /wp:list-item --></ul><!-- /wp:list -->',
    ];

    if ($index % 3 === 0) {
        $blocks[] = '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">The numbers</h3><!-- /wp:heading -->';
        $blocks[] = '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>Quarter</th><th>Jobs</th><th>Repeat customers</th></tr></thead><tbody>'
            . '<tr><td>Q1</td><td>' . (40 + $index) . '</td><td>' . (12 + $index % 7) . '</td></tr>'
            . '<tr><td>Q2</td><td>' . (52 + $index) . '</td><td>' . (15 + $index % 5) . '</td></tr>'
            . '<tr><td>Q3</td><td>' . (47 + $index) . '</td><td>' . (18 + $index % 4) . '</td></tr>'
            . '</tbody></table><figcaption class="wp-element-caption">Jobs completed per quarter</figcaption></figure><!-- /wp:table -->';
    }

    if ($index % 4 === 1) {
        $galleryIds = [$firstAttachmentId + 1, $firstAttachmentId + 2, $firstAttachmentId + 3];
        $attachmentIds = [...$attachmentIds, ...$galleryIds];
        $images = implode('', array_map(
            static fn (int $id): string => '<!-- wp:image {"id":' . $id . '} --><figure class="wp-block-image"><img src="' . FIXTURE_HOST . '/wp-content/uploads/gallery-' . $id . '.png" alt="Workshop photograph ' . $id . '" class="wp-image-' . $id . '"/></figure><!-- /wp:image -->',
            $galleryIds,
        ));
        $blocks[] = '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery has-nested-images columns-3">' . $images . '</figure><!-- /wp:gallery -->';
    }

    if ($index % 5 === 2) {
        $blocks[] = '<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>We would rather be on time with a smaller promise than late with a bigger one.</p><!-- /wp:paragraph --><cite>Site manager</cite></blockquote><!-- /wp:quote -->';
    }

    $blocks[] = '<!-- wp:heading --><h2 class="wp-block-heading">What we would do differently</h2><!-- /wp:heading -->';
    $blocks[] = $paragraph(3);
    $blocks[] = $paragraph(4);

    return [implode("\n", $blocks), $attachmentIds];
}

function cdata(string $value): string
{
    return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
}

function slug(string $title): string
{
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: $title)), '-');
}

function attachmentItem(int $id, int $parentId, string $file, string $title): string
{
    $url = FIXTURE_HOST . '/wp-content/uploads/' . $file;

    return <<<XML
        <item>
            <title>{$title}</title>
            <link>{$url}</link>
            <wp:post_id>{$id}</wp:post_id>
            <wp:post_name>{$file}</wp:post_name>
            <wp:post_type>attachment</wp:post_type>
            <wp:post_parent>{$parentId}</wp:post_parent>
            <wp:status>inherit</wp:status>
            <wp:attachment_url>{$url}</wp:attachment_url>
        </item>

XML;
}

$channel = [];

foreach ($authors as $author) {
    $channel[] = "        <wp:author>\n            <wp:author_login>" . cdata($author['login']) . "</wp:author_login>\n            <wp:author_email>" . cdata($author['email']) . "</wp:author_email>\n            <wp:author_display_name>" . cdata($author['name']) . "</wp:author_display_name>\n        </wp:author>\n";
}

foreach ($categories as $slug => $name) {
    $channel[] = "        <wp:category>\n            <wp:category_nicename>" . cdata($slug) . "</wp:category_nicename>\n            <wp:cat_name>" . cdata($name) . "</wp:cat_name>\n        </wp:category>\n";
}

foreach ($tags as $slug => $name) {
    $channel[] = "        <wp:tag>\n            <wp:tag_slug>" . cdata($slug) . "</wp:tag_slug>\n            <wp:tag_name>" . cdata($name) . "</wp:tag_name>\n        </wp:tag>\n";
}

$categorySlugs = array_keys($categories);
$tagSlugs = array_keys($tags);

foreach ($subjects as $index => $title) {
    $postId = 1000 + $index * 10;
    $featuredId = $postId + 1;
    [$content, $galleryIds] = postBody($index, $paragraphs, $featuredId);
    $author = $authors[$index % count($authors)]['login'];
    $slug = slug($title);
    $date = sprintf('2026-%02d-%02d 09:00:00', 1 + intdiv($index, 3), 1 + ($index * 3) % 27);
    $category = $categorySlugs[$index % count($categorySlugs)];
    $tagA = $tagSlugs[$index % count($tagSlugs)];
    $tagB = $tagSlugs[($index + 2) % count($tagSlugs)];
    $excerpt = $paragraphs[$index % count($paragraphs)];

    $channel[] = '        <item>
            <title>' . htmlspecialchars($title, ENT_XML1) . '</title>
            <link>' . FIXTURE_HOST . '/' . substr($date, 0, 4) . '/' . substr($date, 5, 2) . '/' . $slug . '/</link>
            <dc:creator>' . cdata($author) . '</dc:creator>
            <content:encoded>' . cdata($content) . '</content:encoded>
            <excerpt:encoded>' . cdata($excerpt) . '</excerpt:encoded>
            <category domain="category" nicename="' . $category . '">' . cdata($categories[$category]) . '</category>
            <category domain="post_tag" nicename="' . $tagA . '">' . cdata($tags[$tagA]) . '</category>
            <category domain="post_tag" nicename="' . $tagB . '">' . cdata($tags[$tagB]) . '</category>
            <wp:post_id>' . $postId . '</wp:post_id>
            <wp:post_date>' . $date . '</wp:post_date>
            <wp:post_name>' . $slug . '</wp:post_name>
            <wp:status>publish</wp:status>
            <wp:post_type>post</wp:post_type>
            <wp:postmeta>
                <wp:meta_key>' . cdata('_thumbnail_id') . '</wp:meta_key>
                <wp:meta_value>' . cdata((string) $featuredId) . '</wp:meta_value>
            </wp:postmeta>
        </item>
';
    $channel[] = attachmentItem($featuredId, $postId, 'featured-' . $featuredId . '.png', 'Featured image for ' . htmlspecialchars($title, ENT_XML1));

    foreach ($galleryIds as $galleryId) {
        $channel[] = attachmentItem($galleryId, $postId, 'gallery-' . $galleryId . '.png', 'Gallery photograph ' . $galleryId);
    }
}

$pages = [
    'About us' => 'We are a small team that plans carefully, works tidily and writes everything down.',
    'Services' => 'We take on repairs, refits and planned maintenance, and we quote every job in writing first.',
    'Contact' => 'Call the office on weekdays between nine and five, or send a message and we will reply within a day.',
    'Careers' => 'We hire people who explain their decisions and ask before they assume.',
    'Privacy notice' => 'We keep the details you send us only for as long as we need them to do the work you asked for.',
];

$pageIndex = 0;

foreach ($pages as $title => $intro) {
    $pageId = 2000 + $pageIndex++;
    $slug = slug($title);
    $content = '<!-- wp:paragraph --><p>' . $intro . '</p><!-- /wp:paragraph -->' . "\n"
        . '<!-- wp:heading --><h2 class="wp-block-heading">How we work</h2><!-- /wp:heading -->' . "\n"
        . '<!-- wp:paragraph --><p>' . $paragraphs[$pageIndex % count($paragraphs)] . '</p><!-- /wp:paragraph -->';

    $channel[] = '        <item>
            <title>' . htmlspecialchars($title, ENT_XML1) . '</title>
            <link>' . FIXTURE_HOST . '/' . $slug . '/</link>
            <dc:creator>' . cdata($authors[0]['login']) . '</dc:creator>
            <content:encoded>' . cdata($content) . '</content:encoded>
            <wp:post_id>' . $pageId . '</wp:post_id>
            <wp:post_date>2026-01-01 09:00:00</wp:post_date>
            <wp:post_name>' . $slug . '</wp:post_name>
            <wp:status>publish</wp:status>
            <wp:post_type>page</wp:post_type>
        </item>
';
}

$xml = '<?xml version="1.0" encoding="UTF-8" ?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
        <title>Fixture Site</title>
        <link>' . FIXTURE_HOST . '</link>
        <description>Imported WordPress content that Capell themes are proved against</description>
        <language>en-GB</language>
        <wp:wxr_version>1.2</wp:wxr_version>
        <wp:base_site_url>' . FIXTURE_HOST . '</wp:base_site_url>
        <wp:base_blog_url>' . FIXTURE_HOST . '</wp:base_blog_url>
' . implode('', $channel) . '    </channel>
</rss>
';

file_put_contents(__DIR__ . '/theme-fixture-site.xml', $xml);
