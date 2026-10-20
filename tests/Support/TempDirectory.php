<?php

declare(strict_types=1);

namespace Shimomo\AutoraceNews\Tests\Support;

/**
 * テストごとの一時ディレクトリ。path() はまだ作らない（作るのはテスト対象の役目）。
 *
 * @author shimomo
 */
final class TempDirectory
{
    /**
     * @return string
     */
    public static function path(): string
    {
        return sys_get_temp_dir() . '/autorace-news-test-' . bin2hex(random_bytes(6));
    }

    /**
     * @param string $dir
     * @return void
     */
    public static function remove(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/*');

        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        rmdir($dir);
    }
}
