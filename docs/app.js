// autorace-news: 1 日分の JSON を読んで 3 段に描く。ビルドなし、依存なし。
// 純粋関数は export してテストから使う。DOM を触るのは main() だけ。

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];
const STALE_AFTER_MS = 3 * 60 * 60 * 1000;

/** 段の並び（固定）。表にない source は official に入れる。 */
export const SECTIONS = [
  { id: "official", title: "公式のお知らせ", empty: "この日の公式のお知らせはありません。" },
  { id: "news", title: "新聞記事", empty: "この日の日刊スポーツの記事はありません。" },
  { id: "video", title: "動画", empty: "この日の動画はありません。" },
];

const SECTION_OF_SOURCE = {
  nikkansports: "news",
  youtube: "video",
};

const SOURCE_LABELS = {
  "autorace.jp": "AUTORACE.JP",
  kawaguchi: "川口オート",
  isesaki: "伊勢崎オート",
  hamamatsu: "浜松オート",
  sanyo: "山陽オート",
  nikkansports: "日刊スポーツ",
  youtube: "YouTube",
};

/** どの配信元にも付いていて、見分けの役に立たない分類 */
const HIDDEN_TAGS = new Set(["ニュース", "pickup"]);

/** 末尾に続く「空白＋#/＃で始まる語」の並び。先頭の 1 つは前の文字に続いていてもよい */
const TRAILING_HASHTAGS = /(?:[\s　]*[#＃][^\s　#＃]+)+[\s　]*$/u;

const timeFormatter = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});
const dateTimeFormatter = new Intl.DateTimeFormat("ja-JP", {
  timeZone: "Asia/Tokyo",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** @param {string} source */
export function sectionOf(source) {
  return SECTION_OF_SOURCE[source] ?? "official";
}

/** @param {{ source: string, sourceName?: string }} article */
export function sourceLabel(article) {
  return SOURCE_LABELS[article.source] ?? article.sourceName ?? article.source;
}

/** @param {string} title */
export function stripTrailingHashtags(title) {
  const stripped = title.replace(TRAILING_HASHTAGS, "");
  return stripped === "" ? title : stripped;
}

/** @param {{ source: string, title: string }} article */
export function displayTitle(article) {
  return article.source === "youtube" ? stripTrailingHashtags(article.title) : article.title;
}

/** @param {string[]} categories */
export function visibleTags(categories) {
  return categories.filter((c) => !HIDDEN_TAGS.has(c)).slice(0, 2);
}

/**
 * @param {{ source: string, publishedAt: string }[]} articles
 * @returns {Record<"official" | "news" | "video", any[]>}
 */
export function groupBySection(articles) {
  const groups = { official: [], news: [], video: [] };
  for (const article of articles) groups[sectionOf(article.source)].push(article);
  for (const list of Object.values(groups)) {
    list.sort((a, b) => Date.parse(b.publishedAt) - Date.parse(a.publishedAt));
  }
  return groups;
}

/** @param {string} iso */
export function formatTime(iso) {
  return timeFormatter.format(new Date(iso));
}

/** @param {string} iso */
export function formatDateTime(iso) {
  return dateTimeFormatter.format(new Date(iso));
}

/** @param {string} date YYYY-MM-DD */
export function dateLabel(date) {
  const [y, m, d] = date.split("-").map(Number);
  const weekday = WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
  return `${date} (${weekday})`;
}

/**
 * @param {string} hash location.hash（"#2026-10-17" など）
 * @param {string[]} dates 新しい順
 * @returns {{ date: string | null, fallback: boolean }}
 */
export function resolveDate(hash, dates) {
  const latest = dates[0] ?? null;
  const wanted = hash.replace(/^#/, "");
  if (!wanted) return { date: latest, fallback: false };
  if (dates.includes(wanted)) return { date: wanted, fallback: false };
  return { date: latest, fallback: latest !== null };
}

/**
 * 最後の収集から 3 時間を超えていたら true（GitHub の cron の遅れを見込む）。
 * @param {string} checkedAt
 * @param {Date} now
 */
export function isStale(checkedAt, now) {
  return now.getTime() - Date.parse(checkedAt) > STALE_AFTER_MS;
}

/** @param {unknown} value */
export function escapeHtml(value) {
  return String(value ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#39;");
}

/** http(s) 以外（javascript: など）はリンクにしない。 @param {string} url */
export function safeUrl(url) {
  return /^https?:\/\//i.test(url) ? url : "#";
}

/** @param {{ source: string, sourceName?: string, title: string, url: string, categories: string[], publishedAt: string }} article */
export function renderCard(article) {
  const tags = visibleTags(article.categories)
    .map((tag) => `<span class="tag">${escapeHtml(tag)}</span>`)
    .join("");
  return `<article class="card">
  <h3 class="title"><a href="${escapeHtml(safeUrl(article.url))}" target="_blank" rel="noopener noreferrer">${escapeHtml(displayTitle(article))}</a></h3>
  <p class="meta"><span class="src ${sectionOf(article.source)}">${escapeHtml(sourceLabel(article))}</span>${tags}<span class="time">${formatTime(article.publishedAt)}</span></p>
</article>`;
}

/** @param {Parameters<typeof renderCard>[0][]} articles */
export function renderSections(articles) {
  const groups = groupBySection(articles);
  return SECTIONS.map(({ id, title, empty }) => {
    const list = groups[id];
    const body = list.length
      ? `<div class="grid">\n${list.map(renderCard).join("\n")}\n</div>`
      : `<p class="empty">${empty}</p>`;
    return `<h2 class="section">${title} <span class="n">${list.length} 件</span></h2>\n${body}`;
  }).join("\n");
}

/** @param {{ source: string, message: string }} failure */
export function failureMessage(failure) {
  const label = SOURCE_LABELS[failure.source] ?? failure.source;
  return `${label} の取得に失敗しました（${failure.message}）。次の収集で取り直します。`;
}

/** @param {string} checkedAt */
export function staleMessage(checkedAt) {
  return `収集が止まっている可能性があります（最終収集: ${formatDateTime(checkedAt)}）`;
}
