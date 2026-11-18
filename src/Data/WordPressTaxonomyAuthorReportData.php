<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Data;

use Illuminate\Database\Eloquent\Model;

final readonly class WordPressTaxonomyAuthorReportData
{
    /**
     * @param  list<Model>  $createdModels  Rollback views of the taxonomies the import created, then of its terms, so a rollback removes terms before their taxonomy.
     * @param  list<string>  $unmatchedAuthors  WordPress logins with no Capell user for their export email.
     */
    public function __construct(
        public int $pagesWithTerms = 0,
        public int $taxonomiesCreated = 0,
        public int $termsCreated = 0,
        public int $authorsMatched = 0,
        public array $unmatchedAuthors = [],
        public array $createdModels = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pages_with_terms' => $this->pagesWithTerms,
            'taxonomies_created' => $this->taxonomiesCreated,
            'terms_created' => $this->termsCreated,
            'authors_matched' => $this->authorsMatched,
            'unmatched_authors' => $this->unmatchedAuthors,
        ];
    }
}
