# Worked extension examples

These developer-facing recipes are kept beside the package contract. Replace the example values with the site-specific records and data objects used by the calling workflow.

<!-- example: contract Capell\WordPressImporter\Contracts\WordPressMediaHostResolver -->

```php
<?php
declare(strict_types=1);
final class ExampleWordPressMediaHostResolverImplementation implements \Capell\WordPressImporter\Contracts\WordPressMediaHostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        throw new LogicException('Implement this package contract for the calling site.');
    }
}

app()->bind(\Capell\WordPressImporter\Contracts\WordPressMediaHostResolver::class, ExampleWordPressMediaHostResolverImplementation::class);
```
