// Generates the TalentTrack control room page from GitHub, the three
// environments and team.json. Usage: node gen.mjs  -> writes control-room.html
import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = 'caspernieuwenhuizen/talenttrack';
const MAIN = 'C:/Users/Casper.DESKTOP-3F5830C/Documents/GitHub/talenttrack';
const sh = (cmd) => execSync(cmd, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], maxBuffer: 1 << 26 });
const gh = (args) => JSON.parse(sh(`gh ${args}`));
const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const team = JSON.parse(readFileSync(join(here, 'team.json'), 'utf8'));

const issues = gh(`issue list --repo ${REPO} --state open --limit 200 --json number,title,labels,createdAt,updatedAt`)
  .map((i) => ({ ...i, labels: i.labels.map((l) => l.name) }));
const prs = gh(`pr list --repo ${REPO} --state open --json number,title,headRefName,isDraft,mergeStateStatus,headRefOid,labels,updatedAt`);
const releases = gh(`release list --repo ${REPO} --limit 6 --json tagName,publishedAt`);
const latest = releases[0];
for (const pr of prs) {
  try {
    const runs = gh(`api repos/${REPO}/commits/${pr.headRefOid}/check-runs --jq "[.check_runs[] | {status, conclusion}]"`);
    const done = runs.filter((r) => r.status === 'completed');
    const bad = done.filter((r) => ['failure', 'timed_out', 'cancelled', 'action_required'].includes(r.conclusion));
    pr.ci = bad.length ? `red (${bad.length} failed)` : done.length < runs.length ? `running (${done.length}/${runs.length})` : runs.length ? 'green' : 'no checks';
  } catch { pr.ci = 'unknown'; }
}
const envVersion = (host) => { try { return (sh(`curl -sS --max-time 15 https://${host}/wp-content/plugins/talenttrack/readme.txt`).match(/Stable tag:\s*([0-9.]+)/) || [])[1] || '?'; } catch { return 'unreachable'; } };
const localVersion = (readFileSync(join(MAIN, 'talenttrack.php'), 'utf8').match(/Version:\s+([0-9.]+)/) || [])[1] || '?';
const envs = [
  { n: 1, role: 'Test bench', host: 'local XAMPP WordPress', v: localVersion },
  { n: 2, role: 'Pilot (Leon Hutten)', host: 'sto.mediamaniacs.nl', v: envVersion('sto.mediamaniacs.nl') },
  { n: 3, role: 'Main live site', host: 'jg4it.mediamaniacs.nl', v: envVersion('jg4it.mediamaniacs.nl') },
];
let snippets = [];
try { snippets = sh(`git -C "${MAIN}" ls-tree --name-only origin/main changelog.d/`).split('\n').filter((f) => f.endsWith('.md') && !f.endsWith('README.md')); } catch {}
let baselineCount = '?', phpstanCount = '?';
try { const b = JSON.parse(readFileSync(join(MAIN, 'tests/e2e/mobile-baseline.json'), 'utf8')); baselineCount = Object.entries(b).filter(([k]) => !k.startsWith('_')).reduce((n, [, v]) => n + (Array.isArray(v) ? v.length : Object.keys(v).length), 0); } catch {}
try { phpstanCount = (readFileSync(join(MAIN, 'phpstan-baseline.neon'), 'utf8').match(/message:/g) || []).length; } catch {}

const has = (i, l) => i.labels.includes(l);
const ready = issues.filter((i) => has(i, 'ready-for-dev') && !has(i, 'epic'));
const inProgress = ready.filter((i) => has(i, 'in-progress'));
const unclaimed = ready.filter((i) => !has(i, 'in-progress'));
const unshaped = issues.filter((i) => !has(i, 'ready-for-dev') && !has(i, 'epic') && !has(i, 'in-progress'));
const epics = issues.filter((i) => has(i, 'epic'));
const lastThree = releases.slice(0, 3).map((r) => r.publishedAt).sort()[0];
const escaped = issues.filter((i) => has(i, 'bug') && has(i, 'from-hq') && i.createdAt > lastThree).length;
const lane = (i) => has(i, 'p1') ? 'P1' : has(i, 'tech-debt') ? 'P3' : 'P2';
const age = (iso) => { const d = Math.floor((Date.now() - new Date(iso)) / 864e5); return d === 0 ? 'today' : d === 1 ? '1 day' : `${d} days`; };
const row = (i) => `<tr><td class="n"><a href="https://github.com/${REPO}/issues/${i.number}">#${i.number}</a></td><td class="n"><span class="pill ${lane(i).toLowerCase()}">${lane(i)}</span></td><td class="t">${esc(i.title)}</td><td class="mono">${has(i, 'from-hq') ? 'HQ' : ''}</td><td class="mono">${age(i.createdAt)}</td></tr>`;
const now = new Date();
const stamp = now.toLocaleString('nl-NL', { timeZone: 'Europe/Amsterdam', dateStyle: 'medium', timeStyle: 'short' });
const openDecisions = team.decisions.filter((d) => d.state === 'open');
const answered = team.decisions.filter((d) => d.state !== 'open');

const html = `<title>TalentTrack Control Room</title>
<meta name="claude:viewport-fit-sides" content="cover">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@500;600;700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
/* Layout: a headline band, then a two-column grid of panels that stacks on a phone; one accent (pitch green), purple for anything that is the owner's. */
:root{--bg:#f6f7f4;--paper:#fff;--fg:#182019;--muted:#5b655d;--line:#d6dbd4;--accent:#1f6f43;--accent-soft:#e3efe6;--warn:#9a6400;--warn-soft:#fbf1dc;--bad:#a12d2d;--bad-soft:#f9e4e4;--you:#7a3e8f;--you-soft:#f1e8f5;--display:"Barlow Semi Condensed","Arial Narrow",Arial,sans-serif;--body:"IBM Plex Sans","Segoe UI",Roboto,sans-serif;--mono:"IBM Plex Mono",Consolas,monospace}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#131815;--paper:#1b211d;--fg:#e8ece8;--muted:#a2aca4;--line:#313a34;--accent:#5fbf85;--accent-soft:#1f3327;--warn:#e0b45a;--warn-soft:#3a3020;--bad:#e07a7a;--bad-soft:#3b2222;--you:#c598d6;--you-soft:#352a3b;color-scheme:dark}}
:root[data-theme="dark"]{--bg:#131815;--paper:#1b211d;--fg:#e8ece8;--muted:#a2aca4;--line:#313a34;--accent:#5fbf85;--accent-soft:#1f3327;--warn:#e0b45a;--warn-soft:#3a3020;--bad:#e07a7a;--bad-soft:#3b2222;--you:#c598d6;--you-soft:#352a3b;color-scheme:dark}
*{box-sizing:border-box}body{background:var(--bg);color:var(--fg);font-family:var(--body);font-size:15px;line-height:1.5}
.wrap{margin:0 auto;padding-block:1.5rem 3rem;padding-left:max(24px,env(safe-area-inset-left,0px));padding-right:max(24px,env(safe-area-inset-right,0px))}
h1,h2,h3{font-family:var(--display);line-height:1.1;margin:0;text-wrap:balance}h1{font-size:clamp(1.8rem,4vw,2.6rem);font-weight:700}h2{font-size:1.25rem;font-weight:600;border-bottom:2px solid var(--fg);padding-bottom:.35rem;margin-bottom:.75rem}
.eyebrow{font-family:var(--mono);font-size:.72rem;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}
.head{display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem 1.5rem;align-items:flex-end;border-bottom:2px solid var(--fg);padding-bottom:1rem}
.headline{margin:1rem 0 0;padding:1rem 1.1rem;background:var(--accent-soft);border-left:4px solid var(--accent);font-size:1.1rem}
.grid{display:grid;grid-template-columns:repeat(12,minmax(0,1fr));gap:1rem;margin-top:1.25rem}
.panel{background:var(--paper);border:1px solid var(--line);padding:1rem 1.1rem;min-width:0;grid-column:span 12}
@media (min-width:1024px){.p-dec{grid-column:span 6}.p-env{grid-column:span 6}.p-queue{grid-column:span 7}.p-pr{grid-column:span 5}.p-q{grid-column:span 12}}
@media (min-width:1440px){.p-dec{grid-column:span 4}.p-env{grid-column:span 4}.p-q{grid-column:span 4}.p-queue{grid-column:span 7}.p-pr{grid-column:span 5}}
.panel.you{border-color:var(--you)}.panel.you h2{border-color:var(--you)}
table{width:100%;border-collapse:collapse;font-size:.92rem}.tbl{overflow-x:auto}th,td{text-align:left;vertical-align:top;padding:.4rem .5rem;border-bottom:1px solid var(--line)}th{font-family:var(--display);font-size:.95rem}td a{color:var(--accent);font-family:var(--mono);font-size:.85rem;text-decoration:none}td.t{width:100%;min-width:18rem}td.n{white-space:nowrap}
.mono{font-family:var(--mono);font-size:.82rem;color:var(--muted);white-space:nowrap}
.pill{display:inline-block;font-family:var(--mono);font-size:.7rem;padding:.05rem .45rem;border-radius:999px;border:1px solid var(--line);white-space:nowrap}.pill.p1{border-color:var(--bad);color:var(--bad);background:var(--bad-soft)}.pill.p2{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}.pill.p3{color:var(--muted)}
.pill.live{border-color:var(--accent);color:var(--accent);background:var(--accent-soft)}.pill.open{border-color:var(--warn);color:var(--warn);background:var(--warn-soft)}
.envs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.6rem}@media (max-width:480px){.envs{grid-template-columns:1fr}}.env{border:1px solid var(--line);padding:.7rem .8rem}.env .v{font-family:var(--display);font-size:1.5rem;font-weight:600}.env .v.behind{color:var(--warn)}.env .host{font-family:var(--mono);font-size:.78rem;color:var(--muted);word-break:break-all}
.seats{display:grid;gap:.5rem}.seat{display:grid;grid-template-columns:auto 1fr;gap:.2rem .8rem;align-items:baseline;padding:.5rem 0;border-bottom:1px solid var(--line)}.seat:last-child{border-bottom:0}.seat .who{font-family:var(--display);font-weight:600;font-size:1.05rem}.seat .who small{display:block;font-family:var(--body);font-weight:400;font-size:.8rem;color:var(--muted)}.seat .now{font-size:.9rem}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.6rem}.kpi{border:1px solid var(--line);padding:.6rem .8rem}.kpi .n{font-family:var(--display);font-size:1.6rem;font-weight:600;font-variant-numeric:tabular-nums}.kpi .l{font-size:.8rem;color:var(--muted)}
.dec{padding:.7rem 0;border-bottom:1px solid var(--line)}.dec:last-child{border-bottom:0}.dec .q{font-weight:500}.dec .r{font-size:.9rem;margin-top:.25rem}.dec .r b{color:var(--you)}
.foot{margin-top:2rem;font-size:.85rem;color:var(--muted)}
</style>
<div class="wrap">
  <div class="head"><div><div class="eyebrow">TalentTrack · delivery team</div><h1>Control room</h1></div><div class="mono">generated ${esc(stamp)} · refreshed by the lead</div></div>
  <p class="headline">${esc(team.headline)}</p>
  <div class="grid">
    <section class="panel you p-dec"><h2>Decisions for you · ${openDecisions.length} open</h2>
      ${openDecisions.map((d) => `<div class="dec"><span class="eyebrow">${esc(d.id)} · asked ${esc(d.asked)}</span><div class="q">${esc(d.question)}</div><div class="r"><b>Recommendation:</b> ${esc(d.recommendation)}</div><div class="r"><b>If nobody answers:</b> ${esc(d.ifSilent)}</div></div>`).join('') || '<p>Nothing waits on you.</p>'}
      <p class="mono" style="margin:.75rem 0 0">Answer in the lead's session (Joris). Answered this week: ${answered.map((d) => esc(d.id)).join(', ') || 'none'}.</p>
    </section>
    <section class="panel p-env"><h2>Environments</h2><div class="envs">${envs.map((e) => `<div class="env"><div class="eyebrow">${e.n} · ${esc(e.role)}</div><div class="v ${latest && e.v !== latest.tagName.replace(/^v/, '') ? 'behind' : ''}">${esc(e.v)}</div><div class="host">${esc(e.host)}</div></div>`).join('')}</div>
      <p class="mono" style="margin:.75rem 0 0">Latest release ${esc(latest?.tagName)} · ${esc(latest ? new Date(latest.publishedAt).toLocaleDateString('nl-NL') : '')} · unreleased snippets on main: ${snippets.length}${snippets.length ? ` (${snippets.map((s) => esc(s.replace('changelog.d/', ''))).join(', ')})` : ''}</p></section>
    <section class="panel p-q"><h2>Quality signals</h2>
      <div class="kpis"><div class="kpi"><div class="n">${escaped}</div><div class="l">HQ bugs since the last 3 releases</div></div><div class="kpi"><div class="n">${baselineCount}</div><div class="l">mobile gate baseline entries</div></div><div class="kpi"><div class="n">${phpstanCount}</div><div class="l">PHPStan baseline entries</div></div><div class="kpi"><div class="n">–</div><div class="l">re-failed fixes (first HQ run pending)</div></div></div></section>
    <section class="panel p-team"><h2>Team</h2><div class="seats">${team.seats.map((s) => `<div class="seat"><div class="who">${esc(s.name)}<small>${esc(s.seat)}</small></div><div class="now"><span class="pill ${s.state === 'live' ? 'live' : 'open'}">${esc(s.state)}</span> ${esc(s.now)}</div></div>`).join('')}</div></section>
    <section class="panel p-queue"><h2>Queue · ${ready.length} ready</h2>
      <div class="kpis"><div class="kpi"><div class="n">${unclaimed.length}</div><div class="l">ready, unclaimed</div></div><div class="kpi"><div class="n">${inProgress.length}</div><div class="l">in progress</div></div><div class="kpi"><div class="n">${unshaped.length}</div><div class="l">waiting for shaping</div></div><div class="kpi"><div class="n">${epics.length}</div><div class="l">open epics</div></div></div>
      <div class="tbl"><table><thead><tr><th>Issue</th><th>Lane</th><th>Title</th><th></th><th>Age</th></tr></thead><tbody>${[...inProgress, ...unclaimed].sort((a, b) => a.createdAt < b.createdAt ? -1 : 1).map(row).join('')}</tbody></table></div></section>
    <section class="panel p-pr"><h2>Pull requests · ${prs.length} open</h2>
      <div class="tbl"><table><thead><tr><th>PR</th><th>Title</th><th>CI</th><th>Merge</th></tr></thead><tbody>${prs.map((p) => `<tr><td class="n"><a href="https://github.com/${REPO}/pull/${p.number}">#${p.number}</a></td><td class="t">${esc(p.title)}${p.isDraft ? ' <span class="pill">draft</span>' : ''}</td><td class="mono">${esc(p.ci)}</td><td class="mono">${esc(p.mergeStateStatus.toLowerCase())}</td></tr>`).join('') || '<tr><td colspan="4">None.</td></tr>'}</tbody></table></div></section>
    <section class="panel p-unshaped"><h2>Waiting for shaping · ${unshaped.length}</h2>
      <div class="tbl"><table><thead><tr><th>Issue</th><th>Lane</th><th>Title</th><th></th><th>Age</th></tr></thead><tbody>${unshaped.sort((a, b) => a.createdAt < b.createdAt ? -1 : 1).map(row).join('') || '<tr><td colspan="5">Nothing.</td></tr>'}</tbody></table></div></section>
  </div>
  <p class="foot">Everything above is read from GitHub, the three installs and the lead's team.json. The roadmap is <a href="https://github.com/${REPO}/blob/main/docs/roadmap.md">docs/roadmap.md</a>; the charter is <a href="https://github.com/${REPO}/blob/main/docs/team/README.md">docs/team/README.md</a>.</p>
</div>`;
writeFileSync(join(here, 'control-room.html'), html);
console.log(`control-room.html written: ${issues.length} issues, ${prs.length} PRs, envs ${envs.map((e) => e.v).join('/')}`);
