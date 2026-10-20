<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\CollectorFactory;
use Shimomo\AutoraceNews\KawaguchiFeedSource;
use Shimomo\AutoraceNews\Tests\Support\Feeds;
use Shimomo\AutoraceNewsCollector\Contracts\Source;

/**
 * @author shimomo
 */
final class CollectorFactoryTest extends TestCase
{
    /**
     * 川口だけ RSS 版に差し替え、ほかはライブラリの既定のまま。配信元キーは7種のまま。
     *
     * @return void
     */
    #[Test]
    public function replacesKawaguchiWithFeedSources(): void
    {
        $sources = CollectorFactory::create(Feeds::fetcher([]))->sources();
        $kawaguchi = array_values(array_filter($sources, static fn(Source $s): bool => $s->key() === 'kawaguchi'));

        self::assertCount(2, $kawaguchi);
        self::assertContainsOnlyInstancesOf(KawaguchiFeedSource::class, $kawaguchi);
        self::assertEqualsCanonicalizing(
            ['autorace.jp', 'nikkansports', 'kawaguchi', 'isesaki', 'hamamatsu', 'sanyo', 'youtube'],
            array_values(array_unique(array_map(static fn(Source $s): string => $s->key(), $sources))),
        );
    }
}
