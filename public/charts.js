'use strict';

// Hand-rolled stacked bar chart (SVG, no libraries).
// Palette: validated 8-slot categorical set (dataviz reference instance),
// dark-surface steps only (app is dark-theme only). The UI itself is
// monochrome, so these are the only saturated colours on the page. All
// nine fills measure >= 3.89:1 against --panel #17171a.

const PALETTE = ['#3987e5', '#199e70', '#c98500', '#4ca82f', '#9b6ef3', '#e66767', '#d55181', '#d95926'];
const OTHER_COLOR = '#6b7686';

// A series must hold at least this share of the range to earn its own colour,
// and at most this many can be shown before the categorical palette stops being
// readable. Everything else folds into "Other" — which is openable, so folding
// hides nothing permanently.
const FOLD_MIN_SHARE = 0.03;
const MAX_SERIES = PALETTE.length;

// Chart-local view state. Kept here so legend toggles and drill-down can
// re-render from the last payload without another round trip.
const chartState = { drill: null, hidden: new Set(), last: null, els: null };

// The bucket size the chart is currently drawn at, read from the pressed control
// so charts.js does not need its own copy of app.js state.
function currentBucket() {
  const active = document.querySelector('#bucket-switch button[aria-pressed="true"]');
  return active ? active.dataset.bucket : 'day';
}

function chartFmtDur(secs) {
  const h = secs / 3600;
  return h >= 10 ? Math.round(h) + 'h' : (Math.round(h * 10) / 10) + 'h';
}

function seriesTotal(s) {
  return Object.values(s.values).reduce((a, b) => a + b, 0);
}

// Stable color slots: project id modulo palette size, probed to be unique
// within the displayed set, so filtering doesn't repaint surviving series.
function assignSlots(series) {
  const taken = new Set();
  return series.map(s => {
    if (s.id === -1) return -1; // "Other" owns OTHER_COLOR, not a palette slot
    let slot = ((s.id % PALETTE.length) + PALETTE.length) % PALETTE.length;
    // Bounded probe. An unbounded `while` here spins forever the moment the
    // series count reaches PALETTE.length, which is now reachable.
    for (let i = 0; i < PALETTE.length && taken.has(slot); i++) {
      slot = (slot + 1) % PALETTE.length;
    }
    taken.add(slot);
    return slot;
  });
}

/**
 * Fold by share, not by a fixed rank. A long tail of 40 small projects used to
 * be crushed into an unopenable grey band worth ~45% of all tracked time; now
 * the fold is reported honestly and "Other" can be opened to see inside it.
 */
function foldSeries(data, drillIds) {
  const { buckets } = data;
  const pool = drillIds ? data.series.filter(s => drillIds.includes(s.id)) : data.series;
  const grand = pool.reduce((a, s) => a + seriesTotal(s), 0) || 1;

  const ranked = pool.map(s => ({ s, t: seriesTotal(s) })).sort((a, b) => b.t - a.t);
  const kept = [];
  const folded = [];
  for (const r of ranked) {
    if (kept.length < MAX_SERIES && r.t / grand >= FOLD_MIN_SHARE) kept.push(r.s);
    else folded.push(r.s);
  }
  // A range where nothing clears the threshold still deserves a chart.
  if (!kept.length) {
    kept.push(...ranked.slice(0, MAX_SERIES).map(r => r.s));
    folded.length = 0;
    folded.push(...ranked.slice(MAX_SERIES).map(r => r.s));
  }
  if (!folded.length) return { buckets, series: kept, folded: [], foldedShare: 0, grand };

  const other = { id: -1, name: 'Other', values: {} };
  let otherTotal = 0;
  for (const s of folded) {
    for (const [k, v] of Object.entries(s.values)) {
      other.values[k] = (other.values[k] || 0) + v;
      otherTotal += v;
    }
  }
  return {
    buckets,
    series: [...kept, other],
    folded: folded.map(s => s.id),
    foldedCount: folded.length,
    foldedShare: otherTotal / grand,
    grand,
  };
}

function renderChart(container, legendEl, data) {
  chartState.last = data;
  chartState.els = { container, legendEl };
  paintChart();
}

function repaint() {
  if (chartState.last) paintChart();
}

function paintChart() {
  const { container, legendEl } = chartState.els;
  const data = chartState.last;
  const folded = foldSeries(data, chartState.drill);
  const buckets = folded.buckets;
  // Legend toggles remove a series from the drawing but keep it in the legend.
  const series = folded.series.filter(s => !chartState.hidden.has(s.id));

  container.innerHTML = '';
  if (!buckets.length || !folded.series.length) {
    container.innerHTML = '<p class="empty">No data for this range.</p>';
    legendEl.innerHTML = '';
    return;
  }

  if (chartState.drill) container.appendChild(buildDrillBar(folded));

  const allSlots = assignSlots(folded.series);
  const colorOf = s => {
    if (s.id === -1) return OTHER_COLOR;
    return PALETTE[allSlots[folded.series.indexOf(s)]];
  };

  if (!series.length) {
    const p = document.createElement('p');
    p.className = 'empty';
    p.textContent = 'Every series is hidden — re-enable one from the legend.';
    container.appendChild(p);
    renderLegend(legendEl, folded, colorOf);
    return;
  }

  const H = 260, padL = 44, padB = 26, padT = 16, gap = 2;
  const availW = Math.max(container.clientWidth - 8, 320);
  const step = Math.min(76, Math.max(28, Math.floor((availW - padL) / buckets.length)));
  const barW = Math.min(64, step - 6);
  const W = padL + step * buckets.length + 8;

  const totals = buckets.map(b => series.reduce((acc, s) => acc + (s.values[b] || 0), 0));
  const maxTotal = Math.max(...totals, 1);
  const plotH = H - padT - padB;
  const y = v => padT + plotH - (v / maxTotal) * plotH;

  const svgNS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(svgNS, 'svg');
  svg.setAttribute('width', W);
  svg.setAttribute('height', H);
  svg.setAttribute('role', 'img');
  svg.setAttribute('aria-label',
    `Time per project, ${buckets[0]} to ${buckets[buckets.length - 1]}, ` +
    `${series.length} series shown. The same figures are in the table that follows.`);

  const css = getComputedStyle(document.body);
  const gridColor = css.getPropertyValue('--chart-grid').trim() || '#2a2a30';
  const textMuted = css.getPropertyValue('--text-secondary').trim() || '#a8a8b0';

  // recessive horizontal grid + hour labels
  const ticks = 4;
  for (let t = 0; t <= ticks; t++) {
    const gy = y((maxTotal / ticks) * t);
    const line = document.createElementNS(svgNS, 'line');
    line.setAttribute('x1', padL);
    line.setAttribute('x2', W - 4);
    line.setAttribute('y1', gy);
    line.setAttribute('y2', gy);
    line.setAttribute('stroke', gridColor);
    svg.appendChild(line);
    const lbl = svgText(svg, padL - 6, gy + 4, chartFmtDur((maxTotal / ticks) * t), textMuted);
    lbl.setAttribute('text-anchor', 'end');
  }

  const maxBucketIdx = totals.indexOf(Math.max(...totals));
  const tooltip = getTooltip();
  const labelEvery = Math.ceil(buckets.length / 10);

  buckets.forEach((bucket, bi) => {
    const x = padL + step * bi + (step - barW) / 2;
    let cursor = padT + plotH; // baseline
    const segs = series
      .map(s => ({ s, v: s.values[bucket] || 0 }))
      .filter(o => o.v > 0)
      .sort((a, b) => b.v - a.v); // largest at the bottom of the stack
    segs.forEach((seg, k) => {
      const rawH = (seg.v / maxTotal) * plotH;
      const hPx = Math.max(rawH - (k > 0 ? gap : 0), 1);
      const yTop = cursor - (k > 0 ? gap : 0) - hPx;
      const isTop = k === segs.length - 1;
      const el = document.createElementNS(svgNS, 'path');
      el.setAttribute('d', roundedTopRect(x, yTop, barW, hPx, isTop ? 4 : 0));
      el.setAttribute('fill', colorOf(seg.s));
      el.setAttribute('class', 'seg');
      // Every segment is a real control: focusable, named, and activatable.
      el.setAttribute('tabindex', '0');
      el.setAttribute('role', 'button');
      const label = `${seg.s.name}, ${bucketLabel(bucket)}, ${fmtDur(seg.v)}` +
        (seg.s.id === -1
          ? `, ${folded.foldedCount} folded projects — activate to open`
          : ' — activate to filter the work log to this project and bucket');
      el.setAttribute('aria-label', label);
      const html = `<strong>${escHtml(seg.s.name)}</strong><br>` +
        `${bucketLabel(bucket)} · ${fmtDur(seg.v)}<br>` +
        `<span style="color:${textMuted}">bucket total ${fmtDur(totals[bi])}</span>` +
        (seg.s.id === -1
          ? `<br><span style="color:${textMuted}">${folded.foldedCount} projects — open</span>`
          : `<br><span style="color:${textMuted}">click to filter the work log</span>`);
      el.addEventListener('mousemove', e => showTooltipAt(tooltip, e.clientX, e.clientY, html));
      el.addEventListener('mouseleave', () => { tooltip.hidden = true; });
      // Keyboard users get the same information on focus, not only on hover.
      el.addEventListener('focus', () => {
        const r = el.getBoundingClientRect();
        showTooltipAt(tooltip, r.left + r.width / 2, r.top, html);
      });
      el.addEventListener('blur', () => { tooltip.hidden = true; });
      // "Other" opens itself; a real project narrows the work log to that bucket.
      const activate = seg.s.id === -1
        ? () => openOther(folded)
        : () => {
            if (typeof setCrossFilter === 'function') {
              setCrossFilter({
                projectId: seg.s.id,
                projectName: seg.s.name,
                bucket: currentBucket(),
                bucketKey: bucket,
              });
            }
          };
      el.addEventListener('click', activate);
      el.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
      });
      svg.appendChild(el);
      cursor = yTop;
    });
    // selective direct label: only the tallest bucket's total is printed
    if (bi === maxBucketIdx && totals[bi] > 0) {
      const lbl = svgText(svg, x + barW / 2, cursor - 5, chartFmtDur(totals[bi]), textMuted);
      lbl.setAttribute('text-anchor', 'middle');
      lbl.setAttribute('font-weight', '600');
    }
    if (bi % labelEvery === 0) {
      const lbl = svgText(svg, x + barW / 2, H - 8, bucketLabel(bucket), textMuted);
      lbl.setAttribute('text-anchor', 'middle');
    }
  });

  container.appendChild(svg);
  container.appendChild(buildDataTable(buckets, series));
  renderLegend(legendEl, folded, colorOf);
}

// A text alternative carrying the actual numbers, so the chart is not a dead
// end for anyone who cannot read the pixels.
function buildDataTable(buckets, series) {
  const wrap = document.createElement('div');
  wrap.className = 'visually-hidden';
  const head = series.map(s => `<th scope="col">${escHtml(s.name)}</th>`).join('');
  const rows = buckets.map(b => {
    const cells = series.map(s => `<td>${fmtDur(s.values[b] || 0)}</td>`).join('');
    return `<tr><th scope="row">${escHtml(bucketLabel(b))}</th>${cells}</tr>`;
  }).join('');
  wrap.innerHTML =
    `<table><caption>Time per project per bucket</caption>` +
    `<thead><tr><th scope="col">Bucket</th>${head}</tr></thead><tbody>${rows}</tbody></table>`;
  return wrap;
}

function buildDrillBar(folded) {
  const bar = document.createElement('p');
  bar.className = 'drill-bar';
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = '← All projects';
  btn.addEventListener('click', closeOther);
  bar.appendChild(btn);
  const span = document.createElement('span');
  span.className = 'muted';
  span.textContent = ` Showing the ${chartState.drill.length} smaller projects`;
  bar.appendChild(span);
  return bar;
}

function openOther(folded) {
  if (!folded.folded.length) return;
  chartState.drill = folded.folded;
  chartState.hidden.clear();
  repaint();
  announceChart(`Opened Other: ${folded.folded.length} smaller projects.`);
  const back = chartState.els.container.querySelector('.drill-bar button');
  if (back) back.focus();
}

function closeOther() {
  chartState.drill = null;
  chartState.hidden.clear();
  repaint();
  announceChart('Showing all projects.');
}

function announceChart(msg) {
  if (typeof announce === 'function') announce(msg);
}

function renderLegend(legendEl, folded, colorOf) {
  legendEl.innerHTML = '';
  folded.series.forEach(s => {
    const off = chartState.hidden.has(s.id);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'legend-item' + (off ? ' is-off' : '');
    btn.setAttribute('aria-pressed', String(!off));
    const share = s.id === -1 && folded.foldedShare
      ? ` — ${folded.foldedCount} projects · ${Math.round(folded.foldedShare * 100)}%`
      : '';
    btn.innerHTML =
      `<span class="swatch" style="background:${colorOf(s)}" aria-hidden="true"></span>` +
      `<span class="legend-name">${escHtml(s.name)}${escHtml(share)}</span>`;
    btn.addEventListener('click', () => {
      if (chartState.hidden.has(s.id)) chartState.hidden.delete(s.id);
      else chartState.hidden.add(s.id);
      repaint();
      announceChart(`${s.name} ${chartState.hidden.has(s.id) ? 'hidden' : 'shown'}.`);
    });
    legendEl.appendChild(btn);
  });

  if (folded.folded.length) {
    const open = document.createElement('button');
    open.type = 'button';
    open.className = 'legend-open';
    open.textContent = chartState.drill ? '← Back to all projects' : 'Open Other →';
    open.addEventListener('click', () => (chartState.drill ? closeOther() : openOther(folded)));
    legendEl.appendChild(open);
  }
}

function svgText(svg, x, yPos, content, fill) {
  const el = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  el.setAttribute('x', x);
  el.setAttribute('y', yPos);
  el.setAttribute('font-size', '11');
  el.setAttribute('fill', fill);
  el.textContent = content;
  svg.appendChild(el);
  return el;
}

function bucketLabel(b) {
  if (/^\d{4}-\d{2}-\d{2}$/.test(b)) return b.slice(5); // MM-DD
  if (b.includes('W')) return b.slice(5);               // W27
  return b;                                             // YYYY-MM
}

function roundedTopRect(x, y, w, h, r) {
  if (h <= 0) h = 1;
  r = Math.min(r, h, w / 2);
  if (r <= 0) return `M${x},${y} h${w} v${h} h${-w} Z`;
  return `M${x},${y + r} a${r},${r} 0 0 1 ${r},${-r} h${w - 2 * r} a${r},${r} 0 0 1 ${r},${r} v${h - r} h${-w} Z`;
}

function escHtml(s) {
  return String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

function getTooltip() {
  let tip = document.getElementById('chart-tooltip');
  if (!tip) {
    tip = document.createElement('div');
    tip.id = 'chart-tooltip';
    tip.hidden = true;
    Object.assign(tip.style, {
      position: 'fixed', zIndex: 20, pointerEvents: 'none',
      background: 'var(--raised)', border: '1px solid var(--line-control)',
      borderRadius: '8px', padding: '6px 10px', fontSize: '12px',
      boxShadow: '0 4px 6px -1px rgba(0,0,0,.3)', color: 'var(--text)',
    });
    document.body.appendChild(tip);
  }
  return tip;
}

function showTooltipAt(tip, cx, cy, html) {
  tip.innerHTML = html;
  tip.hidden = false;
  const pad = 12;
  let left = cx + pad, top = cy + pad;
  const rect = tip.getBoundingClientRect();
  if (left + rect.width > window.innerWidth - 8) left = cx - rect.width - pad;
  if (top + rect.height > window.innerHeight - 8) top = cy - rect.height - pad;
  tip.style.left = Math.max(8, left) + 'px';
  tip.style.top = Math.max(8, top) + 'px';
}
