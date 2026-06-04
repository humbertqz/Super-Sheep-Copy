<?php

declare(strict_types=1);

namespace SuperSheepCopy\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SuperSheepCopy\Shared\Urls\UrlReplacementEngine;

final class UrlReplacementEngineTest extends TestCase
{
    public function testReplacesPlainUrl(): void
    {
        $engine = new UrlReplacementEngine();

        self::assertSame(
            'Visit http://localhost:8888/copysite/page',
            $engine->replace('Visit https://website.com/page', 'https://website.com', 'http://localhost:8888/copysite')
        );
        self::assertSame(1, $engine->countReplacements('Visit https://website.com/page', 'https://website.com'));
    }

    public function testReplacesUrlVariants(): void
    {
        $engine = new UrlReplacementEngine();
        $input = implode("\n", array(
            'https://website.com/page',
            'http://website.com/page',
            'https://www.website.com/page',
            'http://www.website.com/page',
            '//website.com/page',
            'https:\/\/website.com\/page',
            'https%3A%2F%2Fwebsite.com%2Fpage',
        ));

        $result = $engine->replace($input, 'https://website.com', 'http://localhost:8888/copysite');

        self::assertStringContainsString('http://localhost:8888/copysite/page', $result);
        self::assertStringContainsString('http:\/\/localhost:8888\/copysite\/page', $result);
        self::assertStringContainsString('http%3A%2F%2Flocalhost%3A8888%2Fcopysite%2Fpage', $result);
        self::assertStringNotContainsString('website.com', $result);
    }

    public function testCountsUrlVariants(): void
    {
        $engine = new UrlReplacementEngine();
        $input = 'https://website.com http://website.com https:\/\/website.com https%3A%2F%2Fwebsite.com';

        self::assertSame(4, $engine->countReplacements($input, 'https://website.com'));
    }
}
