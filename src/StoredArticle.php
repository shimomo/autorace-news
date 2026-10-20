<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

use DateTimeZone;
use Shimomo\AutoraceNewsCollector\Article;
use UnexpectedValueException;

/**
 * 日ファイルに保存する記事1件。
 *
 * 公開してよいのは見出し・リンク・配信元・日時だけなので、ライブラリの Article から
 * 本文・要約・サムネイルを落とした7項目だけを持つ。publishedAt は JST の DATE_ATOM 文字列。
 *
 * @author shimomo
 */
final readonly class StoredArticle
{
    private const array STRING_FIELDS = ['source', 'sourceName', 'id', 'title', 'url', 'publishedAt'];

    /**
     * @param string $source ソースキー
     * @param string $sourceName 表示名
     * @param string $id ソース内で安定した ID
     * @param string $title
     * @param string $url
     * @param list<string> $categories
     * @param string $publishedAt Y-m-d\TH:i:sP（Asia/Tokyo）
     */
    public function __construct(
        public string $source,
        public string $sourceName,
        public string $id,
        public string $title,
        public string $url,
        public array $categories,
        public string $publishedAt,
    ) {
    }

    /**
     * @param \Shimomo\AutoraceNewsCollector\Article $article
     * @return self
     */
    public static function fromArticle(Article $article): self
    {
        return new self(
            $article->source,
            $article->sourceName,
            $article->id,
            $article->title,
            $article->url,
            $article->categories,
            // ライブラリは JST に揃えて返すが、日の振り分けがずれると致命的なのでここでも揃える
            $article->publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM),
        );
    }

    /**
     * 日ファイルから読んだ1件。壊れたデータを黙って通すと次の書き出しで消えるので、例外にする。
     *
     * @param array<array-key, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $strings = [];

        foreach (self::STRING_FIELDS as $field) {
            $value = $data[$field] ?? null;

            if (!is_string($value)) {
                throw new UnexpectedValueException(sprintf('Article field "%s" must be a string.', $field));
            }

            $strings[$field] = $value;
        }

        $categories = $data['categories'] ?? null;

        if (!is_array($categories) || !array_is_list($categories)) {
            throw new UnexpectedValueException('Article field "categories" must be a list.');
        }

        $names = [];

        foreach ($categories as $category) {
            if (!is_string($category)) {
                throw new UnexpectedValueException('Article field "categories" must contain only strings.');
            }

            $names[] = $category;
        }

        return new self(
            $strings['source'],
            $strings['sourceName'],
            $strings['id'],
            $strings['title'],
            $strings['url'],
            $names,
            $strings['publishedAt'],
        );
    }

    /**
     * @return array{source: string, sourceName: string, id: string, title: string, url: string, categories: list<string>, publishedAt: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'sourceName' => $this->sourceName,
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'categories' => $this->categories,
            'publishedAt' => $this->publishedAt,
        ];
    }

    /**
     * 同一性の判定に使う。区切りの NUL は source にも id にも現れない。
     *
     * @return string
     */
    public function key(): string
    {
        return $this->source . "\0" . $this->id;
    }

    /**
     * @return string YYYY-MM-DD（JST）
     */
    public function date(): string
    {
        return substr($this->publishedAt, 0, 10);
    }
}
