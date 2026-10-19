<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RegisterCommandsInCurrentProcessAction;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\MigrationAssistant\Enums\ImportSessionKind;
use Capell\MigrationAssistant\Enums\ImportSessionStatus;
use Capell\MigrationAssistant\Models\ImportSession;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Workbench\App\Console\Commands\SeedAdminScreenshotFixtureCommand;

beforeEach(function (): void {
    $fixture = getenv('CAPELL_SCREENSHOT_FIXTURE');
    $path = getenv('CAPELL_SCREENSHOT_APP_PATH');
    putenv('CAPELL_SCREENSHOT_FIXTURE=record-state');
    putenv('CAPELL_SCREENSHOT_APP_PATH=' . base_path());
    $this->beforeApplicationDestroyed(static function () use ($fixture, $path): void {
        putenv($fixture === false ? 'CAPELL_SCREENSHOT_FIXTURE' : 'CAPELL_SCREENSHOT_FIXTURE=' . $fixture);
        putenv($path === false ? 'CAPELL_SCREENSHOT_APP_PATH' : 'CAPELL_SCREENSHOT_APP_PATH=' . $path);
    });
    Notification::fake();
    Http::preventStrayRequests();
    $this->fakeMigrationAssistantLocalStorage();
    Role::findOrCreate('super_admin', 'web');
    $this->actingAs(User::factory()->create(['email' => 'admin@example.com'])->assignRole('super_admin'));
    $site = Site::factory()->create(['default' => true]);
    Layout::factory()->create(['site_id' => $site->id]);
    Blueprint::factory()->page()->create();
    require dirname(__DIR__, 4) . '/workbench/routes/screenshot-fixtures-real-admin.php';
});

it('executes the real WXR import and reuses its completed native session without duplicating pages', function (): void {
    $this->get('/screenshot-fixtures/wordpress-importer/session')->assertRedirect();
    $session = ImportSession::query()->where('source_filename', 'northwind-editorial-export.xml')->sole();
    $sessionId = $session->getKey();
    throw_unless(is_int($sessionId), RuntimeException::class, 'Expected an integer import session key.');
    expect($session->status)->toBe(ImportSessionStatus::Completed)
        ->and($session->source_environment)->toBe('wordpress-wxr')
        ->and(data_get($session->result_summary, 'pages_created'))->toBe(2)
        ->and(data_get($session->result_summary, 'page_urls_created'))->toBe(2)
        ->and(data_get($session->validation_results, 'blocking_errors'))->toBe([])
        ->and(PageUrl::query()->whereIn('url', ['/screenshot-wordpress-guide', '/screenshot-wordpress-contact'])->count())->toBe(2);
    $manifest = $session->manifest;
    $attributes = data_get($manifest, 'wordpress_wxr.default_page_attributes');
    throw_unless(is_array($manifest) && is_array($attributes), RuntimeException::class, 'Expected persisted import target attributes.');
    data_set($manifest, 'wordpress_wxr.default_page_attributes', array_reverse($attributes, true));
    $session->forceFill(['manifest' => $manifest])->save();
    $this->get('/screenshot-fixtures/wordpress-importer/session')
        ->assertRedirect('/admin/migration-assistant/import-sessions/' . $sessionId);
    expect(ImportSession::query()->count())->toBe(1)
        ->and(Page::query()->whereIn('name', ['Studio publishing guide', 'Studio contact details'])->count())->toBe(2);
    Http::assertNothingSent();
});

it('refuses a foreign source record without changing its status or importing pages', function (): void {
    $foreign = ImportSession::query()->create([
        'uuid' => (string) Str::uuid(), 'kind' => ImportSessionKind::PageImport,
        'status' => ImportSessionStatus::Failed, 'source_environment' => 'wordpress-wxr',
        'source_filename' => 'northwind-editorial-export.xml', 'source_package_checksum' => 'foreign',
    ]);
    $this->get('/screenshot-fixtures/wordpress-importer/session')->assertConflict();
    expect($foreign->fresh()?->status)->toBe(ImportSessionStatus::Failed)
        ->and(ImportSession::query()->count())->toBe(1)
        ->and(Page::query()->where('name', 'Studio publishing guide')->count())->toBe(0);
});

it('refuses an existing target URL before starting an import', function (bool $hidden): void {
    $site = Site::query()->where('default', true)->sole();
    $foreign = PageUrl::factory()->create([
        'site_id' => $site->id, 'language_id' => $site->language_id, 'url' => '/screenshot-wordpress-guide',
    ]);
    $scopes = PageUrl::getAllGlobalScopes();
    try {
        if ($hidden) {
            PageUrl::addGlobalScope('capture-workspace', static fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
        }

        $this->get('/screenshot-fixtures/wordpress-importer/session')->assertConflict();
        expect(ImportSession::query()->count())->toBe(0);
    } finally {
        PageUrl::setAllGlobalScopes($scopes);
    }

    expect($foreign->fresh()?->url)->toBe('/screenshot-wordpress-guide');
})->with([false, true]);

it('rejects a foreign actor before importing', function (): void {
    $this->actingAs(User::factory()->create(['email' => 'customer@example.test']));
    $this->get('/screenshot-fixtures/wordpress-importer/session')->assertForbidden();
    expect(ImportSession::query()->count())->toBe(0);
});

it('rejects a non-disposable application before importing', function (): void {
    putenv('CAPELL_SCREENSHOT_APP_PATH=/');
    $this->get('/screenshot-fixtures/wordpress-importer/session')->assertForbidden();
    expect(ImportSession::query()->count())->toBe(0);
});

it('prepares the same real import from the guarded capture command without HTTP authentication', function (): void {
    RegisterCommandsInCurrentProcessAction::run([SeedAdminScreenshotFixtureCommand::class]);
    auth()->logout();
    Gate::define('screenshot-import-actor', static fn (User $user): bool => $user->email === 'admin@example.com');
    ImportSession::creating(static function (): void {
        Gate::authorize('screenshot-import-actor');
    });
    $exitCode = Artisan::call('capell:screenshot-admin-fixture', ['--package' => 'wordpress-importer']);
    expect(auth()->user())->toBeNull();
    expect($exitCode)->toBe(0)
        ->and(ImportSession::query()->sole()->status)->toBe(ImportSessionStatus::Completed)
        ->and(Page::query()->whereIn('name', ['Studio publishing guide', 'Studio contact details'])->count())->toBe(2);
    Http::assertNothingSent();
});

it('verifies the actual imported records when a workspace hides them from normal model queries', function (): void {
    $this->get('/screenshot-fixtures/wordpress-importer/session')->assertRedirect();
    $pageScopes = Page::getAllGlobalScopes();
    $urlScopes = PageUrl::getAllGlobalScopes();
    try {
        Page::addGlobalScope('capture-workspace', static fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
        PageUrl::addGlobalScope('capture-workspace', static fn (Builder $query): Builder => $query->whereRaw('1 = 0'));
        expect(Page::query()->count())->toBe(0)->and(PageUrl::query()->count())->toBe(0);
        $this->get('/screenshot-fixtures/wordpress-importer/session')->assertRedirect();
        expect(ImportSession::query()->count())->toBe(1);
    } finally {
        Page::setAllGlobalScopes($pageScopes);
        PageUrl::setAllGlobalScopes($urlScopes);
    }
});
