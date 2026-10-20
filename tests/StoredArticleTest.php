<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\StoredArticle;
use Shimomo\AutoraceNews\Tests\Support\Articles;
use Shimomo\AutoraceNewsCollector\Article;
use UnexpectedValueException;

/**
 * @author shimomo
 */
final class StoredArticleTest extends TestCase
{
    /**
     * 公開してよい7項目だけを残す。本文・要約・サムネイルはデータに書かない。
     *
     * @return void
     */
    #[Test]
    public function fromArticleKeepsOnlyPublicFields(): void
    {
        $article = new Article(
            'autorace.jp',
            'AUTORACE.JP',
            '038825',
            'https://autorace.jp/news/2026/10/19/038825/',
            '【第8戦(山陽ステージ)のポイントを追記！】',
            ['ニュース', 'レース情報'],
            new DateTimeImmutable('2026-10-19 18:32:00', new DateTimeZone('Asia/Tokyo')),
            'summary',
            '<p>body</p>',
            'body',
            'https://autorace.jp/thumb.jpg',
        );

        self::assertSame([
            'source' => 'autorace.jp',
            'sourceName' => 'AUTORACE.JP',
            'id' => '038825',
            'title' => '【第8戦(山陽ステージ)のポイントを追記！】',
            'url' => 'https://autorace.jp/news/2026/10/19/038825/',
            'categories' => ['ニュース', 'レース情報'],
            'publishedAt' => '2026-10-19T18:32:00+09:00',
        ], StoredArticle::fromArticle($article)->toArray());
    }

    /**
     * UTC で来ても日付と保存値は JST にする（日の区切りは JST の 0:00）。
     *
     * @return void
     */
    #[Test]
    public function fromArticleNormalizesToTokyo(): void
    {
        $article = Articles::library('isesaki', '7159', '2026-10-19T15:10:00Z');
        $stored = StoredArticle::fromArticle($article);

        self::assertSame('2026-10-20T00:10:00+09:00', $stored->publishedAt);
        self::assertSame('2026-10-20', $stored->date());
    }

    /**
     * @return void
     */
    #[Test]
    public function roundTripsThroughArray(): void
    {
        $stored = Articles::stored('sanyo', '4009', '2026-10-19 20:10:00', '１０／１９　応援メッセージ📣', ['応援メッセージ']);

        self::assertEquals($stored, StoredArticle::fromArray($stored->toArray()));
    }

    /**
     * @return void
     */
    #[Test]
    public function fromArrayRejectsMissingField(): void
    {
        $data = Articles::stored('youtube', 'abc', '2026-10-19 19:00:00')->toArray();
        unset($data['title']);

        $this->expectException(UnexpectedValueException::class);
        StoredArticle::fromArray($data);
    }

    /**
     * 分類に文字列以外が混ざったデータは読まない。
     *
     * @return void
     */
    #[Test]
    public function fromArrayRejectsNonStringCategories(): void
    {
        $data = Articles::stored('youtube', 'abc', '2026-10-19 19:00:00')->toArray();
        $data['categories'] = ['ok', 1];

        $this->expectException(UnexpectedValueException::class);
        StoredArticle::fromArray($data);
    }

    /**
     * @return void
     */
    #[Test]
    public function keyIdentifiesSourceAndId(): void
    {
        self::assertSame(
            Articles::stored('kawaguchi', '100', '2026-10-19 10:00:00', 'old')->key(),
            Articles::stored('kawaguchi', '100', '2026-10-20 10:00:00', 'new')->key(),
        );
        self::assertNotSame(
            Articles::stored('kawaguchi', '100', '2026-10-19 10:00:00')->key(),
            Articles::stored('isesaki', '100', '2026-10-19 10:00:00')->key(),
        );
    }
}
