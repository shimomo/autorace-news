<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shimomo\AutoraceNews\DataStore;
use Shimomo\AutoraceNews\FailureNotice;
use Shimomo\AutoraceNews\StoredArticle;
use Shimomo\AutoraceNews\Tests\Support\Articles;
use Shimomo\AutoraceNews\Tests\Support\TempDirectory;

/**
 * @author shimomo
 */
final class DataStoreTest extends TestCase
{
    private string $dir = '';

    /**
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        $this->dir = TempDirectory::path();
    }

    /**
     * @return void
     */
    #[\Override]
    protected function tearDown(): void
    {
        TempDirectory::remove($this->dir);
    }

    /**
     * @return void
     */
    #[Test]
    public function hasArticlesDependsOnIndexDates(): void
    {
        $store = new DataStore($this->dir);
        self::assertFalse($store->hasArticles());

        $store->save([], '2026-10-20T12:17:05+09:00', []);
        self::assertFalse($store->hasArticles());

        $store->save([Articles::stored('sanyo', '1', '2026-10-19 20:10:00')], '2026-10-20T12:17:05+09:00', []);
        self::assertTrue($store->hasArticles());
    }

    /**
     * 同時刻は source、id の順にして、同じ入力なら同じ出力にする。
     *
     * @return void
     */
    #[Test]
    public function savesOneFilePerDayNewestFirst(): void
    {
        $late = Articles::stored('youtube', 'v1', '2026-10-19 22:15:00');
        $tieB = Articles::stored('sanyo', '9', '2026-10-19 15:00:00');
        $tieA = Articles::stored('autorace.jp', '038826', '2026-10-19 15:00:00');
        $other = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00');

        (new DataStore($this->dir))->save(
            [$tieB, $other, $late, $tieA],
            '2026-10-20T12:17:05+09:00',
            [new FailureNotice('autorace.jp', 'HTTP 503')],
        );

        self::assertSame([
            'date' => '2026-10-19',
            'articles' => [$late->toArray(), $tieA->toArray(), $tieB->toArray()],
        ], $this->readJson('2026-10-19.json'));
        self::assertSame([
            'dates' => ['2026-10-19', '2026-10-17'],
            'checkedAt' => '2026-10-20T12:17:05+09:00',
            'failures' => [['source' => 'autorace.jp', 'message' => 'HTTP 503']],
        ], $this->readJson('index.json'));
    }

    /**
     * @return void
     */
    #[Test]
    public function writesPrettyUnescapedJson(): void
    {
        (new DataStore($this->dir))->save(
            [Articles::stored('sanyo', '1', '2026-10-19 20:10:00', '応援メッセージ')],
            '2026-10-20T12:17:05+09:00',
            [],
        );
        $raw = (string) file_get_contents($this->dir . '/2026-10-19.json');

        self::assertStringContainsString('"title": "応援メッセージ"', $raw);
        self::assertStringContainsString('"url": "https://example.com/sanyo/1"', $raw);
        self::assertStringEndsWith("}\n", $raw);
    }

    /**
     * @return void
     */
    #[Test]
    public function roundTripsArticles(): void
    {
        $articles = [
            Articles::stored('sanyo', '1', '2026-10-19 20:10:00'),
            Articles::stored('youtube', 'v', '2026-10-18 07:00:00'),
        ];
        $store = new DataStore($this->dir);
        $store->save($articles, '2026-10-20T12:17:05+09:00', []);

        self::assertEqualsCanonicalizing(
            array_map(static fn(StoredArticle $a): array => $a->toArray(), $articles),
            array_map(static fn(StoredArticle $a): array => $a->toArray(), $store->loadArticles()),
        );
    }

    /**
     * @return void
     */
    #[Test]
    public function deletesDayFilesThatBecameEmpty(): void
    {
        $store = new DataStore($this->dir);
        $store->save([Articles::stored('sanyo', '1', '2026-10-18 20:15:00')], '2026-10-20T11:17:05+09:00', []);
        $store->save([Articles::stored('sanyo', '1', '2026-10-19 00:05:00')], '2026-10-20T12:17:05+09:00', []);

        self::assertFileDoesNotExist($this->dir . '/2026-10-18.json');
        self::assertSame(['2026-10-19'], $this->readJson('index.json')['dates']);
    }

    /**
     * 中身が変わらない日ファイルは書き直さない（毎時のコミットの差分を小さく保つ）。
     *
     * @return void
     */
    #[Test]
    public function leavesUnchangedDayFilesUntouched(): void
    {
        $store = new DataStore($this->dir);
        $old = Articles::stored('sanyo', '1', '2026-10-18 20:15:00');
        $store->save([$old], '2026-10-20T11:17:05+09:00', []);
        touch($this->dir . '/2026-10-18.json', 1_000_000_000);
        clearstatcache();

        $store->save([$old, Articles::stored('sanyo', '2', '2026-10-19 20:10:00')], '2026-10-20T12:17:05+09:00', []);
        clearstatcache();

        self::assertSame(1_000_000_000, filemtime($this->dir . '/2026-10-18.json'));
        self::assertFileExists($this->dir . '/2026-10-19.json');
    }

    /**
     * 壊れたデータを読み飛ばすと、次の書き出しでその日の記事が消える。
     *
     * @return void
     */
    #[Test]
    public function loadArticlesFailsOnBrokenJson(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir . '/2026-10-19.json', '{');

        $this->expectException(JsonException::class);
        (new DataStore($this->dir))->loadArticles();
    }

    /**
     * @param string $name
     * @return array<array-key, mixed>
     */
    private function readJson(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->dir . '/' . $name), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
