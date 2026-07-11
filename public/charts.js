'use strict';

// Hand-rolled stacked bar chart (SVG, no libraries).
// Palette: validated 8-slot categorical set (dataviz reference instance),
// dark-surface steps only (app is dark-theme only); slot 5 is the brand
// indigo, re-validated against the #1e1e24 card surface. Max 8 series:
// top 7 + "Other".

const PALETTE = ['#3987e5', '#199e70', '#c98500', '#008300', '#6366f1', '#e66767', '#d55181', '#d95926'];
const OTHER_COLOR = '#6b7686';

function chartFmtDur(secs) {
  const h = secs / 3600;
  return h >= 10 ? Math.round(h) + 'h' : (Math.round(h * 10) / 10) + 'h';
}

// Stable color slots: project id modulo palette size, probed to be unique
// within the displayed set, so filtering doesn't repaint surviving series.
function assignSlots(series) {
  const taken = new Set();
  return series.map(s => {
    let slot = ((s.id % PALETTE.length) + PALETTE.length) % PALETTE.length;
    while (taken.has(slot)) slot = (slot + 1) % PALETTE.length;
    taken.add(slot);
    return slot;
  });
}

function foldSeries(data) {
  const { buckets, series } = data;
  if (series.length <= 8) return { buckets, series };
  const top = series.slice(0, 7);
  const other = { id: -1, name: 'Other', values: {} };
  for (const s of series.slice(7)) {
    for (const [k, v] of Object.entries(s.values)) {
      other.values[k] = (other.values[k] || 0) + v;
    }
  }
  return { buckets, series: [...top, other] };
}

function renderChart(container, legendEl, data) {
  const { buckets, series } = foldSeries(data);

  if (!buckets.length || !series.length) {
    container.innerHTML = '<p class="empty">No data for this range.</p>';
    legendEl.innerHTML = '';
    return;
  }

  const slots = assignSlots(series);
  const colorOf = i => series[i].id === -1 ? OTHER_COLOR : PALETTE[slots[i]];

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
  svg.setAttribute('aria-label', 'Stacked bar chart of time per project');

  const css = getComputedStyle(document.body);
  const gridColor = css.getPropertyValue('--chart-grid').trim() || '#2c2c33';
  const textMuted = css.getPropertyValue('--text-secondary').trim() || '#a1a1aa';

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
      .map((s, si) => ({ si, v: s.values[bucket] || 0 }))
      .filter(o => o.v > 0)
      .sort((a, b) => b.v - a.v); // largest at the bottom of the stack
    segs.forEach((seg, k) => {
      const rawH = (seg.v / maxTotal) * plotH;
      const hPx = Math.max(rawH - (k > 0 ? gap : 0), 1);
      const yTop = cursor - (k > 0 ? gap : 0) - hPx;
      const isTop = k === segs.length - 1;
      const el = document.createElementNS(svgNS, 'path');
      el.setAttribute('d', roundedTopRect(x, yTop, barW, hPx, isTop ? 4 : 0));
      el.setAttribute('fill', colorOf(seg.si));
      el.addEventListener('mousemove', e => showTooltip(tooltip, e,
        `<strong>${escHtml(series[seg.si].name)}</strong><br>` +
        `${bucketLabel(bucket)} · ${fmtDur(seg.v)}<br>` +
        `<span style="color:${textMuted}">bucket total ${fmtDur(totals[bi])}</span>`));
      el.addEventListener('mouseleave', () => { tooltip.hidden = true; });
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

  container.innerHTML = '';
  container.appendChild(svg);

  legendEl.innerHTML = series.map((s, i) =>
    `<span><span class="swatch" style="background:${colorOf(i)}"></span>${escHtml(s.name)}</span>`
  ).join('');
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
      background: 'var(--raised)', border: '1px solid var(--line-strong)',
      borderRadius: '8px', padding: '6px 10px', fontSize: '12px',
      boxShadow: '0 4px 6px -1px rgba(0,0,0,.3)', color: 'var(--text)',
    });
    document.body.appendChild(tip);
  }
  return tip;
}

function showTooltip(tip, e, html) {
  tip.innerHTML = html;
  tip.hidden = false;
  const pad = 12;
  let left = e.clientX + pad, top = e.clientY + pad;
  const rect = tip.getBoundingClientRect();
  if (left + rect.width > window.innerWidth - 8) left = e.clientX - rect.width - pad;
  if (top + rect.height > window.innerHeight - 8) top = e.clientY - rect.height - pad;
  tip.style.left = left + 'px';
  tip.style.top = top + 'px';
}
