<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\ArticleMerger;
use Shimomo\AutoraceNews\Tests\Support\Articles;

/**
 * @author shimomo
 */
final class ArticleMergerTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function addsNewArticles(): void
    {
        $a = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00');
        $result = (new ArticleMerger())->merge([], [$a]);

        self::assertEquals([$a], $result->articles);
        self::assertSame(1, $result->added);
        self::assertSame(0, $result->updated);
    }

    /**
     * AUTORACE.JP は「【…を追記！】」のようにタイトルを後から変える。
     *
     * @return void
     */
    #[Test]
    public function overwritesBySourceAndId(): void
    {
        $old = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00', '旧タイトル');
        $new = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00', '【追記！】新タイトル');
        $result = (new ArticleMerger())->merge([$old], [$new]);

        self::assertEquals([$new], $result->articles);
        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);
    }

    /**
     * @return void
     */
    #[Test]
    public function unchangedArticleIsNotCounted(): void
    {
        $a = Articles::stored('youtube', 'v1', '2026-10-19 19:00:00');
        $result = (new ArticleMerger())->merge([$a], [$a]);

        self::assertSame(0, $result->added);
        self::assertSame(0, $result->updated);
    }

    /**
     * 配信元から消えた記事も、こちらでは消さない。
     *
     * @return void
     */
    #[Test]
    public function keepsArticlesMissingFromIncoming(): void
    {
        $a = Articles::stored('sanyo', '1', '2026-10-18 20:15:00');
        $b = Articles::stored('sanyo', '2', '2026-10-19 20:10:00');
        $result = (new ArticleMerger())->merge([$a, $b], [$b]);

        self::assertEqualsCanonicalizing([$a, $b], $result->articles);
    }

    /**
     * WordPress のページ送り中に投稿が増えると、同じ記事が2回返ることがある。
     *
     * @return void
     */
    #[Test]
    public function collapsesDuplicatesWithinIncoming(): void
    {
        $first = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00', '1回目');
        $second = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00', '2回目');
        $result = (new ArticleMerger())->merge([], [$first, $second]);

        self::assertEquals([$second], $result->articles);
        self::assertSame(1, $result->added);
        self::assertSame(0, $result->updated);
    }
}
