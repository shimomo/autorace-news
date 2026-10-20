<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\FailureNotice;
use Shimomo\AutoraceNews\FailureSummary;
use Shimomo\AutoraceNews\Tests\Support\Articles;
use Shimomo\AutoraceNewsCollector\CollectResult;
use Shimomo\AutoraceNewsCollector\SourceFailure;

/**
 * @author shimomo
 */
final class FailureSummaryTest extends TestCase
{
    /**
     * 日刊の記事ページだけ取れなかった場合、タイトルとリンクは取れているので失敗にしない。
     *
     * @return void
     */
    #[Test]
    public function ignoresArticlePageFailuresWhenArticleWasCollected(): void
    {
        $article = Articles::library('nikkansports', '202610190001', '2026-10-19 05:00:00');
        $result = new CollectResult([$article], [new SourceFailure('nikkansports', $article->url, 'GET ... returned HTTP 500.')]);

        self::assertSame([], FailureSummary::fromResult($result));
    }

    /**
     * @return void
     */
    #[Test]
    public function urlMatchIsPerSource(): void
    {
        $article = Articles::library('autorace.jp', '1', '2026-10-19 05:00:00');
        $result = new CollectResult([$article], [new SourceFailure('nikkansports', $article->url, 'broken')]);

        self::assertEquals([new FailureNotice('nikkansports', 'broken')], FailureSummary::fromResult($result));
    }

    /**
     * @return void
     */
    #[Test]
    public function keepsSourceWideFailures(): void
    {
        $result = new CollectResult([], [new SourceFailure('autorace.jp', null, 'GET https://autorace.jp/rss.xml returned HTTP 503.')]);

        self::assertEquals(
            [new FailureNotice('autorace.jp', 'GET https://autorace.jp/rss.xml returned HTTP 503.')],
            FailureSummary::fromResult($result),
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function oneNoticePerSourceWithFirstMessage(): void
    {
        $result = new CollectResult([], [
            new SourceFailure('isesaki', null, 'first'),
            new SourceFailure('isesaki', 'https://www.isesaki-auto.jp/1/', 'second'),
            new SourceFailure('sanyo', null, 'third'),
        ]);

        self::assertEquals(
            [new FailureNotice('isesaki', 'first'), new FailureNotice('sanyo', 'third')],
            FailureSummary::fromResult($result),
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function noticeConvertsToArray(): void
    {
        self::assertSame(['source' => 'youtube', 'message' => 'timeout'], (new FailureNotice('youtube', 'timeout'))->toArray());
    }
}
