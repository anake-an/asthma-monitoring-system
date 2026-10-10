<?php

namespace Tests;

use App\Support\Mqtt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Messages "published" during the test: [topic, payload, retain]. */
    protected array $published = [];

    /**
     * Refuse to boot unless the tests are on in-memory SQLite.
     *
     * RefreshDatabase drops every table. Environment variables set by Docker
     * (DB_CONNECTION=mysql in the backend container) take precedence over
     * phpunit.xml, so running the tests inside the production container once
     * wiped the live database. This runs before RefreshDatabase touches anything.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $connection = $config->get('database.default');
        $driver = $config->get("database.connections.{$connection}.driver");
        $database = $config->get("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "Refusing to run tests against the '{$connection}' connection (database '{$database}'). "
                . 'Tests must use in-memory SQLite: run them on a development machine or in an isolated '
                . 'container (see README), never inside the production backend container.'
            );
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Deleting a room, child or account asks the AI engine to delete their model files; never
        // reach a real engine from tests (a test can still fake more routes and assert on this one).
        \Illuminate\Support\Facades\Http::fake(['*/models?*' => \Illuminate\Support\Facades\Http::response(['removed_files' => 0])]);

        // Never talk to a real broker from tests; record what would have been published.
        $test = $this;
        $this->app->instance(Mqtt::class, new class($test) extends Mqtt {
            public function __construct(private $test) {}

            public function publish(string $topic, string $payload, bool $retain = false): void
            {
                $this->test->recordPublish($topic, $payload, $retain);
            }
        });
    }

    public function recordPublish(string $topic, string $payload, bool $retain): void
    {
        $this->published[] = [$topic, $payload, $retain];
    }
}
