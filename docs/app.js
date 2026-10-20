// autorace-news: 最新の日から 7 日分の JSON を読んで 3 段に描く。ビルドなし、依存なし。
// 純粋関数は export してテストから使う。DOM を触るのは main() だけ。

const WEEKDAYS = ["日", "月", "火", "水", "木", "金", "土"];
/** GitHub Actions の定期実行は 1 日に数回しか動かないので、丸 1 日動いていなければ止まっているとみなす */
const STALE_AFTER_MS = 24 * 60 * 60 * 1000;
/** 1 ページの日数。オートレースは 1 日の記事が数件しかないので 1 週間ずつ見せる */
const WEEK_DAYS = 7;

/** 段の並び（固定）。表にない source は official に入れる。 */
export const SECTIONS = [
  { id: "official", title: "公式のお知らせ", empty: "この週の公式のお知らせはありません。" },
  { id: "news", title: "新聞記事", empty: "この週の日刊スポーツの記事はありません。" },
  { id: "video", title: "動画", empty: "この週の動画はありません。" },
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

const jstDateFormatter = new Intl.DateTimeFormat("sv-SE", {
  timeZone: "Asia/Tokyo",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});
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

/**
 * カードの日時。1 週間分を並べるので日付と曜日も出す（例: 10/19 (月) 18:32）。
 * @param {string} iso
 */
export function formatCardTime(iso) {
  const instant = new Date(iso);
  const [, m, d] = jstDateFormatter.format(instant).split("-").map(Number);
  return `${m}/${d} (${weekdayOf(jstDateFormatter.format(instant))}) ${timeFormatter.format(instant)}`;
}

/** @param {string} iso */
export function formatDateTime(iso) {
  return dateTimeFormatter.format(new Date(iso));
}

/** @param {string} date YYYY-MM-DD */
function weekdayOf(date) {
  const [y, m, d] = date.split("-").map(Number);
  return WEEKDAYS[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
}

/** @param {string} date YYYY-MM-DD @param {number} days */
function shiftDate(date, days) {
  const [y, m, d] = date.split("-").map(Number);
  return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

/** @param {string} value */
function isDate(value) {
  return /^\d{4}-\d{2}-\d{2}$/.test(value) && shiftDate(value, 0) === value;
}

/**
 * 表示する 7 日間の最終日。hash が無ければ記事のある最新の日。
 * hash の日付がデータの範囲（最古〜最新の日）の外か、日付として正しくなければ最新にして fallback を立てる。
 * @param {string} hash location.hash（"#2026-10-19" など）
 * @param {string[]} dates 記事のある日。新しい順
 * @returns {{ end: string | null, fallback: boolean }}
 */
export function resolveWeek(hash, dates) {
  const latest = dates[0] ?? null;
  const oldest = dates[dates.length - 1] ?? null;
  const wanted = hash.replace(/^#/, "");
  if (!wanted || latest === null || oldest === null) return { end: latest, fallback: false };
  if (isDate(wanted) && wanted >= oldest && wanted <= latest) return { end: wanted, fallback: false };
  return { end: latest, fallback: true };
}

/**
 * end で終わる 7 日間のうち、記事のある日（新しい順）。
 * @param {string} end
 * @param {string[]} dates
 */
export function daysInWeek(end, dates) {
  const start = shiftDate(end, -(WEEK_DAYS - 1));
  return dates.filter((date) => date >= start && date <= end);
}

/**
 * 「‹ 前の週」の最終日。それより前に記事のある日が無ければ null。
 * @param {string} end
 * @param {string[]} dates
 */
export function prevWeekEnd(end, dates) {
  const candidate = shiftDate(end, -WEEK_DAYS);
  const oldest = dates[dates.length - 1];
  return oldest !== undefined && oldest <= candidate ? candidate : null;
}

/**
 * 「次の週 ›」の最終日。最新の日を超えるときは最新の日で止める。最新の週なら null。
 * @param {string} end
 * @param {string[]} dates
 */
export function nextWeekEnd(end, dates) {
  const latest = dates[0];
  if (latest === undefined || end >= latest) return null;
  const candidate = shiftDate(end, WEEK_DAYS);
  return candidate < latest ? candidate : latest;
}

/**
 * ヘッダーの期間。年は始まりの日に付け、年をまたぐときは終わりの日にも付ける。
 * @param {string} end
 */
export function weekLabel(end) {
  const start = shiftDate(end, -(WEEK_DAYS - 1));
  const [sy, sm, sd] = start.split("-").map(Number);
  const [ey, em, ed] = end.split("-").map(Number);
  const endText = sy === ey ? `${em}/${ed}` : `${ey}/${em}/${ed}`;
  return `${sy}/${sm}/${sd} (${weekdayOf(start)}) 〜 ${endText} (${weekdayOf(end)})`;
}

/**
 * 最後の収集から 24 時間を超えていたら true。
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
  <p class="meta"><span class="src ${sectionOf(article.source)}">${escapeHtml(sourceLabel(article))}</span>${tags}<span class="time">${formatCardTime(article.publishedAt)}</span></p>
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

// ---- 以下は DOM を触る部分 -------------------------------------------------

async function fetchJson(path) {
  const res = await fetch(path, { cache: "no-cache" });
  if (!res.ok) throw new Error(`${path}: HTTP ${res.status}`);
  return res.json();
}

function el(id) {
  const node = document.getElementById(id);
  if (!node) throw new Error(`#${id} not found`);
  return node;
}

function setNotices(notices, warnings) {
  el("notices").innerHTML = [
    ...warnings.map((w) => `<p class="warning">${escapeHtml(w)}</p>`),
    ...notices.map((n) => `<p class="notice">${escapeHtml(n)}</p>`),
  ].join("");
}

async function render(index) {
  const { end, fallback } = resolveWeek(location.hash, index.dates);
  const notices = [];
  const warnings = [];

  el("updated").textContent = index.checkedAt ? `最終更新 ${formatDateTime(index.checkedAt)} JST` : "";
  if (index.checkedAt && isStale(index.checkedAt, new Date())) warnings.push(staleMessage(index.checkedAt));
  for (const failure of index.failures ?? []) notices.push(failureMessage(failure));

  if (!end) {
    el("date").textContent = "データがありません";
    el("count").textContent = "";
    el("sections").innerHTML = "";
    setNotices(["まだ収集結果がありません。", ...notices], warnings);
    return;
  }

  const older = prevWeekEnd(end, index.dates);
  const newer = nextWeekEnd(end, index.dates);
  const prevBtn = el("prev");
  const nextBtn = el("next");
  prevBtn.disabled = !older;
  nextBtn.disabled = !newer;
  prevBtn.onclick = () => {
    if (older) location.hash = older;
  };
  nextBtn.onclick = () => {
    if (newer) location.hash = newer;
  };

  const days = await Promise.all(daysInWeek(end, index.dates).map((date) => fetchJson(`data/${date}.json`)));
  const articles = days.flatMap((day) => day.articles);
  el("date").textContent = weekLabel(end);
  el("count").textContent = `${articles.length} 件`;
  el("sections").innerHTML = renderSections(articles);

  if (fallback) notices.unshift("指定の日付が無いため最新を表示しています。");
  setNotices(notices, warnings);
}

async function main() {
  try {
    const index = await fetchJson("data/index.json");
    await render(index);
    window.addEventListener("hashchange", () => {
      render(index).catch(showError);
    });
  } catch (e) {
    showError(e);
  }
}

function showError(e) {
  setNotices([], [`読み込みに失敗しました: ${e instanceof Error ? e.message : String(e)}`]);
}

if (typeof document !== "undefined") main();
