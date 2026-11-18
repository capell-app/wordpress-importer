<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Models;

use Capell\Core\Models\Term;
use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Model;
use Override;
use RuntimeException;

/**
 * A taxonomy a WordPress import created, viewed as a rollback target. It is refused while terms remain in it,
 * because deleting it would delete terms an editor added since.
 *
 * @property int $id
 * @property int $site_id
 */
final class WordPressImportedTaxonomy extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return TableName::resolve('taxonomies');
    }

    #[Override]
    public function delete(): ?bool
    {
        throw_if(Term::query()->where('taxonomy_id', $this->getKey())->exists(), RuntimeException::class, 'The taxonomy still holds terms the import did not create.');

        return parent::delete();
    }
}
