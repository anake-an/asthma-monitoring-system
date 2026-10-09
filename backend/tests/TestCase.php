<?php

namespace Tests;

use App\Support\Mqtt;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Messages "published" during the test: [topic, payload, retain]. */
    protected array $published = [];

    protected function setUp(): void
    {
        parent::setUp();

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
