<?php

namespace Tests\Unit;

use App\Support\Mqtt;
use PHPUnit\Framework\TestCase;

class MqttTopicTest extends TestCase
{
    public function test_topic_layout(): void
    {
        $this->assertSame('respirosync/devices/AB12CD/config', Mqtt::topic('AB12CD', 'config'));
    }

    public function test_parses_device_topics(): void
    {
        $this->assertSame(['AB12CD', 'telemetry'], Mqtt::parseTopic('respirosync/devices/AB12CD/telemetry'));
        $this->assertSame(['AB12CD', 'events'], Mqtt::parseTopic('respirosync/devices/AB12CD/events'));
    }

    public function test_rejects_other_topics(): void
    {
        $this->assertNull(Mqtt::parseTopic('respirosync/telemetry'));
        $this->assertNull(Mqtt::parseTopic('respirosync/devices/AB12CD/config'));
        $this->assertNull(Mqtt::parseTopic('respirosync/devices/ab12cd/telemetry'));
        $this->assertNull(Mqtt::parseTopic('respirosync/devices/AB12CD/telemetry/extra'));
    }
}
