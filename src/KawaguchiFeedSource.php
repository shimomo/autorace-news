<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Article;
use Shimomo\AutoraceNewsCollector\Contracts\Source;
use Shimomo\AutoraceNewsCollector\Feed\XmlDocument;
use Shimomo\AutoraceNewsCollector\FetchResult;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * 川口オートレースを RSS（news / interview、各最新10件）で読む。
 *
 * ライブラリは WordPress REST API で読むが、川口のサーバー（Xserver）は国外 IP からの REST API を 403 で拒み、
 * GitHub Actions からは取れない。トップページと RSS は通るので、こちらに差し替える。
 * ID は guid の p=（REST と同じ投稿 ID）。毎時の収集なら10件で足りる。
 *
 * @author shimomo
 */
final readonly class KawaguchiFeedSource implements Source
{
    public const string NEWS_FEED_URL = 'https://www.kawaguchiauto.jp/news/feed/';

    public const string INTERVIEW_FEED_URL = 'https://www.kawaguchiauto.jp/interview/feed/';

    /**
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @param string $feedUrl
     * @param list<string> $fixedCategories 分類の先頭に固定で足すラベル
     */
    public function __construct(
        private HttpFetcher $fetcher,
        private string $feedUrl,
        private array $fixedCategories = [],
    ) {
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @return self
     */
    public static function news(HttpFetcher $fetcher): self
    {
        return new self($fetcher, self::NEWS_FEED_URL);
    }

    /**
     * interview は分類を持たないので、ライブラリの REST 版と同じ固定ラベルで区別する。
     *
     * @param \Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @return self
     */
    public static function interview(HttpFetcher $fetcher): self
    {
        return new self($fetcher, self::INTERVIEW_FEED_URL, ['選手インタビュー']);
    }

    /**
     * @return string
     */
    #[\Override]
    public function key(): string
    {
        return 'kawaguchi';
    }

    /**
     * @return string
     */
    #[\Override]
    public function name(): string
    {
        return '川口オートレース';
    }

    /**
     * @param ?\DateTimeImmutable $since
     * @return \Shimomo\AutoraceNewsCollector\FetchResult
     */
    #[\Override]
    public function fetch(?DateTimeImmutable $since): FetchResult
    {
        $document = XmlDocument::fromString($this->fetcher->get($this->feedUrl)->body);
        $timezone = new DateTimeZone('Asia/Tokyo');
        $articles = [];
        $failures = [];

        foreach ($document->elements('/rss/channel/item') as $item) {
            $link = $document->text('link', $item);
            $title = $document->text('title', $item);
            $pubDate = $document->text('pubDate', $item);

            if ($link === null || $link === '' || $title === null || $title === '' || $pubDate === null) {
                $failures[] = new SourceFailure($this->key(), $link, 'Feed item is missing link, title, or pubDate.');

                continue;
            }

            // guid は https://www.kawaguchiauto.jp/?post_type=news&p=13609 の形
            if (preg_match('~[?&]p=(\d+)~', $document->text('guid', $item) ?? '', $matches) !== 1) {
                $failures[] = new SourceFailure($this->key(), $link, 'Could not derive the post id from the guid.');

                continue;
            }

            $publishedAt = DateTimeImmutable::createFromFormat('!' . DATE_RSS, $pubDate);

            if ($publishedAt === false) {
                $failures[] = new SourceFailure($this->key(), $link, sprintf('Unparseable pubDate "%s".', $pubDate));

                continue;
            }

            $publishedAt = $publishedAt->setTimezone($timezone);

            if ($since !== null && $publishedAt < $since) {
                continue;
            }

            $articles[] = new Article(
                $this->key(),
                $this->name(),
                $matches[1],
                $link,
                $title,
                [...$this->fixedCategories, ...$document->texts('category', $item)],
                $publishedAt,
            );
        }

        return new FetchResult($articles, $failures);
    }
}
