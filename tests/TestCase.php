<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Crypto\WorkspaceKeyManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Fakes\InMemoryWorkspaceKeyManager;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        // Container env_file populates $_SERVER ahead of Dotenv/PHPUnit's $_ENV.
        // Set all adapters before providers resolve cache/queue clients.
        foreach (['CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        if (! $app->environment('testing') || ! ($database === ':memory:' || $database === 'karmandeman_testing')) {
            throw new RuntimeException('Refusing to refresh a database outside the isolated test targets.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Private columns are encrypted under workspace keys; tests never reach OpenBao.
        $this->app->instance(WorkspaceKeyManager::class, new InMemoryWorkspaceKeyManager);
    }

    /**
     * Private columns hold workspace ciphertext (ADR-013 S2): assert the
     * decrypted model value and that the stored value is not plaintext.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $where
     */
    protected function assertPrivateValue(string $model, array $where, string $column, mixed $expected): void
    {
        $record = $model::query()->where($where)->latest('id')->firstOrFail();
        $this->assertSame($expected, $record->getAttribute($column));
        $this->assertStringStartsWith('kmv1:', (string) $record->getRawOriginal($column));
    }
}
