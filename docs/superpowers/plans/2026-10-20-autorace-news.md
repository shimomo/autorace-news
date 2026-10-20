# autorace-news Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** autorace-news-collector で 1 時間ごとに記事を集め、タイトル・リンク・配信元・日時だけを 1 日ごと・3 段で GitHub Pages に出す。

**Architecture:** PHP の収集スクリプト（`bin/collect`）がライブラリの `Collector` を呼び、既存の `docs/data/*.json` と `(source, id)` で突き合わせて日ごとの JSON と `index.json` を書く。GitHub Actions が毎時それを実行してコミットし、Pages が `main` の `/docs` を配信する。ページは素の `index.html` + `app.js` + `style.css` で、tech-trends に寄せる。

**Tech Stack:** PHP 8.4、shimomo/autorace-news-collector ^0.2、PHPUnit 13・Pint・Psalm 6、素の JavaScript（ES modules）と `node --test`（Node.js 22 以上）、GitHub Actions、GitHub Pages

**Spec:** `docs/superpowers/specs/2026-10-20-autorace-news-design.md`（章番号は「設計書 5.3」のように参照する）

## Global Constraints

- 作業フォルダ: `/home/shimomo/github/shimomo/autorace-news`。ブランチは `main` に直接コミットする（tech-trends と同じ運用）
- PHP は `^8.4`（Actions は 8.4。ローカルは 8.5 なので `composer.json` の `config.platform.php` を `8.4.0` に固定する）
- ライブラリは `shimomo/autorace-news-collector` `^0.2`。`Collector::default()` の既定値（`maxPages` 3、間隔 1 秒、User-Agent）をそのまま使う
- 名前空間 `Shimomo\AutoraceNews\`（`src/`）、テストは `Shimomo\AutoraceNews\Tests\`（`tests/`）
- PHP のコード規約はライブラリに合わせる: `declare(strict_types=1);`、クラスは `final`（値は `final readonly`）、全メソッドに PHPDoc（`@param` / `@return`）、クラスに `@author shimomo`、テストは `#[Test]` 属性とキャメルケースのメソッド名、`self::assert*`。コメントは日本語
- データに載せる項目は `source`、`sourceName`、`id`、`title`、`url`、`categories`、`publishedAt` の 7 つだけ。`summary`・`bodyHtml`・`bodyText`・`thumbnailUrl` は書かない
- 時刻はすべて Asia/Tokyo、形式は `Y-m-d\TH:i:sP`（PHP の `DATE_ATOM`、例 `2026-10-19T18:32:00+09:00`）。日の区切りは JST の 0:00〜24:00
- `since`: `index.json` が無いか `dates` が空なら `null`、それ以外は「現在時刻 − 48 時間」
- JSON は `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR`、末尾に改行 1 つ
- cron は `17 * * * *`。収集が止まった警告のしきい値は 3 時間（ちょうど 3 時間は警告しない）
- 段と配信元: 公式のお知らせ = `autorace.jp`・`kawaguchi`・`isesaki`・`hamamatsu`・`sanyo`、新聞記事 = `nikkansports`、動画 = `youtube`。表にない source は公式のお知らせ
- テストはネットワークに出ない（PHP はライブラリの `Sources\ArraySource` を `new Collector([...])` に差し込む）
- コミットメッセージは英語の接頭辞付き 1 行（`feat:` `ci:` `docs:` など）＋必要なら日本語の本文、最後に空行と `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- ページに出す文言（そのまま使う）:
  - 段の見出し: `公式のお知らせ` / `新聞記事` / `動画`、件数 `{n} 件`
  - 空の段: `この日の公式のお知らせはありません。` / `この日の日刊スポーツの記事はありません。` / `この日の動画はありません。`
  - 失敗: `{配信元} の取得に失敗しました（{message}）。次の収集で取り直します。`
  - 停止: `収集が止まっている可能性があります（最終収集: {MM/DD HH:MM}）`
  - 日付なし: `指定の日付が無いため最新を表示しています。` / データなし: `まだ収集結果がありません。` / 読み込み失敗: `読み込みに失敗しました: {message}`
  - フッター: `見出しとリンクのみを掲載しています。記事は各配信元でご覧ください。`

## Review Focus

1. **JST の日付境界:** ライブラリが UTC などで `publishedAt` を返しても、日ファイルの振り分けと保存値は JST になる（`2026-10-19T15:10:00Z` → `2026-10-20` の日ファイルに `2026-10-20T00:10:00+09:00`）。→ Task 1 のテスト `fromArticleNormalizesToTokyo`
2. **1 回の収集結果の中で同じ `(source, id)` が 2 回来る**（WordPress のページ送り中に投稿が増えてずれた場合など）: 1 件にまとまり、後に来た値が残る。→ Task 2 のテスト `collapsesDuplicatesWithinIncoming`
3. **タイトルや URL に HTML の特殊文字や `javascript:` が入る:** タイトルはエスケープされて崩れず、`http(s)` 以外の URL はリンクにしない（`href="#"`）。→ Task 6 のテスト `renderCardEscapesTitleAndRejectsUnsafeUrl`
4. **ライブラリに新しい配信元が増える:** 表にない source は公式のお知らせの段に入り、`sourceName` がバッジに出る。→ Task 6 のテスト `unknownSourceGoesToOfficialWithItsName`
5. **深夜 0 時台の時刻表示:** `00:10` が `24:10` にならない。ブラウザのタイムゾーンに関係なく JST で出る。→ Task 6 のテスト `formatsTimesInTokyoWith24HourClock`

---

### Task 1: リポジトリの土台と StoredArticle

保存する記事 1 件の値オブジェクト。ライブラリの `Article` から公開してよい 7 項目だけを取り出し、JST に揃える。

**Files:**
- Create: `.gitignore`、`.editorconfig`、`LICENSE`、`composer.json`、`composer.lock`、`phpunit.xml.dist`、`psalm.xml.dist`、`pint.json`
- Create: `src/StoredArticle.php`
- Create: `tests/Support/Articles.php`、`tests/StoredArticleTest.php`
- 既存: `docs/superpowers/specs/2026-10-20-autorace-news-design.md`、`docs/superpowers/plans/2026-10-20-autorace-news.md`（この計画）を最初のコミットに含める

**Interfaces:**
- Consumes: `Shimomo\AutoraceNewsCollector\Article`（ライブラリ。`source`、`sourceName`、`id`、`url`、`title`、`categories`、`publishedAt` などの public readonly プロパティ）
- Produces:
  - `final readonly class StoredArticle`
    - `__construct(string $source, string $sourceName, string $id, string $title, string $url, list<string> $categories, string $publishedAt)` — `$publishedAt` は `Y-m-d\TH:i:sP`（JST）
    - `static fromArticle(Article $article): self`
    - `static fromArray(array $data): self` — キーの欠落・型違いは `\UnexpectedValueException`
    - `toArray(): array{source: string, sourceName: string, id: string, title: string, url: string, categories: list<string>, publishedAt: string}`（キーはこの順）
    - `key(): string` — `source` と `id` の組を表す文字列（`"{$source}\0{$id}"`）
    - `date(): string` — `publishedAt` の先頭 10 文字（`YYYY-MM-DD`）
  - テスト補助 `Tests\Support\Articles`
    - `static library(string $source, string $id, string $publishedAt, string $title = 'タイトル', list<string> $categories = []): Article` — `publishedAt` は `new DateTimeImmutable($publishedAt, new DateTimeZone('Asia/Tokyo'))`、`sourceName` は `"{$source} name"`、`url` は `"https://example.com/{$source}/{$id}"`
    - `static stored(...同じ引数...): StoredArticle` — `StoredArticle::fromArticle(self::library(...))`

- [ ] **Step 1: git を初期化し、設定ファイルを置く**

```bash
git init -b main
```

- `.editorconfig`、`pint.json` は `/home/shimomo/github/shimomo/autorace-news-collector/` のものをそのままコピーする（`.editorconfig` の `tests/fixtures/**` の節は消す）
- `phpunit.xml.dist`、`psalm.xml.dist` はライブラリのものをコピーし、testsuite 名を `AutoraceNews` にする
- `.gitignore`: `/vendor/`、`.phpunit.cache`、`.phpunit.result.cache`、`phpunit.xml`、`psalm.xml`、`.superpowers/`、`CLAUDE.md`、`.DS_Store`（`composer.lock` は無視しない。アプリなのでコミットする）
- `LICENSE`: MIT、`Copyright (c) 2026 shimomo`（tech-trends の `LICENSE` と同じ本文）
- `composer.json`: `name` `shimomo/autorace-news`、`type` `project`、`license` `MIT`、`require` `php ^8.4` と `shimomo/autorace-news-collector ^0.2`、`require-dev` `laravel/pint ^1.27`・`phpunit/phpunit ^13.2`・`vimeo/psalm ^6.0`、上の autoload 2 つ、`config` に `sort-packages: true` と `platform.php: "8.4.0"`、`scripts` に `test: phpunit`、`analyse: psalm`、`lint: pint --test`、`fix: pint`、`collect: php bin/collect`

- [ ] **Step 2: 依存を入れる**

Run: `composer install`
Expected: `vendor/shimomo/autorace-news-collector` ができ、`composer.lock` が書かれる

- [ ] **Step 3: 失敗するテストを書く（`tests/StoredArticleTest.php`）**

```php
#[Test]
public function fromArticleKeepsOnlyPublicFields(): void
{
    $article = new Article(
        'autorace.jp', 'AUTORACE.JP', '038825', 'https://autorace.jp/news/2026/10/19/038825/',
        '【第8戦(山陽ステージ)のポイントを追記！】', ['ニュース', 'レース情報'],
        new DateTimeImmutable('2026-10-19 18:32:00', new DateTimeZone('Asia/Tokyo')),
        'summary', '<p>body</p>', 'body', 'https://autorace.jp/thumb.jpg',
    );

    self::assertSame([
        'source' => 'autorace.jp',
        'sourceName' => 'AUTORACE.JP',
        'id' => '038825',
        'title' => '【第8戦(山陽ステージ)のポイントを追記！】',
        'url' => 'https://autorace.jp/news/2026/10/19/038825/',
        'categories' => ['ニュース', 'レース情報'],
        'publishedAt' => '2026-10-19T18:32:00+09:00',
    ], StoredArticle::fromArticle($article)->toArray());
}

#[Test]
public function fromArticleNormalizesToTokyo(): void
{
    $article = Articles::library('isesaki', '7159', '2026-10-19T15:10:00Z');
    $stored = StoredArticle::fromArticle($article);

    self::assertSame('2026-10-20T00:10:00+09:00', $stored->publishedAt);
    self::assertSame('2026-10-20', $stored->date());
}

#[Test]
public function roundTripsThroughArray(): void
{
    $stored = Articles::stored('sanyo', '4009', '2026-10-19 20:10:00', '１０／１９　応援メッセージ📣', ['応援メッセージ']);

    self::assertEquals($stored, StoredArticle::fromArray($stored->toArray()));
}

#[Test]
public function fromArrayRejectsMissingField(): void
{
    $data = Articles::stored('youtube', 'abc', '2026-10-19 19:00:00')->toArray();
    unset($data['title']);

    $this->expectException(UnexpectedValueException::class);
    StoredArticle::fromArray($data);
}

#[Test]
public function keyIdentifiesSourceAndId(): void
{
    self::assertSame(
        Articles::stored('kawaguchi', '100', '2026-10-19 10:00:00', 'old')->key(),
        Articles::stored('kawaguchi', '100', '2026-10-20 10:00:00', 'new')->key(),
    );
    self::assertNotSame(
        Articles::stored('kawaguchi', '100', '2026-10-19 10:00:00')->key(),
        Articles::stored('isesaki', '100', '2026-10-19 10:00:00')->key(),
    );
}
```

- [ ] **Step 4: テストが落ちることを確かめる**

Run: `composer test`
Expected: FAIL（`StoredArticle` と `Articles` が無い）

- [ ] **Step 5: `tests/Support/Articles.php` と `src/StoredArticle.php` を書く**

`fromArticle` は `$article->publishedAt->setTimezone(new DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM)`。`fromArray` は 7 キーそれぞれの存在と型（`categories` は文字列の list）を確かめる。

- [ ] **Step 6: テスト・lint・静的解析を通す**

Run: `composer test && composer lint && composer analyse`
Expected: すべて成功（PHPUnit `OK (5 tests, ...)`、Pint の差分なし、Psalm `No errors found!`）

- [ ] **Step 7: コミット**

```bash
git add .
git commit -m "feat: add stored article value object" -m "設計書と実装計画、リポジトリの土台を含む。"
```

（本文の最後に Global Constraints の Co-Authored-By 行を付ける。以降のコミットも同じ）

---

### Task 2: ArticleMerger

既存の記事に、今回集めた記事を `(source, id)` で足す・上書きする（設計書 5.3）。

**Files:**
- Create: `src/ArticleMerger.php`、`src/MergeResult.php`
- Test: `tests/ArticleMergerTest.php`

**Interfaces:**
- Consumes: `StoredArticle`（Task 1）、`Tests\Support\Articles::stored()`
- Produces:
  - `final readonly class MergeResult { __construct(list<StoredArticle> $articles, int $added, int $updated) }`
  - `final class ArticleMerger { merge(list<StoredArticle> $existing, list<StoredArticle> $incoming): MergeResult }` — `articles` の順序は決めない（並べるのは Task 4）。`updated` は既存と `toArray()` が違った件数

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test]
public function addsNewArticles(): void
{
    $a = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00');
    $result = (new ArticleMerger())->merge([], [$a]);

    self::assertEquals([$a], $result->articles);
    self::assertSame(1, $result->added);
    self::assertSame(0, $result->updated);
}

#[Test]
public function overwritesBySourceAndId(): void
{
    $old = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00', '旧タイトル');
    $new = Articles::stored('autorace.jp', '1', '2026-10-19 10:00:00', '【追記！】新タイトル');
    $result = (new ArticleMerger())->merge([$old], [$new]);

    self::assertEquals([$new], $result->articles);
    self::assertSame(0, $result->added);
    self::assertSame(1, $result->updated);
}

#[Test]
public function unchangedArticleIsNotCounted(): void
{
    $a = Articles::stored('youtube', 'v1', '2026-10-19 19:00:00');
    $result = (new ArticleMerger())->merge([$a], [$a]);

    self::assertSame(0, $result->added);
    self::assertSame(0, $result->updated);
}

#[Test]
public function keepsArticlesMissingFromIncoming(): void
{
    $a = Articles::stored('sanyo', '1', '2026-10-18 20:15:00');
    $b = Articles::stored('sanyo', '2', '2026-10-19 20:10:00');
    $result = (new ArticleMerger())->merge([$a, $b], [$b]);

    self::assertCount(2, $result->articles);
}

#[Test]
public function collapsesDuplicatesWithinIncoming(): void
{
    $first = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00', '1回目');
    $second = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00', '2回目');
    $result = (new ArticleMerger())->merge([], [$first, $second]);

    self::assertEquals([$second], $result->articles);
    self::assertSame(1, $result->added);
}
```

- [ ] **Step 2: テストが落ちることを確かめる**

Run: `vendor/bin/phpunit tests/ArticleMergerTest.php`
Expected: FAIL（`ArticleMerger` が無い）

- [ ] **Step 3: `MergeResult` と `ArticleMerger::merge()` を書く**

`key()` を添字にした連想配列に既存を入れ、incoming を順に上書きする。added / updated は「既存にあったか」「既存と `toArray()` が違うか」で数える（incoming 内の重複は最後の 1 件だけを数える）。

- [ ] **Step 4: テストを通す**

Run: `composer test && composer lint && composer analyse`
Expected: すべて成功

- [ ] **Step 5: コミット**

```bash
git add src/ArticleMerger.php src/MergeResult.php tests/ArticleMergerTest.php
git commit -m "feat: merge collected articles by source and id"
```

---

### Task 3: FailureSummary

ライブラリの `failures` を、ページに出す「配信元ごとの失敗」にまとめる（設計書 5.4）。

**Files:**
- Create: `src/FailureNotice.php`、`src/FailureSummary.php`
- Test: `tests/FailureSummaryTest.php`

**Interfaces:**
- Consumes: `Shimomo\AutoraceNewsCollector\CollectResult`（`articles: list<Article>`、`failures: list<SourceFailure>`）、`SourceFailure(string $source, ?string $url, string $message, ?Throwable $previous = null)`
- Produces:
  - `final readonly class FailureNotice { __construct(string $source, string $message); toArray(): array{source: string, message: string} }`
  - `final class FailureSummary { static fromResult(CollectResult $result): list<FailureNotice> }` — 配信元ごとに 1 件、最初に現れた順、メッセージは最初の 1 件

- [ ] **Step 1: 失敗するテストを書く**

```php
#[Test]
public function ignoresArticlePageFailuresWhenArticleWasCollected(): void
{
    $article = Articles::library('nikkansports', '202610190001', '2026-10-19 05:00:00');
    $result = new CollectResult([$article], [new SourceFailure('nikkansports', $article->url, 'GET ... returned HTTP 500.')]);

    self::assertSame([], FailureSummary::fromResult($result));
}

#[Test]
public function urlMatchIsPerSource(): void
{
    $article = Articles::library('autorace.jp', '1', '2026-10-19 05:00:00');
    $result = new CollectResult([$article], [new SourceFailure('nikkansports', $article->url, 'broken')]);

    self::assertEquals([new FailureNotice('nikkansports', 'broken')], FailureSummary::fromResult($result));
}

#[Test]
public function keepsSourceWideFailures(): void
{
    $result = new CollectResult([], [new SourceFailure('autorace.jp', null, 'GET https://autorace.jp/rss.xml returned HTTP 503.')]);

    self::assertEquals(
        [new FailureNotice('autorace.jp', 'GET https://autorace.jp/rss.xml returned HTTP 503.')],
        FailureSummary::fromResult($result),
    );
}

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

#[Test]
public function noticeConvertsToArray(): void
{
    self::assertSame(['source' => 'youtube', 'message' => 'timeout'], (new FailureNotice('youtube', 'timeout'))->toArray());
}
```

- [ ] **Step 2: テストが落ちることを確かめる**

Run: `vendor/bin/phpunit tests/FailureSummaryTest.php`
Expected: FAIL（クラスが無い）

- [ ] **Step 3: `FailureNotice` と `FailureSummary::fromResult()` を書く**

記事の `(source, url)` の集合を作り、`url` がその集合に入る失敗を捨ててから、source ごとに最初の 1 件を残す。

- [ ] **Step 4: テストを通す**

Run: `composer test && composer lint && composer analyse`
Expected: すべて成功

- [ ] **Step 5: コミット**

```bash
git add src/FailureNotice.php src/FailureSummary.php tests/FailureSummaryTest.php
git commit -m "feat: summarize collection failures per source"
```

---

### Task 4: DataStore

`docs/data/` の読み書き（設計書 5.5）。

**Files:**
- Create: `src/DataStore.php`
- Test: `tests/DataStoreTest.php`（各テストで一時ディレクトリを作り、`tearDown` で消す）

**Interfaces:**
- Consumes: `StoredArticle`（Task 1）、`FailureNotice`（Task 3）
- Produces: `final class DataStore`
  - `__construct(string $directory)` — 無ければ `save()` で作る
  - `hasArticles(): bool` — `index.json` があり、`dates` が空でない
  - `loadArticles(): list<StoredArticle>` — `^\d{4}-\d{2}-\d{2}\.json$` のファイルをすべて読む。壊れた JSON は `\JsonException`（握りつぶさない）
  - `save(list<StoredArticle> $articles, string $checkedAt, list<FailureNotice> $failures): void`
    - 日ごとに `{"date": ..., "articles": [...]}` を書く。並びは `publishedAt` 降順 → `source` 昇順 → `id` 昇順
    - 中身が変わらない日ファイルは書き直さない。記事が無くなった日ファイルは消す
    - `index.json` に `{"dates": [降順], "checkedAt": ..., "failures": [...]}` を毎回書く

- [ ] **Step 1: 失敗するテストを書く**

```php
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

#[Test]
public function savesOneFilePerDayNewestFirst(): void
{
    $late = Articles::stored('youtube', 'v1', '2026-10-19 22:15:00');
    $tieB = Articles::stored('sanyo', '9', '2026-10-19 15:00:00');
    $tieA = Articles::stored('autorace.jp', '038826', '2026-10-19 15:00:00');
    $other = Articles::stored('isesaki', '7159', '2026-10-17 14:56:00');

    (new DataStore($this->dir))->save([$tieB, $other, $late, $tieA], '2026-10-20T12:17:05+09:00', [new FailureNotice('autorace.jp', 'HTTP 503')]);

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

#[Test]
public function writesPrettyUnescapedJson(): void
{
    (new DataStore($this->dir))->save([Articles::stored('sanyo', '1', '2026-10-19 20:10:00', '応援メッセージ')], '2026-10-20T12:17:05+09:00', []);
    $raw = (string) file_get_contents($this->dir . '/2026-10-19.json');

    self::assertStringContainsString('"title": "応援メッセージ"', $raw);
    self::assertStringContainsString('"url": "https://example.com/sanyo/1"', $raw);
    self::assertStringEndsWith("}\n", $raw);
}

#[Test]
public function roundTripsArticles(): void
{
    $articles = [Articles::stored('sanyo', '1', '2026-10-19 20:10:00'), Articles::stored('youtube', 'v', '2026-10-18 07:00:00')];
    $store = new DataStore($this->dir);
    $store->save($articles, '2026-10-20T12:17:05+09:00', []);

    self::assertEqualsCanonicalizing($articles, $store->loadArticles());
}

#[Test]
public function deletesDayFilesThatBecameEmpty(): void
{
    $store = new DataStore($this->dir);
    $store->save([Articles::stored('sanyo', '1', '2026-10-18 20:15:00')], '2026-10-20T11:17:05+09:00', []);
    $store->save([Articles::stored('sanyo', '1', '2026-10-19 00:05:00')], '2026-10-20T12:17:05+09:00', []);

    self::assertFileDoesNotExist($this->dir . '/2026-10-18.json');
    self::assertSame(['2026-10-19'], $this->readJson('index.json')['dates']);
}

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
}

#[Test]
public function loadArticlesFailsOnBrokenJson(): void
{
    mkdir($this->dir);
    file_put_contents($this->dir . '/2026-10-19.json', '{');

    $this->expectException(JsonException::class);
    (new DataStore($this->dir))->loadArticles();
}
```

（`$this->dir` は `setUp` で `sys_get_temp_dir()` 配下の未作成のパスにする。`readJson(string $name): array` はテスト内の private ヘルパー）

- [ ] **Step 2: テストが落ちることを確かめる**

Run: `vendor/bin/phpunit tests/DataStoreTest.php`
Expected: FAIL（`DataStore` が無い）

- [ ] **Step 3: `DataStore` を書く**

書き込みは「エンコードした文字列が既存ファイルの中身と同じなら書かない」で判定する。日付の並びは文字列の降順でよい（形式が固定なので）。

- [ ] **Step 4: テストを通す**

Run: `composer test && composer lint && composer analyse`
Expected: すべて成功

- [ ] **Step 5: コミット**

```bash
git add src/DataStore.php tests/DataStoreTest.php
git commit -m "feat: read and write daily data files"
```

---

### Task 5: CollectRun と bin/collect

1 回分の収集をまとめて行う入口（設計書 5.1〜5.4）。

**Files:**
- Create: `src/CollectRun.php`、`src/RunReport.php`、`bin/collect`（実行権限付き）
- Test: `tests/CollectRunTest.php`

**Interfaces:**
- Consumes: `Collector`（ライブラリ。`new Collector(list<Source>)`、`sources(): list<Source>`、`collect(?DateTimeInterface $since): CollectResult`）、`Sources\ArraySource(string $key, string $name, list<Article> $articles = [], list<SourceFailure> $failures = [])`、Task 1〜4 のすべて
- Produces:
  - `final readonly class RunReport`
    - `__construct(int $collected, int $added, int $updated, list<FailureNotice> $failures, bool $allFailed)`
    - `exitCode(): int` — `allFailed` なら `1`、それ以外 `0`
    - `summary(): string` — Actions の job summary 用の Markdown。件数（収集・新規・更新）と、失敗した配信元とメッセージを含む（書式は任せる）
  - `final class CollectRun`
    - `__construct(Collector $collector, DataStore $store)`
    - `run(DateTimeImmutable $now): RunReport` — `since` を決めて収集 → `StoredArticle::fromArticle` → `ArticleMerger` → `FailureSummary` → `DataStore::save($merged, $now を JST の DATE_ATOM, $failures)`。`allFailed` は「失敗した配信元キーの数 = `collector->sources()` の重複を除いたキーの数（1 以上）」
  - `bin/collect [data-dir]` — 既定の `data-dir` は `docs/data`（リポジトリ直下からの相対ではなく `dirname(__DIR__) . '/docs/data'`）。`Collector::default()` で `CollectRun` を回し、`summary()` を標準出力へ、環境変数 `GITHUB_STEP_SUMMARY` があればそのファイルにも追記し、`exitCode()` で終わる

- [ ] **Step 1: 失敗するテストを書く**

```php
// $this->now = new DateTimeImmutable('2026-10-20 12:17:05', new DateTimeZone('Asia/Tokyo'))
// $this->store = new DataStore($this->dir)（一時ディレクトリ）

#[Test]
public function firstRunTakesEverything(): void
{
    $old = Articles::library('kawaguchi', '1', '2026-07-01 10:00:00');
    $this->run([new ArraySource('kawaguchi', '川口', [$old])]);

    self::assertFileExists($this->dir . '/2026-07-01.json');
}

#[Test]
public function laterRunsLookBack48Hours(): void
{
    $this->store->save([Articles::stored('sanyo', '0', '2026-10-15 10:00:00')], '2026-10-20T11:17:05+09:00', []);
    $report = $this->run([new ArraySource('sanyo', '山陽', [
        Articles::library('sanyo', 'old', '2026-10-18 11:17:04'),
        Articles::library('sanyo', 'edge', '2026-10-18 12:17:05'),
    ])]);

    self::assertSame(1, $report->added);
    self::assertSame(['edge'], array_map(static fn(StoredArticle $a): string => $a->id, array_values(array_filter(
        $this->store->loadArticles(),
        static fn(StoredArticle $a): bool => $a->date() === '2026-10-18',
    ))));
}

#[Test]
public function writesCheckedAtAndFailures(): void
{
    $this->run([
        new ArraySource('youtube', 'YouTube', [Articles::library('youtube', 'v', '2026-10-20 07:00:00')]),
        new ArraySource('autorace.jp', 'AUTORACE.JP', [], [new SourceFailure('autorace.jp', null, 'HTTP 503')]),
    ]);
    $index = json_decode((string) file_get_contents($this->dir . '/index.json'), true);

    self::assertSame('2026-10-20T12:17:05+09:00', $index['checkedAt']);
    self::assertSame([['source' => 'autorace.jp', 'message' => 'HTTP 503']], $index['failures']);
}

#[Test]
public function partialFailureStillSavesAndExitsZero(): void
{
    $report = $this->run([
        new ArraySource('youtube', 'YouTube', [Articles::library('youtube', 'v', '2026-10-20 07:00:00')]),
        new ArraySource('autorace.jp', 'AUTORACE.JP', [], [new SourceFailure('autorace.jp', null, 'HTTP 503')]),
    ]);

    self::assertSame(0, $report->exitCode());
    self::assertFileExists($this->dir . '/2026-10-20.json');
}

#[Test]
public function allSourcesFailedExitsNonZero(): void
{
    $report = $this->run([
        new ArraySource('kawaguchi', '川口', [], [new SourceFailure('kawaguchi', null, 'news down')]),
        new ArraySource('kawaguchi', '川口', [], [new SourceFailure('kawaguchi', null, 'interview down')]),
        new ArraySource('sanyo', '山陽', [], [new SourceFailure('sanyo', null, 'down')]),
    ]);

    self::assertTrue($report->allFailed);
    self::assertSame(1, $report->exitCode());
    self::assertFileExists($this->dir . '/index.json');
}

#[Test]
public function movesArticleWhenPublishedDateChanges(): void
{
    $this->store->save([Articles::stored('autorace.jp', '1', '2026-10-19 23:50:00')], '2026-10-20T11:17:05+09:00', []);
    $this->run([new ArraySource('autorace.jp', 'AUTORACE.JP', [Articles::library('autorace.jp', '1', '2026-10-20 00:05:00')])]);

    self::assertFileDoesNotExist($this->dir . '/2026-10-19.json');
    self::assertCount(1, $this->store->loadArticles());
}

#[Test]
public function summaryNamesFailedSources(): void
{
    $report = $this->run([new ArraySource('autorace.jp', 'AUTORACE.JP', [], [new SourceFailure('autorace.jp', null, 'HTTP 503')])]);

    self::assertStringContainsString('autorace.jp', $report->summary());
    self::assertStringContainsString('HTTP 503', $report->summary());
}
```

（`run(list<Source> $sources): RunReport` はテスト内の private ヘルパーで、`(new CollectRun(new Collector($sources), $this->store))->run($this->now)` を返す。`laterRunsLookBack48Hours` の 2 つ目の記事はちょうど 48 時間前で、境界を含むことを確かめる）

- [ ] **Step 2: テストが落ちることを確かめる**

Run: `vendor/bin/phpunit tests/CollectRunTest.php`
Expected: FAIL（`CollectRun` が無い）

- [ ] **Step 3: `RunReport`、`CollectRun::run()`、`bin/collect` を書く**

`bin/collect` は `#!/usr/bin/env php` で始め、`require dirname(__DIR__) . '/vendor/autoload.php';`。`chmod +x bin/collect`。`now` は `new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'))`。

- [ ] **Step 4: テストを通す**

Run: `composer test && composer lint && composer analyse`
Expected: すべて成功

- [ ] **Step 5: 実際のサイトで 1 回動かす（一時ディレクトリへ）**

リポジトリの外の空の一時ディレクトリ（scratchpad があればその下）を `$CHECK` として:

Run: `php bin/collect "$CHECK" && cat "$CHECK/index.json"`
Expected: 終了コード 0。`dates` に複数の日付、`checkedAt` が今の JST、`failures` は `[]`（一時的な失敗が出たらもう一度実行して消えることを確かめる）。日ファイルに本文などのキーが無い（`grep -lE 'bodyHtml|bodyText|summary|thumbnailUrl' "$CHECK"/*.json` が何も出さない）。確認後 `$CHECK` を消す

- [ ] **Step 6: コミット**

```bash
git add src/CollectRun.php src/RunReport.php bin/collect tests/CollectRunTest.php
git commit -m "feat: collect, merge and save in one run"
```

---

### Task 6: app.js の純粋関数

表示に使う関数を `docs/app.js` に書き、`node --test` で確かめる（設計書 6.2〜6.6）。DOM を触る部分は Task 7。

**Files:**
- Create: `docs/app.js`（この Task では純粋関数と export だけ。末尾に `if (typeof document !== "undefined") main();` を置くのは Task 7）
- Test: `tests/js/app.test.mjs`

**Interfaces:**
- Consumes: 日ファイルの記事の形（Task 1 の `toArray()`）、`index.json` の `failures` の形（Task 3）
- Produces（すべて `export`）:
  - `SECTIONS` — `[{ id: "official", title: "公式のお知らせ", empty: "この日の公式のお知らせはありません。" }, { id: "news", title: "新聞記事", empty: "この日の日刊スポーツの記事はありません。" }, { id: "video", title: "動画", empty: "この日の動画はありません。" }]`
  - `sectionOf(source: string): "official" | "news" | "video"`
  - `sourceLabel(article: { source: string, sourceName?: string }): string` — `AUTORACE.JP` / `川口オート` / `伊勢崎オート` / `浜松オート` / `山陽オート` / `日刊スポーツ` / `YouTube`、表にない source は `sourceName`、それも無ければ `source`
  - `stripTrailingHashtags(title: string): string`
  - `displayTitle(article): string` — `youtube` だけ `stripTrailingHashtags`
  - `visibleTags(categories: string[]): string[]` — `ニュース`・`pickup` を除いた先頭 2 つ
  - `groupBySection(articles): { official: Article[], news: Article[], video: Article[] }` — 各段 `publishedAt` 降順
  - `formatTime(iso: string): string`（`18:32`）、`formatDateTime(iso: string): string`（`10/20 09:17`）— どちらも `Intl.DateTimeFormat` に `timeZone: "Asia/Tokyo"` と `hourCycle: "h23"`
  - `dateLabel(date: string): string`、`resolveDate(hash: string, dates: string[]): { date: string | null, fallback: boolean }`、`escapeHtml(value: unknown): string` — tech-trends の `docs/app.js` からそのまま移す
  - `isStale(checkedAt: string, now: Date): boolean` — 3 時間を超えたら `true`
  - `safeUrl(url: string): string` — `http://` か `https://` で始まらなければ `"#"`
  - `renderCard(article): string`、`renderSections(articles): string`
  - `failureMessage(failure: { source: string, message: string }): string`、`staleMessage(checkedAt: string): string`

`renderCard` の形（tech-trends のカードに合わせる）:

```html
<article class="card">
  <h3 class="title"><a href="{safeUrl}" target="_blank" rel="noopener noreferrer">{displayTitle}</a></h3>
  <p class="meta"><span class="src {official|news|video}">{sourceLabel}</span>{<span class="tag">…</span> を visibleTags の数だけ}<span class="time">{formatTime}</span></p>
</article>
```

`renderSections` は `SECTIONS` の順に `<h2 class="section">{title} <span class="n">{n} 件</span></h2>` と、記事があれば `<div class="grid">…</div>`、無ければ `<p class="empty">{empty}</p>`。

- [ ] **Step 1: 失敗するテストを書く（`tests/js/app.test.mjs`）**

```js
import assert from "node:assert/strict";
import { test } from "node:test";
import {
  dateLabel, displayTitle, failureMessage, formatDateTime, formatTime, groupBySection,
  isStale, renderCard, renderSections, resolveDate, sectionOf, sourceLabel, staleMessage,
  stripTrailingHashtags, visibleTags,
} from "../../docs/app.js";

const article = (over = {}) => ({
  source: "autorace.jp", sourceName: "AUTORACE.JP", id: "038825",
  title: "【第8戦(山陽ステージ)のポイントを追記！】", url: "https://autorace.jp/news/2026/10/19/038825/",
  categories: ["ニュース", "レース情報"], publishedAt: "2026-10-19T18:32:00+09:00", ...over,
});

test("stripTrailingHashtags removes only the trailing run", () => {
  assert.equal(
    stripTrailingHashtags("抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン#オートレース #autorace #飯塚オート #motorsport #モータースポーツ #shorts"),
    "抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン",
  );
  assert.equal(
    stripTrailingHashtags("【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦 #オートレース #autorace"),
    "【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦",
  );
  assert.equal(stripTrailingHashtags("日本一を懸けた究極のオープン戦 #バイク  #motorsport ＃shorts"), "日本一を懸けた究極のオープン戦");
  assert.equal(stripTrailingHashtags("#オートレース #autorace"), "#オートレース #autorace");
  assert.equal(stripTrailingHashtags("１０／１９　応援メッセージ📣"), "１０／１９　応援メッセージ📣");
});

test("displayTitle strips hashtags only for YouTube", () => {
  assert.equal(displayTitle(article({ source: "youtube", title: "優勝！ #autorace" })), "優勝！");
  assert.equal(displayTitle(article({ title: "お知らせ #autorace" })), "お知らせ #autorace");
});

test("visibleTags drops generic categories and keeps two", () => {
  assert.deepEqual(visibleTags(["ニュース", "レース情報", "伊勢崎", "pickup"]), ["レース情報", "伊勢崎"]);
  assert.deepEqual(visibleTags(["ニュース", "pickup"]), []);
  assert.deepEqual(visibleTags(["イベント", "お知らせ", "その他"]), ["イベント", "お知らせ"]);
});

test("sources map to sections and labels", () => {
  assert.equal(sectionOf("nikkansports"), "news");
  assert.equal(sectionOf("youtube"), "video");
  assert.equal(sectionOf("kawaguchi"), "official");
  assert.equal(sourceLabel({ source: "kawaguchi", sourceName: "川口オートレース" }), "川口オート");
  assert.equal(sourceLabel({ source: "youtube", sourceName: "オートレース公式 YouTube" }), "YouTube");
});

test("unknownSourceGoesToOfficialWithItsName", () => {
  assert.equal(sectionOf("funabashi"), "official");
  assert.equal(sourceLabel({ source: "funabashi", sourceName: "船橋オート" }), "船橋オート");
  assert.match(renderCard(article({ source: "funabashi", sourceName: "船橋オート" })), /<span class="src official">船橋オート<\/span>/);
});

test("formatsTimesInTokyoWith24HourClock", () => {
  assert.equal(formatTime("2026-10-19T18:32:00+09:00"), "18:32");
  assert.equal(formatTime("2026-10-20T00:10:00+09:00"), "00:10");
  assert.equal(formatTime("2026-10-19T15:10:00Z"), "00:10");
  assert.equal(formatDateTime("2026-10-20T09:17:00+09:00"), "10/20 09:17");
  assert.equal(dateLabel("2026-10-19"), "2026-10-19 (月)");
});

test("isStale after more than three hours", () => {
  const checkedAt = "2026-10-20T09:17:00+09:00";
  assert.equal(isStale(checkedAt, new Date("2026-10-20T12:17:00+09:00")), false);
  assert.equal(isStale(checkedAt, new Date("2026-10-20T12:17:01+09:00")), true);
});

test("resolveDate falls back to the latest", () => {
  assert.deepEqual(resolveDate("", ["2026-10-20", "2026-10-19"]), { date: "2026-10-20", fallback: false });
  assert.deepEqual(resolveDate("#2026-10-19", ["2026-10-20", "2026-10-19"]), { date: "2026-10-19", fallback: false });
  assert.deepEqual(resolveDate("#2026-01-01", ["2026-10-20"]), { date: "2026-10-20", fallback: true });
  assert.deepEqual(resolveDate("", []), { date: null, fallback: false });
});

test("renderCard shows link, badge, tags and time", () => {
  const html = renderCard(article({ categories: ["ニュース", "レース情報", "伊勢崎"] }));
  assert.match(html, /href="https:\/\/autorace\.jp\/news\/2026\/10\/19\/038825\/" target="_blank" rel="noopener noreferrer"/);
  assert.match(html, /<span class="src official">AUTORACE\.JP<\/span><span class="tag">レース情報<\/span><span class="tag">伊勢崎<\/span>/);
  assert.match(html, /<span class="time">18:32<\/span>/);
});

test("renderCardEscapesTitleAndRejectsUnsafeUrl", () => {
  const html = renderCard(article({ title: `Q&A <b>"速報"</b>`, url: "javascript:alert(1)" }));
  assert.match(html, /Q&amp;A &lt;b&gt;&quot;速報&quot;&lt;\/b&gt;/);
  assert.match(html, /href="#"/);
  assert.doesNotMatch(html, /javascript:/);
});

test("renderSections keeps order, counts and empty messages", () => {
  const html = renderSections([
    article({ source: "youtube", id: "a", publishedAt: "2026-10-19T07:00:00+09:00" }),
    article({ source: "youtube", id: "b", publishedAt: "2026-10-19T22:15:00+09:00" }),
    article({ source: "sanyo", id: "c" }),
  ]);
  assert.ok(html.indexOf("公式のお知らせ") < html.indexOf("新聞記事"));
  assert.ok(html.indexOf("新聞記事") < html.indexOf("動画"));
  assert.match(html, /公式のお知らせ <span class="n">1 件<\/span>/);
  assert.match(html, /この日の日刊スポーツの記事はありません。/);
  assert.match(html, /動画 <span class="n">2 件<\/span>/);
  const video = groupBySection([article({ source: "youtube", id: "a", publishedAt: "2026-10-19T07:00:00+09:00" }), article({ source: "youtube", id: "b", publishedAt: "2026-10-19T22:15:00+09:00" })]).video;
  assert.deepEqual(video.map((a) => a.id), ["b", "a"]);
});

test("messages for failures and stale collection", () => {
  assert.equal(failureMessage({ source: "autorace.jp", message: "HTTP 503" }), "AUTORACE.JP の取得に失敗しました（HTTP 503）。次の収集で取り直します。");
  assert.equal(failureMessage({ source: "funabashi", message: "x" }), "funabashi の取得に失敗しました（x）。次の収集で取り直します。");
  assert.equal(staleMessage("2026-10-20T09:17:00+09:00"), "収集が止まっている可能性があります（最終収集: 10/20 09:17）");
});
```

- [ ] **Step 2: テストが落ちることを確かめる**

Run: `node --test "tests/js/*.test.mjs"`
Expected: FAIL（`docs/app.js` が無い）

- [ ] **Step 3: `docs/app.js` の純粋関数を書く**

末尾ハッシュタグの除去は、末尾に続く「空白（全角空白を含む）＋ `#`/`＃` で始まる語」の並びを 1 回だけ取り除き、結果が空なら元を返す:

```js
const TRAILING_HASHTAGS = /(?:[\s　]*[#＃][^\s　#＃]+)+[\s　]*$/u;
```

ファイル冒頭のコメントは tech-trends と同じ趣旨（「1 日分の JSON を読んで描く。ビルドなし、依存なし。純粋関数は export してテストから使う。DOM を触るのは main() だけ。」）。

- [ ] **Step 4: テストを通す**

Run: `node --test "tests/js/*.test.mjs"`
Expected: PASS（`# fail 0`）

- [ ] **Step 5: コミット**

```bash
git add docs/app.js tests/js/app.test.mjs
git commit -m "feat: page helpers for sections, cards and messages"
```

---

### Task 7: ページ本体（index.html、style.css、DOM 部分、favicon）

**Files:**
- Create: `docs/index.html`、`docs/style.css`、`docs/.nojekyll`（空）、`docs/favicon.svg`、`docs/favicon.ico`、`docs/apple-touch-icon.png`
- Modify: `docs/app.js`（DOM を触る `main()` などを足す）

**Interfaces:**
- Consumes: Task 6 の export すべて、`docs/data/index.json` と `docs/data/{date}.json`（Task 4 の形）
- Produces: なし（最終成果物）

- [ ] **Step 1: `docs/index.html` を書く**

tech-trends の `docs/index.html` をもとに、次のとおりにする。

- `<title>Autorace News</title>`、`<meta name="description" content="オートレースの公式発表・新聞記事・動画の見出しを1日ごとにまとめた一覧" />`、favicon 3 つと `style.css`、`<script type="module" src="app.js"></script>`
- ヘッダー: `<h1 class="brand">Autorace News</h1>`、`#prev`（`‹ 前日`）・`#date`（`読み込み中…`）・`#next`（`翌日 ›`）、2 行目に `<span id="count"></span>` と `<span id="updated" class="updated"></span>`（凡例は無し）
- `<div id="notices" class="notices"></div>`、`<main id="sections"></main>`
- フッター:

```html
<footer class="footer">
  見出しとリンクのみを掲載しています。記事は各配信元でご覧ください。<br />
  情報源:
  <a href="https://autorace.jp/" target="_blank" rel="noopener noreferrer">AUTORACE.JP</a> ・
  <a href="https://www.nikkansports.com/public_race/auto/" target="_blank" rel="noopener noreferrer">日刊スポーツ</a> ・
  <a href="https://www.kawaguchiauto.jp/" target="_blank" rel="noopener noreferrer">川口</a> ・
  <a href="https://www.isesaki-auto.jp/" target="_blank" rel="noopener noreferrer">伊勢崎</a> ・
  <a href="https://www.hamamatsuauto.jp/" target="_blank" rel="noopener noreferrer">浜松</a> ・
  <a href="https://sanyoauto.jp/" target="_blank" rel="noopener noreferrer">山陽</a> ・
  <a href="https://www.youtube.com/channel/UCJAPqcMNYk3HMknRuPMNvKA" target="_blank" rel="noopener noreferrer">YouTube</a>
</footer>
```

- [ ] **Step 2: `docs/style.css` を書く**

tech-trends の `docs/style.css` をコピーし、次を変える。

- 消す: `--hot*`、`--new*`、`--gh`、`--zn` と、それを使う `.card.hot`・`.new`・`.hot-mark`・`.count`・`.delta`・`.src.gh`・`.src.zn`・`.card .sub`
- 足す変数（ライト / ダーク）: `--official: #d9480f / #ff8c4b`、`--news: #0969da / #58a6ff`、`--video: #57606a / #8b949e`
- 足すクラス: `.src.official` / `.src.news` / `.src.video`（`color: var(--…)`）、`.section`（`margin: 14px 0 6px; font-size: 14px; display: flex; align-items: baseline; gap: 8px;`）、`.section .n`（`color: var(--muted); font-weight: 400; font-size: 12px;`）、`.time`（`margin-left: auto; font-variant-numeric: tabular-nums;`）
- `.card` の `min-height` を `76px`、`gap` を `6px`。`.empty` は `padding: 0; margin: 0 0 4px; font-size: 12px;`

見本 `.superpowers/brainstorm/*/content/layout.html` の B 案と見比べて同じ見た目になっていること。

- [ ] **Step 3: `docs/app.js` に DOM 部分を足す**

tech-trends の `main()` / `render()` / `setNotices()` / `showError()` / `fetchJson()` / `el()` と同じ流れにする。違い:

- `index.json` の `checkedAt` から `#updated` に `最終更新 {formatDateTime} JST`、`isStale` なら警告に `staleMessage`
- `index.json` の `failures` を `failureMessage` で注意に出す
- 日ファイルの `articles` から `#count` に `{n} 件`、`#sections` に `renderSections`
- `dates` が空なら `#date` に `データがありません`、注意に `まだ収集結果がありません。`
- 警告（停止・読み込み失敗）を先、注意（日付なし・失敗）を後に並べる

- [ ] **Step 4: favicon を作る**

`favicon.svg` を tech-trends と同じ作り（32×32 の角丸の正方形＋白い線の絵柄）で書く。色はニュース色 `#0969da`、絵柄は新聞（横線 3 本の紙）など、tech-trends（オレンジに右肩上がりの矢印）とタブで見分けがつくもの。PNG と ICO は、同じ絵柄を PHP の GD で描く使い捨てのスクリプト（scratch に置き、コミットしない）で `16×16`・`32×32`・`180×180` の PNG を作り、16 と 32 を PNG 埋め込みの ICO（`ICONDIR` + `ICONDIRENTRY` 2 つ）にまとめる。`apple-touch-icon.png` は 180×180。

Run: `file docs/favicon.ico docs/apple-touch-icon.png`
Expected: `MS Windows icon resource - 2 icons, 16x16 ... 32x32 ...`、`PNG image data, 180 x 180`

- [ ] **Step 5: 実データで画面を確かめる**

```bash
php bin/collect
python3 -m http.server 8765 -d docs
```

ブラウザで `http://localhost:8765/` を開いて確かめる:
- 最新の日が出て、3 段が「公式のお知らせ → 新聞記事 → 動画」の順、各段に件数、空の段にメッセージ
- `‹ 前日` で記事のある前の日に移り、端でボタンが無効になる。`#2026-01-01` で注意が出て最新が表示される
- YouTube のタイトルに末尾のハッシュタグが無い。カードのリンクが新しいタブで開く
- ダーク表示（OS の設定）と、幅 640px 以下で 1 列になる
- ブラウザのコンソールにエラーが無い

確認後、サーバーを止めて `rm -rf docs/data`（初回のデータは Task 9 の Actions で作る）。`git status` に `docs/data` が出ないこと。

- [ ] **Step 6: テストを通してコミット**

Run: `node --test "tests/js/*.test.mjs" && composer test`
Expected: PASS

```bash
git add docs/index.html docs/style.css docs/app.js docs/.nojekyll docs/favicon.svg docs/favicon.ico docs/apple-touch-icon.png
git commit -m "feat: static page with daily sections"
```

---

### Task 8: GitHub Actions と README

**Files:**
- Create: `.github/workflows/collect.yml`、`.github/workflows/ci.yml`、`README.md`

**Interfaces:**
- Consumes: `bin/collect`（Task 5）、`composer` scripts（Task 1）、`tests/js/`（Task 6）
- Produces: なし

- [ ] **Step 1: `.github/workflows/collect.yml` を書く**

```yaml
name: collect

on:
  schedule:
    # 毎時 17 分（0 分は Actions が混むので避ける）
    - cron: "17 * * * *"
  workflow_dispatch:

permissions:
  contents: write

concurrency:
  group: collect
  cancel-in-progress: false

jobs:
  collect:
    runs-on: ubuntu-latest
    timeout-minutes: 15
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          tools: composer

      - uses: actions/cache@v4
        with:
          path: vendor
          key: vendor-nodev-${{ hashFiles('composer.lock') }}

      - run: composer install --no-dev --prefer-dist --no-progress

      - name: Collect articles
        id: collect
        continue-on-error: true
        run: php bin/collect

      - name: Commit data
        if: always()
        run: |
          git config user.name "github-actions[bot]"
          git config user.email "41898282+github-actions[bot]@users.noreply.github.com"
          git add docs/data
          if git diff --cached --quiet; then
            echo "no data changes"
          else
            git commit -m "data: $(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M')"
            git pull --rebase origin "${GITHUB_REF_NAME}"
            git push
          fi

      - name: Fail if collection failed
        if: steps.collect.outcome == 'failure'
        run: exit 1
```

- [ ] **Step 2: `.github/workflows/ci.yml` を書く**

tech-trends の `ci.yml` と同じトリガー（`push` は `main` で `docs/data/**` を除く、`pull_request`）。ジョブ `check` の手順: checkout → `shivammathur/setup-php@v2`（`php-version: "8.4"`）→ `actions/setup-node@v4`（`node-version: 22`）→ `composer install --prefer-dist --no-progress` → `composer lint` → `composer analyse` → `composer test` → `node --test "tests/js/*.test.mjs"`。

- [ ] **Step 3: `README.md` を書く**

tech-trends の README と同じ並びで日本語。
- 1 行目の説明、ページ URL `https://shimomo.github.io/autorace-news/`
- 何が見えるか: 1 日 1 ページ、3 段、カードの中身、前日・翌日と `#YYYY-MM-DD`、失敗と停止の表示、見出しとリンクだけを載せていること（AUTORACE.JP のサイトポリシーに触れる）
- 仕組み: 設計書 4 章の図
- セットアップ: 設計書 10 章の 3 手順
- 初回に一部の配信元が失敗したとき: `docs/data/index.json` を消して `gh workflow run collect.yml`（重複はしない）
- ローカルで動かす: `composer install`、`php bin/collect`、`python3 -m http.server 8765 -d docs`、`composer test`、`node --test "tests/js/*.test.mjs"`
- 構成: 設計書 8 章

- [ ] **Step 4: CI と同じコマンドを手元で通す**

Run: `composer lint && composer analyse && composer test && node --test "tests/js/*.test.mjs"`
Expected: すべて成功

- [ ] **Step 5: コミット**

```bash
git add .github/workflows/collect.yml .github/workflows/ci.yml README.md
git commit -m "ci: add hourly collect and ci workflows with readme"
```

---

### Task 9: 公開と初回の収集

**この Task は外に出る操作（public リポジトリの作成、push、Pages の有効化）なので、始める前に作者の了承を取る。**

**Files:** なし（GitHub 上の操作）

- [ ] **Step 1: 作者に了承を取る**

「`shimomo/autorace-news` を public で作って push し、Pages を有効にして初回の収集を実行してよいか」を確認する。了承が無ければここで止める。

- [ ] **Step 2: リポジトリを作って push する**

```bash
gh repo create shimomo/autorace-news --public --source . --push --description "オートレースの公式発表・新聞記事・動画の見出しを1日ごとにまとめた一覧" --homepage "https://shimomo.github.io/autorace-news/"
```

Expected: `main` が push され、`ci` ワークフローが緑になる（`gh run list --workflow ci.yml` で確かめる）

- [ ] **Step 3: Pages を有効にする**

```bash
gh api -X POST repos/shimomo/autorace-news/pages -f build_type=legacy -f 'source[branch]=main' -f 'source[path]=/docs'
```

Expected: 201。`gh api repos/shimomo/autorace-news/pages --jq .html_url` が `https://shimomo.github.io/autorace-news/`

- [ ] **Step 4: 初回の収集を手動で実行する**

```bash
gh workflow run collect.yml
gh run watch
```

Expected: 成功し、`data: YYYY-MM-DD HH:MM` のコミットが `main` に入る。`git pull` 後の `docs/data/index.json` の `failures` が `[]`。失敗している配信元があれば、それが **Actions のサーバーからつながらない**のか一時的なものかを、もう一度実行して見分け、結果を作者に伝える（つながらない場合の対処はこの計画の範囲外）

- [ ] **Step 5: 公開ページを確かめる**

`https://shimomo.github.io/autorace-news/` を開き、Task 7 Step 5 と同じ点を確かめる。翌日以降、毎時 17 分ごろに `data:` コミットが入り続けていることを `gh run list --workflow collect.yml` で確かめる。
