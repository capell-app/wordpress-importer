<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Actions;

use Capell\Core\Actions\Properties\AssignPageTermsAction;
use Capell\Core\Actions\Taxonomies\CreateTaxonomyAction;
use Capell\Core\Models\Page;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Models\Term;
use Capell\WordPressImporter\Data\WordPressTaxonomyAuthorReportData;
use Capell\WordPressImporter\Models\WordPressImportedTaxonomy;
use Capell\WordPressImporter\Models\WordPressImportedTerm;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Carries WordPress categories, tags and authors onto freshly imported pages.
 *
 * Categories and tags become terms in a `category` and a `tag` taxonomy on the page's site; an existing
 * taxonomy or term with the same key or slug is reused. An author is matched to an existing user by the
 * email in the export and never created: an export is untrusted input and must not mint accounts.
 *
 * @method static WordPressTaxonomyAuthorReportData run(iterable<array-key, mixed> $pages)
 */
final class MapWordPressTaxonomiesAndAuthorsAction
{
    use AsFake;
    use AsObject;

    /** @var array<string, string> WordPress taxonomy domain => Capell taxonomy key and name. */
    private const array TAXONOMIES = [
        'category' => 'category',
        'post_tag' => 'tag',
    ];

    /**
     * @param  iterable<array-key, mixed>  $pages
     */
    public function handle(iterable $pages): WordPressTaxonomyAuthorReportData
    {
        $taxonomies = [];
        $created = ['taxonomies' => [], 'terms' => []];
        $pagesWithTerms = 0;
        $matched = 0;
        $unmatched = [];
        $users = [];

        foreach ($pages as $page) {
            if (! $page instanceof Page) {
                continue;
            }

            $wordpress = data_get($page->meta, 'wordpress');
            if (! is_array($wordpress)) {
                continue;
            }

            $termIds = [];
            foreach (self::TAXONOMIES as $domain => $key) {
                $termSlugs = is_array($wordpress['term_slugs'] ?? null) ? $wordpress['term_slugs'] : [];
                $details = is_array($termSlugs[$domain] ?? null) ? $termSlugs[$domain] : [];
                foreach ($details as $detail) {
                    $name = is_array($detail) && is_string($detail['name'] ?? null) ? $detail['name'] : '';
                    $slug = is_array($detail) && is_string($detail['slug'] ?? null) ? $detail['slug'] : '';
                    if ($name === '' || $slug === '') {
                        continue;
                    }

                    $taxonomy = $taxonomies[$page->site_id][$key] ??= $this->taxonomy($page, $key, $created['taxonomies']);
                    $term = Term::query()->firstOrCreate(
                        ['taxonomy_id' => $taxonomy->getKey(), 'slug' => $slug],
                        ['name' => mb_substr($name, 0, 191), 'position' => 0],
                    );
                    if ($term->wasRecentlyCreated) {
                        $created['terms'][] = $term;
                    }
                    $termIds[] = $term->id;
                }
            }

            if ($termIds !== []) {
                AssignPageTermsAction::run($page, array_values(array_unique($termIds)));
                $pagesWithTerms++;
            }

            $login = is_string($wordpress['author_login'] ?? null) ? $wordpress['author_login'] : '';
            $email = is_string($wordpress['author_email'] ?? null) ? mb_strtolower(trim($wordpress['author_email'])) : '';
            if ($login === '') {
                continue;
            }

            $user = $email === '' ? null : ($users[$email] ??= $this->user($email));
            if ($user instanceof Model) {
                Page::query()->withoutGlobalScopes()->whereKey($page->getKey())->update(['created_by' => $user->getKey()]);
                $matched++;
            } else {
                $unmatched[$login] = $login;
            }
        }

        return new WordPressTaxonomyAuthorReportData(
            pagesWithTerms: $pagesWithTerms,
            taxonomiesCreated: count($created['taxonomies']),
            termsCreated: count($created['terms']),
            authorsMatched: $matched,
            unmatchedAuthors: array_values($unmatched),
            createdModels: [
                ...array_map(static fn (Taxonomy $taxonomy): WordPressImportedTaxonomy => WordPressImportedTaxonomy::query()->where('id', $taxonomy->getKey())->firstOrFail(), $created['taxonomies']),
                ...array_map(static fn (Term $term): WordPressImportedTerm => WordPressImportedTerm::query()->where('id', $term->getKey())->firstOrFail(), $created['terms']),
            ],
        );
    }

    /**
     * @param  list<Taxonomy>  $created
     */
    private function taxonomy(Page $page, string $key, array &$created): Taxonomy
    {
        $existing = Taxonomy::query()->where('site_id', $page->site_id)->where('key', $key)->first();
        if ($existing instanceof Taxonomy) {
            return $existing;
        }

        $taxonomy = CreateTaxonomyAction::run($page->site()->firstOrFail(), [
            'key' => $key,
            'name' => (string) __('capell-wordpress-importer::commands.import.taxonomy_names.' . $key),
            'hierarchical' => $key === 'category',
        ]);
        $created[] = $taxonomy;

        return $taxonomy;
    }

    private function user(string $email): ?Model
    {
        $class = config('auth.providers.users.model');
        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            return null;
        }

        $user = $class::query()->whereRaw('lower(email) = ?', [$email])->first();

        return $user instanceof Model ? $user : null;
    }
}
