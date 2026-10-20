# autorace-news

オートレースの公式発表・新聞記事・動画の見出しを 1 時間ごとに集めて、1 週間ごとに 1 ページで眺めるための仕組み。
収集には [autorace-news-collector](https://github.com/shimomo/autorace-news-collector) を使う。
GitHub Actions が収集してリポジトリにコミットし、GitHub Pages がそれを配信する。サーバーもデータベースも使わない。

ページ: https://shimomo.github.io/autorace-news/

## 何が見えるか

- 1 週間 1 ページ（記事のある最新の日から 7 日間）。1 日の記事が数件しかないため。記事は「公式のお知らせ」（AUTORACE.JP と川口・伊勢崎・浜松・山陽）→「新聞記事」（日刊スポーツ）→「動画」（公式 YouTube）の 3 段に分かれる
- カードには見出し（記事へのリンク）・配信元・分類・時刻（JST）だけを出す。集めた記事は除外せずすべて出す
- 各カードには日付・曜日と時刻（例: 10/19 (月) 18:32）を出す
- 「‹ 前の週」「次の週 ›」で 7 日ずつ移る。URL の `#2026-10-19` で「その日で終わる 7 日間」を指定できる
- 取得に失敗した配信元があると、ページ上部に注意が出る（次の収集で成功すれば消える）。最後の収集から 24 時間を超えると警告が出る（GitHub Actions の定期実行は 1 日に数回しか動かないことがあるため）

AUTORACE.JP のサイトポリシーは無断の転載・公開を禁じているため、公開するのは見出しとリンクだけにしている。
本文・要約・画像はページにもデータファイルにも書き出さない。

## 仕組み

```
GitHub Actions（毎時 17 分ごろ）
  php bin/collect
    ├─ CollectorFactory::create()->collect(since)   ← autorace-news-collector（7 配信元。川口だけ RSS に差し替え）
    ├─ 既存の docs/data/*.json と (source, id) で突き合わせて足す・上書きする
    └─ docs/data/YYYY-MM-DD.json と docs/data/index.json を書き出す
  → main ブランチにコミット & push
GitHub Pages（main ブランチの /docs）
  index.html + app.js が JSON を読んで 1 日分を 3 段で描く
```

- 初回（`docs/data/index.json` が無い、または記事のある日が無い）は、各配信元が 1 回で返せる分をすべて取り込む
- 川口だけはライブラリの WordPress REST API ではなく RSS（お知らせ・選手インタビュー、各最新 10 件）で読む。川口のサーバー（Xserver）が国外 IP からの REST API を 403 で拒み、GitHub Actions からは取れないため。RSS には分類が無いので、川口のお知らせにはタグが付かない
- 2 回目以降は直近 48 時間分を毎回取り直す。タイトルが後から変わった記事は新しいタイトルで上書きする
- 古い日のデータは消さない
- 一部の配信元の失敗では Actions の実行を失敗にしない（一時的な失敗のたびに通知が来ないように）。失敗は job summary とページの注意に出る。すべての配信元が失敗したときと、スクリプト自体が落ちたときだけ赤くなる

## セットアップ

1. リポジトリを作成して push する（public。Pages を無料で使うため）
2. Pages を有効化する（`main` ブランチの `/docs` を配信）

   ```bash
   gh api -X POST repos/shimomo/autorace-news/pages \
     -f build_type=legacy -f 'source[branch]=main' -f 'source[path]=/docs'
   ```

3. 最初の収集を手動で実行する

   ```bash
   gh workflow run collect.yml
   gh run watch
   ```

   数分後に https://shimomo.github.io/autorace-news/ に記事が出る。

以降は毎時 17 分ごろに自動で動く。GitHub の cron は数十分遅れることがある。

### 初回に一部の配信元が失敗したとき

その配信元の古い記事は取り込まれないまま、2 回目以降（直近 48 時間分）に進む。
取り直したいときは、Actions の収集が動いていない時間に `docs/data/index.json` を消してコミットし、もう一度手動で実行する。
`(source, id)` で突き合わせるので、記事が重複することはない。
手元のクローンは毎時のデータコミットで遅れているので、先に `git pull` する。

```bash
git pull --rebase
git rm docs/data/index.json
git commit -m "data: refetch everything"
git push
gh workflow run collect.yml
```

## ローカルで動かす

`docs/data/` は Actions がコミットする場所なので、手元で集めて画面を見るときは、
`docs/` を一時ディレクトリに写してそこへ書く（`docs/data/` に書くと作業ツリーが汚れ、次の `git pull` が止まる）。

```bash
composer install
tmp=$(mktemp -d) && cp -r docs/. "$tmp"
php bin/collect "$tmp/data"             # 引数なしなら docs/data/ に書く
python3 -m http.server 8765 -d "$tmp"   # http://localhost:8765/
composer test                           # PHPUnit
composer lint && composer analyse       # Pint / Psalm
node --test "tests/js/*.test.mjs"       # app.js のテスト（Node.js 22 以上）
```

テストはネットワークに出ない。

## 構成

- `bin/collect` 収集の入口
- `src/` 収集側のコード（PHP 8.4）
- `tests/` PHPUnit のテスト、`tests/js/` app.js のテスト
- `docs/` Pages の配信ルート。`index.html` `app.js` `style.css` と `data/`
- `docs/superpowers/` 設計書と実装計画
