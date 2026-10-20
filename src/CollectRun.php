<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Contracts\Source;

/**
 * 1回分の収集。集めて、既存のデータと突き合わせて、書き出す。
 *
 * @author shimomo
 */
final class CollectRun
{
    /**
     * 毎回取り直す範囲。タイトルの追記や遅れて載る記事を拾うため。
     */
    private const string LOOKBACK = 'PT48H';

    /**
     * @param \Shimomo\AutoraceNewsCollector\Collector $collector
     * @param \Shimomo\AutoraceNews\DataStore $store
     */
    public function __construct(
        private Collector $collector,
        private DataStore $store,
    ) {
    }

    /**
     * 初回（記事のある日がまだ無い）は since なしで、各配信元が返せる分をすべて取り込む。
     *
     * @param \DateTimeImmutable $now
     * @return \Shimomo\AutoraceNews\RunReport
     */
    public function run(DateTimeImmutable $now): RunReport
    {
        $since = $this->store->hasArticles() ? $now->sub(new DateInterval(self::LOOKBACK)) : null;
        $result = $this->collector->collect($since);

        $merged = (new ArticleMerger())->merge(
            $this->store->loadArticles(),
            array_map(StoredArticle::fromArticle(...), $result->articles),
        );
        $failures = FailureSummary::fromResult($result);

        $this->store->save(
            $merged->articles,
            $now->setTimezone(new DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM),
            $failures,
        );

        $keys = array_unique(array_map(static fn(Source $source): string => $source->key(), $this->collector->sources()));

        return new RunReport(
            count($result->articles),
            $merged->added,
            $merged->updated,
            $failures,
            $keys !== [] && count($failures) === count($keys),
        );
    }
}
