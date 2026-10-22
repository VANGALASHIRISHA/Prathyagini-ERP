// Shared by the responsive audits. probe(width) runs INSIDE the browser and returns a list of layout problems.
function probe(width) {
  const problems = [];
  const vw = document.documentElement.clientWidth;
  const over = document.documentElement.scrollWidth - vw;
  if (over > 1) problems.push(`page scrolls sideways by ${over}px`);
  const visible = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && !el.closest('[hidden],template'); };
  const inScroller = (el) => { for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if ((o === 'auto' || o === 'scroll') && p.scrollWidth > p.clientWidth + 1) return true; } return false; };
  const sel = 'button, a.btn, input[type=submit], input[type=button], .btn, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=file]), textarea, .tile, .side a, .nav-toggle';
  const seen = new Set();
  document.querySelectorAll(sel).forEach((el) => {
    if (!visible(el) || el.closest('.side') && width > 860 && false) return;
    const r = el.getBoundingClientRect();
    const name = (el.innerText || el.value || el.name || el.id || el.className || el.tagName).toString().trim().slice(0, 28);
    const key = el.tagName + name;
    const isCtl = el.matches('button, a.btn, input[type=submit], input[type=button], .btn, .nav-toggle, .side a, select, input, textarea');
    if (width <= 768 && isCtl && r.height < 36 && !el.closest('table')) problems.push(`tap target too short (${Math.round(r.height)}px): "${name}"`);
    if (width <= 768 && isCtl && r.width < 32 && !el.matches('input')) problems.push(`tap target too narrow (${Math.round(r.width)}px): "${name}"`);
    if (width <= 768 && el.matches('input:not([type=checkbox]):not([type=radio]), select, textarea') && parseFloat(getComputedStyle(el).fontSize) < 16) { if (!seen.has('fs' + key)) { seen.add('fs' + key); problems.push(`font under 16px (iOS zooms the page): ${el.tagName.toLowerCase()} "${name}"`); } }
    if (!inScroller(el) && !el.closest('.side') && (r.right > vw + 1 || r.left < -1)) problems.push(`control cut off by the screen edge: "${name}" (${Math.round(r.left)}..${Math.round(r.right)} of ${vw})`);
    if (el.matches('button, a.btn, .btn') && el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflow !== 'visible') problems.push(`button text clipped: "${name}"`);
  });
  // text that spills out of its box
  document.querySelectorAll('.panel, .card, .tile, .notice, td, th, h1, h2, h3, label').forEach((el) => {
    if (!visible(el) || inScroller(el) || el.closest('table')) return;
    if (el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflowX === 'visible') {
      const r = el.getBoundingClientRect();
      if (r.right > vw + 1) problems.push(`content spills past the screen: <${el.tagName.toLowerCase()}> "${(el.innerText || '').trim().slice(0, 28)}"`);
    }
  });
  // overlapping neighbouring buttons in the same row
  const btns = [...document.querySelectorAll('button, a.btn, .btn')].filter(visible);
  for (let i = 0; i < btns.length; i++) for (let j = i + 1; j < btns.length; j++) {
    if (btns[i].contains(btns[j]) || btns[j].contains(btns[i]) || btns[i].closest('.side') || btns[i].closest('td')) continue;
    const a = btns[i].getBoundingClientRect(), b = btns[j].getBoundingClientRect();
    const ox = Math.min(a.right, b.right) - Math.max(a.left, b.left), oy = Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top);
    if (ox > 3 && oy > 3) { problems.push(`buttons overlap: "${(btns[i].innerText || '').trim().slice(0, 20)}" and "${(btns[j].innerText || '').trim().slice(0, 20)}"`); break; }
  }
  const hdr = document.querySelector('header');
  if (hdr && hdr.scrollWidth > hdr.clientWidth + 2) problems.push('header content overflows');
  return [...new Set(problems)];
}

module.exports = probe;
