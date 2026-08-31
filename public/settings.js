'use strict';

// Settings page: dir → project mappings. Self-contained by design — the project
// has no bundler, so the handful of shared helpers (esc, announce, getJSON) are
// re-declared here rather than extracted into a module loader.

// ---------- helpers ----------
function esc(s) {
  return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
function announce(msg) {
  const el = document.getElementById('sr-status');
  if (el) el.textContent = msg;
}
async function getJSON(url) {
  const r = await fetch(url);
  if (!r.ok) throw new Error(`${url}: ${r.status}`);
  return r.json();
}

// ---------- mappings ----------
// The full list renders at once — this is a page now, not a dialog, and the
// search filter is the way around a long list. Revisit only if it ever grows
// into the thousands.
let allMappings = [];

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
  ` + list.map(m => {
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

document.getElementById('mapping-search').addEventListener('input', () => {
  renderMappings();
});
document.getElementById('unmapped-only').addEventListener('change', () => {
  renderMappings();
});

// ---------- tabs ----------
// APG tabs: roving tabindex, arrow keys move focus AND activate (one panel each,
// no lazy content to fetch), selected state carried by aria-selected + weight.
const tablist = document.querySelector('[role="tablist"]');

function activateTab(tab) {
  tablist.querySelectorAll('[role="tab"]').forEach(t => {
    const on = t === tab;
    t.setAttribute('aria-selected', String(on));
    t.setAttribute('tabindex', on ? '0' : '-1');
  });
  document.querySelectorAll('.tab-panel').forEach(p => {
    p.hidden = p.id !== tab.getAttribute('aria-controls');
  });
  announce(`${tab.textContent} settings.`);
}

tablist.addEventListener('click', e => {
  const tab = e.target.closest('[role="tab"]');
  if (tab) activateTab(tab);
});
tablist.addEventListener('keydown', e => {
  if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
  e.preventDefault();
  const tabs = [...tablist.querySelectorAll('[role="tab"]')];
  const i = tabs.indexOf(document.activeElement);
  if (i < 0) return;
  const step = e.key === 'ArrowRight' ? 1 : tabs.length - 1;
  activateTab(tabs[(i + step) % tabs.length]);
});

(async () => {
  try {
    allMappings = await getJSON('/api/mappings');
    renderMappings();
    announce(`Loaded ${allMappings.length} mapping${allMappings.length === 1 ? '' : 's'}.`);
    document.getElementById('mapping-search').focus();
  } catch (e) {
    document.getElementById('mappings').innerHTML =
      `<p class="empty">Could not load mappings: ${esc(e.message || e)}</p>`;
    announce(`Could not load mappings: ${e.message || e}`);
  }
})();
