<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests\Support;

use RuntimeException;
use Shimomo\AutoraceNewsCollector\Contracts\Sleeper;
use Shimomo\AutoraceNewsCollector\Http\HttpFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * フィードのテスト用。tests/fixtures/ の実レスポンスを読み、ネットワークに出ない HttpFetcher を作る。
 *
 * @author shimomo
 */
final class Feeds
{
    /**
     * @param string $path tests/fixtures/ からの相対パス
     * @return string
     */
    public static function fixture(string $path): string
    {
        $content = file_get_contents(__DIR__ . '/../fixtures/' . $path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Fixture "%s" could not be read.', $path));
        }

        return $content;
    }

    /**
     * 渡した本文を順に返す HttpFetcher。要求された URL は $requested に積む。
     *
     * @param list<string> $bodies
     * @param list<string> $requested
     * @return \Shimomo\AutoraceNewsCollector\Http\HttpFetcher
     */
    public static function fetcher(array $bodies, array &$requested = []): HttpFetcher
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$bodies, &$requested): MockResponse {
            $requested[] = $url;

            return new MockResponse((string) array_shift($bodies));
        });

        $sleeper = new class () implements Sleeper {
            /**
             * @param float $seconds
             * @return void
             */
            #[\Override]
            public function sleep(float $seconds): void
            {
            }
        };

        return new HttpFetcher($client, $sleeper);
    }
}
