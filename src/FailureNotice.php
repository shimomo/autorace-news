<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews;

/**
 * ページに出す「この配信元の取得に失敗した」1件。
 *
 * @author shimomo
 */
final readonly class FailureNotice
{
    /**
     * @param string $source ソースキー
     * @param string $message ライブラリの失敗メッセージ（最初の1件）
     */
    public function __construct(
        public string $source,
        public string $message,
    ) {
    }

    /**
     * @return array{source: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'message' => $this->message,
        ];
    }
}
