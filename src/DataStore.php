<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

use RuntimeException;
use UnexpectedValueException;

/**
 * docs/data/ の読み書き。日ごとの YYYY-MM-DD.json と、日付一覧・最終収集時刻・失敗を持つ index.json。
 *
 * @author shimomo
 */
final class DataStore
{
    private const string DAY_FILE_PATTERN = '/^\d{4}-\d{2}-\d{2}\.json$/';

    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param string $directory 無ければ save() で作る
     */
    public function __construct(
        private string $directory,
    ) {
    }

    /**
     * 2回目以降の収集かどうか。index.json があり、記事のある日が1日以上あること。
     *
     * @return bool
     */
    public function hasArticles(): bool
    {
        $path = $this->directory . '/index.json';

        if (!is_file($path)) {
            return false;
        }

        $index = $this->decode($path);

        return is_array($index['dates'] ?? null) && $index['dates'] !== [];
    }

    /**
     * 全日分の記事。壊れたファイルは例外にする（読み飛ばすと次の save() でその日が消えるため）。
     *
     * @return list<StoredArticle>
     */
    public function loadArticles(): array
    {
        $articles = [];

        foreach ($this->dayFiles() as $path) {
            $day = $this->decode($path);
            $items = $day['articles'] ?? null;

            if (!is_array($items)) {
                throw new UnexpectedValueException(sprintf('%s has no "articles" list.', $path));
            }

            array_push($articles, ...array_map(
                static fn(mixed $item): StoredArticle => is_array($item)
                    ? StoredArticle::fromArray($item)
                    : throw new UnexpectedValueException(sprintf('%s has a non-object article.', $path)),
                array_values($items),
            ));
        }

        return $articles;
    }

    /**
     * 日ごとに並べて書く。中身が変わらない日ファイルは書き直さず、記事が無くなった日ファイルは消す。
     * index.json は毎回書く（checkedAt が変わるため）。
     *
     * @param list<StoredArticle> $articles
     * @param string $checkedAt JST の DATE_ATOM
     * @param list<FailureNotice> $failures
     * @return void
     */
    public function save(array $articles, string $checkedAt, array $failures): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0777, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Could not create %s.', $this->directory));
        }

        $days = [];

        foreach ($articles as $article) {
            $days[$article->date()][] = $article;
        }

        krsort($days, SORT_STRING);

        foreach ($days as $date => $dayArticles) {
            usort($dayArticles, self::compare(...));

            $this->write($this->directory . '/' . $date . '.json', [
                'date' => $date,
                'articles' => array_map(static fn(StoredArticle $a): array => $a->toArray(), $dayArticles),
            ]);
        }

        foreach ($this->dayFiles() as $path) {
            if (!isset($days[basename($path, '.json')]) && !unlink($path)) {
                throw new RuntimeException(sprintf('Could not delete %s.', $path));
            }
        }

        $this->write($this->directory . '/index.json', [
            'dates' => array_map(strval(...), array_keys($days)),
            'checkedAt' => $checkedAt,
            'failures' => array_map(static fn(FailureNotice $f): array => $f->toArray(), $failures),
        ]);
    }

    /**
     * publishedAt の新しい順 → source → id。publishedAt は形式とオフセットが揃っているので文字列で比べられる。
     *
     * @param \Shimomo\AutoraceNews\StoredArticle $a
     * @param \Shimomo\AutoraceNews\StoredArticle $b
     * @return int
     */
    private static function compare(StoredArticle $a, StoredArticle $b): int
    {
        return [$b->publishedAt, $a->source, $a->id] <=> [$a->publishedAt, $b->source, $b->id];
    }

    /**
     * @return list<string>
     */
    private function dayFiles(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $names = scandir($this->directory);

        if ($names === false) {
            throw new RuntimeException(sprintf('Could not list %s.', $this->directory));
        }

        $paths = [];

        foreach ($names as $name) {
            if (preg_match(self::DAY_FILE_PATTERN, $name) === 1) {
                $paths[] = $this->directory . '/' . $name;
            }
        }

        return $paths;
    }

    /**
     * @param string $path
     * @return array<array-key, mixed>
     */
    private function decode(string $path): array
    {
        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Could not read %s.', $path));
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new UnexpectedValueException(sprintf('%s is not a JSON object.', $path));
        }

        return $decoded;
    }

    /**
     * @param string $path
     * @param array<string, mixed> $data
     * @return void
     */
    private function write(string $path, array $data): void
    {
        $json = json_encode($data, self::JSON_FLAGS) . "\n";

        if (is_file($path) && file_get_contents($path) === $json) {
            return;
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException(sprintf('Could not write %s.', $path));
        }
    }
}
