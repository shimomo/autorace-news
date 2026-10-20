# autorace-news 設計書

作成日: 2026-10-20

## 1. 目的と背景

オートレース界の動きを 1 日 1 回確認するための一覧ページ。
[autorace-news-collector](https://github.com/shimomo/autorace-news-collector)（以下「ライブラリ」）で集めた記事の**タイトル・リンク・配信元・日時だけ**を、GitHub Pages で公開する。

利用者は作者本人。ページは公開されるが、ほかの人に見せることは目的ではない。

### なぜ作るか

- 今は中継の実況やコメント欄から動きを知っている。中継を見られなかった日の分や、記事として出たものを拾い損ねる
- 特に、ルール変更のような公式の発表に遅れて気づくことが多い
- 1 日 1 回開けば、公式の発表をその日のうちか翌日には目にできている状態にしたい

## 2. ゴール / 非ゴール / アンチゴール

### ゴール

- GitHub Actions が 1 時間ごとに自動で収集し、手作業なしで更新され続ける
- 1 日分の記事が「公式のお知らせ」「新聞記事」「動画」の 3 段に分かれて見える。公式のお知らせが先頭
- 日付単位で過去に戻れる
- 収集が止まっている、または一部の配信元で失敗していることに、ページ上で気づける

### 非ゴール（今回は作らない）

- 記事の除外・絞り込み・検索。集めた記事はすべて出す（山陽の毎日の「応援メッセージ」や YouTube のショート動画も含む）
- 本文・要約・サムネイル画像の表示
- 日刊スポーツと AUTORACE.JP「【日刊提供】」の重複の束ね
- 通知（メール、Slack、RSS 配信など）
- 既読管理、「前回見たあとに増えた分」の目印
- ライブラリにない情報源の追加
- 認証、複数ユーザー

### アンチゴール（これになったら失敗）

- 見出し・リンク・配信元・日時以外（本文、要約、画像）を公開してしまう
- 公式の発表が取れているのに、ページで目に入らない
- 収集が止まっているのに、ページ上で気づけない（古いデータが最新のように見える）
- ページを見るのにビルド工程が必要になる

## 3. 制約

- GitHub Actions と GitHub Pages だけで動かす。サーバー、DB、外部サービス、追加の Secrets は使わない
- 収集は PHP。ライブラリ `shimomo/autorace-news-collector`（Packagist、`^0.2`、PHP 8.4 以上）を Composer で入れて使う
- 表示ページは素の HTML・CSS・JavaScript。フレームワーク、バンドラー、ビルド工程を持たない
- デザインは [tech-trends](https://github.com/shimomo/tech-trends)（`docs/index.html`、`docs/style.css`、`docs/app.js`）に寄せる
- リポジトリは public の `shimomo/autorace-news`（Pages を無料で使うため）。ローカルの作業フォルダは `~/github/shimomo/autorace-news`
- AUTORACE.JP のサイトポリシーは無断の転載・公開を禁じている。公開するのは見出しとリンクだけにとどめ、本文・要約・画像はデータファイルにも書き出さない

## 4. 全体像

```
[GitHub Actions: 1 時間ごと（毎時 17 分）]
   php bin/collect
     ├─ Collector::default()->collect(since)   ← ライブラリ（7 配信元）
     ├─ 既存の docs/data/*.json と (source, id) で突き合わせて足す・上書きする
     └─ docs/data/YYYY-MM-DD.json と docs/data/index.json を書き出す
   → main ブランチにコミット & push
[GitHub Pages: main ブランチの /docs]
   index.html + app.js が JSON を読んで 1 日分を 3 段で描く
```

tech-trends と同じ構成（Actions がデータをコミット、Pages は `/docs` を配信）。

## 5. 収集

### 5.1 実行

- `collect.yml` を cron `17 * * * *`（毎時 17 分 UTC = JST でも毎時 17 分）と `workflow_dispatch` で動かす。毎時 0 分は Actions が混むので避ける
- ローカルでも `php bin/collect` で同じことができる（`docs/data/` に書く）

### 5.2 取得範囲

- `docs/data/index.json` が無い、または `dates` が空（初回）: `collect()` を `since` なしで呼ぶ。各配信元が 1 回で返せる分をすべて取り込む。川口・伊勢崎などは数か月前の日のページもできる
- 2 回目以降: `since` = 現在時刻 − 48 時間。直近 2 日分を毎回取り直す
- 初回に一部の配信元だけ失敗すると、その配信元の古い分は取り込まれないまま 2 回目以降に進む。取り直したいときは `docs/data/index.json` を消して `collect.yml` を手動で実行する（`(source, id)` で突き合わせるので重複しない）。この手順を README に書く

ライブラリの既定（`Collector::default()`、`maxPages` 3、リクエスト間隔 1 秒、User-Agent 既定値）をそのまま使う。

**2026-10-20 追記:** 川口だけは RSS（`/news/feed/`・`/interview/feed/`、各最新 10 件）で読む（`KawaguchiFeedSource`、組み立ては `CollectorFactory`）。公開直後の確認で、川口のサーバー（Xserver）が GitHub Actions の IP からの REST API を 403 で拒むと分かったため（トップページと RSS は通る）。ID は RSS の guid の `p=`（REST と同じ投稿 ID）。RSS に分類は無いので、お知らせはタグなし、選手インタビューは固定ラベル「選手インタビュー」。

### 5.3 突き合わせ（足し方）

- 記事の同一性は `(source, id)` で判定する。川口は news と interview の 2 系統があるがソースキーはどちらも `kawaguchi`。ID は WordPress の投稿 ID で、サイト内で一意なので衝突しない
- 新しい記事は、`publishedAt`（Asia/Tokyo）の日付の日ファイルに足す
- 既にある記事は、取れた値（title、url、categories、publishedAt、sourceName）で上書きする。AUTORACE.JP は「【…を追記！】」のようにタイトルを後から変えることがあるため
- 上書きで `publishedAt` の日付が変わった場合は、元の日ファイルから消して新しい日ファイルに移す。1 つの記事は常にちょうど 1 つの日ファイルにだけ載る
- 配信元から消えた記事も、こちらでは消さない。古い日のデータは削除しない
- 日刊スポーツと AUTORACE.JP「【日刊提供】」は URL も ID も違うので、両方そのまま載せる（「全部出す」方針から）

### 5.4 失敗の扱い

ライブラリの `collect()` は例外を投げず、失敗は `failures`（`SourceFailure` の配列）に入る。

- 日刊スポーツの「記事ページの取得失敗」は、タイトルとリンクは取れているので**失敗として扱わない**。見分け方: その `SourceFailure` の `url` が、同じ `source` で返ってきた記事の `url` と一致するもの
- それ以外の失敗は、配信元ごとにまとめて `index.json` の `failures` に書く（1 配信元につき 1 件。メッセージは最初の 1 件）
- 一部の配信元が失敗しても、取れた分は通常どおり足して書き出す
- 失敗した配信元の記事は、前回までに取れた分がそのまま残る。1 時間後の収集で取り直される
- Actions の実行を失敗（赤）にするのは、収集スクリプト自体が落ちたとき（例外、書き込み失敗）と、7 配信元すべてが失敗したときだけ。一部の配信元の一時的な失敗で毎時メール通知が飛ぶのを避けるため。一部の失敗はページの注意と、Actions の job summary に出す

### 5.5 書き出すファイル

`docs/data/index.json`（毎回書き直す）:

```json
{
  "dates": ["2026-10-20", "2026-10-19", "2026-10-17"],
  "checkedAt": "2026-10-20T12:17:05+09:00",
  "failures": [
    { "source": "autorace.jp", "message": "GET https://autorace.jp/rss.xml returned HTTP 503." }
  ]
}
```

- `dates`: 記事が 1 件以上ある日。新しい順
- `checkedAt`: 最後に収集を走らせた時刻（失敗した回も含む）
- `failures`: 直近の収集で失敗した配信元。無ければ `[]`

`docs/data/YYYY-MM-DD.json`（その日に変化があったときだけ書き直す）:

```json
{
  "date": "2026-10-19",
  "articles": [
    {
      "source": "autorace.jp",
      "sourceName": "AUTORACE.JP",
      "id": "038825",
      "title": "【第8戦(山陽ステージ)のポイントを追記！】2026スーパースターガールズ王座決定戦への道　更新しました！",
      "url": "https://autorace.jp/news/2026/10/19/038825/",
      "categories": ["ニュース", "レース情報"],
      "publishedAt": "2026-10-19T18:32:00+09:00"
    }
  ]
}
```

- 載せる項目はこの 7 つだけ。`summary`・`bodyHtml`・`bodyText`・`thumbnailUrl` は書き出さない
- タイトルや分類はライブラリが返したまま保存する（表示時の加工は 6.4 でページ側がやる）
- `articles` は `publishedAt` の新しい順。同時刻は `source`、`id` の順にして、同じ入力なら同じ出力になるようにする
- JSON は整形して書く（差分が読めるように）。日本語と `/` はエスケープしない

`checkedAt` が毎回変わるので、データのコミットは 1 時間に 1 回入る。

## 6. ページ

画面見本（ブレインストーミング中に作成、B 案を採用）: `.superpowers/brainstorm/*/content/layout.html`（作業フォルダにだけある。git 管理外）。見た目は tech-trends の `style.css` をほぼそのまま使う。

### 6.1 構成

上から順に:

1. ヘッダー
   - 左: `Autorace News`
   - 右: `‹ 前日` / `2026-10-19 (月)` / `翌日 ›`
   - 2 行目: 左に `8 件`（その日の合計）、右に `最終更新 10/20 12:17 JST`（`checkedAt`）
2. 注意・警告（6.6）。無いときは出さない
3. 3 つの段（6.2）。各段は見出し（`公式のお知らせ 4 件`）＋カードのグリッド
4. フッター
   - 「見出しとリンクのみを掲載しています。記事は各配信元でご覧ください。」
   - 情報源: AUTORACE.JP ・ 日刊スポーツ ・ 川口 ・ 伊勢崎 ・ 浜松 ・ 山陽 ・ YouTube（各サイトのトップへのリンク）

### 6.2 段の割り当て

| 段 | 配信元（source） |
|---|---|
| 公式のお知らせ | `autorace.jp`、`kawaguchi`、`isesaki`、`hamamatsu`、`sanyo` |
| 新聞記事 | `nikkansports` |
| 動画 | `youtube` |

- 段の順は上の表のとおりで固定
- 段の中は `publishedAt` の新しい順
- 記事が 0 件の段も見出しは残し、「この日の公式のお知らせはありません。」「この日の日刊スポーツの記事はありません。」「この日の動画はありません。」と出す
- 表にない source が来たら「公式のお知らせ」に入れる

### 6.3 カード

- 1 行目: タイトル（2 行で切る）。記事 URL へのリンクで、新しいタブで開く（`target="_blank" rel="noopener noreferrer"`）
- 2 行目（meta）: 配信元のバッジ、分類のタグ（最大 2 つ）、右端に時刻 `18:32`（JST）
- 配信元のバッジの文字と色:

| source | バッジの文字 | 色 |
|---|---|---|
| `autorace.jp` | AUTORACE.JP | 公式色（ライト `#d9480f` / ダーク `#ff8c4b`） |
| `kawaguchi` | 川口オート | 公式色 |
| `isesaki` | 伊勢崎オート | 公式色 |
| `hamamatsu` | 浜松オート | 公式色 |
| `sanyo` | 山陽オート | 公式色 |
| `nikkansports` | 日刊スポーツ | 新聞色（`#0969da` / `#58a6ff`） |
| `youtube` | YouTube | 動画色（`#57606a` / `#8b949e`） |

  表にない source は `sourceName` をそのまま出し、公式色にする
- 文字列はすべて HTML エスケープして差し込む（tech-trends の `escapeHtml` と同じ）

### 6.4 表示時の加工

- **YouTube のタイトル:** 末尾に続くハッシュタグ（空白区切りで `#` または `＃` から始まる語の並び）を取り除く。途中のハッシュタグは残す。取り除くと空になる場合は元のまま出す
  - `抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン#オートレース #autorace #飯塚オート #motorsport #モータースポーツ #shorts` → `抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン`
  - `【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦 #オートレース #autorace` → `【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦`
- **分類のタグ:** `categories` から `ニュース` と `pickup` を除き、残りの先頭 2 つを出す（例: AUTORACE.JP `["ニュース","レース情報","伊勢崎"]` → `レース情報` `伊勢崎`、山陽 `["応援メッセージ"]` → `応援メッセージ`）。日刊スポーツと YouTube は分類を持たないのでタグなし

### 6.5 日付の移動と初期表示

tech-trends と同じ動き。

- 開くと `dates[0]`（記事がある最新の日）を出す
- `‹ 前日` / `翌日 ›` は `dates` の隣の日へ移る（記事が無い日は飛ばす）。端ではボタンを無効にする
- URL の `#2026-10-19` で日付を指定できる。`dates` に無い日付なら最新の日を出し、「指定の日付が無いため最新を表示しています。」と注意を出す

### 6.6 注意・警告

- **警告:** `checkedAt` が今から 3 時間より前なら、「収集が止まっている可能性があります（最終収集: 10/20 09:17）」
- **注意:** `index.json` の `failures` 1 件ごとに、「AUTORACE.JP の取得に失敗しました（{message}）。次の収集で取り直します。」。配信元名は 6.3 のバッジの文字を使う
- **読み込み失敗:** JSON が読めないときは警告として「読み込みに失敗しました: …」
- **データ無し:** `dates` が空なら「まだ収集結果がありません。」

### 6.7 見た目

- tech-trends の `style.css` に寄せる。色の変数、ヘッダー、ボタン、カード、バッジ、タグ、注意・警告の見た目、ライト・ダーク（`prefers-color-scheme`）、640px 以下で 1 列
- tech-trends との違い: 🔥・NEW・カウンタ・説明文の行は無い。段の見出しを足す。カードの最小の高さは説明文の行が無いぶん低くする（見本では 76px）
- `<title>` は `Autorace News`。`meta description` は「オートレースの公式発表・新聞記事・動画の見出しを1日ごとにまとめた一覧」
- favicon は tech-trends と同じ作り（`favicon.svg`、`favicon.ico`、`apple-touch-icon.png`）で、ブラウザのタブで tech-trends と見分けがつく色と絵柄にする

## 7. GitHub Actions

### 7.1 collect.yml

tech-trends の `collect.yml` に寄せる。

- `permissions: contents: write`、`concurrency: { group: collect, cancel-in-progress: false }`、`timeout-minutes: 15`
- 手順: checkout → `shivammathur/setup-php`（PHP 8.4）→ `composer install --no-dev`（キャッシュあり）→ `php bin/collect` → `docs/data` をコミット
- コミットは `github-actions[bot]` 名義、メッセージ `data: 2026-10-20 12:17`（JST）。`git pull --rebase` してから push
- 収集が失敗扱い（5.4）でも、書き出せたデータはコミットしてから最後にジョブを失敗させる（tech-trends と同じ順序）

### 7.2 ci.yml

- `push`（main、`docs/data/**` は除く）と `pull_request` で動く
- PHP: `composer install` → lint（Pint）→ 静的解析（Psalm）→ テスト（PHPUnit）。ライブラリと同じ道具立て
- JavaScript: `node --test`（Node.js 22 以上、npm 依存なし）で `docs/app.js` の純粋関数をテストする

## 8. リポジトリ構成

```
.github/workflows/collect.yml, ci.yml
bin/collect                 収集の入口（PHP スクリプト）
src/                        収集側のコード（名前空間 Shimomo\AutoraceNews）
tests/                      PHPUnit のテスト
tests/js/                   app.js のテスト（node --test）
docs/                       Pages の配信ルート
  .nojekyll
  index.html, app.js, style.css, favicon.svg, favicon.ico, apple-touch-icon.png
  data/                     index.json, YYYY-MM-DD.json
  superpowers/specs/        この設計書
composer.json, composer.lock
README.md, LICENSE (MIT), .gitignore (vendor/, .superpowers/)
```

`docs/` を Pages で配信するので、この設計書も公開される（tech-trends と同じ）。

## 9. テスト

ネットワークには出ない。

- **収集側（PHPUnit）:** ライブラリの `Sources\ArraySource` を `Collector` に差し込んで記事を与える
  - 新規追加、`(source, id)` での上書き、`publishedAt` の日付が変わったときの移動、消えた記事を残すこと
  - 日ファイル内の並び順、同じ入力なら同じ出力
  - 初回（`index.json` なし）は `since` なし、2 回目以降は 48 時間前になること
  - 日刊の記事ページ失敗を失敗に数えないこと、それ以外の失敗が配信元ごとに 1 件になること、全配信元失敗で終了コードが 0 以外になること
  - 書き出す JSON に本文・要約・画像が含まれないこと
- **ページ側（node --test）:** 末尾ハッシュタグの除去（6.4 の例を含む）、分類タグの選び方、段への振り分け、時刻と日付の表示、`isStale`（3 時間）、`resolveDate`、`escapeHtml`

## 10. セットアップ（README に書く）

1. GitHub に public の `shimomo/autorace-news` を作って push する
2. Pages を有効化する（`main` ブランチの `/docs`）

   ```bash
   gh api -X POST repos/shimomo/autorace-news/pages \
     -f build_type=legacy -f 'source[branch]=main' -f 'source[path]=/docs'
   ```

3. 最初の収集を手動で実行し、ページを確かめる

   ```bash
   gh workflow run collect.yml
   gh run watch
   ```

README は tech-trends と同じ並び（何が見えるか、仕組み、セットアップ、ローカルで動かす、構成）で日本語で書く。

## 11. 決めたこと

### 作者の決定

- タイトル・リンク・配信元・日時だけを GitHub Pages で公開する
- デザインは tech-trends に寄せる
- 1 日分を種類ごとの段に分け、公式のお知らせを先頭に置く（画面見本の B 案）
- 集めた記事は除外せず、すべて出す
- 画面のまとめ（6 章の内容）と、収集・ため方のまとめ（5 章の内容）を確認済み

### 設計側の判断（作者が説明を聞いて異論なし）

- PHP で収集し、1 時間ごとに Actions で動かす。Pages は main の `/docs`
- 日の区切りは JST の 0:00〜24:00、`publishedAt` で振り分ける
- 直近 48 時間を毎回取り直し、`(source, id)` で上書き。古いデータは消さない。初回は取れる分をすべて取り込む
- 日刊と【日刊提供】は両方出す
- YouTube の末尾ハッシュタグを表示時に省く、配信元の色は 3 色、AUTORACE.JP の「ニュース」は分類から省く
- 失敗は直近の収集分をページに出し、収集が 3 時間止まったら警告。`checkedAt` を毎回書くので、データのコミットが 1 時間に 1 回入る

### この設計書を書く段階での判断（未確認）

- cron は毎時 17 分
- 一部の配信元の失敗では Actions を赤にしない（5.4）
- 道具立ては PHPUnit・Pint・Psalm、JS は `node --test`
- 表にない source の扱い（公式の段、`sourceName` を表示）
- User-Agent はライブラリの既定値のまま
- 初回に一部の配信元が失敗したときは、自動では取り直さず、`index.json` を消して再実行する手順を README に書くだけにする（5.2）

## 12. 作る人に任せること

- `src/` のクラス分け、`bin/collect` の作り
- `composer.json` の細部（`type: project`、scripts の名前など）
- favicon の色と絵柄（tech-trends と見分けがつくこと）
- job summary の書式
- `style.css` の細かい数値

## 13. 未確認・リスク

- **GitHub Actions のサーバーから各サイトにつながるか**は未確認（作者の PC からは 7 配信元すべて取れた。2026-10-20）。最初の手動実行で確かめる。つながらない配信元があれば、ページの注意に出続けるので気づける。その場合の対処は別途
  - 2026-10-20 確認: 川口の REST API だけが Actions から 403（Xserver の国外 IP 制限）。5.2 の追記のとおり RSS に差し替えた。YouTube のフィードは作者の PC からも 404/500 を繰り返しており、YouTube 側の問題
- 2026-10-20 に手元で 4 回収集したうち 1 回、AUTORACE.JP が一時的に失敗した（取り直したら成功）。一時的な失敗はあり得る前提で 5.4 を決めている
- GitHub の cron は数十分遅れることがある。警告のしきい値を 3 時間にしているのはそのため
- 見出しとリンクだけでも、公開にはリスクがゼロではない（AUTORACE.JP のサイトポリシー、見出しの機械的な収集を不法行為とした判例）。作者が承知のうえで公開を決めた
