<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNews\StoredArticle;
use Shimomo\AutoraceNewsCollector\Article;

/**
 * テスト用の記事を作る。日時の文字列にタイムゾーンが無ければ Asia/Tokyo として読む。
 *
 * @author shimomo
 */
final class Articles
{
    /**
     * @param string $source
     * @param string $id
     * @param string $publishedAt
     * @param string $title
     * @param list<string> $categories
     * @return \Shimomo\AutoraceNewsCollector\Article
     */
    public static function library(
        string $source,
        string $id,
        string $publishedAt,
        string $title = 'タイトル',
        array $categories = [],
    ): Article {
        return new Article(
            $source,
            $source . ' name',
            $id,
            'https://example.com/' . $source . '/' . $id,
            $title,
            $categories,
            new DateTimeImmutable($publishedAt, new DateTimeZone('Asia/Tokyo')),
        );
    }

    /**
     * @param string $source
     * @param string $id
     * @param string $publishedAt
     * @param string $title
     * @param list<string> $categories
     * @return \Shimomo\AutoraceNews\StoredArticle
     */
    public static function stored(
        string $source,
        string $id,
        string $publishedAt,
        string $title = 'タイトル',
        array $categories = [],
    ): StoredArticle {
        return StoredArticle::fromArticle(self::library($source, $id, $publishedAt, $title, $categories));
    }
}
