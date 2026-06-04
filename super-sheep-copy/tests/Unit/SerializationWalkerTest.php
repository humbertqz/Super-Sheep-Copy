<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Serialization\SerializationWalker;

final class SerializationWalkerTest extends TestCase
{
    public function testWalksNestedStringValues(): void
    {
        $walker = new SerializationWalker();
        $value = array('url' => 'https://website.com', 'nested' => array('copy' => 'https://website.com/page'));

        $result = $walker->walk($value, static function (string $item): string {
            return str_replace('https://website.com', 'http://localhost:8888/copysite', $item);
        });

        self::assertSame('http://localhost:8888/copysite', $result['url']);
        self::assertSame('http://localhost:8888/copysite/page', $result['nested']['copy']);
    }
}
