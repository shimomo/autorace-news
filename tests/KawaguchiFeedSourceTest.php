<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\KawaguchiFeedSource;
use Shimomo\AutoraceNews\Tests\Support\Feeds;

/**
 * 川口の REST API は GitHub Actions の IP から 403 になるので、RSS で読む。
 *
 * @author shimomo
 */
final class KawaguchiFeedSourceTest extends TestCase
{
    /**
     * @return void
     */
    #[Test]
    public function newsFeedBecomesArticles(): void
    {
        $requested = [];
        $source = KawaguchiFeedSource::news(Feeds::fetcher([Feeds::fixture('kawaguchi/news-feed.xml')], $requested));
        $result = $source->fetch(null);

        self::assertSame(['https://www.kawaguchiauto.jp/news/feed/'], $requested);
        self::assertSame([], $result->failures);
        self::assertCount(10, $result->articles);

        $first = $result->articles[0];
        self::assertSame('kawaguchi', $first->source);
        self::assertSame('川口オートレース', $first->sourceName);
        self::assertSame('13609', $first->id);
        self::assertSame('https://www.kawaguchiauto.jp/news/20261014_nightrace/', $first->url);
        self::assertStringStartsWith('【10月28日・29日】「テレ玉presents 川口ナイトレース」', $first->title);
        self::assertSame([], $first->categories);
        self::assertSame('2026-10-14T10:13:53+09:00', $first->publishedAt->format(DATE_ATOM));
        self::assertNull($first->bodyHtml);
    }

    /**
     * guid の p= が投稿 ID。古い投稿は guid のホストが違っても同じ形。
     *
     * @return void
     */
    #[Test]
    public function idComesFromGuidPostId(): void
    {
        $source = KawaguchiFeedSource::news(Feeds::fetcher([Feeds::fixture('kawaguchi/news-feed.xml')]));
        $ids = array_map(static fn($a): string => $a->id, $source->fetch(null)->articles);

        self::assertSame(['13609', '13509', '1672'], array_slice($ids, 0, 3));
    }

    /**
     * REST 版（ライブラリ）と同じく、選手インタビューは分類で区別する。
     *
     * @return void
     */
    #[Test]
    public function interviewFeedAddsFixedCategory(): void
    {
        $requested = [];
        $source = KawaguchiFeedSource::interview(Feeds::fetcher([Feeds::fixture('kawaguchi/interview-feed.xml')], $requested));
        $first = $source->fetch(null)->articles[0];

        self::assertSame(['https://www.kawaguchiauto.jp/interview/feed/'], $requested);
        self::assertSame('kawaguchi', $first->source);
        self::assertSame('13628', $first->id);
        self::assertSame(['選手インタビュー'], $first->categories);
        self::assertSame('2026-10-15T18:16:10+09:00', $first->publishedAt->format(DATE_ATOM));
    }

    /**
     * 境界ちょうどは含む（publishedAt >= since）。
     *
     * @return void
     */
    #[Test]
    public function sinceKeepsOnlyNewerItems(): void
    {
        $source = KawaguchiFeedSource::news(Feeds::fetcher([Feeds::fixture('kawaguchi/news-feed.xml')]));
        $since = new DateTimeImmutable('2026-10-04 08:00:00', new DateTimeZone('Asia/Tokyo'));
        $ids = array_map(static fn($a): string => $a->id, $source->fetch($since)->articles);

        self::assertSame(['13609', '13509'], $ids);
    }

    /**
     * 1件が壊れていても、ほかの記事は返す。
     *
     * @return void
     */
    #[Test]
    public function brokenItemsBecomeFailures(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
            <item><title>ID が取れない</title><link>https://www.kawaguchiauto.jp/news/a/</link><pubDate>Wed, 14 Oct 2026 01:13:53 +0000</pubDate><guid>https://www.kawaguchiauto.jp/news/a/</guid></item>
            <item><title></title><link>https://www.kawaguchiauto.jp/news/b/</link><pubDate>Wed, 14 Oct 2026 01:13:53 +0000</pubDate><guid>https://www.kawaguchiauto.jp/?p=2</guid></item>
            <item><title>日付が壊れている</title><link>https://www.kawaguchiauto.jp/news/c/</link><pubDate>yesterday-ish</pubDate><guid>https://www.kawaguchiauto.jp/?p=3</guid></item>
            <item><title>正常</title><link>https://www.kawaguchiauto.jp/news/d/</link><pubDate>Wed, 14 Oct 2026 01:13:53 +0000</pubDate><guid>https://www.kawaguchiauto.jp/?post_type=news&amp;p=4</guid></item>
            </channel></rss>
            XML;
        $result = KawaguchiFeedSource::news(Feeds::fetcher([$xml]))->fetch(null);

        self::assertSame(['4'], array_map(static fn($a): string => $a->id, $result->articles));
        self::assertSame(
            ['https://www.kawaguchiauto.jp/news/a/', 'https://www.kawaguchiauto.jp/news/b/', 'https://www.kawaguchiauto.jp/news/c/'],
            array_map(static fn($f): ?string => $f->url, $result->failures),
        );
        self::assertSame(['kawaguchi'], array_values(array_unique(array_map(static fn($f): string => $f->source, $result->failures))));
    }
}
