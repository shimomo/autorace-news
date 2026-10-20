<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

/**
 * 既存の記事に、今回集めた記事を (source, id) で足す・上書きする。
 * 今回の結果に無い既存の記事は消さない。
 *
 * @author shimomo
 */
final class ArticleMerger
{
    /**
     * @param list<StoredArticle> $existing
     * @param list<StoredArticle> $incoming 同じ (source, id) が複数あれば後のものを採る
     * @return \Shimomo\AutoraceNews\MergeResult
     */
    public function merge(array $existing, array $incoming): MergeResult
    {
        $byKey = [];

        foreach ($existing as $article) {
            $byKey[$article->key()] = $article;
        }

        $latest = [];

        foreach ($incoming as $article) {
            $latest[$article->key()] = $article;
        }

        $added = 0;
        $updated = 0;

        foreach ($latest as $key => $article) {
            $before = $byKey[$key] ?? null;

            if ($before === null) {
                $added++;
            } elseif ($before->toArray() !== $article->toArray()) {
                $updated++;
            }

            $byKey[$key] = $article;
        }

        return new MergeResult(array_values($byKey), $added, $updated);
    }
}
