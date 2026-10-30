// Generates the TalentTrack roadmap page from docs/roadmap.md plus the live
// state of every issue it links. Usage: node gen.mjs  -> writes roadmap.html
//
// docs/roadmap.md stays the source; this page is a view on it. Each table row
// becomes a card. Progress per row is read from GitHub:
//   - a row that links several issues counts those issues;
//   - a row that links one epic counts the issues that reference it;
//   - a row that names the `from-hq` label counts that label since ROUND_SINCE.
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = 'caspernieuwenhuizen/talenttrack';
const ROOT = join(here, '..', '..', '..');
const ROUND_SINCE = '2026-09-27';
const PILOT_SINCE = '2026-09-22';
const CONTROL_ROOM = 'https://claude.ai/artifact/W3kyHMsDXBECvyCpKKcvJN';

const sh = (cmd) => execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 1 << 26 });
const gh = (args) => JSON.parse(sh(`gh ${args}`));
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ---- parse docs/roadmap.md -------------------------------------------------
const md = readFileSync(join(ROOT, 'docs', 'roadmap.md'), 'utf8').replace(/\r/g, '');
const body = md.replace(/^---[\s\S]*?---\n/, '');
const reviewed = (body.match(/Last reviewed:\s*([0-9-]+)/) || [])[1] || '';
const sections = {};
let current = null;
for (const line of body.split('\n')) {
  const h = line.match(/^##\s+(.+)/);
  if (h) { current = h[1].trim(); sections[current] = { text: [], rows: [] }; continue; }
  if (!current) continue;
  if (line.startsWith('|')) {
    const cells = line.split('|').slice(1, -1).map((c) => c.trim());
    if (cells.every((c) => /^-+$/.test(c))) continue;
    sections[current].rows.push(cells);
  } else if (line.trim()) {
    sections[current].text.push(line.trim());
  }
}
for (const s of Object.values(sections)) s.rows = s.rows.slice(1); // drop the header row

const inline = (s) => esc(s)
  .replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g, '<a href="$2">$1</a>')
  .replace(/`([^`]+)`/g, '<code>$1</code>');
const plain = (s) => String(s).replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/`/g, '');
const issueNumbers = (s) => [...new Set([...String(s).matchAll(/issues\/(\d+)|#(\d{3,5})/g)].map((m) => Number(m[1] || m[2])))];

// ---- live issue state ------------------------------------------------------
const stateCache = new Map();
const issueState = (n) => {
  if (!stateCache.has(n)) {
    try {
      const i = gh(`issue view ${n} --repo ${REPO} --json number,state,title,labels`);
      stateCache.set(n, { n, state: i.state, title: i.title, labels: i.labels.map((l) => l.name) });
    } catch { stateCache.set(n, { n, state: 'UNKNOWN', title: '', labels: [] }); }
  }
  return stateCache.get(n);
};
const classify = (i) => i.state === 'CLOSED' ? 'done' : i.labels.includes('in-progress') ? 'doing' : 'todo';

const progressFor = (epicCell) => {
  if (/from-hq/.test(epicCell)) {
    const list = gh(`issue list --repo ${REPO} --label from-hq --state all --search "created:>=${ROUND_SINCE}" --limit 300 --json number,state,title,labels`)
      .map((i) => ({ n: i.number, state: i.state, title: i.title, labels: i.labels.map((l) => l.name) }));
    return { items: list, basis: `from-hq issues since ${ROUND_SINCE}` };
  }
  const nums = issueNumbers(epicCell);
  if (nums.length === 1) {
    const epic = issueState(nums[0]);
    if (/epic/i.test(epic.title) || epic.labels.includes('epic')) {
      const kids = gh(`issue list --repo ${REPO} --search "\\"#${nums[0]}\\"" --state all --limit 100 --json number,state,title,labels`)
        .filter((i) => i.number !== nums[0])
        .map((i) => ({ n: i.number, state: i.state, title: i.title, labels: i.labels.map((l) => l.name) }));
      if (kids.length) return { items: kids, basis: `issues linked to #${nums[0]}` };
    }
  }
  if (nums.length) return { items: nums.map(issueState), basis: 'the issues named here' };
  return null;
};

const card = (row, opts = {}) => {
  const [epic, value, state] = row;
  const p = opts.progress === false ? null : progressFor(epic);
  let bar = '', chips = '', count = '';
  if (p && p.items.length) {
    const c = { done: 0, doing: 0, todo: 0 };
    for (const i of p.items) c[classify(i)]++;
    const total = p.items.length;
    const pct = (k) => ((c[k] / total) * 100).toFixed(2);
    bar = `<div class="bar" role="img" aria-label="${c.done} of ${total} done, ${c.doing} in progress"><span class="b-done" style="width:${pct('done')}%"></span><span class="b-doing" style="width:${pct('doing')}%"></span></div>`;
    count = `<div class="count"><b>${c.done}</b>/${total} done<span>${c.doing ? ` · ${c.doing} in progress` : ''}</span></div>`;
    if (total <= 14) {
      chips = '<ul class="chips">' + p.items.sort((a, b) => a.n - b.n).map((i) =>
        `<li><a class="chip ${classify(i)}" href="https://github.com/${REPO}/issues/${i.n}" title="${esc(i.title)}">#${i.n}</a></li>`).join('') + '</ul>';
    }
    count = count.replace('</div>', `<small>${esc(p.basis)}</small></div>`);
  }
  return `<article class="epic">
    <h3>${inline(epic)}</h3>
    <p class="value">${inline(value)}</p>
    ${bar}${count}
    <p class="state">${inline(state)}</p>
    ${chips}
  </article>`;
};

const column = (name, cls, note) => {
  const s = sections[name];
  if (!s) return '';
  return `<section class="col ${cls}" aria-labelledby="h-${cls}">
    <header><h2 id="h-${cls}">${esc(name)}</h2><span class="n">${s.rows.length}</span></header>
    <p class="note">${esc(note)}</p>
    <div class="cards">${s.rows.map((r) => card(r, { progress: cls !== 'later' })).join('')}</div>
  </section>`;
};

const direction = (sections['Direction']?.text || []).join(' ');
const queueNote = (sections['How the queue reads']?.text || []).join(' ');
const decisions = sections['Decided this cycle']?.rows || [];
const shipped = sections['Shipped recently']?.rows || [];

let ready = '?', backlog = '?', latest = '?';
try { ready = gh(`issue list --repo ${REPO} --label ready-for-dev --state open --limit 300 --json number`).length; } catch {}
try { backlog = gh(`issue list --repo ${REPO} --label backlog --state open --limit 300 --json number`).length; } catch {}
try { latest = gh(`release list --repo ${REPO} --limit 1 --json tagName`)[0].tagName; } catch {}

const today = new Date();
const pilotDays = Math.floor((today - new Date(PILOT_SINCE)) / 86400000);
const generated = today.toLocaleString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const html = `<title>TalentTrack Roadmap</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: a pitch-green direction band, then Now / Next / Later as three columns read left to right like a season plan (stacked on a phone), then decisions and shipped work. Same palette and faces as the control room, so the two read as one product. */
:root{--bg:#f6f7f4;--paper:#fff;--fg:#182019;--muted:#5b655d;--line:#d6dbd4;--accent:#1f6f43;--accent-soft:#e3efe6;--doing:#b07a12;--doing-soft:#fbf1dc;--todo:#c9cfc8;--you:#7a3e8f;--you-soft:#f1e8f5;--band:#1d5a38;--band-fg:#f2f7f1;--band-line:rgba(255,255,255,.22);
--display:"Barlow Semi Condensed","Arial Narrow",system-ui,sans-serif;--body:"IBM Plex Sans",system-ui,sans-serif;--mono:"IBM Plex Mono",ui-monospace,Menlo,monospace}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#131815;--paper:#1b211d;--fg:#e8ece8;--muted:#a2aca4;--line:#313a34;--accent:#5fbf85;--accent-soft:#1f3327;--doing:#e0b45a;--doing-soft:#3a3020;--todo:#3d4640;--you:#c598d6;--you-soft:#2e2233;--band:#16301f;--band-fg:#e8f1ea;--band-line:rgba(255,255,255,.14);color-scheme:dark}}
:root[data-theme="dark"]{--bg:#131815;--paper:#1b211d;--fg:#e8ece8;--muted:#a2aca4;--line:#313a34;--accent:#5fbf85;--accent-soft:#1f3327;--doing:#e0b45a;--doing-soft:#3a3020;--todo:#3d4640;--you:#c598d6;--you-soft:#2e2233;--band:#16301f;--band-fg:#e8f1ea;--band-line:rgba(255,255,255,.14);color-scheme:dark}
*{box-sizing:border-box}
body{background:var(--bg);color:var(--fg);font-family:var(--body);font-size:15px;line-height:1.5}
a{color:var(--accent)}a:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
code{font-family:var(--mono);font-size:.85em;background:var(--accent-soft);padding:0 .25em}
.inner{max-width:1600px;margin:0 auto;padding-left:max(24px,env(safe-area-inset-left,0px));padding-right:max(24px,env(safe-area-inset-right,0px))}
h1,h2,h3{font-family:var(--display);line-height:1.1;margin:0;text-wrap:balance}
.eyebrow{font-family:var(--mono);font-size:.72rem;letter-spacing:.08em;text-transform:uppercase}

.band{background:var(--band);color:var(--band-fg);position:relative;overflow:hidden}
.band .inner{padding-block:1.6rem 1.8rem;position:relative;display:grid;gap:1rem}
.band svg{position:absolute;right:-60px;top:50%;transform:translateY(-50%);width:420px;height:420px;opacity:.9;pointer-events:none}
.band .top{display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem 1.5rem;align-items:baseline}
.band .eyebrow{color:var(--band-fg);opacity:.8}
.band h1{font-size:clamp(2rem,5vw,3.2rem);font-weight:700;letter-spacing:.01em}
.band .links{display:flex;flex-wrap:wrap;gap:.5rem}
.band .links a{color:var(--band-fg);border:1px solid var(--band-line);padding:.45rem .8rem;min-height:2.5rem;display:inline-flex;align-items:center;text-decoration:none;font-weight:500}
.band .links a:hover{background:rgba(255,255,255,.08)}
.direction{max-width:62ch;font-size:clamp(1.05rem,1.6vw,1.25rem);line-height:1.45;margin:0}
.facts{display:flex;flex-wrap:wrap;gap:.5rem 2rem;margin:0;padding:0;list-style:none}
.facts li{display:grid}.facts b{font-family:var(--display);font-size:1.7rem;font-weight:600;font-variant-numeric:tabular-nums;line-height:1}
.facts span{font-size:.8rem;opacity:.8}

main .inner{padding-block:1.6rem 3rem;display:grid;gap:2rem}
.season{display:grid;grid-template-columns:minmax(0,1fr);gap:1.25rem}
@media (min-width:1024px){.season{grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) minmax(0,.8fr)}}
.col header{display:flex;align-items:baseline;gap:.6rem;border-bottom:3px solid var(--fg);padding-bottom:.4rem}
.col h2{font-size:1.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.col .n{font-family:var(--mono);font-size:.8rem;color:var(--muted)}
.col.now header{border-color:var(--accent)}.col.now h2{color:var(--accent)}
.col.later h2{color:var(--muted)}.col.later header{border-color:var(--line)}
.note{color:var(--muted);font-size:.85rem;margin:.5rem 0 .9rem}
.cards{display:grid;gap:.9rem}
.epic{background:var(--paper);border:1px solid var(--line);padding:1rem 1.1rem 1.05rem;display:grid;gap:.55rem;min-width:0}
.col.now .epic{border-top:3px solid var(--accent)}
.col.later .epic{background:transparent}
.epic h3{font-size:1.15rem;font-weight:600}
.epic h3 a{color:inherit;text-decoration-color:var(--line)}
.value{margin:0;font-size:.98rem}
.state{margin:0;font-size:.85rem;color:var(--muted)}
.bar{display:flex;height:.55rem;background:var(--todo);overflow:hidden}
.bar span{display:block;height:100%}.b-done{background:var(--accent)}.b-doing{background:var(--doing)}
.count{font-size:.85rem;display:flex;flex-wrap:wrap;gap:.1rem .4rem;align-items:baseline;font-variant-numeric:tabular-nums}
.count b{font-family:var(--display);font-size:1.15rem}.count span{color:var(--doing)}
.count small{flex-basis:100%;font-family:var(--mono);font-size:.68rem;color:var(--muted)}
.chips{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.35rem}
.chip{display:inline-flex;align-items:center;min-height:1.9rem;font-family:var(--mono);font-size:.75rem;padding:0 .5rem;border:1px solid var(--line);text-decoration:none;color:var(--muted);font-variant-numeric:tabular-nums}
.chip.done{border-color:var(--accent);background:var(--accent-soft);color:var(--accent)}
.chip.doing{border-color:var(--doing);background:var(--doing-soft);color:var(--doing)}
@media (pointer:coarse){.chip{min-height:3rem;padding:0 .8rem}.band .links a{min-height:3rem}}
.legend{display:flex;flex-wrap:wrap;gap:1rem;font-size:.8rem;color:var(--muted);margin:0;padding:0;list-style:none}
.legend i{display:inline-block;width:.8rem;height:.55rem;margin-right:.35rem;vertical-align:middle}
.legend .l-done{background:var(--accent)}.legend .l-doing{background:var(--doing)}.legend .l-todo{background:var(--todo)}

.lower{display:grid;gap:1.25rem;grid-template-columns:minmax(0,1fr)}
@media (min-width:1024px){.lower{grid-template-columns:minmax(0,1.2fr) minmax(0,1fr)}}
.block h2{font-size:1.25rem;font-weight:600;border-bottom:2px solid var(--fg);padding-bottom:.35rem;margin-bottom:.4rem}
.block.you h2{border-color:var(--you);color:var(--you)}
.dec{display:grid;gap:.15rem;padding:.75rem 0 .75rem .9rem;border-left:3px solid var(--you);margin:.6rem 0;background:linear-gradient(90deg,var(--you-soft),transparent 70%)}
.dec b{font-weight:600}.dec p{margin:0;font-size:.92rem;color:var(--muted)}
.ship{list-style:none;margin:0;padding:0}
.ship li{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:.2rem 1rem;padding:.6rem 0;border-bottom:1px solid var(--line);align-items:baseline}
.ship li:last-child{border-bottom:0}
.ship .rel{font-family:var(--mono);font-size:.78rem;color:var(--accent);text-align:right}
.queue{font-size:.88rem;color:var(--muted);max-width:75ch;margin:0}
.foot{font-size:.8rem;color:var(--muted);border-top:1px solid var(--line);padding-top:1rem;margin:0}
@media (max-width:480px){.band svg{display:none}.ship li{grid-template-columns:1fr}.ship .rel{text-align:left}}
</style>

<header class="band">
  <svg viewBox="0 0 420 420" aria-hidden="true" fill="none" stroke="var(--band-line)" stroke-width="2">
    <rect x="10" y="10" width="400" height="400"/><line x1="10" y1="210" x2="410" y2="210"/><circle cx="210" cy="210" r="62"/><circle cx="210" cy="210" r="3" fill="var(--band-line)"/>
    <rect x="120" y="10" width="180" height="70"/><rect x="120" y="340" width="180" height="70"/>
  </svg>
  <div class="inner">
    <div class="top">
      <div><div class="eyebrow">TalentTrack · product roadmap</div><h1>Roadmap</h1></div>
      <nav class="links" aria-label="Related pages"><a href="${CONTROL_ROOM}">Control room</a><a href="https://github.com/${REPO}/blob/main/docs/roadmap.md">Source on GitHub</a></nav>
    </div>
    <p class="direction">${inline(direction)}</p>
    <ul class="facts">
      <li><b>${pilotDays}</b><span>days into the pilot</span></li>
      <li><b>${esc(latest)}</b><span>latest release</span></li>
      <li><b>${ready}</b><span>ready for a developer</span></li>
      <li><b>${backlog}</b><span>in the backlog</span></li>
    </ul>
  </div>
</header>

<main>
  <div class="inner">
    <div class="season">
      ${column('Now', 'now', 'Being built this cycle.')}
      ${column('Next', 'next', 'Starts when a lane frees up.')}
      ${column('Later', 'later', 'Agreed, not yet scheduled.')}
    </div>
    <ul class="legend" aria-label="Progress legend"><li><i class="l-done"></i>done</li><li><i class="l-doing"></i>in progress</li><li><i class="l-todo"></i>to do</li></ul>
    <div class="lower">
      <section class="block you"><h2>Decided this cycle</h2>
        ${decisions.map(([d, v]) => `<div class="dec"><b>${inline(d)}</b><p>${inline(v)}</p></div>`).join('')}
      </section>
      <section class="block"><h2>Shipped recently</h2>
        <ul class="ship">${shipped.map(([e, r]) => `<li><span>${inline(e)}</span><span class="rel">${inline(r)}</span></li>`).join('')}</ul>
      </section>
    </div>
    <p class="queue">${inline(queueNote)}</p>
    <p class="foot">Generated ${esc(generated)} from <code>docs/roadmap.md</code> (last reviewed ${esc(reviewed)}) and the live state of every linked issue. The product owner keeps the file true; this page is regenerated from it.</p>
  </div>
</main>
`;
writeFileSync(join(here, 'roadmap.html'), html);
console.log('roadmap.html written');
