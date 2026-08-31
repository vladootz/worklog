'use strict';

const state = {
  from: isoDate(daysAgo(29)),
  to: isoDate(new Date()),
  projects: [], // selected project ids; empty = all
  bucket: 'day',
  presetFromUrl: false,
};

// ---------- url state ----------
// Every value here arrives from the address bar and is therefore untrusted.
// Anything that does not validate is dropped and the default used — never
// echoed back into the DOM, never passed through to the API.
const BUCKETS = ['day', 'week', 'month'];

function validIsoDate(v) {
  if (typeof v !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(v)) return false;
  const [y, m, d] = v.split('-').map(Number);
  if (m < 1 || m > 12 || d < 1 || d > 31) return false;
  const dt = new Date(Date.UTC(y, m - 1, d));
  return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d;
}

function readUrlState() {
  const p = new URLSearchParams(location.search);
  const from = p.get('from');
  const to = p.get('to');
  const bucket = p.get('bucket');
  const projects = (p.get('projects') || '')
    .split(',')
    .map(v => Number(v))
    .filter(v => Number.isInteger(v) && v > 0);

  // A range is only accepted whole, and only if it is not inverted.
  if (validIsoDate(from) && validIsoDate(to) && from <= to) {
    state.from = from;
    state.to = to;
    state.presetFromUrl = true;
  }
  if (BUCKETS.includes(bucket)) state.bucket = bucket;
  if (projects.length) state.projects = [...new Set(projects)];
}

function writeUrlState() {
  const p = new URLSearchParams();
  p.set('from', state.from);
  p.set('to', state.to);
  if (state.projects.length) p.set('projects', state.projects.join(','));
  if (state.bucket !== 'day') p.set('bucket', state.bucket);
  history.replaceState(null, '', `${location.pathname}?${p}`);
}

// ---------- cross-filter ----------
// Clicking a chart segment narrows the work log to that project in that bucket.
// This is a view of the rows already loaded — it never refetches and never
// touches state.projects, so the chart itself is unaffected.
let crossFilter = null; // { projectId, projectName, bucket, bucketKey }

// Mirrors Api::bucketKey() — ISO week with a Monday start, matching date('o-\WW').
function isoWeekKey(dateStr) {
  const [y, m, d] = dateStr.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d));
  const day = dt.getUTCDay() || 7;          // Sunday = 7
  dt.setUTCDate(dt.getUTCDate() + 4 - day); // Thursday decides the ISO year
  const isoYear = dt.getUTCFullYear();
  const jan1 = new Date(Date.UTC(isoYear, 0, 1));
  const week = Math.ceil(((dt - jan1) / 86400000 + 1) / 7);
  return `${isoYear}-W${String(week).padStart(2, '0')}`;
}

function rowInBucket(date, bucket, key) {
  if (bucket === 'month') return date.slice(0, 7) === key;
  if (bucket === 'week') return isoWeekKey(date) === key;
  return date === key;
}

function setCrossFilter(cf) {
  crossFilter = cf;
  shownDays = DAYS_PER_PAGE;
  paintDays();
  if (cf) {
    announce(`Work log filtered to ${cf.projectName}, ${cf.bucketKey}.`);
    document.getElementById('cross-filter')?.querySelector('button')?.focus();
  } else {
    announce('Work log filter cleared.');
  }
}

function renderCrossFilter() {
  const host = document.getElementById('cross-filter');
  host.innerHTML = '';
  if (!crossFilter) return;
  const label = document.createElement('span');
  label.textContent = `Showing ${crossFilter.projectName} · ${crossFilter.bucketKey}`;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = 'Clear filter';
  btn.addEventListener('click', () => setCrossFilter(null));
  host.appendChild(label);
  host.appendChild(btn);
}

// ---------- helpers ----------
function isoDate(d) {
  const p = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}
function daysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d; }
function fmtDur(secs) {
  let h = Math.floor(secs / 3600), m = Math.round((secs % 3600) / 60);
  if (m === 60) { h += 1; m = 0; }
  if (h === 0 && m === 0) return '0m';
  return ((h ? `${h}h ` : '') + (m ? `${m}m` : '')).trim();
}
// Minutes as fmtDur would actually print them. A day header that sums raw seconds
// disagrees with the rows beneath it by a minute or two, because each row rounds
// on its own — and on a billing tool the visible arithmetic has to reconcile.
function displayedMinutes(secs) {
  let h = Math.floor(secs / 3600), m = Math.round((secs % 3600) / 60);
  if (m === 60) { h += 1; m = 0; }
  return h * 60 + m;
}
function fmtMins(mins) {
  const h = Math.floor(mins / 60), m = mins % 60;
  if (h === 0 && m === 0) return '0m';
  return ((h ? `${h}h ` : '') + (m ? `${m}m` : '')).trim();
}
function fmtTime(epoch) {
  return new Date(epoch * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}
function esc(s) {
  return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
function qs() {
  const p = new URLSearchParams({ from: state.from, to: state.to });
  if (state.projects.length) p.set('projects', state.projects.join(','));
  return p;
}
// Single polite live region — async work the user cannot see otherwise
// (ingest, background digests, mapping saves) is announced through here.
function announce(msg) {
  const el = document.getElementById('sr-status');
  if (el) el.textContent = msg;
}
async function getJSON(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error(`${url}: ${r.status}`);
  return r.json();
}

// ---------- rendering ----------
// Leverage is the app's actual claim, so it gets the size; the two hour figures
// are the sentence that explains it rather than three co-equal cards.
function renderHero(sum) {
  const n = sum.projects.length;
  document.getElementById('hero').innerHTML = `
    <p class="hero-figure">
      <span class="hero-value">${esc(sum.leverage)}×</span>
      <span class="hero-label">Leverage</span>
    </p>
    <p class="hero-sentence">
      <strong>${esc(fmtDur(sum.total_project_seconds))}</strong> of project time compressed into
      <strong>${esc(fmtDur(sum.wall_clock_seconds))}</strong> of wall clock<br>
      across <strong>${n}</strong> ${n === 1 ? 'project' : 'projects'}
      <span class="hero-range">· ${esc(rangeLabel())}</span>
    </p>
    ${n ? `<p class="hero-top">Most time: ${esc(topProjects(sum.projects))}</p>` : ''}`;
}
function rangeLabel() {
  const active = document.querySelector('.presets button.active');
  return active ? active.textContent.toLowerCase() : `${state.from} → ${state.to}`;
}
function topProjects(projects) {
  return projects.slice(0, 3).map(p => p.name).join(', ') || '—';
}

// The work log is grouped by day and revealed a page at a time: 136 flat rows
// with the date repeated on every one was 14,000px of unreadable page.
const DAYS_PER_PAGE = 10;
let allDayRows = [];
let shownDays = DAYS_PER_PAGE;

// Takes {r, i} pairs so the index always points back into allDayRows, even when
// the visible set has been narrowed by a cross-filter.
function groupByDate(pairs) {
  const groups = [];
  const seen = new Map();
  for (const pair of pairs) {
    if (!seen.has(pair.r.date)) {
      const g = { date: pair.r.date, items: [] };
      seen.set(pair.r.date, g);
      groups.push(g);
    }
    seen.get(pair.r.date).items.push(pair);
  }
  return groups;
}

// A repaint of the same range is a background event (a finished ingest, a returning
// digest pass) and must not undo what the user has done to the page. A repaint of a
// *different* range is a new question being asked, so paging and cross-filter reset.
function currentQueryKey() {
  return `${state.from}|${state.to}|${state.projects.join(',')}`;
}
let lastQueryKey = null;

function renderDays(rows) {
  const sameQuery = lastQueryKey === currentQueryKey();
  lastQueryKey = currentQueryKey();
  const reopen = sameQuery ? openRowKeys() : [];
  const focusKey = sameQuery ? focusedRowKey() : null;
  allDayRows = rows;
  if (!sameQuery) {
    shownDays = DAYS_PER_PAGE;
    // The row set just changed underneath it, so a stale cross-filter would lie.
    crossFilter = null;
  }
  paintDays();
  restoreOpenRows(reopen);
  if (focusKey) focusRow(focusKey);
}

// Expanded rows are identified by project+date, not by index: a repaint can reorder
// or drop rows, and an index would then re-open the wrong day.
function openRowKeys() {
  return [...document.querySelectorAll('#days-table tbody tr.day-row')]
    .filter(tr => tr.nextElementSibling?.classList.contains('detail'))
    .map(tr => allDayRows[Number(tr.dataset.i)])
    .filter(Boolean)
    .map(rowKey);
}

function restoreOpenRows(keys) {
  if (!keys.length) return;
  const want = new Set(keys);
  document.querySelectorAll('#days-table tbody tr.day-row').forEach(tr => {
    const row = allDayRows[Number(tr.dataset.i)];
    if (row && want.has(rowKey(row))) toggleDetail(tr, row);
  });
}

function focusedRowKey() {
  const tr = document.activeElement?.closest?.('#days-table tbody tr');
  if (!tr) return null;
  const host = tr.classList.contains('day-row') ? tr : tr.previousElementSibling;
  const row = allDayRows[Number(host?.dataset.i)];
  return row ? rowKey(row) : null;
}

function focusRow(key) {
  const tr = [...document.querySelectorAll('#days-table tbody tr.day-row')]
    .find(t => { const r = allDayRows[Number(t.dataset.i)]; return r && rowKey(r) === key; });
  tr?.querySelector('button.disclosure')?.focus();
}

function paintDays() {
  renderCrossFilter();
  const rows = crossFilter
    ? allDayRows.filter(r => r.project_id === crossFilter.projectId &&
        rowInBucket(r.date, crossFilter.bucket, crossFilter.bucketKey))
    : allDayRows;
  const tbody = document.querySelector('#days-table tbody');
  const empty = document.getElementById('empty');
  empty.hidden = rows.length > 0;
  if (!rows.length) {
    empty.innerHTML = `No activity between ${esc(state.from)} and ${esc(state.to)}.
      <span class="empty-actions">
        <button type="button" id="empty-widen">Widen to last 30 days</button>
        <button type="button" id="empty-read">Read data</button>
      </span>`;
    empty.querySelector('#empty-widen').addEventListener('click', () =>
      document.querySelector('.presets button[data-preset="30d"]').click());
    empty.querySelector('#empty-read').addEventListener('click', () => refresh());
  }
  const groups = groupByDate(rows.map(r => ({ r, i: allDayRows.indexOf(r) })));
  const visible = groups.slice(0, shownDays);

  tbody.innerHTML = visible.map(g => {
    const dayMins = g.items.reduce((a, o) => a + displayedMinutes(o.r.seconds), 0);
    const daySessions = g.items.reduce((a, o) => a + o.r.sessions.length, 0);
    const head = `
      <tr class="day-head">
        <td aria-hidden="true"></td>
        <th scope="rowgroup" colspan="2">${g.date}</th>
        <td class="time">${fmtMins(dayMins)}</td>
        <td>${daySessions}</td>
        <td class="muted">${g.items.length} project${g.items.length === 1 ? '' : 's'}</td>
      </tr>`;
    const body = g.items.map(({ r, i }) => {
      const delivered = deliveredCell(r, i);
      const badge = r.auto ? '<span class="badge warn" title="Auto-mapped directory — review in settings">unmapped</span>' : '';
      return `
      <tr class="day-row" data-i="${i}">
        <td class="disclosure-cell">
          <button type="button" class="disclosure" aria-expanded="false" aria-controls="detail-${i}">
            <span class="chev" aria-hidden="true">▸</span>
            <span class="visually-hidden">Show detail for ${esc(r.project)} on ${esc(r.date)}</span>
          </button>
        </td>
        <td class="col-date visually-hidden">${r.date}</td>
        <td data-label="Project">${esc(r.project)}${r.client ? ` <span class="muted">· ${esc(r.client)}</span>` : ''}${badge}</td>
        <td class="time" data-label="Time">${fmtDur(r.seconds)}</td>
        <td data-label="Sessions">${r.sessions.length}</td>
        <td data-label="Delivered">${delivered}</td>
      </tr>`;
    }).join('');
    return head + body;
  }).join('');

  renderShowMore(groups.length);

  tbody.querySelectorAll('tr.day-row').forEach(tr => {
    const row = allDayRows[Number(tr.dataset.i)];
    const btn = tr.querySelector('button.disclosure');
    // The button is the real control: it gets focus, Enter and Space for free.
    btn.addEventListener('click', e => { e.stopPropagation(); toggleDetail(tr, row); });
    btn.addEventListener('keydown', e => {
      if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') toggleDetail(tr, row);
    });
    // Mouse users keep the whole-row target, without it stealing the button's job.
    tr.addEventListener('click', e => {
      if (e.target.closest('button, a, input, select')) return;
      toggleDetail(tr, row);
    });
    const gen = tr.querySelector('button.gen-digest');
    if (gen) gen.addEventListener('click', e => { e.stopPropagation(); generateDigest(gen, row); });
  });
}
function renderShowMore(totalDays) {
  const host = document.getElementById('show-more-host');
  host.innerHTML = '';
  if (shownDays >= totalDays) return;
  const remaining = totalDays - shownDays;
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.id = 'show-more';
  btn.textContent = `Show ${Math.min(DAYS_PER_PAGE, remaining)} more days (${remaining} left)`;
  btn.addEventListener('click', () => {
    shownDays += DAYS_PER_PAGE;
    paintDays();
    announce(`Showing ${Math.min(shownDays, totalDays)} of ${totalDays} days.`);
    const next = document.getElementById('show-more');
    (next || document.querySelector('#days-table tbody tr.day-head:last-of-type th'))?.focus();
  });
  host.appendChild(btn);
}

/**
 * A session title only counts as a deliverable if the source really titled it.
 * Claude Code seeds the title from the first prompt, so a "title" carrying a
 * filesystem path, a URL or a slash-command is prompt text wearing a title's
 * clothes. Raw prompts never belong in a column labelled "Delivered".
 */
function looksLikePrompt(s) {
  const t = String(s ?? '').trim();
  if (!t || t.length > 90) return true;
  if (t.startsWith('/')) return true;                 // slash command
  if (/https?:\/\//.test(t)) return true;             // pasted URL
  if (/(^|\s)[~/][\w.\/-]{6,}/.test(t)) return true;   // absolute or ~ path
  if (/^new session\b/i.test(t)) return true;         // source's placeholder title
  if (/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/.test(t)) return true; // ISO stamp, not prose
  return false;
}

// digest -> commit subjects -> a real title -> an explicit way to get a digest.
function deliveredCell(r, i) {
  if (r.digest) {
    return esc(firstLine(stripBullets(r.digest))) +
      (r.digest_stale ? '<span class="badge warn">stale</span>' : '');
  }
  if (r.commits.length) {
    const subjects = r.commits.map(c => firstLine(c.message)).filter(Boolean);
    const shown = subjects.slice(0, 2).join(' · ');
    const more = subjects.length > 2
      ? ` <span class="muted">+${subjects.length - 2} more</span>` : '';
    return `<span class="from-commits" title="From commit messages">${esc(shown)}</span>${more}`;
  }
  const title = r.sessions[0]?.title;
  if (!looksLikePrompt(title)) {
    return `<span class="muted">${esc(firstLine(title))}</span>`;
  }
  return `<button type="button" class="gen-digest" data-i="${i}">Generate digest</button>`;
}

function firstLine(s) {
  const line = String(s ?? '').split('\n').find(l => l.trim()) ?? '';
  return line.length > 110 ? line.slice(0, 110) + '…' : line;
}

// Digests are bullet lists, and the model is inconsistent about the marker —
// "- x", "- - x", "• x". The Delivered cell is already a list context, so strip
// any leading markers rather than rendering "- - Implemented ...".
function stripBullets(s) {
  return String(s ?? '').replace(/^\s*(?:[-*•]\s*)+/, '').trim();
}

function toggleDetail(tr, row) {
  const btn = tr.querySelector('button.disclosure');
  const chev = tr.querySelector('.chev');
  const next = tr.nextElementSibling;
  if (next && next.classList.contains('detail')) {
    next.remove();
    btn.setAttribute('aria-expanded', 'false');
    chev.textContent = '▸';
    btn.focus(); // collapsing must not strand focus on a removed node
    return;
  }
  btn.setAttribute('aria-expanded', 'true');
  chev.textContent = '▾';
  const detail = document.createElement('tr');
  detail.className = 'detail';
  detail.id = btn.getAttribute('aria-controls');
  detail.addEventListener('keydown', e => {
    if (e.key === 'Escape') { e.stopPropagation(); toggleDetail(tr, row); }
  });
  detail.innerHTML = `<td></td><td colspan="5"><div class="detail-inner">
    <div><h3>Work blocks</h3>${row.blocks.map(b =>
      `<span class="block-chip">${fmtTime(b[0])}–${fmtTime(b[1])}</span>`).join('')}
    </div>
    <div><h3>Sessions</h3><ul>${row.sessions.map(s =>
      `<li>${esc(firstLine(s.title || s.first_prompt || '(untitled)'))} <span class="muted">· ${s.source}${s.cost ? ' · $' + Number(s.cost).toFixed(2) : ''}</span></li>`).join('')}</ul>
    </div>
    ${row.commits.length ? `<div><h3>Commits</h3><ul>${row.commits.map(c =>
      `<li><code>${c.hash.slice(0, 7)}</code> ${esc(firstLine(c.message))} <span class="muted">+${c.additions ?? 0}/−${c.deletions ?? 0}</span></li>`).join('')}</ul></div>` : ''}
    <div class="detail-delivered">${deliveredBlock(row)}</div>
  </div></td>`;
  tr.after(detail);
  bindRegen(detail, row);
}

// The detail row's Delivered block, rendered on its own so a finished digest can
// replace just this block without rebuilding — and so collapsing — the whole row.
function deliveredBlock(row) {
  return `<h3>Delivered ${row.digest_stale ? '<span class="badge warn">stale</span>' : ''}</h3>
      ${row.digest ? `<div class="digest">${esc(row.digest)}</div>` : '<span class="muted">No digest yet.</span>'}
      <p><button type="button" class="regen">${row.digest ? 'Regenerate now' : 'Generate now'}</button></p>`;
}

function bindRegen(detail, row) {
  const btn = detail.querySelector('.regen');
  if (btn) btn.addEventListener('click', e => generateDigest(e.currentTarget, row));
}

async function generateDigest(btn, row) {
  btn.disabled = true;
  btn.textContent = 'Queued…';
  try {
    const res = await fetch('/api/digest/generate', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: row.project_id, date: row.date }),
    });
    const out = await res.json();
    if (!res.ok) throw new Error(out.error || `HTTP ${res.status}`);
    btn.textContent = out.queued ? 'Generating…' : 'Already running…';
    announce(`Digest queued for ${row.project} on ${row.date}. It appears when the model finishes.`);
    pendingDigests.add(rowKey(row));
    watchDigests(await lastDigestAt());
  } catch (e) {
    btn.disabled = false;
    btn.textContent = 'Retry';
    announce(`Could not queue the digest: ${e.message || e}`);
  }
}

function renderProjectFilter(projects) {
  const box = document.getElementById('project-options');
  box.innerHTML = projects.filter(p => !Number(p.ignored)).map(p => `
    <label><input type="checkbox" id="pf-${p.id}" name="pf-${p.id}" value="${p.id}" ${state.projects.includes(p.id) ? 'checked' : ''}> ${esc(p.name)}</label>
  `).join('');
  box.querySelectorAll('input').forEach(cb => cb.addEventListener('change', () => {
    state.projects = [...box.querySelectorAll('input:checked')].map(i => Number(i.value));
    document.querySelector('#project-filter summary').textContent =
      state.projects.length ? `${state.projects.length} project(s)` : 'All projects';
    announce(state.projects.length
      ? `Filtered to ${state.projects.length} project${state.projects.length === 1 ? '' : 's'}.`
      : 'Showing all projects.');
    load();
  }));
}

// ---------- data loading ----------
// First paint shows the shape of the page rather than three empty boxes.
function renderSkeleton() {
  document.getElementById('hero').innerHTML =
    '<div class="sk sk-figure"></div><div class="sk sk-line"></div><div class="sk sk-line short"></div>';
  document.getElementById('chart').innerHTML = '<div class="sk sk-chart"></div>';
  document.querySelector('#days-table tbody').innerHTML = Array.from({ length: 6 }, () =>
    '<tr><td colspan="6"><div class="sk sk-row"></div></td></tr>').join('');
}

function renderLoadError(err) {
  document.getElementById('hero').innerHTML = `
    <p class="state-error"><strong>Could not load the dashboard.</strong><br>
      <span class="muted">${esc(err.message || err)}</span></p>
    <p><button type="button" id="load-retry">Retry</button></p>`;
  document.getElementById('load-retry').addEventListener('click', () => load());
  document.getElementById('chart').innerHTML = '';
  document.getElementById('chart-legend').innerHTML = '';
  announce(`Could not load the dashboard: ${err.message || err}`);
}

async function load() {
  writeUrlState();
  try {
    const [sum, days, series] = await Promise.all([
      getJSON('/api/summary?' + qs()),
      getJSON('/api/days?' + qs()),
      getJSON('/api/timeseries?' + qs() + '&bucket=' + state.bucket),
    ]);
    renderHero(sum);
    renderDays(days);
    if (typeof renderChart === 'function') {
      renderChart(document.getElementById('chart'), document.getElementById('chart-legend'), series);
    }
  } catch (e) {
    renderLoadError(e);
  }
}

async function loadProjects() {
  renderProjectFilter(await getJSON('/api/projects'));
}

// ---------- filters ----------
readUrlState();
document.getElementById('from').value = state.from;
document.getElementById('to').value = state.to;
document.getElementById('from').addEventListener('change', e => { state.from = e.target.value; clearPreset(); load(); });
document.getElementById('to').addEventListener('change', e => { state.to = e.target.value; clearPreset(); load(); });

// Visual state and the exposed state are set together so they cannot drift.
function setPressed(btn, group) {
  document.querySelectorAll(group).forEach(b => {
    const on = b === btn;
    b.classList.toggle('active', on);
    b.setAttribute('aria-pressed', String(on));
  });
}
function clearPreset() {
  setPressed(null, '.presets button');
}
document.querySelectorAll('.presets button').forEach(btn => btn.addEventListener('click', () => {
  setPressed(btn, '.presets button');
  const now = new Date();
  const monday = new Date(now);
  monday.setDate(now.getDate() - ((now.getDay() + 6) % 7)); // Monday start
  const ranges = {
    today: [now, now],
    week: [monday, now],
    month: [new Date(now.getFullYear(), now.getMonth(), 1), now],
    '30d': [daysAgo(29), now],
  };
  const [f, t] = ranges[btn.dataset.preset];
  state.from = isoDate(f);
  state.to = isoDate(t);
  document.getElementById('from').value = state.from;
  document.getElementById('to').value = state.to;
  load();
}));

document.querySelectorAll('#bucket-switch button').forEach(btn => btn.addEventListener('click', () => {
  setPressed(btn, '#bucket-switch button');
  state.bucket = btn.dataset.bucket;
  load();
}));

const projectFilter = document.getElementById('project-filter');
const projectSummary = projectFilter.querySelector('summary');
projectFilter.addEventListener('toggle', () => {
  projectSummary.setAttribute('aria-expanded', String(projectFilter.open));
});
document.addEventListener('click', e => {
  if (projectFilter.open && !projectFilter.contains(e.target)) projectFilter.open = false;
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && projectFilter.open) { projectFilter.open = false; projectSummary.focus(); }
});

// ---------- timester export ----------
const exportDialog = document.getElementById('export-dialog');
document.getElementById('export-btn').addEventListener('click', () => {
  const scope = state.projects.length ? `${state.projects.length} filtered project(s)` : 'all projects';
  exportDialog.querySelector('.hint').textContent =
    `One entry per project per day, ${state.from} → ${state.to}, ${scope}. Use timester's MERGE import — ` +
    `and merge each range only once: timester does not dedupe re-imported entries.`;
  exportDialog.showModal();
});
document.getElementById('export-close').addEventListener('click', () => exportDialog.close());
document.getElementById('export-download').addEventListener('click', () => {
  const rounding = document.getElementById('export-rounding').value;
  window.location.href = '/api/export/timester?' + qs() + '&rounding=' + rounding;
});

// ---------- read data (ingest on demand) ----------
const STALE_SECS = 5 * 60; // auto-refresh on load only if the last ingest is older than this
const refreshBtn = document.getElementById('refresh-btn');
let refreshing = false;

async function reloadAll() {
  await Promise.all([loadProjects(), load()]);
}
async function lastDigestAt() {
  try { return (await getJSON('/api/status')).last_digest_at || 0; }
  catch { return 0; }
}

async function refresh() {
  if (refreshing) return;
  refreshing = true;
  document.body.classList.add('is-refreshing');
  refreshBtn.disabled = true;
  refreshBtn.classList.add('loading');
  refreshBtn.textContent = 'Reading…';
  announce('Reading the latest session data…');
  const digestBefore = await lastDigestAt();
  try {
    await fetch('/api/ingest', { method: 'POST' });
    await reloadAll();
    refreshBtn.textContent = 'Read data';
    refreshBtn.title = 'Last read at ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    announce('Session data updated.');
    watchDigests(digestBefore);
  } catch (e) {
    refreshBtn.textContent = 'Retry read';
    refreshBtn.title = String(e);
    announce(`Could not read session data: ${e.message || e}. Press Retry read to try again.`);
  } finally {
    refreshBtn.classList.remove('loading');
    document.body.classList.remove('is-refreshing');
    refreshBtn.disabled = false;
    refreshing = false;
  }
}

// ---------- digests arriving in the background ----------
// The Ollama pass runs detached, so its results have to land in a page the user is
// still using: an expanded day, a "show more" page, a cross-filter. Repainting the
// whole work log threw all of that away, so nothing here re-renders the table —
// only the Delivered cell and the open detail block of the days that changed.
const pendingDigests = new Set();
let digestWatch = null;

function rowKey(row) { return `${row.project_id}|${row.date}`; }

// Re-read the day rows and copy across only the digest fields. Everything else in
// allDayRows stays as painted, so indices, grouping and paging cannot shift under
// the DOM we are about to patch.
async function patchDigests() {
  let fresh;
  try { fresh = await getJSON('/api/days?' + qs()); } catch { return false; }
  const byKey = new Map(fresh.map(r => [rowKey(r), r]));
  let changed = 0;
  allDayRows.forEach((row, i) => {
    const f = byKey.get(rowKey(row));
    if (!f) return;
    const sameDigest = (f.digest ?? null) === (row.digest ?? null);
    const sameStale = Number(f.digest_stale ?? 0) === Number(row.digest_stale ?? 0);
    if (sameDigest && sameStale) return;
    row.digest = f.digest;
    row.digest_stale = f.digest_stale;
    // Marking a day stale is the *start* of a generation, not the end: only a day
    // that carries a digest and is no longer stale has actually been regenerated.
    if (f.digest && !Number(f.digest_stale ?? 0)) pendingDigests.delete(rowKey(row));
    changed++;
    paintDeliveredCell(i);
  });
  return changed > 0;
}

// Repaint one row's Delivered cell (and its detail block, if open) from allDayRows.
// A row that is currently paged out or filtered away simply has no node — its data
// is already updated, so the next paintDays() shows it.
function paintDeliveredCell(i) {
  const tr = document.querySelector(`#days-table tbody tr.day-row[data-i="${i}"]`);
  if (!tr) return;
  const row = allDayRows[i];
  const cell = tr.querySelector('td[data-label="Delivered"]');
  if (cell) {
    const hadFocus = cell.contains(document.activeElement);
    cell.innerHTML = deliveredCell(row, i);
    const gen = cell.querySelector('button.gen-digest');
    if (gen) gen.addEventListener('click', e => { e.stopPropagation(); generateDigest(gen, row); });
    markPending(cell, row);
    // The button the user was on may be gone — a digest replaces it with text.
    // Focus must land somewhere in the same row, never on <body>.
    if (hadFocus) (gen || tr.querySelector('button.disclosure'))?.focus();
  }
  const detail = tr.nextElementSibling;
  if (!detail || !detail.classList.contains('detail')) return;
  const host = detail.querySelector('.detail-delivered');
  if (!host) return;
  const hadFocus = host.contains(document.activeElement);
  host.innerHTML = deliveredBlock(row);
  bindRegen(host, row);
  markPending(host, row);
  if (hadFocus) host.querySelector('.regen')?.focus();
}

// A row can be repainted mid-generation — the day is marked stale before the model
// runs, so its badge changes while the button must still read "Generating…".
function markPending(root, row) {
  if (!pendingDigests.has(rowKey(row))) return;
  const btn = root.querySelector('.regen, button.gen-digest');
  if (!btn) return;
  btn.disabled = true;
  btn.textContent = 'Generating…';
}

// Poll while the detached pass runs. Each tick patches whatever has landed, so a
// digest shows up as soon as its own day is written rather than at the end of the run.
// The stop condition is the rows we are waiting on, not the pass: a pass already in
// flight when the user clicks finishes almost immediately and would otherwise end the
// watch before their own day was ever generated.
const DIGEST_POLL_MS = 10000;
const DIGEST_POLL_TRIES = 60; // ten minutes — a queued day can sit behind a long backlog

function watchDigests(before) {
  if (digestWatch) clearInterval(digestWatch);
  let tries = 0;
  digestWatch = setInterval(async () => {
    tries++;
    if (await patchDigests()) announce('New deliverable summaries are ready.');
    // Rows the user asked for keep the watch alive on their own; with none pending
    // the watch is only following a background pass, which ends on its timestamp.
    const done = pendingDigests.size === 0 && (await lastDigestAt()) > before;
    if (!done && tries < DIGEST_POLL_TRIES) return;
    clearInterval(digestWatch);
    digestWatch = null;
    if (pendingDigests.size) {
      // Nothing arrived in time — Ollama is down, or the generation failed. Repaint
      // so those buttons leave the disabled "Generating…" state and can be retried.
      const stuck = new Set(pendingDigests);
      pendingDigests.clear();
      allDayRows.forEach((row, i) => { if (stuck.has(rowKey(row))) paintDeliveredCell(i); });
      announce('The digest did not arrive. Press generate to try again.');
    }
  }, DIGEST_POLL_MS);
}

refreshBtn.addEventListener('click', refresh);

// A URL-supplied range is not one of the presets, so clear the default highlight.
if (state.presetFromUrl) {
  clearPreset();
  document.getElementById('from').value = state.from;
  document.getElementById('to').value = state.to;
}
if (state.bucket !== 'day') {
  const b = document.querySelector(`#bucket-switch button[data-bucket="${state.bucket}"]`);
  if (b) setPressed(b, '#bucket-switch button');
}

// Initial paint from the cached DB, then re-read sources if the data has gone stale.
renderSkeleton();
loadProjects();
load();
(async () => {
  try {
    const s = await getJSON('/api/status');
    if (!s.last_ingest_at || (s.now - s.last_ingest_at) > STALE_SECS) refresh();
  } catch { /* status unavailable — keep the cached view as-is */ }
})();
