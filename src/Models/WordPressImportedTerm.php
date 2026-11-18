<?php

declare(strict_types=1);

namespace Capell\WordPressImporter\Models;

use Capell\Core\Models\Page;
use Capell\Core\Models\Taxonomy;
use Capell\Core\Support\TableName;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use RuntimeException;

/**
 * A term a WordPress import created, viewed as a rollback target.
 *
 * A rollback entry needs the site that owns the record, and a term only has its taxonomy's site, so the
 * site is read from the taxonomy when the row is loaded. Rolling a term back is refused while a page that
 * is not part of the importing run still uses it: such a page would silently lose the term.
 *
 * @property int $id
 * @property int $taxonomy_id
 * @property int $site_id
 */
final class WordPressImportedTerm extends Model
{
    /** @var list<string> */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return TableName::resolve('terms');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  string|null  $connection
     */
    #[Override]
    public function newFromBuilder($attributes = [], $connection = null): static
    {
        $model = parent::newFromBuilder($attributes, $connection);
        $siteId = Taxonomy::query()->withoutGlobalScopes()->whereKey($model->getAttribute('taxonomy_id'))->value('site_id');

        if (is_int($siteId)) {
            $model->setRawAttributes([...$model->getAttributes(), 'site_id' => $siteId], true);
        }

        return $model;
    }

    /**
     * @return BelongsTo<Taxonomy, $this>
     */
    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class);
    }

    #[Override]
    public function delete(): ?bool
    {
        $created = $this->getRawOriginal('created_at');

        $foreignPage = Page::query()
            ->withoutGlobalScopes()
            ->whereIn((new Page)->getKeyName(), fn ($query) => $query->select('page_id')->from(TableName::resolve('page_term'))->where('term_id', $this->getKey()))
            ->where(fn ($query) => $query->whereNull('meta->wordpress->import_session_uuid')->orWhere('created_at', '>', $created))
            ->exists();

        throw_if($foreignPage, RuntimeException::class, 'A page outside this import still uses the term.');

        return parent::delete();
    }
}
