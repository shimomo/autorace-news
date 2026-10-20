<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

use Shimomo\AutoraceNewsCollector\Collector;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Shimomo\AutoraceNewsCollector\Http\NativeSleeper;
use Symfony\Component\HttpClient\HttpClient;

/**
 * bin/collect が使う Collector。ライブラリの既定から川口（REST）を外し、RSS 版に差し替える。
 *
 * @author shimomo
 */
final class CollectorFactory
{
    /**
     * 同じ HttpFetcher を全ソースで共有するので、リクエスト間隔は川口の RSS にも効く。
     *
     * @param ?\Shimomo\AutoraceNewsCollector\Http\HttpFetcher $fetcher
     * @return \Shimomo\AutoraceNewsCollector\Collector
     */
    public static function create(?HttpFetcher $fetcher = null): Collector
    {
        $fetcher ??= new HttpFetcher(HttpClient::create(), new NativeSleeper());

        return new Collector([
            ...Collector::default($fetcher)->without('kawaguchi')->sources(),
            KawaguchiFeedSource::news($fetcher),
            KawaguchiFeedSource::interview($fetcher),
        ]);
    }
}
