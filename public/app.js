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

function renderDays(rows) {
  allDayRows = rows;
  shownDays = DAYS_PER_PAGE;
  // The row set just changed underneath it, so a stale cross-filter would lie.
  crossFilter = null;
  paintDays();
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
    <div><h3>Delivered ${row.digest_stale ? '<span class="badge warn">stale</span>' : ''}</h3>
      ${row.digest ? `<div class="digest">${esc(row.digest)}</div>` : '<span class="muted">No digest yet.</span>'}
      <p><button type="button" class="regen">${row.digest ? 'Regenerate now' : 'Generate now'}</button></p>
    </div>
  </div></td>`;
  tr.after(detail);
  detail.querySelector('.regen').addEventListener('click', e =>
    generateDigest(e.currentTarget, row));
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

// ---------- settings (dir → project mappings) ----------
const settingsDialog = document.getElementById('settings-dialog');
const MAPPINGS_PER_PAGE = 40;
let allMappings = [];
let mappingsShown = MAPPINGS_PER_PAGE;

function filteredMappings() {
  const q = document.getElementById('mapping-search').value.trim().toLowerCase();
  const unmappedOnly = document.getElementById('unmapped-only').checked;
  return allMappings.filter(m => {
    if (unmappedOnly && !Number(m.auto)) return false;
    if (!q) return true;
    return `${m.dir} ${m.project} ${m.client || ''}`.toLowerCase().includes(q);
  });
}

// Filters the already-fetched array. It must never issue a request per keystroke.
function renderMappings() {
  const list = filteredMappings();
  const visible = list.slice(0, mappingsShown);
  const box = document.getElementById('mappings');

  document.getElementById('mapping-count').textContent =
    `${list.length} of ${allMappings.length} mapping${allMappings.length === 1 ? '' : 's'}`;

  if (!list.length) {
    box.innerHTML = '<p class="empty">No mapping matches that search.</p>';
    return;
  }

  box.innerHTML = `
    <datalist id="project-names">${[...new Set(allMappings.map(m => m.project))]
      .map(n => `<option value="${esc(n)}">`).join('')}</datalist>
    <div class="mapping-row mapping-head"><span>Directory</span><span>Project</span><span>Client</span><span>Ignore</span><span>Status</span></div>
  ` + visible.map(m => {
    const i = allMappings.indexOf(m);
    return `
    <div class="mapping-row" data-dir="${esc(m.dir)}">
      <span class="dir" title="${esc(m.dir)}">${esc(m.dir)}${m.auto ? ' <span class="badge warn">auto</span>' : ''}</span>
      <label class="visually-hidden" for="m-project-${i}">Project for ${esc(m.dir)}</label>
      <input type="text" class="m-project" id="m-project-${i}" name="m-project-${i}" list="project-names" value="${esc(m.project)}">
      <label class="visually-hidden" for="m-client-${i}">Client for ${esc(m.dir)}</label>
      <input type="text" class="m-client" id="m-client-${i}" name="m-client-${i}" value="${esc(m.client || '')}" placeholder="—">
      <span class="ignore-cell">
        <label class="visually-hidden" for="m-ignored-${i}">Ignore ${esc(m.dir)}</label>
        <input type="checkbox" class="m-ignored" id="m-ignored-${i}" name="m-ignored-${i}" ${Number(m.ignored) ? 'checked' : ''}>
      </span>
      <span class="save-state" role="status" aria-live="polite"></span>
    </div>`;
  }).join('');

  if (list.length > mappingsShown) {
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'mapping-more';
    more.textContent = `Show ${Math.min(MAPPINGS_PER_PAGE, list.length - mappingsShown)} more (${list.length - mappingsShown} left)`;
    more.addEventListener('click', () => { mappingsShown += MAPPINGS_PER_PAGE; renderMappings(); });
    box.appendChild(more);
  }

  box.querySelectorAll('.mapping-row[data-dir]').forEach(row => {
    const stateEl = row.querySelector('.save-state');
    const save = async () => {
      const dir = row.dataset.dir;
      stateEl.className = 'save-state saving';
      stateEl.textContent = 'Saving…';
      let res, err = '';
      try {
        res = await fetch('/api/mappings', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            dir,
            project: row.querySelector('.m-project').value.trim(),
            client: row.querySelector('.m-client').value.trim(),
            ignored: row.querySelector('.m-ignored').checked,
          }),
        });
        if (!res.ok) err = (await res.json().catch(() => ({}))).error || `HTTP ${res.status}`;
      } catch (e) {
        err = String(e.message || e);
      }
      // Failure must be readable, not just a red edge.
      row.classList.toggle('save-failed', !!err);
      stateEl.className = 'save-state ' + (err ? 'failed' : 'saved');
      stateEl.textContent = err ? `Not saved — ${err}` : 'Saved';
      announce(err ? `Could not save ${dir}: ${err}` : `Saved ${dir}.`);
      if (!err) {
        const m = allMappings.find(x => x.dir === dir);
        if (m) {
          m.project = row.querySelector('.m-project').value.trim();
          m.client = row.querySelector('.m-client').value.trim();
          m.ignored = row.querySelector('.m-ignored').checked ? 1 : 0;
        }
      }
    };
    row.querySelectorAll('input').forEach(inp => inp.addEventListener('change', save));
  });
}

document.getElementById('settings-btn').addEventListener('click', async () => {
  allMappings = await getJSON('/api/mappings');
  mappingsShown = MAPPINGS_PER_PAGE;
  document.getElementById('mapping-search').value = '';
  document.getElementById('unmapped-only').checked = false;
  renderMappings();
  settingsDialog.showModal();
  document.getElementById('mapping-search').focus();
});
document.getElementById('mapping-search').addEventListener('input', () => {
  mappingsShown = MAPPINGS_PER_PAGE;
  renderMappings();
});
document.getElementById('unmapped-only').addEventListener('change', () => {
  mappingsShown = MAPPINGS_PER_PAGE;
  renderMappings();
});
document.getElementById('settings-close').addEventListener('click', () => {
  settingsDialog.close();
  loadProjects();
  load();
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

// The Ollama digest pass runs in the background after /api/ingest returns; poll its
// timestamp for a few minutes and reload once it advances so new bullets appear on their own.
function watchDigests(before) {
  let tries = 0;
  const iv = setInterval(async () => {
    if (++tries > 20 || (await lastDigestAt()) > before) {
      clearInterval(iv);
      if (tries <= 20) { load(); announce('New deliverable summaries are ready.'); }
    }
  }, 15000);
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
