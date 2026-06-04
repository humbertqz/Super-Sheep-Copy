<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Serialization\SerializationWalker;
use SuperSheepCopy\Shared\Urls\StructuredValueReplacer;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class StructuredValueReplacerTest extends TestCase
{
    public function testReplacesSerializedArrayWithoutCorruptingLengths(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());
        $serialized = serialize(array('url' => 'https://website.com/page'));

        $result = $replacer->replace($serialized, 'https://website.com', 'http://localhost:8888/copysite');
        $decoded = unserialize($result->value(), array('allowed_classes' => true));

        self::assertSame('serialized', $result->format());
        self::assertSame(1, $result->replacementCount());
        self::assertSame('http://localhost:8888/copysite/page', $decoded['url']);
    }

    public function testReplacesJsonWithoutEscapingSlashes(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());

        $result = $replacer->replace('{"url":"https:\/\/website.com\/page"}', 'https://website.com', 'http://localhost:8888/copysite');

        self::assertSame('json', $result->format());
        self::assertSame('{"url":"http://localhost:8888/copysite/page"}', $result->value());
    }

    public function testFallsBackToPlainString(): void
    {
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());

        $result = $replacer->replace('Visit https://website.com/page', 'https://website.com', 'http://localhost:8888/copysite');

        self::assertSame('plain', $result->format());
        self::assertSame('Visit http://localhost:8888/copysite/page', $result->value());
    }

    public function testSerializedObjectsDoNotInvokeWakeupDuringReplacement(): void
    {
        SerializedWakeupProbe::$woke_up = false;
        $replacer = new StructuredValueReplacer(new UrlReplacementEngine(), new SerializationWalker());
        $serialized = serialize(new SerializedWakeupProbe('https://website.com/page'));

        $result = $replacer->replace($serialized, 'https://website.com', 'http://localhost:8888/copysite');

        self::assertSame('serialized', $result->format());
        self::assertSame(1, $result->replacementCount());
        self::assertStringContainsString('http://localhost:8888/copysite/page', $result->value());
        self::assertFalse(SerializedWakeupProbe::$woke_up);
    }
}

final class SerializedWakeupProbe
{
    public static bool $woke_up = false;
    public string $url;

    public function __construct(string $url)
    {
        $this->url = $url;
    }

    public function __wakeup(): void
    {
        self::$woke_up = true;
    }
}
