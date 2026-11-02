// The control room's live questions panel. The lead writes questions into the
// artifact's `questions` collection; the owner answers them on the page; the
// lead reads the answers back. The panel renders a static fallback (the open
// decisions from team.json) until the store answers, so the page still works
// where the db capability is not available.

export const QUESTIONS_CSS = `
.qs{display:grid;gap:.9rem}
.qcard{border:1px solid var(--you);background:var(--you-soft);padding:.8rem .9rem}
.qcard .eyebrow{color:var(--you)}
.qcard .q{font-weight:600;margin:.2rem 0 .35rem}
.qcard .ctx{font-size:.9rem;color:var(--muted);margin:0 0 .5rem;white-space:pre-line}
.qopts{display:grid;gap:.5rem}
.qopt{display:block;width:100%;text-align:left;min-height:48px;padding:.6rem .75rem;border:1px solid var(--line);background:var(--paper);color:var(--fg);font:inherit;cursor:pointer;touch-action:manipulation}
.qopt:hover{border-color:var(--you)}
.qopt:active{background:var(--you-soft)}
.qopt:focus-visible{outline:3px solid var(--you);outline-offset:2px}
.qopt[aria-pressed="true"]{border:2px solid var(--you)}
.qopt .lbl{font-weight:600}
.qopt .rec{font-family:var(--mono);font-size:.7rem;color:var(--you);margin-left:.4rem}
.qopt .desc{display:block;font-size:.85rem;color:var(--muted);margin-top:.15rem}
.qnote{width:100%;min-height:48px;margin-top:.5rem;padding:.5rem;font:inherit;font-size:16px;border:1px solid var(--line);background:var(--paper);color:var(--fg)}
.qsend{margin-top:.5rem;min-height:48px;padding:.5rem 1rem;border:0;background:var(--you);color:#fff;font:inherit;font-weight:600;cursor:pointer;touch-action:manipulation}
.qsend:disabled{opacity:.5;cursor:default}
.qsend:focus-visible{outline:3px solid var(--fg);outline-offset:2px}
.qstatus{font-family:var(--mono);font-size:.78rem;color:var(--muted);margin-top:.4rem}
.qdone{font-size:.88rem;padding:.4rem 0;border-bottom:1px solid var(--line)}
.qdone b{color:var(--you)}
@media (prefers-reduced-motion:no-preference){.qcard{transition:opacity .2s}}
`;

// Plain script, no template literals inside, so it can live in the page as is.
export const QUESTIONS_JS = `
(function(){
  var box = document.getElementById('tt-questions');
  var doneBox = document.getElementById('tt-answered');
  var note = document.getElementById('tt-q-note');
  if (!box || !window.claude || typeof window.claude.use !== 'function') return;
  function el(tag, cls, text){ var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  Promise.all([window.claude.use('db'), window.claude.use('user')]).then(function(r){
    var db = r[0], user = r[1];
    if (!db) return;
    var isOwner = false;
    try { isOwner = user ? !!user.isOwner() : false; } catch (e) {}
    var timer = setTimeout(function(){ if (note) note.textContent = 'Live questions could not be reached yet; showing the last generated list.'; }, 10000);
    var picks = {};
    var busy = {};
    db.collection('questions').onSnapshot(function(snap){
      if (!snap.metadata.fromCache) { clearTimeout(timer); if (note) note.textContent = ''; }
      var open = [], done = [];
      snap.docs.forEach(function(d){ var q = d.data(); if (!q) return; q._id = d.id; if (q.state === 'answered') done.push(q); else open.push(q); });
      open.sort(function(a,b){ return String(a.asked||'') < String(b.asked||'') ? -1 : 1; });
      done.sort(function(a,b){ return String(b.answeredAt||'') < String(a.answeredAt||'') ? -1 : 1; });
      render(open, done);
    }, function(err){ clearTimeout(timer); if (note) note.textContent = 'Live questions are not available in this view.'; });
    function render(open, done){
      var h = document.getElementById('tt-q-count');
      if (h) h.textContent = String(open.length);
      box.textContent = '';
      if (!open.length) { box.appendChild(el('p', null, 'Nothing waits on you.')); }
      open.forEach(function(q){
        var card = el('div', 'qcard');
        card.appendChild(el('div', 'eyebrow', (q.header || 'Question') + ' · asked ' + (q.asked || '')));
        card.appendChild(el('div', 'q', q.question || ''));
        if (q.context) card.appendChild(el('p', 'ctx', q.context));
        var opts = el('div', 'qopts');
        (q.options || []).forEach(function(o){
          var b = el('button', 'qopt'); b.type = 'button';
          b.setAttribute('aria-pressed', picks[q._id] === o.label ? 'true' : 'false');
          var l = el('span', 'lbl', o.label); b.appendChild(l);
          if (o.recommended) b.appendChild(el('span', 'rec', 'recommended'));
          if (o.description) b.appendChild(el('span', 'desc', o.description));
          b.disabled = !isOwner;
          b.addEventListener('click', function(){ picks[q._id] = o.label; render(open, done); });
          opts.appendChild(b);
        });
        card.appendChild(opts);
        var ta = el('textarea', 'qnote'); ta.placeholder = 'Optional note or another answer'; ta.id = 'n-' + q._id; ta.setAttribute('aria-label', 'Note for this question'); ta.disabled = !isOwner;
        card.appendChild(ta);
        var send = el('button', 'qsend', busy[q._id] ? 'Sending...' : 'Send answer'); send.type = 'button';
        send.disabled = !isOwner || !!busy[q._id];
        var st = el('div', 'qstatus', isOwner ? '' : 'Only the owner answers here.');
        send.addEventListener('click', function(){
          var pick = picks[q._id] || '';
          var txt = (ta.value || '').trim();
          if (!pick && !txt) { st.textContent = 'Pick an option or write a note first.'; return; }
          busy[q._id] = true; send.disabled = true; send.textContent = 'Sending...';
          db.doc('questions/' + q._id).update({ state: 'answered', answer: pick || 'Other', note: txt, answeredAt: new Date().toISOString() }).then(function(){ busy[q._id] = false; }).catch(function(e){ busy[q._id] = false; send.disabled = false; send.textContent = 'Send answer'; st.textContent = 'Not saved (' + (e && e.code ? e.code : 'error') + '). Try again.'; });
        });
        card.appendChild(send); card.appendChild(st);
        box.appendChild(card);
      });
      if (doneBox) {
        doneBox.textContent = '';
        done.slice(0, 8).forEach(function(q){
          var row = el('div', 'qdone');
          row.appendChild(el('span', null, (q.header || 'Question') + ': '));
          row.appendChild(el('b', null, q.answer || ''));
          if (q.note) row.appendChild(el('span', null, ' (' + q.note + ')'));
          row.appendChild(el('span', 'mono', q.processed ? ' · handled' : ' · with the lead'));
          doneBox.appendChild(row);
        });
      }
    }
  });
})();
`;
