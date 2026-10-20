<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

/**
 * 突き合わせの結果。articles の順序は決めない（並べるのは DataStore）。
 *
 * @author shimomo
 */
final readonly class MergeResult
{
    /**
     * @param list<StoredArticle> $articles
     * @param int $added 新しく足した件数
     * @param int $updated 既存から中身が変わった件数
     */
    public function __construct(
        public array $articles,
        public int $added,
        public int $updated,
    ) {
    }
}
