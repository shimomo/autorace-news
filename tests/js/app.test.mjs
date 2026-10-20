import assert from "node:assert/strict";
import { test } from "node:test";
import {
  daysInWeek,
  displayTitle,
  failureMessage,
  formatCardTime,
  formatDateTime,
  groupBySection,
  isStale,
  nextWeekEnd,
  prevWeekEnd,
  renderCard,
  renderSections,
  resolveWeek,
  sectionOf,
  sourceLabel,
  staleMessage,
  stripTrailingHashtags,
  visibleTags,
  weekLabel,
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
  assert.equal(formatCardTime("2026-10-19T18:32:00+09:00"), "10/19 (月) 18:32");
  assert.equal(formatCardTime("2026-10-20T00:10:00+09:00"), "10/20 (火) 00:10");
  assert.equal(formatCardTime("2026-10-19T15:10:00Z"), "10/20 (火) 00:10");
  assert.equal(formatDateTime("2026-10-20T09:17:00+09:00"), "10/20 09:17");
});

test("isStale after more than twenty-four hours", () => {
  const checkedAt = "2026-10-20T09:17:00+09:00";
  assert.equal(isStale(checkedAt, new Date("2026-10-21T09:17:00+09:00")), false);
  assert.equal(isStale(checkedAt, new Date("2026-10-21T09:17:01+09:00")), true);
});

const DATES = ["2026-10-20", "2026-10-19", "2026-10-16", "2026-10-12", "2026-10-04", "2026-09-30"];

test("resolveWeek ends on the latest day or the requested day", () => {
  assert.deepEqual(resolveWeek("", DATES), { end: "2026-10-20", fallback: false });
  assert.deepEqual(resolveWeek("#2026-10-13", DATES), { end: "2026-10-13", fallback: false });
  assert.deepEqual(resolveWeek("#2026-09-30", DATES), { end: "2026-09-30", fallback: false });
  assert.deepEqual(resolveWeek("#2026-10-21", DATES), { end: "2026-10-20", fallback: true });
  assert.deepEqual(resolveWeek("#2026-09-29", DATES), { end: "2026-10-20", fallback: true });
  assert.deepEqual(resolveWeek("#2026-02-30", DATES), { end: "2026-10-20", fallback: true });
  assert.deepEqual(resolveWeek("#foo", DATES), { end: "2026-10-20", fallback: true });
  assert.deepEqual(resolveWeek("", []), { end: null, fallback: false });
});

test("daysInWeek keeps the seven days ending on end", () => {
  assert.deepEqual(daysInWeek("2026-10-20", DATES), ["2026-10-20", "2026-10-19", "2026-10-16"]);
  assert.deepEqual(daysInWeek("2026-10-13", DATES), ["2026-10-12"]);
  assert.deepEqual(daysInWeek("2026-10-10", DATES), ["2026-10-04"]);
});

test("week navigation moves seven days and stops at the data range", () => {
  assert.equal(prevWeekEnd("2026-10-20", DATES), "2026-10-13");
  assert.equal(prevWeekEnd("2026-10-06", DATES), null);
  assert.equal(prevWeekEnd("2026-10-07", DATES), "2026-09-30");
  assert.equal(nextWeekEnd("2026-10-20", DATES), null);
  assert.equal(nextWeekEnd("2026-10-13", DATES), "2026-10-20");
  assert.equal(nextWeekEnd("2026-10-16", DATES), "2026-10-20");
  assert.equal(nextWeekEnd("2026-10-06", DATES), "2026-10-13");
});

test("weekLabel shows the range with the year once", () => {
  assert.equal(weekLabel("2026-10-20"), "2026/10/14 (水) 〜 10/20 (火)");
  assert.equal(weekLabel("2026-01-04"), "2025/12/29 (月) 〜 2026/1/4 (日)");
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
  assert.match(html, /<span class="time">10\/19 \(月\) 18:32<\/span>/);
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
  assert.match(html, /この週の日刊スポーツの記事はありません。/);
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
