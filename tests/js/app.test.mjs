import assert from "node:assert/strict";
import { test } from "node:test";
import {
  dateLabel,
  displayTitle,
  failureMessage,
  formatDateTime,
  formatTime,
  groupBySection,
  isStale,
  renderCard,
  renderSections,
  resolveDate,
  sectionOf,
  sourceLabel,
  staleMessage,
  stripTrailingHashtags,
  visibleTags,
} from "../../docs/app.js";

const article = (over = {}) => ({
  source: "autorace.jp",
  sourceName: "AUTORACE.JP",
  id: "038825",
  title: "【第8戦(山陽ステージ)のポイントを追記！】",
  url: "https://autorace.jp/news/2026/10/19/038825/",
  categories: ["ニュース", "レース情報"],
  publishedAt: "2026-10-19T18:32:00+09:00",
  ...over,
});

test("stripTrailingHashtags removes only the trailing run", () => {
  assert.equal(
    stripTrailingHashtags(
      "抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン#オートレース #autorace #飯塚オート #motorsport #モータースポーツ #shorts",
    ),
    "抜群のスタートで石本圭耶が優勝！地元で初のウイニングラン",
  );
  assert.equal(
    stripTrailingHashtags("【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦 #オートレース #autorace"),
    "【第8戦/山陽ステージ】#スーパースターガールズ王座決定戦 トライアル戦",
  );
  assert.equal(
    stripTrailingHashtags("日本一を懸けた究極のオープン戦 #バイク  #motorsport ＃shorts"),
    "日本一を懸けた究極のオープン戦",
  );
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
  assert.match(
    renderCard(article({ source: "funabashi", sourceName: "船橋オート" })),
    /<span class="src official">船橋オート<\/span>/,
  );
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
  assert.deepEqual(resolveDate("#2026-10-19", ["2026-10-20", "2026-10-19"]), {
    date: "2026-10-19",
    fallback: false,
  });
  assert.deepEqual(resolveDate("#2026-01-01", ["2026-10-20"]), { date: "2026-10-20", fallback: true });
  assert.deepEqual(resolveDate("", []), { date: null, fallback: false });
});

test("renderCard shows link, badge, tags and time", () => {
  const html = renderCard(article({ categories: ["ニュース", "レース情報", "伊勢崎"] }));
  assert.match(
    html,
    /href="https:\/\/autorace\.jp\/news\/2026\/10\/19\/038825\/" target="_blank" rel="noopener noreferrer"/,
  );
  assert.match(
    html,
    /<span class="src official">AUTORACE\.JP<\/span><span class="tag">レース情報<\/span><span class="tag">伊勢崎<\/span>/,
  );
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
  const video = groupBySection([
    article({ source: "youtube", id: "a", publishedAt: "2026-10-19T07:00:00+09:00" }),
    article({ source: "youtube", id: "b", publishedAt: "2026-10-19T22:15:00+09:00" }),
  ]).video;
  assert.deepEqual(
    video.map((a) => a.id),
    ["b", "a"],
  );
});

test("messages for failures and stale collection", () => {
  assert.equal(
    failureMessage({ source: "autorace.jp", message: "HTTP 503" }),
    "AUTORACE.JP の取得に失敗しました（HTTP 503）。次の収集で取り直します。",
  );
  assert.equal(
    failureMessage({ source: "funabashi", message: "x" }),
    "funabashi の取得に失敗しました（x）。次の収集で取り直します。",
  );
  assert.equal(staleMessage("2026-10-20T09:17:00+09:00"), "収集が止まっている可能性があります（最終収集: 10/20 09:17）");
});
