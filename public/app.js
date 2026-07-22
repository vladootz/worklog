'use strict';

const state = {
  from: isoDate(daysAgo(29)),
  to: isoDate(new Date()),
  projects: [], // selected project ids; empty = all
  bucket: 'day',
};

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
async function getJSON(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error(`${url}: ${r.status}`);
  return r.json();
}

// ---------- rendering ----------
function renderCards(sum) {
  const cards = [
    { label: 'Project time', value: fmtDur(sum.total_project_seconds), sub: 'sum of per-project time' },
    { label: 'Wall clock', value: fmtDur(sum.wall_clock_seconds), sub: 'deduplicated real time' },
    { label: 'Leverage', value: sum.leverage + '×', sub: 'agent-hours per real hour' },
    { label: 'Active projects', value: sum.projects.length, sub: topProjects(sum.projects) },
  ];
  document.getElementById('cards').innerHTML = cards.map(c =>
    `<div class="card"><div class="label">${c.label}</div><div class="value">${c.value}</div><div class="sub">${esc(c.sub)}</div></div>`
  ).join('');
}
function topProjects(projects) {
  return projects.slice(0, 3).map(p => p.name).join(', ') || '—';
}

function renderDays(rows) {
  const tbody = document.querySelector('#days-table tbody');
  document.getElementById('empty').hidden = rows.length > 0;
  tbody.innerHTML = rows.map((r, i) => {
    const delivered = r.digest
      ? esc(firstLine(r.digest)) + (r.digest_stale ? '<span class="badge warn">stale</span>' : '')
      : `<span class="muted">${esc(firstLine(r.sessions[0]?.title || r.sessions[0]?.first_prompt || ''))}</span>`;
    const badge = r.auto ? '<span class="badge warn" title="Auto-mapped directory — review in settings">unmapped</span>' : '';
    return `
      <tr class="day-row" data-i="${i}">
        <td class="muted">▸</td>
        <td>${r.date}</td>
        <td>${esc(r.project)}${r.client ? ` <span class="muted">· ${esc(r.client)}</span>` : ''}${badge}</td>
        <td class="time">${fmtDur(r.seconds)}</td>
        <td>${r.sessions.length}</td>
        <td>${delivered}</td>
      </tr>`;
  }).join('');

  tbody.querySelectorAll('tr.day-row').forEach(tr => {
    tr.addEventListener('click', () => toggleDetail(tr, rows[Number(tr.dataset.i)]));
  });
}
function firstLine(s) {
  const line = String(s ?? '').split('\n')[0];
  return line.length > 110 ? line.slice(0, 110) + '…' : line;
}

function toggleDetail(tr, row) {
  const next = tr.nextElementSibling;
  if (next && next.classList.contains('detail')) {
    next.remove();
    tr.querySelector('td').textContent = '▸';
    return;
  }
  tr.querySelector('td').textContent = '▾';
  const detail = document.createElement('tr');
  detail.className = 'detail';
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
      ${row.digest ? `<div class="digest">${esc(row.digest)}</div>` : '<span class="muted">No digest yet — run ingest.</span>'}
      <p><button class="regen" data-project="${row.project_id}" data-date="${row.date}">Regenerate on next ingest</button></p>
    </div>
  </div></td>`;
  tr.after(detail);
  detail.querySelector('.regen').addEventListener('click', async e => {
    await fetch('/api/digest/stale', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ project_id: row.project_id, date: row.date }),
    });
    e.target.textContent = 'Marked stale ✓';
    e.target.disabled = true;
  });
}

function renderProjectFilter(projects) {
  const box = document.getElementById('project-options');
  box.innerHTML = projects.filter(p => !Number(p.ignored)).map(p => `
    <label><input type="checkbox" value="${p.id}" ${state.projects.includes(p.id) ? 'checked' : ''}> ${esc(p.name)}</label>
  `).join('');
  box.querySelectorAll('input').forEach(cb => cb.addEventListener('change', () => {
    state.projects = [...box.querySelectorAll('input:checked')].map(i => Number(i.value));
    document.querySelector('#project-filter summary').textContent =
      state.projects.length ? `${state.projects.length} project(s)` : 'All projects';
    load();
  }));
}

// ---------- data loading ----------
async function load() {
  const [sum, days, series] = await Promise.all([
    getJSON('/api/summary?' + qs()),
    getJSON('/api/days?' + qs()),
    getJSON('/api/timeseries?' + qs() + '&bucket=' + state.bucket),
  ]);
  renderCards(sum);
  renderDays(days);
  if (typeof renderChart === 'function') {
    renderChart(document.getElementById('chart'), document.getElementById('chart-legend'), series);
  }
}

async function loadProjects() {
  renderProjectFilter(await getJSON('/api/projects'));
}

// ---------- filters ----------
document.getElementById('from').value = state.from;
document.getElementById('to').value = state.to;
document.getElementById('from').addEventListener('change', e => { state.from = e.target.value; clearPreset(); load(); });
document.getElementById('to').addEventListener('change', e => { state.to = e.target.value; clearPreset(); load(); });

function clearPreset() {
  document.querySelectorAll('.presets button').forEach(b => b.classList.remove('active'));
}
document.querySelectorAll('.presets button').forEach(btn => btn.addEventListener('click', () => {
  clearPreset();
  btn.classList.add('active');
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
  document.querySelectorAll('#bucket-switch button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  state.bucket = btn.dataset.bucket;
  load();
}));

document.addEventListener('click', e => {
  const pf = document.getElementById('project-filter');
  if (pf.open && !pf.contains(e.target)) pf.open = false;
});

// ---------- settings (dir → project mappings) ----------
const settingsDialog = document.getElementById('settings-dialog');
document.getElementById('settings-btn').addEventListener('click', async () => {
  const mappings = await getJSON('/api/mappings');
  const box = document.getElementById('mappings');
  box.innerHTML = `
    <datalist id="project-names">${[...new Set(mappings.map(m => m.project))].map(n => `<option value="${esc(n)}">`).join('')}</datalist>
    <div class="mapping-row" style="font-weight:600"><span>Directory</span><span>Project</span><span>Client</span><span>Ignore</span></div>
  ` + mappings.map((m, i) => `
    <div class="mapping-row" data-dir="${esc(m.dir)}">
      <span class="dir">${esc(m.dir)}${m.auto ? ' <span class="badge warn">auto</span>' : ''}</span>
      <input type="text" class="m-project" list="project-names" value="${esc(m.project)}">
      <input type="text" class="m-client" value="${esc(m.client || '')}" placeholder="—">
      <input type="checkbox" class="m-ignored" ${Number(m.ignored) ? 'checked' : ''}>
    </div>`).join('');
  box.querySelectorAll('.mapping-row[data-dir]').forEach(row => {
    const save = async () => {
      const res = await fetch('/api/mappings', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          dir: row.dataset.dir,
          project: row.querySelector('.m-project').value.trim(),
          client: row.querySelector('.m-client').value.trim(),
          ignored: row.querySelector('.m-ignored').checked,
        }),
      });
      row.style.outline = res.ok ? '' : '2px solid var(--danger)';
    };
    row.querySelectorAll('input').forEach(inp => inp.addEventListener('change', save));
  });
  settingsDialog.showModal();
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
  refreshBtn.disabled = true;
  refreshBtn.classList.add('loading');
  refreshBtn.textContent = 'Reading…';
  const digestBefore = await lastDigestAt();
  try {
    await fetch('/api/ingest', { method: 'POST' });
    await reloadAll();
    refreshBtn.textContent = 'Read data';
    refreshBtn.title = 'Last read at ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    watchDigests(digestBefore);
  } catch (e) {
    refreshBtn.textContent = 'Retry read';
    refreshBtn.title = String(e);
  } finally {
    refreshBtn.classList.remove('loading');
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
      if (tries <= 20) load();
    }
  }, 15000);
}

refreshBtn.addEventListener('click', refresh);

// Initial paint from the cached DB, then re-read sources if the data has gone stale.
loadProjects();
load();
(async () => {
  try {
    const s = await getJSON('/api/status');
    if (!s.last_ingest_at || (s.now - s.last_ingest_at) > STALE_SECS) refresh();
  } catch { /* status unavailable — keep the cached view as-is */ }
})();
