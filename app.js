/* Loop — front end. Talks to api/ (PHP + MySQL). No build step, no libraries. */
(function () {
'use strict';

const TYPES = {
  tell:   {label:'Tell',   role:'recipient', verb:'closes when everyone has seen it'},
  ask:    {label:'Ask',    role:'responder', verb:'closes when answered'},
  decide: {label:'Decide', role:'decider',   verb:'closes when a choice is made'},
  do:     {label:'Do',     role:'owner',     verb:'closes when marked done'},
  share:  {label:'Share',  role:'recipient', verb:'no reply expected'}
};
const SINGLE = {decide:true, do:true};
const POLL_MS = 20000;
const CACHE_KEY = 'loop.cache.v1';

const S = {
  me:null, users:{}, spaces:[], loops:[], settings:{nowPerWeek:0, windows:['11:30','15:00','17:30'], skipDays:[0], ai:false},
  skew:0, view:'home', from:'home', spaceId:null, loopId:null, peek:false,
  draft:null, reply:'', pick:null, confirmWithdraw:false, q:'',
  auth:null, ready:false, offline:false, booted:false, installEvt:null, listening:false, toastT:null,
  threads:[], talk:null, talkText:'', talkMentions:{}, mentionPick:false, mq:'', msgMenu:null, msgEdit:null, searchQ:'', searchRes:null, dmPick:false, guestAdd:null, push:null,
  org:null, pp:null, limits:{}, hosted:null, wsx:null
};

const app = document.getElementById('app');
const nav = document.getElementById('nav');
const toastEl = document.getElementById('toast');

/* ---------- helpers ---------- */
function esc(s){return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function toast(msg){toastEl.textContent = msg; toastEl.hidden = false; clearTimeout(S.toastT); S.toastT = setTimeout(() => { toastEl.hidden = true; }, 2800);}
function now(){return Date.now() + S.skew;}
const NS = location.pathname.replace(/[^/]*$/, '');   // one Loop per address: hosted workspaces share a browser origin
const nsk = (k) => NS === '/' ? k : NS + k;
function store(k, v){try{ v === null ? localStorage.removeItem(nsk(k)) : localStorage.setItem(nsk(k), v); }catch(e){}}
function load(k){try{ return localStorage.getItem(nsk(k)); }catch(e){ return null; }}
/** The organisation this Loop belongs to (from the server; cached so the sign-in screen knows it too). */
function org(){ if(!S.org){ try{ S.org = JSON.parse(load('loop.org') || 'null'); }catch(e){} } return S.org || {name:'', short:'', peopleSource:'local'}; }
function orgShort(){ return org().short || org().name || ''; }
function isLocal(){ return org().peopleSource !== 'portal'; }
function setOrg(o){ if(!o) return; S.org = {name:o.name || o.org || '', short:o.short || o.orgShort || '', peopleSource:o.peopleSource || 'local', version:o.version || '', sso:Array.isArray(o.sso) ? o.sso : ((S.org && S.org.sso) || [])}; store('loop.org', JSON.stringify(S.org)); if(S.org.short || S.org.name) document.title = (S.org.short || S.org.name) + ' Loop'; }

async function api(action, body){
  const opts = {credentials:'same-origin', headers:{}};
  if(body !== undefined){ opts.method = 'POST'; opts.headers = {'Content-Type':'application/json', 'X-Loop':'1'}; opts.body = JSON.stringify(body); }
  let res;
  try{ res = await fetch('api/?a=' + encodeURIComponent(action), opts); }
  catch(e){ setOffline(true); throw {code:0, message:'You’re offline. Check your connection and try again.'}; }
  setOffline(false);
  let j = {}; try{ j = await res.json(); }catch(e){}
  if(j && j.setup){ location.href = 'setup.php'; throw {code:503, message:j.error || 'Loop isn’t set up yet.'}; }
  if(res.status === 401){ signedOut(); throw {code:401, message:'Please sign in again.'}; }
  if(!res.ok) throw {code:res.status, message:j.error || ('Error '+res.status+'. Check api/config.php and the database settings.')};
  return j;
}
function setOffline(v){ if(S.offline !== v){ S.offline = v; if(S.ready) paint(); } }

/* ---------- time ---------- */
function windows(digest){
  const w = (S.settings.windows || ['11:30','15:00','17:30']).map(x => x.split(':').map(Number));
  return digest ? [w[w.length-1]] : w;
}
function nextWindow(t, digest){
  const d = new Date(t), skip = S.settings.skipDays || [0];
  for(let i=0;i<9;i++){
    const day = new Date(d.getFullYear(), d.getMonth(), d.getDate()+i);
    if(skip.includes(day.getDay())) continue;
    for(const [h,m] of windows(digest)){
      const x = new Date(day.getFullYear(), day.getMonth(), day.getDate(), h, m).getTime();
      if(x > t) return x;
    }
  }
  return t;
}
function ymd(t){const d=new Date(t);return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');}
function clock(t){return new Date(t).toLocaleTimeString([], {hour:'numeric', minute:'2-digit'}).toLowerCase();}
function dayLabel(t){
  const d = new Date(t), today = new Date(now());
  const diff = Math.round((new Date(d.getFullYear(),d.getMonth(),d.getDate()) - new Date(today.getFullYear(),today.getMonth(),today.getDate()))/86400000);
  if(diff === 0) return 'today'; if(diff === 1) return 'tomorrow'; if(diff === -1) return 'yesterday';
  return d.toLocaleDateString([], {weekday:'short', day:'numeric', month:'short'});
}
function when(t){return dayLabel(t) + ' ' + clock(t);}
function dueLabel(due){if(!due) return ''; const [y,m,d] = due.split('-').map(Number); return dayLabel(new Date(y,m-1,d).getTime());}
function overdue(l){return l.status === 'open' && l.due && l.due < ymd(now());}
function shortDate(t){return new Date(t).toLocaleDateString([], {day:'numeric', month:'short'});}

/* ---------- people ---------- */
function nm(id){
  if(S.me && id === S.me.id) return 'You';
  const u = S.users[id];
  return u ? (u.name || 'Someone') + (u.guest ? ' (guest)' : '') : 'Someone';
}
function fullName(id){ const u = S.users[id]; return (u && u.name) || (S.pp && S.pp.list && (S.pp.list.find(p => p.id === id) || {}).name) || 'Someone'; }
function personMeta(id){ const u = S.users[id]; return u ? [u.title, u.group].filter(Boolean).join(' · ') : ''; }
function hue(id){ let h = 0; for(const c of String(id)) h = (h*31 + c.charCodeAt(0)) % 360; return h; }
function initials(id){
  const n = fullName(id);
  return n.split(/[\s._-]+/).filter(Boolean).slice(0,2).map(w => w[0].toUpperCase()).join('') || '?';
}
function av(id, lg){ return '<span class="av'+(lg?' lg':'')+'" aria-hidden="true" style="background:hsl('+hue(id)+' 42% 40%)">'+esc(initials(id))+'</span>'; }
/** Everyone I can send a loop to: active, with an email, not me. */
function others(){ return Object.keys(S.users).filter(id => id !== S.me.id && S.users[id].active && S.users[id].canSignIn).sort((a,b) => nm(a).localeCompare(nm(b))); }
/** People I loop with most, then my own SBU or department. */
function suggested(){
  const count = {};
  S.loops.forEach(l => {
    const ids = [l.author, ...partIds(l)];
    if(!ids.includes(S.me.id)) return;
    ids.forEach(i => { if(i !== S.me.id) count[i] = (count[i]||0) + 1; });
  });
  const pool = others();
  const frequent = pool.filter(i => count[i]).sort((a,b) => count[b]-count[a]);
  const mine = pool.filter(i => !count[i] && S.me.group && S.users[i].group === S.me.group);
  return frequent.concat(mine).slice(0, 8);
}
function searchPeople(q){
  const words = q.toLowerCase().split(/\s+/).filter(Boolean);
  const pool = others().concat(Object.keys(S.users).filter(id => S.users[id].guest && S.users[id].active));
  return pool.filter(id => {
    const u = S.users[id], hay = (u.name+' '+u.title+' '+u.group).toLowerCase();
    return words.every(w => hay.includes(w));
  }).slice(0, 10);
}
function nowUsed(){ return S.me ? S.me.nowUsed || 0 : 0; }
function nowLimit(){ return S.settings.nowPerWeek || 0; }
function nowLeft(){ return nowLimit() ? nowLimit() - nowUsed() : Infinity; }
function nowText(){ return nowLimit() ? nowLeft()+' of '+nowLimit()+' “now” sends left this week' : nowUsed()+' “now” send'+(nowUsed()===1?'':'s')+' this week'; }

/* ---------- loop logic ---------- */
function role(l, id){ return (l.parts || {})[id || S.me.id]; }
function partIds(l){ return Object.keys(l.parts || {}); }
function delivered(l){ return (l.deliverAt || 0) <= now(); }
function isDigest(l){ return l.type === 'tell' || l.type === 'share'; }
function needsMe(l){
  if(l.status !== 'open' || l.author === S.me.id || !role(l) || !delivered(l)) return false;
  if(isDigest(l)) return !(l.seenBy || {})[S.me.id];
  return true;
}
function queuedForMe(l){ return l.status === 'open' && l.author !== S.me.id && role(l) && !delivered(l); }
function space(id){ return S.spaces.find(s => s.id === id); }
function spaceName(id){ const s = space(id); return s ? s.name : ''; }
function sortNeeds(a,b){
  const u = l => (l.urgent || l.nudgedAt) ? 0 : 1;
  return u(a)-u(b) || (a.due||'9999').localeCompare(b.due||'9999') || (a.deliverAt||0)-(b.deliverAt||0);
}
function waitingOn(l){ return partIds(l).filter(id => isDigest(l) ? !(l.seenBy||{})[id] : true); }
function needsCount(){ return S.loops.filter(l => needsMe(l) && !isDigest(l)).length; }
function digestCount(){ return S.loops.filter(l => needsMe(l) && isDigest(l)).length; }

/* ---------- icons ---------- */
const I = {
  home:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 11l8-7 8 7v9H4z"/><path d="M10 20v-6h4v6"/></svg>',
  sent:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12l16-8-6 16-3-7z"/></svg>',
  spaces:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/></svg>',
  digest:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg>',
  plus:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true" style="width:26px;height:26px"><path d="M12 5v14M5 12h14"/></svg>',
  back:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>',
  check:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12l5 5 9-10"/></svg>',
  search:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg>',
  talk:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/></svg>',
  loop:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true" style="width:16px;height:16px"><path d="M20 12a8 8 0 1 1-3-6.2"/><path d="M17 2v4h4"/></svg>',
  mic:'<svg class="i" viewBox="0 0 24 24" aria-hidden="true" style="width:28px;height:28px"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>'
};

/* ---------- data ---------- */
let syncing = null;
async function sync(){
  if(syncing) return syncing;
  syncing = (async () => {
    try{
      const d = await api('sync');
      applyData(d);
      store(CACHE_KEY, JSON.stringify(d));
      S.ready = true;
      if(S.view === 'login') S.view = 'home';
      paint();
    }catch(e){ if(e.code !== 401 && !S.ready){ S.loadErr = e.code ? e.message : ''; paint(); } }
    finally{ syncing = null; }
  })();
  return syncing;
}
function applyData(d){
  S.me = d.me; S.settings = d.settings || S.settings; S.spaces = d.spaces || [];
  if(d.org) setOrg(d.org);
  S.limits = d.limits || {}; S.hosted = d.hosted || null;
  S.users = {}; (d.users || []).forEach(u => S.users[u.id] = u);
  S.loops = (d.loops || []).filter(l => TYPES[l.type]);
  S.threads = d.threads || [];
  if(d.serverNow) S.skew = d.serverNow - Date.now();
  if('setAppBadge' in navigator){ const n = needsCount() + talkUnread(); (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(() => {}); }
}
function signedOut(){
  store(CACHE_KEY, null);
  S.me = null; S.ready = false; S.loops = []; S.users = {}; S.spaces = [];
  S.view = 'login'; S.auth = {stage:'email', email:load('loop.email') || '', code:'', busy:false, err:''};
  paint();
}

/* ---------- render ---------- */
function paint(){
  const a = document.activeElement, fid = a && a.id, sel = a && typeof a.selectionStart === 'number' ? [a.selectionStart, a.selectionEnd] : null;
  let html = '';
  if(S.view === 'login') html = viewLogin();
  else if(!S.ready || !S.me) html = '<div class="head"><div class="eyebrow">Loop</div><h1>Opening Loop…</h1>'+(S.offline?'<p class="muted">You’re offline. Loop will load when you’re back online.</p>':'')+(S.loadErr?'<div class="banner">Loop’s server replied: '+esc(S.loadErr)+'</div><button class="btn ghost" data-act="retry" style="align-self:flex-start">Try again</button>':'')+'</div>';
  else {
    if(S.view === 'loop') html = viewLoop();
    else if(S.view === 'talk') html = viewTalk();
    else if(S.view === 'thread') html = S.talk ? viewThread() : viewTalk();
    else if(S.view === 'search') html = viewSearch();
    else if(S.view === 'new') html = viewNew();
    else if(S.view === 'sent') html = viewSent();
    else if(S.view === 'spaces') html = S.spaceId ? viewSpace() : viewSpaces();
    else if(S.view === 'digest') html = viewDigest();
    else if(S.view === 'you') html = viewYou();
    else if(S.view === 'people') html = viewPeople();
    else if(S.view === 'workspace') html = viewWorkspace();
    else html = viewHome();
    if(S.offline) html = '<div class="banner">You’re offline. Showing what Loop had last.</div>' + html;
  }
  if(S.viewer && S.ready && S.me) html += viewerHtml();
  app.innerHTML = html;
  document.body.classList.toggle('in-thread', S.view === 'thread');
  if(S.ready) setTimeout(hydrateThumbs, 0);
  paintNav();
  if(fid){ const el = document.getElementById(fid); if(el){ el.focus({preventScroll:true}); if(sel && el.setSelectionRange) try{ el.setSelectionRange(sel[0], sel[1]); }catch(e){} } }
}

function paintNav(){
  if(!S.ready || !S.me || S.view === 'login' || S.view === 'thread' || S.view === 'search'){ nav.hidden = true; return; }
  nav.hidden = false;
  const cur = S.view === 'loop' ? (S.from === 'thread' ? 'talk' : S.from) : S.view === 'digest' ? 'home' : S.view;
  const b = (v, label, icon, badge) => '<button data-act="go" data-v="'+v+'"'+(cur===v?' aria-current="page"':'')+'>'+icon+'<span>'+label+'</span>'+(badge?'<span class="badge">'+badge+'</span>':'')+'</button>';
  nav.innerHTML = '<div class="in">' + b('home','Needs you',I.home,needsCount()+digestCount()) + b('talk','Talk',I.talk,talkUnread()) +
    '<button class="new" data-act="new" aria-label="New loop">'+I.plus+'</button>' +
    b('spaces','Spaces',I.spaces,0) + b('sent','Sent',I.sent,0) + '</div>';
}

function topbar(){
  return '<div class="topbar"><span class="wordmark"><img src="icons/icon-192.png" alt="">Loop</span><span class="row" style="gap:6px"><button class="iconbtn" data-act="search" aria-label="Search">'+I.search+'</button><button class="youbtn" data-act="go" data-v="you" aria-label="You and settings">'+av(S.me.id, true)+'</button></span></div>';
}
function chip(type){ return '<span class="chip c-'+type+'">'+TYPES[type].label+'</span>'; }

function loopCard(l, mode){
  const sp = spaceName(l.spaceId);
  let sub;
  if(mode === 'needs'){ sub = 'From ' + esc(nm(l.author)) + (sp ? ' · ' + esc(sp) : ''); }
  else if(mode === 'sent'){
    const w = waitingOn(l);
    sub = !delivered(l) ? 'Arrives ' + when(l.deliverAt) + ' · to ' + esc(w.map(nm).join(', ')) : 'Waiting on ' + esc(w.map(nm).join(', ') || 'no one');
    if(sp) sub += ' · ' + esc(sp);
  }
  else if(mode === 'closed'){ sub = esc((l.outcome && l.outcome.text) || (l.status === 'withdrawn' ? 'Withdrawn' : 'Closed')); }
  else { sub = 'From ' + esc(nm(l.author)) + ' · ' + when(l.deliverAt); }
  let right = '';
  if(overdue(l)) right = '<span class="chip c-warn">Overdue</span>';
  else if(l.nudgedAt && mode === 'needs') right = '<span class="chip c-warn">Nudged</span>';
  else if(l.editedAt && (mode === 'needs' || mode === 'digest') && !(l.seenBy||{})[S.me.id]) right = '<span class="chip c-ask">Edited</span>';
  else if(l.urgent && mode === 'needs') right = '<span class="chip c-warn">Now</span>';
  else if(l.due && l.status === 'open') right = '<span class="chip c-plain">by '+esc(dueLabel(l.due))+'</span>';
  else if(mode === 'closed' || mode === 'sent') right = chip(l.type);
  return '<button class="card" data-act="open" data-id="'+esc(l.id)+'">' +
    '<div class="row between" style="flex-wrap:nowrap;align-items:flex-start"><span class="t">'+esc(l.title)+'</span>'+right+'</div>' +
    '<div class="s">'+sub+'</div></button>';
}

/* ----- sign-in ----- */
function viewLogin(){
  const a = S.auth;
  if(!S.infoAsked){ S.infoAsked = true; api('info').then(r => { setOrg(r); if(S.view === 'login') paint(); }).catch(() => {}); }
  const qs = new URLSearchParams(location.search);
  if(qs.get('sso_error')){ a.err = ssoMessage(qs.get('sso_error'), qs.get('sso'), qs.get('sso_email')); a.stage = 'email'; history.replaceState(null, '', location.pathname); }
  const sso = (org().sso || []).filter(p => SSO_LABEL[p]);
  let html = '<div class="login"><img class="mark" src="icons/icon-192.png" alt=""><div class="head"><div class="eyebrow">'+esc(orgShort())+'</div><h1>Loop</h1><p class="muted">Asks, decisions, tasks and updates, each with a finish line.</p></div>';
  if(a.stage === 'email'){
    if(sso.length) html += '<div class="panel">' + sso.map(p => '<a class="btn ghost wide" style="text-decoration:none" href="api/sso.php?start='+p+'" data-sso>Continue with '+SSO_LABEL[p]+'</a>').join('') + '<div class="muted" style="font-size:13.5px;text-align:center">or get a code by email</div></div>';
    html += '<form class="panel" id="f-email"><label class="f" for="l-email">Your email<input type="email" id="l-email" data-bind="auth.email" autocomplete="email" inputmode="email" value="'+esc(a.email)+'" required></label>';
    if(a.err) html += '<div class="err">'+esc(a.err)+'</div>';
    html += '<button class="btn wide" type="submit"'+(a.busy?' disabled':'')+'>'+(a.busy?'Sending code…':'Email me a code')+'</button><p class="muted" style="font-size:13.5px">'+(isLocal() ? 'Use your work email'+(orgShort() ? ' at '+esc(orgShort()) : '')+'. No password: we email you a 6-digit code.' : 'Use the email on your '+esc(orgShort())+' Resources record. No password: we email you a 6-digit code. This is separate from the shared Portal login.')+'</p></form>';
  } else {
    html += '<form class="panel" id="f-code"><p>We sent a code to <strong>'+esc(a.email)+'</strong>.</p><label class="f" for="l-code">6-digit code<input type="text" class="code" id="l-code" data-bind="auth.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" value="'+esc(a.code)+'" required></label>';
    if(a.err) html += '<div class="err">'+esc(a.err)+'</div>';
    html += '<button class="btn wide" type="submit"'+(a.busy?' disabled':'')+'>'+(a.busy?'Checking…':'Sign in')+'</button><div class="row between"><button type="button" class="linkbtn" data-act="authback">Use another email</button><button type="button" class="linkbtn" data-act="resend">Send a new code</button></div></form>';
  }
  return html + '</div>';
}

/* ----- main views ----- */
function viewHome(){
  const mine = S.loops.filter(l => needsMe(l) && !isDigest(l)).sort(sortNeeds);
  const queued = S.loops.filter(queuedForMe);
  const dc = digestCount();
  const groups = ['decide','ask','do'].map(t => {
    const items = mine.filter(l => l.type === t);
    return items.length ? '<div class="group"><div class="eyebrow t-'+t+'">'+TYPES[t].label+'</div>'+items.map(l => loopCard(l,'needs')).join('')+'</div>' : '';
  }).join('');
  const mins = mine.reduce((s,l) => s + (l.type === 'decide' ? 3 : 1), 0);
  let html = topbar() + installCard();
  html += '<div class="head"><div class="eyebrow">'+esc(new Date(now()).toLocaleDateString([], {weekday:'long', day:'numeric', month:'long'}))+'</div>';
  if(mine.length) html += '<h1>'+mine.length+(mine.length===1?' thing needs':' things need')+' you</h1><p class="muted">About '+mins+' minute'+(mins===1?'':'s')+'. Everything else can wait.</p></div>' + groups;
  else html += '<h1>Nothing needs you right now</h1><p class="muted">You’re all caught up.</p></div>';
  if(queued.length){
    html += '<div class="soft row between"><span>'+queued.length+' more arriving · next at '+clock(nextWindow(now()))+'</span><button class="linkbtn" data-act="peek">'+(S.peek?'Hide':'Peek')+'</button></div>';
    if(S.peek) html += '<div class="group">'+queued.sort((a,b) => a.deliverAt-b.deliverAt).map(l => loopCard(l,'queued')).join('')+'</div>';
  }
  html += '<button class="soft row between" data-act="go" data-v="digest"><span class="stack" style="gap:2px"><strong>Digest</strong><span class="muted" style="font-size:13.5px">'+(dc ? dc+' tell'+(dc===1?'':'s')+' and shares to read' : 'Tells and shares land at '+clock(nextWindow(now(), true)))+'</span></span><span class="muted">Open</span></button>';
  if(S.loops.length === 0) html += onboarding();
  return html;
}
function onboarding(){
  const n = others().length;
  if(isLocal() && n === 0) return '<div class="empty"><h2>Add your team</h2><p>'+(S.me.isAdmin ? 'Loop is set up. Add the people you work with, one by one or from a spreadsheet, and invite them by email.' : 'No one else is on this Loop yet. Ask your Loop admin to add your colleagues.')+'</p>'+(S.me.isAdmin ? '<button class="btn" data-act="people" style="align-self:flex-start">Add people</button>' : '')+'</div>';
  return '<div class="empty"><h2>Start your first loop</h2><p>'+(isLocal() ? 'Everyone on '+esc(orgShort() ? orgShort()+'’s' : 'this')+' Loop can get loops' : 'Everyone on the '+esc(orgShort())+' Resources list with an email can get loops')+': '+n+' colleague'+(n===1?'':'s')+' right now. They sign in at this same address with their own email.</p>' +
    '<p class="muted">Tap + and say what you need, for example “Ask Priya if the drawings can go out by Monday.”</p></div>';
}
function installCard(){
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if(standalone || load('loop.installHidden')) return '';
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
  const how = S.installEvt ? '<button class="btn small" data-act="install">Install Loop</button>'
    : ios ? '<span>In Safari, tap <strong>Share</strong>, then <strong>Add to Home Screen</strong>.</span>'
    : '<span>Open your browser menu and choose <strong>Add to Home Screen</strong> or <strong>Install app</strong>.</span>';
  return '<div class="soft stack" style="gap:8px"><div class="row between"><strong>Put Loop on your Home Screen</strong><button class="linkbtn" data-act="hideinstall" style="padding:0">Dismiss</button></div>'+how+'</div>';
}

function viewSent(){
  const open = S.loops.filter(l => l.author === S.me.id && l.status === 'open').sort((a,b) => (overdue(b)-overdue(a)) || b.createdAt-a.createdAt);
  const closed = S.loops.filter(l => l.author === S.me.id && l.status !== 'open').sort((a,b) => (b.closedAt||0)-(a.closedAt||0)).slice(0,15);
  let html = topbar() + '<div class="head"><div class="eyebrow">Sent</div><h1>Waiting on others</h1><p class="muted">'+esc(nowText())+'.</p></div>';
  html += open.length ? '<div class="group">'+open.map(l => loopCard(l,'sent')).join('')+'</div>' : '<div class="empty"><strong>No open loops</strong><span class="muted">Loops you send show here until they close.</span></div>';
  if(closed.length) html += '<div class="group"><div class="eyebrow">Recently closed</div>'+closed.map(l => loopCard(l,'closed')).join('')+'</div>';
  return html;
}

function viewDigest(){
  const unseen = S.loops.filter(l => needsMe(l) && isDigest(l)).sort((a,b) => b.deliverAt-a.deliverAt);
  const week = now() - 7*86400000;
  const seen = S.loops.filter(l => isDigest(l) && role(l) && (l.seenBy||{})[S.me.id] && l.deliverAt > week).sort((a,b) => b.deliverAt-a.deliverAt).slice(0,20);
  const queued = S.loops.filter(l => queuedForMe(l) && isDigest(l));
  const at = clock(nextWindow(now(), true));
  let html = topbar() + '<div class="head"><div class="eyebrow">Digest</div><h1>Tells and shares</h1><p class="muted">These never interrupt you. They gather here at '+at+'.</p></div>';
  if(unseen.length) html += '<div class="group"><div class="row between"><span class="eyebrow">To read</span><button class="linkbtn" data-act="seenall">Mark all seen</button></div>'+unseen.map(l => loopCard(l,'digest')).join('')+'</div>';
  else html += '<div class="empty"><strong>You’re caught up</strong><span class="muted">'+(queued.length ? queued.length+' more arrive at '+at+'.' : 'Nothing waiting.')+'</span></div>';
  if(queued.length && unseen.length) html += '<div class="soft">'+queued.length+' more arrive at '+at+'.</div>';
  if(seen.length) html += '<div class="group"><div class="eyebrow">Earlier this week</div>'+seen.map(l => loopCard(l,'digest')).join('')+'</div>';
  return html;
}

function kindOptions(cur){ return ['project','team','place','family'].map(k => '<option value="'+k+'"'+(cur===k?' selected':'')+'>'+k[0].toUpperCase()+k.slice(1)+'</option>').join(''); }
function canEditSpace(s){ return s.createdBy === S.me.id || S.me.isAdmin; }
function viewSpaces(){
  const card = s => {
    const ls = S.loops.filter(l => l.spaceId === s.id);
    const open = ls.filter(l => l.status === 'open' && (l.author === S.me.id || role(l))).length;
    const rec = ls.filter(l => l.status === 'closed' && (l.type === 'decide' || l.type === 'ask')).length;
    return '<button class="card" data-act="space" data-id="'+esc(s.id)+'"><div class="row between"><span class="t">'+esc(s.name)+'</span><span class="chip c-plain">'+esc(s.archived ? 'archived' : s.kind)+'</span></div><div class="s">'+open+' open · '+rec+' recorded</div></button>';
  };
  const active = S.spaces.filter(s => !s.archived);
  const minePart = active.filter(s => (s.members||[]).includes(S.me.id)), otherPart = active.filter(s => !(s.members||[]).includes(S.me.id));
  const rows = minePart.map(card).join('') + (otherPart.length ? '<div class="eyebrow" style="padding:10px 4px 0">Other spaces · visible to directors</div>' + otherPart.map(card).join('') : '');
  const old = S.spaces.filter(s => s.archived);
  return topbar() + '<div class="head"><div class="eyebrow">Spaces</div><h1>Projects and teams</h1><p class="muted">'+(S.me.isDirector ? 'You see every space as a director. Others only see the spaces they’re in.' : 'The spaces you’re in. You’re added when someone puts you on a loop in a space, mentions you in its talk, or adds you.')+'</p></div>' +
    (rows ? '<div class="group">'+rows+'</div>' : '<div class="empty"><strong>No spaces yet</strong><span class="muted">Create one for a project you lead, or wait to be added to one.</span></div>') +
    '<form class="panel" id="f-space"><h2>New space</h2><label class="f" for="sp-name">Name<input type="text" id="sp-name" placeholder="e.g. Proddatur mixed-use" maxlength="80"></label>' +
    '<label class="f" for="sp-kind">Kind<select id="sp-kind"><option value="project">Project</option><option value="team">Team</option><option value="place">Place</option><option value="family">Family</option></select></label>' +
    '<button class="btn" type="submit">Add space</button></form>' +
    (old.length ? '<div class="group"><button class="linkbtn" data-act="showarchived">'+(S.showArchived?'Hide':'Show')+' '+old.length+' archived space'+(old.length===1?'':'s')+'</button>'+(S.showArchived?old.map(card).join(''):'')+'</div>' : '');
}

function viewSpace(){
  const s = space(S.spaceId);
  if(!s){ S.spaceId = null; return viewSpaces(); }
  const ls = S.loops.filter(l => l.spaceId === s.id);
  const ledger = ls.filter(l => l.status === 'closed' && (l.type === 'decide' || l.type === 'ask') && l.outcome).sort((a,b) => (b.closedAt||0)-(a.closedAt||0));
  const open = ls.filter(l => l.status === 'open' && (l.author === S.me.id || role(l))).sort(sortNeeds);
  const people = new Set(); ls.forEach(l => { people.add(l.author); partIds(l).forEach(i => people.add(i)); });
  let html = '<button class="linkbtn" data-act="spaceback">'+I.back+'All spaces</button>';
  if(S.spaceEdit && S.spaceEdit.id === s.id){
    const e = S.spaceEdit;
    html += '<form class="panel" id="f-spaceedit"><h2>Edit space</h2><label class="f" for="se-name">Name<input type="text" id="se-name" data-bind="spaceEdit.name" maxlength="80" value="'+esc(e.name)+'"></label>' +
      '<label class="f" for="se-kind">Kind<select id="se-kind" data-bind="spaceEdit.kind">'+kindOptions(e.kind)+'</select></label>' +
      (e.err ? '<div class="err">'+esc(e.err)+'</div>' : '') +
      '<div class="row between"><button type="button" class="btn ghost small" data-act="spacearchive">'+(s.archived?'Unarchive':'Archive')+'</button><span class="row"><button type="button" class="btn ghost small" data-act="spaceeditcancel">Cancel</button><button class="btn small" type="submit"'+(e.busy?' disabled':'')+'>Save</button></span></div>' +
      '<p class="muted" style="font-size:13px">Archiving hides the space from lists and the composer. Its loops and decision ledger stay as they are.</p></form>';
  } else {
    html += '<div class="row between" style="align-items:flex-end;flex-wrap:nowrap"><div class="head" style="min-width:0"><div class="eyebrow">'+esc(s.archived ? 'archived '+s.kind : s.kind)+' · '+people.size+' '+(people.size===1?'person':'people')+'</div><h1 style="overflow-wrap:anywhere">'+esc(s.name)+'</h1></div>'+(canEditSpace(s)?'<button class="btn ghost small" data-act="spaceedit" style="flex:none">Edit</button>':'')+'</div>';
  }
  html += '<div class="group"><div class="eyebrow">Decision ledger</div>';
  html += ledger.length ? '<div class="panel" style="gap:0;padding-block:4px"><div class="ledger">'+ledger.map(l =>
    '<div><span class="mono muted" style="font-size:12.5px;padding-top:2px">'+esc(shortDate(l.closedAt))+'</span><button data-act="open" data-id="'+esc(l.id)+'"><strong style="overflow-wrap:anywhere">'+esc(l.outcome.text)+'</strong><span class="muted" style="font-size:13px;overflow-wrap:anywhere">'+esc(l.title)+' · '+esc(nm(l.outcome.by))+'</span></button></div>').join('')+'</div></div>'
    : '<div class="empty"><span class="muted">Answers and decisions made in this space are recorded here, newest first.</span></div>';
  html += '</div><div class="group"><div class="eyebrow">Still open</div>' + (open.length ? open.map(l => loopCard(l,'sent')).join('') : '<div class="empty"><span class="muted">No open loops you’re part of.</span></div>') + '</div>';
  const mem = s.members || [], inIt = mem.includes(S.me.id);
  html += '<div class="panel"><div class="row between"><span class="eyebrow">Members · '+mem.length+'</span>'+(s.archived ? '' : inIt ? '<button class="linkbtn" data-act="spaceleave" data-id="'+esc(s.id)+'" style="padding:0">Leave</button>' : '')+'</div>' +
    '<div class="row" style="gap:6px">'+mem.slice(0, 24).map(id => '<span title="'+esc(fullName(id))+'">'+avp(id)+'</span>').join('')+(mem.length > 24 ? '<span class="muted">+'+(mem.length-24)+'</span>' : '')+'</div>' +
    (s.archived ? '' : addPeopleBox(s)) +
    (s.archived ? '' : inIt ? '<button class="btn" data-act="thread" data-t="s:'+esc(s.id)+'">'+I.talk+' Talk in '+esc(s.name)+'</button>' : S.me.isDirector ? '<button class="btn" data-act="spacejoin" data-id="'+esc(s.id)+'">Join to talk here</button>' : '') + '</div>';
  if(!s.archived && (inIt || S.me.isDirector)) html += '<button class="btn ghost" data-act="new" data-space="'+esc(s.id)+'">'+I.plus+' New loop in '+esc(s.name)+'</button>';
  return html;
}

function viewYou(){
  let html = '<button class="linkbtn" data-act="go" data-v="home">'+I.back+'Back</button>';
  html += '<div class="row" style="flex-wrap:nowrap">'+av(S.me.id, true)+'<div class="head" style="min-width:0"><h1 style="font-size:26px">'+esc(S.me.name)+'</h1><span class="muted" style="overflow-wrap:anywhere">'+esc([S.me.title, S.me.group].filter(Boolean).join(' · '))+'</span><span class="muted" style="font-size:13.5px;overflow-wrap:anywhere">'+esc(S.me.email)+'</span></div></div>';
  if(!isLocal()) html += '<p class="muted" style="font-size:13.5px">Your name, title and SBU come from the Resources app. Ask the admin there to correct them.</p>';
  html += '<form class="panel" id="f-me">' + (isLocal() ? '<label class="f" for="me-name">Your name<input type="text" id="me-name" maxlength="120" value="'+esc(S.me.name)+'"></label>' + (S.me.isAdmin ? '' : '<span class="muted" style="font-size:13px">Your title and team are set by your Loop admin.</span>') : '') + '<label class="row between" for="me-notify" style="cursor:pointer;flex-wrap:nowrap"><span>Email me when loops reach me</span><input type="checkbox" id="me-notify"'+(S.me.notifyEmail?' checked':'')+' style="width:22px;height:22px;accent-color:var(--ink);flex:none"></label>' +
    '<button class="btn small" type="submit" style="align-self:flex-start">Save</button></form>';
  html += '<div class="soft muted" style="font-size:14px">Loops arrive at '+esc((S.settings.windows||[]).map(w => { const [h,m] = w.split(':').map(Number); return clock(new Date(2000,0,1,h,m).getTime()); }).join(', '))+'. The last window is the digest for tells and shares.</div>';
  html += pushPanel();
  if(S.push === null){ S.push = 'checking'; pushState().then(st => { S.push = st; if(S.view === 'you') paint(); }); }
  if(S.me.isAdmin) html += adminPanel();
  html += '<button class="btn ghost" data-act="logout">Sign out</button>';
  return html;
}
function adminPanel(){
  const all = Object.values(S.users).filter(u => u.active);
  const signedIn = all.filter(u => u.lastSeen);
  const noEmail = all.filter(u => !u.canSignIn).sort((a,b) => (a.group||'').localeCompare(b.group||'') || a.name.localeCompare(b.name));
  const notYet = all.filter(u => u.canSignIn && !u.lastSeen);
  let html = S.hosted ? hostedPanel() : '<div class="panel"><div class="eyebrow">Admin · file store</div>' +
    (filesOn() ? '<p style="font-size:14px">Attachments are on (up to '+S.settings.filesMaxMb+' MB each). Run this after setting up or changing the file store.</p>' : '<p style="font-size:14px">Attachments are off. Add the file store details under <span class="mono">storage</span> in api/config.php.</p>') +
    (filesOn() ? '<button class="btn ghost small" data-act="storecheck" style="align-self:flex-start">'+(S.storeCheck === 'busy' ? 'Checking…' : 'Check file store')+'</button>' : '') +
    (Array.isArray(S.storeCheck) ? '<div class="ppl">'+S.storeCheck.map(x => '<div><span>'+esc(x.step)+'</span><span class="'+(x.ok ? '' : 'err')+'" style="font-size:13px;text-align:right">'+(x.ok ? 'OK' : esc(x.detail || 'Failed'))+'</span></div>').join('')+'</div>' : '') + '</div>';
  if(isLocal()){
    const ppl = all.filter(u => !u.guest && !u.system);
    html += '<div class="panel"><div class="eyebrow">Admin · people</div>' +
      '<div class="row" style="gap:18px"><span><strong class="mono">'+ppl.length+'</strong> <span class="muted">on Loop</span></span><span><strong class="mono">'+ppl.filter(u => u.lastSeen).length+'</strong> <span class="muted">signed in</span></span><span><strong class="mono">'+ppl.filter(u => !u.lastSeen).length+'</strong> <span class="muted">not yet</span></span></div>' +
      '<p style="font-size:14px">Add people, import a spreadsheet, choose admins and directors, and send invitations.</p><button class="btn small" data-act="people" style="align-self:flex-start">Manage people</button>' +
      '<p class="muted" style="font-size:13px">Loop '+esc(org().version || '')+'</p></div>';
    return html;
  }
  html += '<div class="panel"><div class="eyebrow">Admin · rollout</div>' +
    '<div class="row" style="gap:18px"><span><strong class="mono">'+signedIn.length+'</strong> <span class="muted">signed in</span></span><span><strong class="mono">'+notYet.length+'</strong> <span class="muted">not yet</span></span><span><strong class="mono">'+noEmail.length+'</strong> <span class="muted">no email</span></span></div>';
  if(noEmail.length) html += '<p style="font-size:14px">These people are on Resources without an email, so they can’t sign in or receive loops. Add their emails in the Resources app; Loop picks them up within 10 minutes.</p><div class="ppl">' +
    noEmail.map(u => '<div><span class="row" style="gap:8px">'+av(u.id)+esc(u.name)+'</span><span class="muted" style="font-size:13px">'+esc(u.group || u.title || '')+'</span></div>').join('') + '</div>';
  return html + '</div>';
}

/* ----- people (admin, standalone Loop) ----- */
async function loadPeople(){
  try{ const r = await api('people.list', {}); if(S.pp){ S.pp.list = r.people; S.pp.err = ''; } }
  catch(e){ if(S.pp) S.pp.err = e.message; }
  if(S.view === 'people') paint();
}
async function savePerson(body, ok){
  S.pp.busy = true; S.pp.err = ''; paint();
  try{ await api('people.update', body); toast(ok); S.pp.edit = null; await loadPeople(); sync(); }
  catch(e){ S.pp.err = e.message; }
  S.pp.busy = false; paint();
}
function pickCsv(){
  const inp = document.createElement('input'); inp.type = 'file'; inp.accept = '.csv,.tsv,.txt,text/csv,text/plain';
  inp.addEventListener('change', async () => {
    const f = inp.files[0]; if(!f) return;
    if(f.size > 1000000) return toast('That file is too big. Split it into smaller files.');
    const csv = await f.text();
    S.pp.busy = true; S.pp.imp = null; paint();
    try{ const r = await api('people.import', {csv, invite:S.pp.impInvite}); S.pp.imp = r; await loadPeople(); sync(); }
    catch(e){ S.pp.imp = {error:e.message}; }
    S.pp.busy = false; paint();
  });
  inp.click();
}
const SSO_LABEL = {google:'Google', microsoft:'Microsoft'};
/** Sign-in errors arrive as short codes, so a link can't put arbitrary words on this screen. */
function ssoMessage(code, p, email){
  const label = SSO_LABEL[p] || 'single sign-on';
  const okEmail = /^[^\s@<>"']{1,64}@[a-z0-9.-]{1,190}\.[a-z]{2,}$/i.test(email || '') ? email : '';
  return ({
    notset: 'That sign-in option isn’t set up on this Loop.',
    cancelled: 'Sign-in was cancelled.',
    failed: 'The '+label+' sign-in didn’t work. Try again, or use an email code.',
    expired: 'Sign-in took too long. Please try again.',
    unverified: 'That Google account’s email isn’t verified.',
    domain: 'Use your organisation’s Google account.',
    tenant: 'Use your work Microsoft account.',
    noemail: 'That account has no email address Loop can use.',
    notfound: (okEmail || 'That account')+' isn’t on '+(orgShort() ? orgShort()+'’s Loop' : 'this Loop')+'. Ask your Loop admin to add you, or sign in with the email they used.'
  })[code] || 'Sign-in couldn’t be checked. Please try again.';
}
function peopleMeter(n){
  const max = S.limits.maxPeople || 0, jd = S.limits.joinDomain || '';
  let h = '';
  if(max){
    const pct = Math.min(100, Math.round(n / max * 100)), full = n >= max;
    h += '<div class="panel" style="gap:8px"><div class="row between"><strong>'+n+' of '+max+' people</strong><span class="muted" style="font-size:13.5px">'+(full ? 'Full' : (max - n)+' left')+'</span></div>' +
      '<div style="height:8px;border-radius:8px;background:var(--raise);overflow:hidden"><div style="height:8px;width:'+pct+'%;background:'+(full ? 'var(--now, #A1270F)' : 'var(--ink)')+'"></div></div>' +
      (full ? '<span class="muted" style="font-size:13.5px">To add someone, turn someone else off first.'+(S.hosted ? ' Need more people? <a href="'+esc(S.hosted.selfHostUrl)+'" target="_blank" rel="noopener">Self-host LOOP</a> free, with no limit.' : '')+'</span>' : '') + '</div>';
  }
  if(jd) h += '<div class="soft" style="font-size:14px">Anyone with an <strong>@'+esc(jd)+'</strong> email can join by themselves: they open this Loop and sign in. '+(S.hosted ? 'Change it in <button class="linkbtn" data-act="go" data-v="workspace">Workspace settings</button>.' : '')+'</div>';
  return h;
}
function hostedPanel(){
  const H = S.hosted;
  return '<div class="panel"><div class="eyebrow">Admin · workspace</div>' +
    '<p style="font-size:14px"><strong>'+esc(H.plan)+' plan</strong> on LOOP by loopsconnect: up to '+H.maxPeople+' people, without file attachments or AI drafting.</p>' +
    '<div class="row" style="gap:8px"><button class="btn small" data-act="go" data-v="workspace">Workspace settings</button><a class="btn ghost small" style="text-decoration:none" href="'+esc(H.selfHostUrl)+'" target="_blank" rel="noopener">Self-host LOOP</a></div></div>';
}
const WEEKDAYS = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
async function loadWs(){
  try{ const r = await api('ws.get', {}); S.wsx = {ws:r.ws, f:{orgName:r.ws.orgName, orgShort:r.ws.orgShort, timezone:r.ws.timezone, windows:r.ws.windows.slice(), skipDays:r.ws.skipDays.slice(), selfJoin:!!r.ws.joinDomain}, busy:false, err:'', del:''}; }
  catch(e){ S.wsx = {err:e.message}; }
  if(S.view === 'workspace') paint();
}
function viewWorkspace(){
  let html = '<button class="linkbtn" data-act="go" data-v="you">'+I.back+'Back</button>';
  if(!S.hosted || !S.me.isAdmin) return html + '<div class="empty">Only this workspace’s admins can change its settings.</div>';
  const X = S.wsx;
  if(!X){ loadWs(); return html + '<div class="head"><h1>Workspace</h1><p class="muted">Loading…</p></div>'; }
  if(!X.ws) return html + '<div class="banner">'+esc(X.err)+'</div>';
  const f = X.f, my = (S.me.email || '').split('@')[1] || '';
  let zones = []; try{ zones = Intl.supportedValuesOf('timeZone'); }catch(e){}
  if(!zones.includes(f.timezone)) zones.unshift(f.timezone);
  html += '<div class="head"><div class="eyebrow">'+esc(orgShort())+' · admin</div><h1>Workspace</h1><p class="muted" style="overflow-wrap:anywhere">'+esc(X.ws.url)+'</p></div>';
  if(X.err) html += '<div class="banner">'+esc(X.err)+'</div>';
  html += '<form class="panel" id="f-ws">' +
    '<label class="f" for="ws-name">Organisation name<input type="text" id="ws-name" data-bind="wsx.f.orgName" maxlength="120" value="'+esc(f.orgName)+'"></label>' +
    '<label class="f" for="ws-short">Short name<input type="text" id="ws-short" data-bind="wsx.f.orgShort" maxlength="60" value="'+esc(f.orgShort)+'"><span style="font-weight:400">Used in emails, e.g. “Asha at '+esc(f.orgShort || 'Acme')+' has a question”.</span></label>' +
    '<label class="f" for="ws-tz">Time zone<select id="ws-tz" data-bind="wsx.f.timezone">'+zones.map(z => '<option'+(z === f.timezone ? ' selected' : '')+'>'+esc(z)+'</option>').join('')+'</select></label>' +
    '<div class="f"><span>Delivery times</span><span style="font-weight:400">Loops arrive at these times. The last one is the digest for tells and shares.</span>' +
      f.windows.map((w, i) => '<div class="row" style="gap:8px;flex-wrap:nowrap"><input type="time" data-wsw="'+i+'" value="'+esc(w)+'" style="max-width:160px">'+(f.windows.length > 1 ? '<button type="button" class="linkbtn" data-act="wswdel" data-i="'+i+'">Remove</button>' : '')+'</div>').join('') +
      (f.windows.length < 4 ? '<button type="button" class="linkbtn" data-act="wswadd" style="align-self:flex-start">+ Add a time</button>' : '') + '</div>' +
    '<div class="f"><span>No deliveries on</span><div class="row" style="gap:6px">'+WEEKDAYS.map((d, i) => '<label class="chip '+(f.skipDays.includes(i) ? 'c-plain' : '')+'" style="cursor:pointer;border:1px solid var(--line)"><input type="checkbox" data-wsd="'+i+'"'+(f.skipDays.includes(i) ? ' checked' : '')+' style="accent-color:var(--ink)">'+d+'</label>').join('')+'</div></div>' +
    '<label class="row" for="ws-join" style="cursor:pointer;flex-wrap:nowrap;gap:10px;align-items:flex-start"><input type="checkbox" id="ws-join" data-bind="wsx.f.selfJoin"'+(f.selfJoin ? ' checked' : '')+' style="width:22px;height:22px;accent-color:var(--ink);flex:none;margin-top:2px"><span><strong>Let anyone with an @'+esc(my)+' email join by themselves</strong><br><span class="muted" style="font-size:13.5px">Share this workspace’s link in your staff group: colleagues open it and sign in with their work email. It stops at '+X.ws.maxPeople+' people. Only works with your organisation’s own email domain.</span></span></label>' +
    '<button class="btn" type="submit"'+(X.busy ? ' disabled' : '')+'>'+(X.busy ? 'Saving…' : 'Save')+'</button></form>';
  html += '<div class="panel"><div class="eyebrow">Your data</div><p style="font-size:14px">Download everything in this workspace (people, spaces, loops, decisions and talk) as one file. It’s also how you move to your own server later.</p><button class="btn ghost small" data-act="wsexport" style="align-self:flex-start">Export everything</button></div>';
  html += '<form class="panel" id="f-wsdel" style="border:1px solid var(--line)"><div class="eyebrow" style="color:var(--now, #A1270F)">Delete workspace</div><p style="font-size:14px">This erases '+esc(X.ws.orgName)+' for everyone: people, loops, spaces and talk. It can’t be undone. Export first if you want a copy.</p>' +
    '<label class="f" for="ws-del"><span>Type <span class="mono">'+esc(X.ws.slug)+'</span> to confirm</span><input type="text" id="ws-del" data-bind="wsx.del" autocomplete="off" autocapitalize="none" spellcheck="false" value="'+esc(X.del)+'"></label>' +
    '<button class="btn" type="submit" style="background:var(--now, #A1270F);color:#fff"'+(X.del.trim() === X.ws.slug ? '' : ' disabled')+'>Delete this workspace</button></form>';
  return html;
}

const ROLE_LABEL = {member:'Member', director:'Director', admin:'Admin'};
function roleSelect(id, bind, val){
  return '<label class="f" for="'+id+'">Role<select id="'+id+'" data-bind="'+bind+'">' + Object.keys(ROLE_LABEL).map(r => '<option value="'+r+'"'+(val===r?' selected':'')+'>'+ROLE_LABEL[r]+'</option>').join('') + '</select></label>';
}
function viewPeople(){
  const P = S.pp;
  let html = '<button class="linkbtn" data-act="go" data-v="you">'+I.back+'Back</button>';
  if(!P || !S.me.isAdmin) return html + '<div class="empty">Only Loop admins can manage people.</div>';
  const list = P.list || [];
  const on = list.filter(p => p.active);
  html += '<div class="head"><div class="eyebrow">'+esc(orgShort() || 'Loop')+' · admin</div><h1>People</h1><p class="muted">'+(P.list ? on.length+' on Loop'+(list.length > on.length ? ' · '+(list.length-on.length)+' turned off' : '') : 'Loading…')+'. Everyone here can sign in with their email and get loops.</p></div>';
  html += peopleMeter(on.length);
  if(P.err) html += '<div class="banner">'+esc(P.err)+'</div>';

  html += '<div class="row" style="gap:8px"><button class="btn small" data-act="padd">'+(P.adding ? 'Close' : 'Add a person')+'</button><button class="btn ghost small" data-act="pimport"'+(P.busy?' disabled':'')+'>Import a spreadsheet</button></div>';
  if(P.adding){
    const a = P.add;
    html += '<form class="panel" id="f-padd"><h2>Add a person</h2>' +
      '<label class="f" for="pa-name">Name<input type="text" id="pa-name" data-bind="pp.add.name" maxlength="120" value="'+esc(a.name)+'" required></label>' +
      '<label class="f" for="pa-email">Email<input type="email" id="pa-email" data-bind="pp.add.email" maxlength="190" inputmode="email" value="'+esc(a.email)+'" required></label>' +
      '<label class="f" for="pa-title">Title<input type="text" id="pa-title" data-bind="pp.add.title" maxlength="120" value="'+esc(a.title)+'" placeholder="Optional"></label>' +
      '<label class="f" for="pa-group">Team<input type="text" id="pa-group" data-bind="pp.add.group" maxlength="120" value="'+esc(a.group)+'" placeholder="Optional"></label>' +
      roleSelect('pa-role', 'pp.add.role', a.role) +
      '<label class="row" for="pa-inv" style="cursor:pointer;flex-wrap:nowrap;gap:10px"><input type="checkbox" id="pa-inv" data-bind="pp.add.invite"'+(a.invite?' checked':'')+' style="width:22px;height:22px;accent-color:var(--ink);flex:none"><span>Email them an invitation</span></label>' +
      '<button class="btn" type="submit"'+(P.busy?' disabled':'')+'>'+(P.busy?'Adding…':'Add')+'</button></form>';
  }
  html += '<div class="soft stack" style="font-size:13.5px"><span>A spreadsheet needs a first row with column names: <span class="mono">name, email</span>, and optionally <span class="mono">title, team, role</span> (role is member, director or admin). Save it as CSV. People already on Loop are updated, not duplicated.</span>' +
    '<label class="row" for="pi-inv" style="cursor:pointer;flex-wrap:nowrap;gap:10px"><input type="checkbox" id="pi-inv" data-bind="pp.impInvite"'+(P.impInvite?' checked':'')+' style="width:20px;height:20px;accent-color:var(--ink);flex:none"><span>Invite new people by email when importing</span></label></div>';
  if(P.imp){
    const r = P.imp;
    html += '<div class="panel">' + (r.error ? '<div class="err">'+esc(r.error)+'</div>' :
      '<strong>Imported: '+r.added+' added, '+r.updated+' updated'+(r.invited ? ', '+r.invited+' invited' : '')+'.</strong>' +
      (r.skipped && r.skipped.length ? '<span class="muted" style="font-size:13.5px">Skipped '+r.skipped.length+': '+r.skipped.map(x => 'line '+x.line+' ('+esc(x.reason)+')').join(', ')+'</span>' : '')) +
      '<button class="linkbtn" data-act="pimpclose" style="align-self:flex-start">OK</button></div>';
  }
  if(list.length > 8) html += '<input type="search" id="pp-q" data-bind="pp.q" placeholder="Find a person" value="'+esc(P.q)+'" aria-label="Find a person">';
  const q = P.q.trim().toLowerCase();
  const shown = list.filter(p => !q || (p.name+' '+p.email+' '+p.title+' '+p.group).toLowerCase().includes(q));
  html += '<div class="group">' + shown.map(p => {
    const st = !p.active ? '<span class="chip c-plain">Off</span>' : p.role !== 'member' ? '<span class="chip c-plain">'+ROLE_LABEL[p.role]+'</span>' : '';
    let h = '<button class="card" data-act="pedit" data-id="'+esc(p.id)+'"'+(p.active?'':' style="opacity:.6"')+'><div class="row between" style="flex-wrap:nowrap"><span class="row" style="gap:10px;flex-wrap:nowrap;min-width:0">'+av(p.id)+'<span class="stack" style="gap:1px;min-width:0"><strong style="overflow-wrap:anywhere">'+esc(p.name)+'</strong><span class="muted" style="font-size:13px;overflow-wrap:anywhere">'+esc([p.title, p.group].filter(Boolean).join(' · ') || p.email)+'</span></span></span>'+st+'</div>' +
      '<div class="s">'+(p.lastSeen ? 'Signed in · last seen '+esc(when(p.lastSeen)) : 'Hasn’t signed in yet')+'</div></button>';
    const e = P.edit;
    if(e && e.id === p.id){
      h += '<form class="panel" id="f-pedit">' +
        '<label class="f" for="pe-name">Name<input type="text" id="pe-name" data-bind="pp.edit.name" maxlength="120" value="'+esc(e.name)+'"></label>' +
        '<label class="f" for="pe-email">Email<input type="email" id="pe-email" data-bind="pp.edit.email" maxlength="190" value="'+esc(e.email)+'"></label>' +
        '<label class="f" for="pe-title">Title<input type="text" id="pe-title" data-bind="pp.edit.title" maxlength="120" value="'+esc(e.title)+'"></label>' +
        '<label class="f" for="pe-group">Team<input type="text" id="pe-group" data-bind="pp.edit.group" maxlength="120" value="'+esc(e.group)+'"></label>' +
        roleSelect('pe-role', 'pp.edit.role', e.role) +
        '<p class="muted" style="font-size:13px">Directors see every space. Admins manage people and settings.'+(e.cfgAdmin ? ' This person is also listed as an admin in api/config.php.' : '')+'</p>' +
        '<div class="row" style="gap:8px"><button class="btn small" type="submit"'+(P.busy?' disabled':'')+'>Save</button>' +
        (e.active ? '<button type="button" class="btn ghost small" data-act="pinvite" data-id="'+esc(e.id)+'">Send invitation</button>' : '') +
        (e.id !== S.me.id ? '<button type="button" class="btn ghost small" data-act="pactive">'+(e.active ? 'Turn off' : 'Turn back on')+'</button>' : '') + '</div>' +
        (e.active && e.id !== S.me.id ? '<p class="muted" style="font-size:13px">Turning someone off signs them out and stops new loops reaching them. Their past loops stay.</p>' : '') + '</form>';
    }
    return h;
  }).join('') + '</div>';
  if(P.list && !shown.length) html += '<div class="empty">'+(q ? 'No one matches “'+esc(P.q.trim())+'”.' : 'No one yet. Add your first colleague above.')+'</div>';
  return html;
}

function viewLoop(){
  const l = S.loops.find(x => x.id === S.loopId);
  if(!l) return '<button class="linkbtn" data-act="back">'+I.back+'Back</button><div class="empty">This loop is no longer available.</div>';
  const r = role(l), mineAuthor = l.author === S.me.id, open = l.status === 'open';
  const sp = spaceName(l.spaceId);
  let html = '<div class="row between"><button class="linkbtn" data-act="back">'+I.back+'Back</button><span class="row" style="gap:6px">'+chip(l.type)+(overdue(l)?'<span class="chip c-warn">Overdue</span>':l.due&&open?'<span class="chip c-plain">by '+esc(dueLabel(l.due))+'</span>':'')+'</span></div>';
  if(S.edit && S.edit.id === l.id) return html + editForm(l);
  html += '<div class="head"><div class="muted" style="font-size:13.5px">'+(sp?esc(sp)+' · ':'')+'from '+esc(nm(l.author))+' · '+esc(when(l.createdAt))+(l.editedAt?' · edited '+esc(when(l.editedAt)):'')+'</div><h1 style="font-size:26px;overflow-wrap:anywhere">'+esc(l.title)+'</h1></div>';
  if(l.body) html += '<p style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(l.body)+'</p>';
  html += filesHtml(l.files);

  if(l.status === 'closed' && l.outcome){
    const word = {decide:'Decided', ask:'Answered', do:'Done'}[l.type] || 'Closed';
    html += '<div class="outcome"><span class="eyebrow" style="color:var(--do)">'+word+' · '+esc(nm(l.outcome.by))+' · '+esc(when(l.outcome.at))+'</span><strong style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(l.outcome.text)+'</strong>'+(l.outcome.note?'<span style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(l.outcome.note)+'</span>':'')+filesHtml(l.outcome.files)+'</div>';
  } else if(l.status === 'withdrawn'){
    html += '<div class="soft">Withdrawn by '+esc(nm(l.author))+'.</div>';
  }

  if(l.type === 'decide' && (l.options||[]).length){
    const canPick = open && r === 'decider';
    html += '<div class="group" role="radiogroup" aria-label="Options">' + l.options.map((o,i) => {
      const on = canPick ? S.pick === o.id : !!(l.outcome && l.outcome.optionId === o.id);
      return '<label class="opt'+(on?' on':'')+'" for="opt-'+esc(o.id)+'"><input type="radio" name="opt" id="opt-'+esc(o.id)+'" data-act="pick" data-id="'+esc(o.id)+'"'+(on?' checked':'')+(canPick?'':' disabled')+'><span><strong>'+String.fromCharCode(65+i)+' · '+esc(o.label)+'</strong></span></label>';
    }).join('') + '</div>';
  }

  html += '<div class="panel"><div class="eyebrow">In this loop</div><div class="ppl"><div><span class="row" style="gap:8px">'+av(l.author)+esc(nm(l.author))+'</span><span class="muted">sender</span></div>';
  partIds(l).forEach(id => {
    let st = !delivered(l) ? 'arrives '+clock(l.deliverAt) : (l.seenBy||{})[id] ? 'seen' : 'delivered';
    if(l.outcome && l.outcome.by === id && l.type !== 'tell') st = {decide:'decided', ask:'answered', do:'done'}[l.type] || st;
    html += '<div><span class="row" style="gap:8px">'+av(id)+esc(nm(id))+'</span><span class="muted">'+esc(l.parts[id])+' · '+esc(st)+'</span></div>';
  });
  html += '</div><div class="muted" style="font-size:13px">'+esc(TYPES[l.type].label)+' '+esc(TYPES[l.type].verb)+'.</div></div>';

  const reps = Object.entries(l.replies||{}).map(([k,v]) => Object.assign({k}, v)).sort((a,b) => a.at-b.at);
  if(reps.length) html += '<div class="panel"><div class="eyebrow">Notes</div><div class="thread">'+reps.map(m => noteHtml(m, open)).join('')+'</div></div>';

  if(open){
    let label = 'Add a note', primary = '';
    if(r === 'responder' && l.type === 'ask'){ label = 'Your answer'; primary = '<button class="btn" data-act="answer"'+(S.reply.trim()?'':' disabled')+'>Send answer</button>'; }
    if(r === 'decider' && l.type === 'decide'){ label = 'Note with your choice (optional)'; const o = (l.options||[]).find(o => o.id === S.pick); primary = '<button class="btn" data-act="choose"'+(o?'':' disabled')+'>'+(o ? 'Choose '+String.fromCharCode(65+l.options.indexOf(o)) : 'Pick an option')+'</button>'; }
    if(r === 'owner' && l.type === 'do'){ label = 'Note or proof (optional)'; primary = '<button class="btn" data-act="done">'+I.check+'Mark done</button>'; }
    if(r || mineAuthor){
      html += '<div class="panel"><label class="f" for="reply">'+label+'<textarea id="reply" data-bind="reply" placeholder="Type here">'+esc(S.reply)+'</textarea></label>'+attTray('reply')+'<div class="row end">'+(filesOn()?'<span style="margin-right:auto">'+attButton('reply','Attach')+'</span>':'');
      html += '<button class="btn '+(primary?'ghost ':'')+'small" data-act="note"'+((S.reply.trim()||attReady('reply').length) && !attBusy('reply')?'':' disabled')+'>Post note</button>'+primary+'</div></div>';
    }
    if(mineAuthor){
      const left = nowLeft();
      html += '<div class="row">';
      if(!delivered(l)) html += '<button class="btn ghost small" data-act="sendnow"'+(left>0?'':' disabled')+'>Deliver now'+(nowLimit()?' ('+left+' left)':'')+'</button>';
      else if(!isDigest(l)) html += '<button class="btn ghost small" data-act="nudge"'+(l.nudgedAt?' disabled':'')+'>'+(l.nudgedAt?'Nudged '+esc(dayLabel(l.nudgedAt)):'Nudge once')+'</button>';
      html += '<button class="btn ghost small" data-act="editopen">Edit</button><button class="btn ghost small" data-act="withdraw">Withdraw</button></div>';
      if(S.confirmWithdraw) html += '<div class="soft row between"><span>Withdraw this loop for everyone?</span><span class="row"><button class="btn small" data-act="withdrawyes">Withdraw</button><button class="btn ghost small" data-act="withdrawno">Keep</button></span></div>';
    }
  }
  return html;
}

function peoplePicker(d){
  let html = '';
  const toLabel = {tell:'To', ask:'Who should answer', decide:'Who decides', do:'Who does it', share:'With'}[d.type];
  html += '<div class="f"><span id="to-l">'+toLabel+(SINGLE[d.type]?' (one person)':'')+'</span>';
  if(d.to.length) html += '<div class="pick" aria-label="Chosen">'+d.to.map(id => '<button type="button" data-act="to" data-id="'+esc(id)+'" aria-pressed="true" aria-label="Remove '+esc(fullName(id))+'">'+av(id)+esc(fullName(id))+' ×</button>').join('')+'</div>';
  html += '<input type="search" id="d-q" data-bind="q" placeholder="Search by name, title or SBU" value="'+esc(S.q)+'" autocomplete="off" aria-labelledby="to-l">';
  const list = (S.q.trim() ? searchPeople(S.q) : suggested()).filter(id => !d.to.includes(id));
  if(list.length) html += '<div class="plist">'+(S.q.trim() ? '' : '<span class="muted" style="font-weight:500;font-size:12.5px">Suggested</span>')+list.map(id =>
    '<button type="button" class="prow" data-act="to" data-id="'+esc(id)+'">'+av(id)+'<span class="stack" style="gap:0;min-width:0"><span class="pn">'+esc(fullName(id))+(S.users[id].guest?' <span class="chip c-plain" style="padding:1px 7px">guest</span>':'')+'</span><span class="pm">'+esc(personMeta(id))+'</span></span></button>').join('')+'</div>';
  const qEmail = isEmail(S.q.trim()) ? S.q.trim() : '';
  if(!list.length && qEmail){
    // Typed an email address: offer to send to it straight away.
    if(!S.guestAdd || S.guestAdd.fromQ !== qEmail) S.guestAdd = {name:guessName(qEmail), email:qEmail, fromQ:qEmail, busy:false, err:''};
  } else if(!list.length && S.q.trim()) html += '<span style="font-weight:400">No one at '+esc(orgShort() || 'Loop')+' matches “'+esc(S.q.trim())+'”. For someone outside, type their email address.</span>';
  if(d.type !== 'share' || qEmail) html += guestForm(qEmail);
  html += '</div>';
  return html;
}

function noteHtml(m, open){
  if(m.kind === 'edit') return '<div class="msg edit"><span class="who">'+esc(nm(m.by))+' edited this loop · '+esc(when(m.at))+'</span><span style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(m.text)+'</span></div>';
  if(S.noteEdit && S.noteEdit.id === m.k) return '<div class="msg"><label class="f" for="note-edit">Edit your note<textarea id="note-edit" data-bind="noteEdit.text">'+esc(S.noteEdit.text)+'</textarea></label><div class="row end"><button class="btn ghost small" data-act="notecancel">Cancel</button><button class="btn small" data-act="notesave">Save</button></div></div>';
  const mine = m.by === S.me.id && m.kind === 'note' && open;
  return '<div class="msg"><span class="who">'+esc(nm(m.by))+(m.kind==='answer'?' · answer':'')+' · '+esc(when(m.at))+(m.editedAt?' · edited':'')+(mine?' · <button class="linkbtn" data-act="noteedit" data-id="'+esc(m.k)+'" style="padding:0;font-size:12.5px">Edit</button>':'')+'</span>'+(m.text?'<span style="white-space:pre-wrap;overflow-wrap:anywhere">'+esc(m.text)+'</span>':'')+filesHtml(m.files)+'</div>';
}

function editForm(l){
  const e = S.edit;
  let html = '<div class="head"><div class="eyebrow">Edit '+esc(TYPES[l.type].label)+'</div><h1 style="font-size:26px">Change this loop</h1><p class="muted">Everyone in it sees what changed. People who already read it get it back marked as edited.</p></div><div class="panel">';
  html += '<label class="f" for="e-title">'+(l.type==='ask'||l.type==='decide'?'Question':'Headline')+'<input type="text" id="e-title" data-bind="edit.title" maxlength="200" value="'+esc(e.title)+'"></label>';
  html += '<label class="f" for="e-body">Details (optional)<textarea id="e-body" data-bind="edit.body" style="min-height:64px">'+esc(e.body)+'</textarea></label>';
  html += peoplePicker(e);
  if(l.type === 'decide') html += '<div class="f"><span>Options</span>' + e.options.map((o,i) => '<div class="row" style="flex-wrap:nowrap;gap:6px"><input type="text" id="e-opt-'+i+'" data-bind="edit.options.'+i+'.label" placeholder="Option '+String.fromCharCode(65+i)+'" value="'+esc(o.label)+'" maxlength="140">'+(e.options.length>2?'<button type="button" class="btn ghost small" data-act="optdel" data-i="'+i+'" aria-label="Remove option '+String.fromCharCode(65+i)+'">×</button>':'')+'</div>').join('') + (e.options.length < 4 ? '<button type="button" class="linkbtn" data-act="addopt" style="align-self:flex-start">Add option</button>' : '') + '</div>';
  if(!isDigest(l)) html += '<label class="f" for="e-due">Needed by (optional)<input type="date" id="e-due" data-bind="edit.due" value="'+esc(e.due)+'"></label>';
  if(e.err) html += '<div class="err">'+esc(e.err)+'</div>';
  html += '<div class="row end"><button class="btn ghost" data-act="editcancel">Cancel</button><button class="btn" data-act="editsave"'+(e.busy?' disabled':'')+'>'+(e.busy?'Saving…':'Save changes')+'</button></div></div>';
  return html;
}

async function saveEdit(){
  const e = S.edit; e.err = '';
  if(!e.title.trim()) e.err = 'Add a headline or question.';
  else if(!e.to.length) e.err = 'Choose who this loop is for.';
  else if(e.type === 'decide' && e.options.filter(o => o.label.trim()).length < 2) e.err = 'A Decide loop needs at least two options.';
  if(e.err) return paint();
  e.busy = true; paint();
  try{
    const r = await api('loops.edit', {id:e.id, title:e.title.trim(), body:e.body.trim(), due:e.due, to:e.to,
      options:e.options.filter(o => o.label.trim()).map(o => ({id:o.id, label:o.label.trim()}))});
    S.edit = null; S.q = '';
    toast(r.changed ? 'Saved · everyone in the loop can see what changed' : 'Nothing changed');
    await sync();
  }catch(err){ e.busy = false; e.err = err.message; paint(); }
}

function viewNew(){
  const d = S.draft;
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  let html = '<div class="row between"><button class="linkbtn" data-act="cancelnew">'+I.back+'Cancel</button><span class="muted" style="font-size:13px">'+esc(nowText())+'</span></div>';
  if(d.stage === 'speak'){
    html += '<div class="head"><div class="eyebrow">New loop</div><h1>Say what you need</h1><p class="muted">'+(SR?'Tap the mic and speak, or type it.':'Type it, or use your keyboard’s dictation key.')+'</p></div>';
    html += '<div class="panel speak"><label class="f" for="speak">What do you need?<textarea id="speak" data-bind="draft.text" style="min-height:120px;font-size:17px" placeholder="Ask Priya if the drawings can go out by Monday">'+esc(d.text)+'</textarea></label>';
    if(SR) html += '<button type="button" class="mic'+(S.listening?' on':'')+'" data-act="mic" aria-label="'+(S.listening?'Stop listening':'Speak')+'">'+I.mic+'</button>';
    if(d.err) html += '<div class="err">'+esc(d.err)+'</div>';
    html += '<div class="row end">'+(S.settings.ai
      ? '<button class="btn ghost" data-act="manual">Fill in myself</button><button class="btn" data-act="draft"'+(d.busy||!d.text.trim()?' disabled':'')+'>'+(d.busy?'Drafting…':'Draft my loop')+'</button>'
      : '<button class="btn" data-act="manual">Continue</button>')+'</div></div>';
    return html;
  }
  html += '<div class="head"><div class="eyebrow">'+(d.drafted?'Drafted from what you said':'New loop')+'</div><h1>Check and send</h1></div>';
  if(d.text && d.drafted) html += '<div class="soft muted" style="font-size:14px;overflow-wrap:anywhere">“'+esc(d.text)+'”</div>';
  html += '<div class="panel">';
  html += '<div class="f"><span id="type-l">Type</span><div class="seg" role="group" aria-labelledby="type-l">' + Object.keys(TYPES).map(t => '<button type="button" data-act="type" data-t="'+t+'" aria-pressed="'+(d.type===t)+'" class="'+(d.type===t?'c-'+t:'')+'">'+TYPES[t].label+'</button>').join('') + '</div><span style="font-weight:400">'+esc(TYPES[d.type].label)+' '+esc(TYPES[d.type].verb)+'.</span></div>';
  html += '<label class="f" for="d-title">'+(d.type==='ask'||d.type==='decide'?'Question':'Headline')+'<input type="text" id="d-title" data-bind="draft.title" maxlength="200" value="'+esc(d.title)+'"></label>';
  html += '<label class="f" for="d-body">Details (optional)<textarea id="d-body" data-bind="draft.body" style="min-height:64px">'+esc(d.body)+'</textarea></label>';
  html += peoplePicker(d);
  if(d.type === 'decide') html += '<div class="f"><span>Options</span>' + d.options.map((o,i) => '<input type="text" id="d-opt-'+i+'" data-bind="draft.options.'+i+'" placeholder="Option '+String.fromCharCode(65+i)+'" value="'+esc(o)+'" maxlength="140">').join('') + (d.options.length < 4 ? '<button type="button" class="linkbtn" data-act="addopt" style="align-self:flex-start">Add option</button>' : '') + '</div>';
  html += '<label class="f" for="d-space">Space<select id="d-space" data-bind="draft.spaceId"><option value="">No space</option>'+S.spaces.filter(s => !s.archived).map(s => '<option value="'+esc(s.id)+'"'+(d.spaceId===s.id?' selected':'')+'>'+esc(s.name)+'</option>').join('')+'<option value="__new"'+(d.spaceId==='__new'?' selected':'')+'>New space…</option></select></label>';
  if(d.spaceId === '__new') html += '<label class="f" for="d-newspace">New space name<input type="text" id="d-newspace" data-bind="draft.newSpace" value="'+esc(d.newSpace)+'" maxlength="80"></label>';
  if(!isDigest(d)) html += '<label class="f" for="d-due">Needed by (optional)<input type="date" id="d-due" data-bind="draft.due" value="'+esc(d.due)+'"></label>';
  const left = nowLeft();
  const arrive = d.urgent ? 'immediately' : isDigest(d) ? 'in the '+clock(nextWindow(now(), true))+' digest, '+dayLabel(nextWindow(now(), true)) : 'at the next window, '+when(nextWindow(now()));
  html += '<label class="soft row between" for="d-urgent" style="cursor:pointer"><span>Deliver now <span class="muted">'+(nowLimit()?'('+left+' of '+nowLimit()+' left this week)':'(skips the window)')+'</span></span><input type="checkbox" id="d-urgent" data-bind="draft.urgent"'+(d.urgent?' checked':'')+(left<=0&&!d.urgent?' disabled':'')+' style="width:22px;height:22px;accent-color:var(--ink)"></label>';
  html += '<div class="muted" style="font-size:13.5px">Arrives '+esc(arrive)+'.</div>';
  if(d.err) html += '<div class="err">'+esc(d.err)+'</div>';
  if(filesOn()) html += '<div class="f"><span>Attachments (optional)</span>'+attTray('draft')+'<span>'+attButton('draft','Attach photos or files')+'</span></div>';
  html += '<button class="btn" data-act="send"'+(d.busy||attBusy('draft')?' disabled':'')+'>'+(d.busy?'Sending…':attBusy('draft')?'Uploading…':'Send loop')+'</button></div>';
  return html;
}

/* ---------- actions ---------- */
function setBind(path, val){
  const parts = path.split('.');
  let o = S; for(let i=0;i<parts.length-1;i++){ if(o[parts[i]] == null) return; o = o[parts[i]]; }
  o[parts[parts.length-1]] = val;
}
function go(view){ S.view = view; S.spaceId = null; window.scrollTo(0,0); paint(); }

async function act(action, body, ok){
  try{ const r = await api(action, body); if(ok) toast(ok); await sync(); return r; }
  catch(e){ if(e.code !== 401) toast(e.message); throw e; }
}

function openLoop(id){
  if(S.view !== 'loop') S.from = S.view;
  if(S.view === 'search') S.from = 'search';
  S.view = 'loop'; S.loopId = id; S.reply = ''; attClear('reply'); S.pick = null; S.confirmWithdraw = false; S.edit = null; S.noteEdit = null;
  window.scrollTo(0,0); paint();
  const l = S.loops.find(x => x.id === id);
  if(l && role(l) && delivered(l) && !(l.seenBy||{})[S.me.id]) api('loops.seen', {ids:[id]}).then(sync).catch(() => {});
}

function newDraft(spaceId){
  S.draft = {stage:'speak', text:'', type:'ask', title:'', body:'', to:[], spaceId:spaceId||'', newSpace:'', due:'', options:['',''], urgent:false, busy:false, err:'', drafted:false, adding:false};
  S.q = ''; S.guestAdd = null; attClear('draft');
}

async function aiDraft(){
  const d = S.draft; if(!d.text.trim()) return;
  stopMic();
  d.busy = true; d.err = ''; paint();
  try{
    const r = (await api('ai.draft', {text:d.text})).draft || {};
    if(TYPES[r.type]) d.type = r.type;
    d.title = String(r.title || d.text).slice(0,200);
    d.body = String(r.body || '');
    const valid = others();
    d.to = Array.isArray(r.to) ? r.to.map(String).filter(i => valid.includes(i)) : [];
    if(SINGLE[d.type]) d.to = d.to.slice(0,1);
    if(r.spaceId && space(String(r.spaceId))) d.spaceId = String(r.spaceId);
    else if(r.newSpace){ d.spaceId = '__new'; d.newSpace = String(r.newSpace).slice(0,80); }
    if(r.due && /^\d{4}-\d{2}-\d{2}$/.test(r.due)) d.due = r.due;
    if(d.type === 'decide'){ const o = Array.isArray(r.options) ? r.options.map(String).filter(Boolean).slice(0,4) : []; d.options = o.length >= 2 ? o : (o.concat(['',''])).slice(0, Math.max(2, o.length)); }
    d.stage = 'form'; d.drafted = true;
  }catch(e){ d.err = e.code === 501 ? 'AI drafting is off. Fill it in yourself.' : e.message; }
  d.busy = false; paint();
}

async function sendDraft(){
  const d = S.draft; d.err = '';
  const title = d.title.trim();
  const opts = d.options.map(o => o.trim()).filter(Boolean);
  if(!title) d.err = 'Add a headline or question.';
  else if(!d.to.length) d.err = 'Choose who this loop is for.';
  else if(d.type === 'decide' && opts.length < 2) d.err = 'A Decide loop needs at least two options.';
  else if(d.spaceId === '__new' && !d.newSpace.trim()) d.err = 'Name the new space, or pick an existing one.';
  if(d.err) return paint();
  d.busy = true; paint();
  try{
    const r = await api('loops.create', {
      type:d.type, title, body:d.body, to:d.to, options:d.type === 'decide' ? opts : [],
      spaceId:d.spaceId === '__new' ? '' : d.spaceId, newSpace:d.spaceId === '__new' ? d.newSpace.trim() : '',
      due:isDigest(d) ? '' : d.due, urgent:!!d.urgent, fromTalk:d.fromTalk || '', suggestionId:d.suggestionId || '', fileIds:attReady('draft')
    });
    toast(d.urgent ? 'Sent now' : 'Sent · arrives ' + when(r.deliverAt));
    const back = d.fromThread;
    attClear('draft');
    S.draft = null; window.scrollTo(0,0);
    await sync();
    if(back){ S.view = 'talk'; openThread(back); } else { S.view = 'sent'; paint(); }
  }catch(e){ d.busy = false; d.err = e.message; paint(); }
}


/* ---------- talk: spaces and one-to-one ---------- */
const TALK_POLL_MS = 3000;
function isActive(id){ const u = S.users[id]; return !!(u && u.lastSeen && now() - u.lastSeen < 3*60000); }
function avp(id, lg){ return '<span class="avwrap">'+av(id, lg)+(isActive(id)?'<span class="dot" aria-label="active now"></span>':'')+'</span>'; }
function seenText(id){
  const u = S.users[id]; if(!u || !u.lastSeen) return 'Not on Loop yet';
  return isActive(id) ? 'Active now' : 'Last active '+when(u.lastSeen);
}
function threadOf(t){ return S.threads.find(x => x.thread === t); }
function dmThread(a, b){ const x = [a, b].sort(); return 'd:'+x[0]+':'+x[1]; }
function dmOther(t){ const p = t.split(':'); return p[1] === S.me.id ? p[2] : p[1]; }
function threadName(t){ return t.startsWith('s:') ? spaceName(t.slice(2)) || 'Space' : fullName(dmOther(t)); }
function talkUnread(){ return S.threads.reduce((n, t) => n + (t.unread || 0), 0); }

function viewTalk(){
  let html = topbar() + '<div class="head"><div class="eyebrow">Talk</div><h1>Conversations</h1><p class="muted">Quick back-and-forth, in a project space or one to one. Anything that needs an answer can become a loop.</p></div>';
  html += '<div class="row"><button class="btn small" data-act="dmopen">New message</button><button class="btn ghost small" data-act="go" data-v="spaces">Browse spaces</button></div>';
  if(S.dmPick){
    const q = S.q.trim(), list = q ? searchPeople(q).filter(id => !S.users[id].guest) : suggested();
    html += '<div class="panel"><label class="f" for="dm-q">Message someone<input type="search" id="dm-q" data-bind="q" placeholder="Search by name, title or SBU" value="'+esc(S.q)+'" autocomplete="off"></label>' +
      (list.length ? '<div class="plist">'+list.map(id => '<button type="button" class="prow" data-act="dmstart" data-id="'+esc(id)+'">'+avp(id)+'<span class="stack" style="gap:0;min-width:0"><span class="pn">'+esc(fullName(id))+'</span><span class="pm">'+esc(personMeta(id))+'</span></span></button>').join('')+'</div>' : '<span class="muted">No one matches.</span>') +
      '<button class="linkbtn" data-act="dmclose" style="align-self:flex-start">Cancel</button></div>';
  }
  const list = S.threads.slice().filter(t => t.thread.startsWith('d:') || (space(t.thread.slice(2)) && !space(t.thread.slice(2)).archived))
    .sort((a,b) => ((b.last && b.last.at) || 0) - ((a.last && a.last.at) || 0));
  if(!list.length) return html + '<div class="empty"><strong>No conversations yet</strong><span class="muted">Start one with a colleague, or join a project space to talk with everyone on it.</span></div>';
  html += '<div class="group">' + list.map(t => {
    const isDm = t.thread.startsWith('d:'), other = isDm ? dmOther(t.thread) : null;
    const lead = isDm ? avp(other) : '<span class="av sp" aria-hidden="true">#</span>';
    const last = t.last ? (t.last.kind === 'system' ? nm(t.last.by)+' '+t.last.text : (t.last.by === S.me.id ? 'You: ' : (isDm ? '' : fullName(t.last.by).split(' ')[0]+': '))+(t.last.text || 'Message deleted')) : 'No messages yet';
    return '<button class="card trow" data-act="thread" data-t="'+esc(t.thread)+'"><span class="row" style="flex-wrap:nowrap;gap:12px;min-width:0">'+lead+'<span class="stack" style="gap:2px;min-width:0;flex:1"><span class="row between" style="flex-wrap:nowrap"><span class="t">'+esc(threadName(t.thread))+'</span><span class="muted" style="font-size:12px;white-space:nowrap">'+(t.last ? esc(clock(t.last.at)) : '')+'</span></span><span class="s ell">'+esc(last)+'</span></span>'+
      (t.unread ? '<span class="count'+(t.mentioned?' at':'')+'">'+(t.mentioned?'@':'')+t.unread+'</span>' : '')+'</span></button>';
  }).join('') + '</div>';
  return html;
}

function openThread(t){
  if(S.view !== 'thread') S.from = S.view === 'loop' ? S.from : (S.view === 'new' ? 'talk' : S.view);
  S.view = 'thread';
  S.talk = {thread:t, messages:[], members:[], suggestions:[], after:0, loading:true, err:'', locked:false, lastSpot:0, newSince:0};
  S.talkText = ''; S.talkMentions = {}; S.mentionPick = false; S.msgMenu = null; S.msgEdit = null; S.q = ''; attClear('talk');
  paint(); loadTalk(true);
}
let talkBusy = false;
async function loadTalk(first){
  const T = S.talk; if(!T || talkBusy) return;
  talkBusy = true;
  try{
    const r = await api('talk.list', first ? {thread:T.thread} : {thread:T.thread, after:T.after});
    if(S.talk !== T) return;
    const byId = {}; T.messages.forEach(m => byId[m.id] = m);
    let added = 0;
    r.messages.forEach(m => { if(!byId[m.id]){ added++; if(m.kind === 'msg') T.newSince++; } byId[m.id] = m; });
    T.messages = Object.values(byId).sort((a,b) => a.at - b.at);
    T.members = r.members; T.suggestions = r.suggestions || []; T.after = r.serverNow; T.loading = false; T.locked = false;
    const th = threadOf(T.thread); if(th){ th.unread = 0; th.mentioned = false; }
    if(first || added) paintThread(true);
    maybeSpot();
  }catch(e){
    if(S.talk === T){ T.loading = false; if(e.code === 403){ T.locked = true; T.err = e.message; } paintThread(false); }
  }finally{ talkBusy = false; }
}
function paintThread(stick){
  if(S.view !== 'thread') return;
  const nearBottom = window.innerHeight + window.scrollY >= document.body.scrollHeight - 160;
  paint();
  if(stick && (nearBottom || S.talk.stuck !== true)){ window.scrollTo(0, document.body.scrollHeight); S.talk.stuck = true; }
}
async function maybeSpot(){
  const T = S.talk;
  if(!S.settings.ai || !T || T.locked || T.newSince < 4 || now() - T.lastSpot < 180000) return;
  T.lastSpot = now(); T.newSince = 0;
  try{ const r = await api('ai.spot', {thread:T.thread}); if(S.talk === T && r.suggestions && r.suggestions.length){ T.suggestions = r.suggestions.concat(T.suggestions); paintThread(false); } }catch(e){}
}

function renderText(m){
  let h = esc(m.text);
  (m.mentions || []).forEach(id => { const n = esc('@'+fullName(id)); h = h.split(n).join('<strong class="mention">'+n+'</strong>'); });
  return h;
}
function viewThread(){
  const T = S.talk, t = T.thread, isDm = t.startsWith('d:');
  const sp = isDm ? null : space(t.slice(2)), other = isDm ? dmOther(t) : null;
  let html = '<div class="thead"><button class="linkbtn" data-act="threadback" aria-label="Back">'+I.back+'</button>';
  if(isDm) html += avp(other, true)+'<span class="stack" style="gap:0;min-width:0"><strong class="ell">'+esc(fullName(other))+'</strong><span class="muted" style="font-size:12.5px">'+esc(seenText(other))+'</span></span>';
  else html += '<span class="av sp lg" aria-hidden="true">#</span><span class="stack" style="gap:0;min-width:0;flex:1"><strong class="ell">'+esc(sp ? sp.name : 'Space')+'</strong><span class="muted" style="font-size:12.5px">'+T.members.length+' member'+(T.members.length===1?'':'s')+' · '+T.members.filter(isActive).length+' active</span></span><button class="btn ghost small" data-act="space" data-id="'+esc(t.slice(2))+'" style="flex:none">Details</button>';
  html += '</div>';
  if(T.loading) return html + '<p class="muted">Loading…</p>';
  if(T.locked) return html + '<div class="empty"><strong>'+esc(T.err)+'</strong>'+(S.me.isDirector && !isDm ? '<button class="btn" data-act="spacejoin" data-id="'+esc(t.slice(2))+'" style="align-self:flex-start">Join space</button>' : '')+'</div>';
  html += '<div class="msgs">';
  if(!T.messages.length) html += '<div class="empty"><span class="muted">'+(isDm ? 'Say hello to '+esc(fullName(other).split(' ')[0])+'.' : 'Start the conversation for '+esc(sp ? sp.name : 'this space')+'.')+'</span></div>';
  let lastDay = '', lastBy = '', lastAt = 0;
  T.messages.forEach(m => {
    const day = dayLabel(m.at);
    if(day !== lastDay){ html += '<div class="dayline"><span>'+esc(day[0].toUpperCase()+day.slice(1))+'</span></div>'; lastDay = day; lastBy = ''; }
    if(m.kind === 'system'){
      const l = m.loopId && S.loops.find(x => x.id === m.loopId);
      html += '<div class="sysmsg">'+esc(nm(m.by))+' '+esc(m.text)+(m.loopId ? ' · <button class="linkbtn" data-act="open" data-id="'+esc(m.loopId)+'" style="padding:0;font-size:13px">'+(l && l.status !== 'open' ? 'See outcome' : 'Open loop')+'</button>' : '')+'</div>';
      lastBy = ''; return;
    }
    const grouped = m.by === lastBy && m.at - lastAt < 5*60000;
    lastBy = m.by; lastAt = m.at;
    const mine = m.by === S.me.id;
    if(S.msgEdit && S.msgEdit.id === m.id){
      html += '<div class="msgrow"><label class="f" for="msg-edit" style="width:100%">Edit message<textarea id="msg-edit" data-bind="msgEdit.text">'+esc(S.msgEdit.text)+'</textarea></label><div class="row end"><button class="btn ghost small" data-act="msgeditcancel">Cancel</button><button class="btn small" data-act="msgeditsave">Save</button></div></div>';
      return;
    }
    html += '<div class="msgrow'+(grouped?' grouped':'')+'">' + (grouped ? '<span class="avspace"></span>' : avp(m.by)) + '<div class="msgbody">' +
      (grouped ? '' : '<div class="msgmeta"><strong>'+esc(fullName(m.by))+'</strong> <span class="muted">'+esc(clock(m.at))+'</span></div>') +
      '<button class="msgtext" data-act="msgmenu" data-id="'+esc(m.id)+'">'+(m.kind === 'deleted' ? '<em class="muted">Message deleted</em>' : renderText(m))+(m.editedAt && m.kind !== 'deleted' ? ' <span class="muted" style="font-size:12px">(edited)</span>' : '')+'</button>' + filesHtml(m.kind === 'deleted' ? [] : m.files) +
      (m.loopId ? '<button class="looplink" data-act="open" data-id="'+esc(m.loopId)+'">'+I.loop+' Became a loop</button>' : '') +
      (S.msgMenu === m.id && m.kind === 'msg' ? '<div class="row msgacts">'+(m.loopId ? '' : '<button class="btn small" data-act="msgloop" data-id="'+esc(m.id)+'">Make a loop</button>')+(mine ? '<button class="btn ghost small" data-act="msgedit" data-id="'+esc(m.id)+'">Edit</button><button class="btn ghost small" data-act="msgdel" data-id="'+esc(m.id)+'">Delete</button>' : '')+'</div>' : '') +
      '</div></div>';
  });
  html += '</div>';
  T.suggestions.forEach(sg => {
    const L = sg.loop, who = (L.to || []).map(fullName).join(', ');
    html += '<div class="suggest"><div class="row between" style="flex-wrap:nowrap"><span class="eyebrow">Loop noticed</span>'+chip(L.type)+'</div><strong>'+esc(L.title)+'</strong>'+(who||L.why ? '<span class="muted" style="font-size:13.5px">'+esc([who && (L.type==='decide' ? who+' to decide' : 'for '+who), L.why].filter(Boolean).join(' · '))+'</span>' : '')+
      '<div class="row"><button class="btn small" data-act="sugguse" data-id="'+esc(sg.id)+'">Make it a loop</button><button class="btn ghost small" data-act="suggdismiss" data-id="'+esc(sg.id)+'">Dismiss</button></div></div>';
  });
  return html + composer();
}
function composer(){
  const T = S.talk, isDm = T.thread.startsWith('d:');
  let html = '<div class="composer">' + attTray('talk');
  if(S.mentionPick){
    const q = (S.mq || '').trim(), pool = isDm ? [] : T.members.filter(id => id !== S.me.id);
    const list = q ? searchPeople(q).filter(id => !S.users[id].guest) : pool.slice(0, 8);
    html += '<div class="mentionbox"><input type="search" id="m-q" data-bind="mq" placeholder="Mention someone" value="'+esc(S.mq||'')+'" autocomplete="off">' +
      '<div class="plist">'+list.slice(0,6).map(id => '<button type="button" class="prow" data-act="mention" data-id="'+esc(id)+'">'+av(id)+'<span class="pn">'+esc(fullName(id))+'</span></button>').join('')+'</div></div>';
  }
  html += '<div class="row" style="flex-wrap:nowrap;gap:8px;align-items:flex-end">' + (isDm ? '' : '<button class="btn ghost small" data-act="mentionpick" aria-label="Mention someone" style="flex:none">@</button>') +
    attButton('talk') +
    '<textarea id="talk-text" data-bind="talkText" rows="1" placeholder="Message '+esc(threadName(T.thread))+'">'+esc(S.talkText)+'</textarea>' +
    '<button class="btn small" data-act="talksend" style="flex:none"'+((S.talkText.trim() || attReady('talk').length) && !attBusy('talk')?'':' disabled')+'>Send</button></div></div>';
  return html;
}
async function talkSend(){
  const T = S.talk, text = S.talkText.trim(), fileIds = attReady('talk');
  if(!T || (!text && !fileIds.length)) return;
  if(attBusy('talk')) return toast('Wait for the upload to finish.');
  const mentions = Object.keys(S.talkMentions).filter(id => text.includes('@'+fullName(id)));
  S.talkText = ''; S.talkMentions = {}; S.mentionPick = false; attClear('talk');
  const tmp = {id:'tmp'+Date.now(), thread:T.thread, by:S.me.id, text: text || 'Sending attachment…', kind:'msg', mentions, at:now(), pending:true};
  T.messages.push(tmp); paintThread(true);
  try{
    const r = await api('talk.send', {thread:T.thread, text, mentions, fileIds});
    T.messages = T.messages.filter(m => m !== tmp);
    if(!T.messages.some(m => m.id === r.message.id)) T.messages.push(r.message);
    T.newSince++; paintThread(true); maybeSpot();
  }catch(e){ T.messages = T.messages.filter(m => m !== tmp); S.talkText = text; paintThread(false); toast(e.message); }
}
function loopFromMessage(m){
  const T = S.talk, isDm = T.thread.startsWith('d:');
  newDraft(isDm ? '' : T.thread.slice(2));
  const d = S.draft;
  d.stage = 'form'; d.title = m.text.replace(/\s+/g,' ').slice(0,200); d.fromTalk = m.id; d.fromThread = T.thread;
  const who = isDm ? dmOther(T.thread) : (m.by !== S.me.id ? m.by : null);
  if(who) d.to = [who];
  S.from = 'thread'; S.view = 'new'; window.scrollTo(0,0); paint();
}
function loopFromSuggestion(sg){
  const T = S.talk, L = sg.loop, isDm = T.thread.startsWith('d:');
  newDraft(isDm ? '' : T.thread.slice(2));
  const d = S.draft;
  d.stage = 'form'; d.type = TYPES[L.type] ? L.type : 'ask'; d.title = L.title; d.drafted = true; d.text = '';
  d.to = (L.to || []).filter(id => S.users[id] && id !== S.me.id); if(SINGLE[d.type]) d.to = d.to.slice(0,1);
  if(d.type === 'decide') d.options = (L.options || []).concat(['','']).slice(0, Math.max(2, (L.options||[]).length));
  d.fromTalk = sg.msgId || ''; d.suggestionId = sg.id; d.fromThread = T.thread;
  S.from = 'thread'; S.view = 'new'; window.scrollTo(0,0); paint();
}

function addPeopleBox(s){
  if(!S.addPeople || S.addPeople !== s.id) return '<button class="linkbtn" data-act="addpeople" data-id="'+esc(s.id)+'" style="align-self:flex-start;padding:0">Add people</button>';
  const q = S.q.trim(), mem = s.members || [];
  const list = (q ? searchPeople(q).filter(id => !S.users[id].guest) : suggested()).filter(id => !mem.includes(id)).slice(0, 8);
  return '<div class="soft stack"><label class="f" for="ap-q">Add someone to '+esc(s.name)+'<input type="search" id="ap-q" data-bind="q" placeholder="Search by name, title or SBU" value="'+esc(S.q)+'" autocomplete="off"></label>' +
    (list.length ? '<div class="plist">'+list.map(id => '<button type="button" class="prow" data-act="addmember" data-id="'+esc(id)+'">'+av(id)+'<span class="stack" style="gap:0;min-width:0"><span class="pn">'+esc(fullName(id))+'</span><span class="pm">'+esc(personMeta(id))+'</span></span></button>').join('')+'</div>' : '<span class="muted" style="font-size:13.5px">'+(q ? 'No one matches.' : 'Search for a colleague.')+'</span>') +
    '<button class="linkbtn" data-act="addpeopleclose" style="align-self:flex-start;padding:0">Done</button></div>';
}

/* ---------- search ---------- */
let searchT = null, searchSeq = 0;
function runSearch(){
  clearTimeout(searchT);
  searchT = setTimeout(async () => {
    const q = (S.searchQ || '').trim(), seq = ++searchSeq;
    if(q.length < 2){ S.searchRes = null; return paint(); }
    try{ const r = await api('search', {q}); if(seq === searchSeq){ S.searchRes = r; paint(); } }catch(e){}
  }, 300);
}
function viewSearch(){
  let html = '<div class="thead"><button class="linkbtn" data-act="back" aria-label="Back">'+I.back+'</button><input type="search" id="s-q" data-bind="searchQ" placeholder="Search loops, notes and talk" value="'+esc(S.searchQ||'')+'" autocomplete="off" style="flex:1"></div>';
  const r = S.searchRes, q = (S.searchQ||'').trim();
  if(q.length < 2) return html + '<p class="muted">Type at least two letters. Searches every loop you’re in, its notes, and talk in your spaces and conversations.</p>';
  if(!r) return html + '<p class="muted">Searching…</p>';
  if(!r.loops.length && !r.talk.length) return html + '<div class="empty"><span class="muted">Nothing found for “'+esc(q)+'”.</span></div>';
  if(r.loops.length){
    r.loops.forEach(l => { if(!S.loops.some(x => x.id === l.id)) S.loops.push(l); });
    html += '<div class="group"><div class="eyebrow">Loops</div>'+r.loops.map(l => loopCard(l, l.status === 'open' ? (l.author === S.me.id ? 'sent' : 'needs') : 'closed')).join('')+'</div>';
  }
  if(r.talk.length) html += '<div class="group"><div class="eyebrow">Talk</div>'+r.talk.map(m => '<button class="card" data-act="thread" data-t="'+esc(m.thread)+'"><span class="row between" style="flex-wrap:nowrap"><span class="t ell">'+esc(threadName(m.thread))+'</span><span class="muted" style="font-size:12px;white-space:nowrap">'+esc(shortDate(m.at))+'</span></span><span class="s">'+esc(fullName(m.by))+': '+renderText(m)+'</span></button>').join('')+'</div>';
  return html;
}

/* ---------- phone notifications ---------- */
function pushSupported(){ return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && !!S.settings.vapidPublic; }
async function pushState(){
  if(!pushSupported()) return 'unsupported';
  try{ const reg = await navigator.serviceWorker.ready; const sub = await reg.pushManager.getSubscription(); return sub ? 'on' : (Notification.permission === 'denied' ? 'blocked' : 'off'); }catch(e){ return 'off'; }
}
function b64uToBytes(s){ const b = atob(s.replace(/-/g,'+').replace(/_/g,'/') + '==='.slice((s.length + 3) % 4)); return Uint8Array.from(b, c => c.charCodeAt(0)); }
async function pushOn(){
  try{
    const perm = await Notification.requestPermission();
    if(perm !== 'granted'){ S.push = 'blocked'; paint(); return toast('Notifications are blocked. Allow them in your phone’s settings for Loop.'); }
    const reg = await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:b64uToBytes(S.settings.vapidPublic)});
    await api('push.subscribe', {sub:sub.toJSON()});
    S.push = 'on'; paint(); toast('Notifications on');
  }catch(e){ toast(e.message || 'Couldn’t turn notifications on.'); }
}
async function pushOff(){
  try{
    const reg = await navigator.serviceWorker.ready, sub = await reg.pushManager.getSubscription();
    if(sub){ await api('push.unsubscribe', {endpoint:sub.endpoint}); await sub.unsubscribe(); }
    S.push = 'off'; paint(); toast('Notifications off');
  }catch(e){ toast(e.message || 'Couldn’t turn notifications off.'); }
}
function pushPanel(){
  const st = S.push;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent), standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  let body;
  if(st === 'on') body = '<span>On for this phone. You’ll hear about “now” loops, nudges, messages to you and mentions.</span><button class="btn ghost small" data-act="pushoff" style="align-self:flex-start">Turn off</button>';
  else if(st === 'blocked') body = '<span>Blocked in this phone’s settings. Allow notifications for Loop there, then come back.</span>';
  else if(st === 'unsupported' || !st) body = '<span>'+(ios && !standalone ? 'On iPhone, add Loop to your Home Screen first, then open it from there to turn on notifications.' : 'This browser can’t show Loop notifications.')+'</span>';
  else body = '<span>Get “now” loops, nudges, messages and mentions as notifications on this phone.</span><button class="btn small" data-act="pushon" style="align-self:flex-start">Turn on notifications</button>';
  return '<div class="panel"><div class="eyebrow">Phone notifications</div>'+body+'</div>';
}

/* ---------- people outside the organisation (guests) ---------- */
const isEmail = v => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v);
function guessName(email){ return email.split('@')[0].split(/[._-]+/).filter(Boolean).map(w => w[0].toUpperCase() + w.slice(1)).join(' '); }
function guestForm(typed){
  const g = S.guestAdd;
  if(!g) return '<button type="button" class="linkbtn" data-act="guestopen" style="align-self:flex-start">Someone outside '+esc(orgShort() || 'your team')+'? Add by email</button>';
  return '<div class="soft stack"><span style="font-weight:400;font-size:13.5px">'+(typed ? '<strong>'+esc(typed)+'</strong> isn’t on '+esc(orgShort() ? orgShort()+'’s' : 'the')+' Loop. Send it to them as a guest: ' : '')+'They get an email with a private link to read and reply. No sign-up, and they only see this loop.</span>' +
    '<label class="f" for="g-name">Name<input type="text" id="g-name" data-bind="guestAdd.name" maxlength="120" value="'+esc(g.name)+'" placeholder="e.g. Ramesh (StructCon)"></label>' +
    '<label class="f" for="g-email">Email<input type="email" id="g-email" data-bind="guestAdd.email" inputmode="email" value="'+esc(g.email)+'"></label>' +
    (g.err ? '<div class="err">'+esc(g.err)+'</div>' : '') +
    '<div class="row end"><button type="button" class="btn ghost small" data-act="guestcancel">Cancel</button><button type="button" class="btn small" data-act="guestadd"'+(g.busy?' disabled':'')+'>Add</button></div></div>';
}
async function addGuest(){
  const g = S.guestAdd, d = S.view === 'loop' ? S.edit : S.draft; g.err = '';
  if(!g.name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(g.email.trim())){ g.err = 'Add their name and a valid email.'; return paint(); }
  g.busy = true; paint();
  try{
    const r = await api('people.guest', {name:g.name.trim(), email:g.email.trim()});
    await sync();
    d.to = SINGLE[d.type] ? [r.id] : [...new Set([...d.to, r.id])];
    S.guestAdd = null; S.q = ''; paint();
  }catch(e){ g.busy = false; g.err = e.message; paint(); }
}

/* ---------- attachments ---------- */
// Files go straight from the phone to the office file store with a one-time link; Loop only keeps the record.
const ATT_ACCEPT = 'image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip,.dwg';
const urlCache = {};
function filesOn(){ return !!(S.settings && S.settings.filesMaxMb); }
function fmtSize(b){ return b < 1024*1024 ? Math.max(1, Math.round(b/1024))+' KB' : (b/1048576).toFixed(b < 10485760 ? 1 : 0)+' MB'; }
function attList(ctx){ S.att = S.att || {talk:[], reply:[], draft:[]}; return S.att[ctx]; }
function attReady(ctx){ return attList(ctx).filter(a => a.status === 'ready').map(a => a.id); }
function attBusy(ctx){ return attList(ctx).some(a => a.status === 'uploading'); }
function attClear(ctx){ attList(ctx).forEach(a => { if(a.thumb) try{ URL.revokeObjectURL(a.thumb); }catch(e){} }); S.att[ctx] = []; }
const I_CLIP = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5l-7.8 7.8a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8"/></svg>';
const I_DOC = '<svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/></svg>';

function attButton(ctx, label){
  if(!filesOn()) return '';
  return '<button type="button" class="btn ghost small" data-act="attpick" data-ctx="'+ctx+'"'+(label ? '' : ' aria-label="Attach a photo or file"')+' style="flex:none">'+I_CLIP+(label ? ' '+label : '')+'</button>';
}
function attTray(ctx){
  const list = attList(ctx); if(!list.length) return '';
  return '<div class="atttray">'+list.map((a, i) => '<div class="attchip'+(a.status === 'error' ? ' bad' : '')+'">' +
    (a.thumb ? '<img src="'+a.thumb+'" alt="">' : '<span class="atticon">'+I_DOC+'</span>') +
    '<span class="stack" style="gap:0;min-width:0"><span class="ell" style="font-weight:600;font-size:13.5px">'+esc(a.name)+'</span><span class="muted" style="font-size:12px">'+
    (a.status === 'uploading' ? 'Uploading '+(a.pct||0)+'%' : a.status === 'error' ? esc(a.err || 'Didn’t upload') : fmtSize(a.size))+'</span></span>' +
    '<button type="button" class="linkbtn" data-act="attremove" data-ctx="'+ctx+'" data-i="'+i+'" aria-label="Remove '+esc(a.name)+'" style="padding:6px">×</button></div>').join('')+'</div>';
}
const fileIndex = {};
function filesHtml(files){
  if(!files || !files.length) return '';
  files.forEach(f => fileIndex[f.id] = f);
  const live = files.filter(f => (f.state || 'ready') === 'ready'), gone = files.filter(f => f.state && f.state !== 'ready');
  const imgs = live.filter(f => f.image), docs = live.filter(f => !f.image);
  let h = '';
  if(imgs.length) h += '<div class="attimgs">'+imgs.map(f => '<button type="button" class="attimg" data-act="fileview" data-id="'+esc(f.id)+'" aria-label="'+esc(f.name)+'"><img data-fid="'+esc(f.id)+'" alt="'+esc(f.name)+'"'+(urlCache[f.id] && urlCache[f.id].exp > Date.now() ? ' src="'+esc(urlCache[f.id].url)+'"' : '')+'>'+(f.expiresAt && !f.keptBy ? '' : f.keptBy && f.kind === 'talk' ? '<span class="keptmark" aria-label="Kept">Kept</span>' : '')+'</button>').join('')+'</div>';
  if(docs.length) h += '<div class="attdocs">'+docs.map(f => '<button type="button" class="attdoc" data-act="fileview" data-id="'+esc(f.id)+'">'+I_DOC+'<span class="stack" style="gap:0;min-width:0;text-align:left"><span class="ell" style="font-weight:600">'+esc(f.name)+'</span><span class="muted" style="font-size:12px">'+fmtSize(f.size)+(f.keptBy && f.kind === 'talk' ? ' · kept' : '')+'</span></span></button>').join('')+'</div>';
  if(gone.length) h += '<div class="attdocs">'+gone.map(f => '<div class="attgone">'+esc(f.name)+' · '+(f.state === 'expired' ? 'expired after '+(S.settings.talkDays || 30)+' days' : 'removed')+'</div>').join('')+'</div>';
  return h;
}
function viewerHtml(){
  const f = S.viewer && fileIndex[S.viewer]; if(!f) return '';
  const mine = f.by === S.me.id, canRemove = mine || S.me.isDirector;
  const expiring = f.kind === 'talk' && f.expiresAt && !f.keptBy;
  const c = urlCache[f.id];
  return '<div class="viewer" role="dialog" aria-modal="true" aria-label="'+esc(f.name)+'"><div class="vsheet">' +
    '<div class="row between" style="flex-wrap:nowrap"><strong class="ell">'+esc(f.name)+'</strong><button class="linkbtn" data-act="viewclose" style="padding:6px 0 6px 12px">Close</button></div>' +
    (f.image ? '<img class="vimg" data-fid="'+esc(f.id)+'" alt="'+esc(f.name)+'"'+(c && c.exp > Date.now() ? ' src="'+esc(c.url)+'"' : '')+'>' : '<div class="vdoc">'+I_DOC+'<span>'+fmtSize(f.size)+'</span></div>') +
    '<div class="muted" style="font-size:13.5px">Sent by '+esc(nm(f.by))+'. '+(expiring ? 'Leaves the file store on '+esc(new Date(f.expiresAt).toLocaleDateString([], {day:'numeric', month:'short'}))+'. Save it to your phone, or keep it here for everyone.' : f.keptBy && f.kind === 'talk' ? 'Kept for good by '+esc(nm(f.keptBy))+'.' : 'Stays with this loop.')+'</div>' +
    '<div class="row"><button class="btn small" data-act="filesave" data-id="'+esc(f.id)+'">Save to phone</button><button class="btn ghost small" data-act="fileopen" data-id="'+esc(f.id)+'">Open</button>' +
    (expiring ? '<button class="btn ghost small" data-act="filekeep" data-id="'+esc(f.id)+'">Keep for good</button>' : '') +
    (canRemove ? (S.confirmRemove === f.id ? '<button class="btn small danger" data-act="fileremoveyes" data-id="'+esc(f.id)+'">Remove for everyone</button>' : '<button class="btn ghost small" data-act="fileremove" data-id="'+esc(f.id)+'">Remove</button>') : '') +
    '</div></div></div>';
}
async function saveFile(id){
  const f = fileIndex[id]; if(!f) return;
  try{
    const url = await fileUrl(id);
    // Phones: share sheet → "Save Image" / "Save to Files". Elsewhere: a normal download.
    if(navigator.canShare){
      const blob = await (await fetch(url)).blob();
      const file = new File([blob], f.name, {type:f.mime});
      if(navigator.canShare({files:[file]})){ await navigator.share({files:[file]}); return; }
    }
    const r = await api('files.get', {id, download:true});
    const a = document.createElement('a'); a.href = r.url; a.download = f.name; document.body.appendChild(a); a.click(); a.remove();
  }catch(e){ if(e && e.name !== 'AbortError') toast(e.message || 'Couldn’t save that file.'); }
}
function refreshAfterFileChange(){
  if(S.view === 'thread' && S.talk){ S.talk.messages.forEach(m => (m.files || []).forEach(f => { if(fileIndex[f.id]) Object.assign(f, fileIndex[f.id]); })); paint(); }
  else sync();
}
async function fileUrl(id){
  const c = urlCache[id]; if(c && c.exp > Date.now()) return c.url;
  const r = await api('files.get', {id});
  urlCache[id] = {url:r.url, exp:Date.now() + 8*60000};
  return r.url;
}
let thumbBusy = false;
async function hydrateThumbs(){
  if(thumbBusy) return; thumbBusy = true;
  try{
    for(const img of [...document.querySelectorAll('img[data-fid]:not([src])')]){
      try{ img.src = await fileUrl(img.dataset.fid); }catch(e){ img.alt = 'Photo unavailable'; img.setAttribute('src', 'data:,'); }
    }
  }finally{ thumbBusy = false; }
}
async function openFile(id){
  // Open a tab synchronously so phones don't block it, then point it at the signed link.
  const w = window.open('', '_blank');
  try{ const u = await fileUrl(id); if(w) w.location = u; else location.href = u; }
  catch(e){ if(w) w.close(); toast(e.message || 'Couldn’t open that file.'); }
}
async function shrinkImage(file){
  if(!/^image\/(jpeg|png|webp|heic|heif)$/.test(file.type) || !('createImageBitmap' in window)) return {blob:file};
  try{
    const bmp = await createImageBitmap(file);
    const scale = Math.min(1, 2048 / Math.max(bmp.width, bmp.height));
    const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
    if(scale === 1 && file.type === 'image/jpeg' && file.size < 1.5*1048576) return {blob:file, w, h};
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    c.getContext('2d').drawImage(bmp, 0, 0, w, h);
    const blob = await new Promise(res => c.toBlob(res, 'image/jpeg', 0.82));
    if(!blob || blob.size >= file.size) return {blob:file, w, h};
    return {blob, w, h, name:file.name.replace(/\.(png|webp|heic|heif|jpe?g)$/i, '') + '.jpg', type:'image/jpeg'};
  }catch(e){ return {blob:file}; }
}
function putWithProgress(url, blob, type, onPct){
  return new Promise((resolve, reject) => {
    const x = new XMLHttpRequest();
    x.open('PUT', url); x.setRequestHeader('Content-Type', type);
    x.upload.onprogress = e => { if(e.lengthComputable) onPct(Math.round(e.loaded / e.total * 100)); };
    x.onload = () => x.status >= 200 && x.status < 300 ? resolve() : reject(new Error('The file store said no ('+x.status+').'));
    x.onerror = () => reject(new Error('Couldn’t reach the office file store.'));
    x.send(blob);
  });
}
async function uploadOne(ctx, file){
  const a = {name:file.name || 'file', size:file.size, status:'uploading', pct:0, thumb:null};
  attList(ctx).push(a); paint();
  try{
    const s = await shrinkImage(file);
    const blob = s.blob, type = s.type || file.type || 'application/octet-stream', name = s.name || a.name;
    a.name = name; a.size = blob.size;
    if(/^image\//.test(type)){ try{ a.thumb = URL.createObjectURL(blob); }catch(e){} }
    if(blob.size > S.settings.filesMaxMb * 1048576) throw new Error('Over '+S.settings.filesMaxMb+' MB');
    const st = await api('files.start', {name, mime:type, size:blob.size, w:s.w || null, h:s.h || null});
    a.id = st.id; paint();
    let last = 0;
    await putWithProgress(st.put, blob, st.mime, p => { a.pct = p; if(p - last >= 10){ last = p; paint(); } });
    await api('files.finish', {id:st.id});
    a.status = 'ready';
  }catch(e){ a.status = 'error'; a.err = e.message || 'Didn’t upload'; }
  paint();
}
function pickFiles(ctx){
  let inp = document.getElementById('file-pick');
  if(!inp){ inp = document.createElement('input'); inp.type = 'file'; inp.id = 'file-pick'; inp.multiple = true; inp.accept = ATT_ACCEPT; inp.hidden = true; document.body.appendChild(inp);
    inp.addEventListener('change', () => { const files = [...inp.files]; inp.value = ''; const c = inp.dataset.ctx;
      const room = 10 - attList(c).length; if(files.length > room) toast('Up to 10 files at a time.');
      files.slice(0, Math.max(0, room)).forEach(f => uploadOne(c, f)); }); }
  inp.dataset.ctx = ctx; inp.click();
}

/* ----- voice ----- */
let rec = null;
function startMic(){
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition; if(!SR) return;
  rec = new SR(); rec.lang = navigator.language && navigator.language.startsWith('en') ? navigator.language : 'en-IN';
  rec.interimResults = true; rec.continuous = false;
  const base = S.draft.text ? S.draft.text.replace(/\s*$/, ' ') : '';
  rec.onresult = ev => {
    let t = ''; for(let i=0;i<ev.results.length;i++) t += ev.results[i][0].transcript;
    S.draft.text = base + t;
    const ta = document.getElementById('speak'); if(ta) ta.value = S.draft.text;
    const b = document.querySelector('[data-act="draft"]'); if(b) b.disabled = !S.draft.text.trim();
  };
  rec.onerror = ev => { if(ev.error === 'not-allowed' || ev.error === 'service-not-allowed') toast('Allow microphone access to speak, or type instead.'); };
  rec.onend = () => { S.listening = false; rec = null; if(S.view === 'new') paint(); };
  try{ rec.start(); S.listening = true; paint(); }catch(e){ S.listening = false; }
}
function stopMic(){ if(rec){ try{ rec.stop(); }catch(e){} } }

/* ---------- events ---------- */
document.addEventListener('click', async ev => {
  const el = ev.target.closest('[data-act]'); if(!el) return;
  const a = el.dataset.act;
  if(a === 'pick'){ S.pick = el.dataset.id; return paint(); }
  if(el.tagName === 'BUTTON' && el.type !== 'submit') ev.preventDefault();
  const L = () => S.loops.find(x => x.id === S.loopId);
  switch(a){
    case 'go': S.viewer = null; return go(el.dataset.v);
    case 'new': if(S.view !== 'new') S.from = S.view === 'loop' ? S.from : S.view; newDraft(el.dataset.space); S.view = 'new'; window.scrollTo(0,0); return paint();
    case 'cancelnew': stopMic(); S.draft = null; return go(S.from && S.from !== 'new' ? S.from : 'home');
    case 'open': return openLoop(el.dataset.id);
    case 'back': if(S.view === 'search'){ S.view = S.from && S.from !== 'search' ? S.from : 'home'; return paint(); } S.view = S.from || 'home'; if(S.view === 'thread' && S.talk){ paint(); return loadTalk(false); } return paint();
    case 'peek': S.peek = !S.peek; return paint();
    case 'space': S.spaceEdit = null; S.addPeople = null; S.spaceId = el.dataset.id; window.scrollTo(0,0); return paint();
    case 'spaceback': S.spaceId = null; S.spaceEdit = null; return paint();
    case 'showarchived': S.showArchived = !S.showArchived; return paint();
    case 'spaceedit': { const sp = space(S.spaceId); S.spaceEdit = {id:sp.id, name:sp.name, kind:sp.kind, busy:false, err:''}; return paint(); }
    case 'spaceeditcancel': S.spaceEdit = null; return paint();
    case 'spacearchive': { const sp = space(S.spaceId), e = S.spaceEdit; await act('spaces.update', {id:sp.id, name:e.name.trim() || sp.name, kind:e.kind, archived:!sp.archived}, sp.archived ? 'Space restored' : 'Space archived').then(() => { S.spaceEdit = null; paint(); }).catch(() => {}); return; }
    case 'hideinstall': store('loop.installHidden', '1'); return paint();
    case 'install': if(S.installEvt){ S.installEvt.prompt(); S.installEvt = null; paint(); } return;
    case 'mic': return S.listening ? stopMic() : startMic();
    case 'manual': stopMic(); S.draft.stage = 'form'; if(!S.draft.title && S.draft.text) S.draft.title = S.draft.text.slice(0,200); return paint();
    case 'draft': return aiDraft();
    case 'type': { const d = S.draft; d.type = el.dataset.t; if(SINGLE[d.type]) d.to = d.to.slice(0,1); if(d.type === 'decide' && d.options.length < 2) d.options = ['','']; if(isDigest(d)) d.due = ''; return paint(); }
    case 'to': { const d = S.view === 'loop' ? S.edit : S.draft, id = el.dataset.id; d.to = d.to.includes(id) ? d.to.filter(x => x !== id) : SINGLE[d.type] ? [id] : [...d.to, id]; S.q = ''; return paint(); }
    case 'addopt': if(S.view === 'loop') S.edit.options.push({id:null, label:''}); else S.draft.options.push(''); return paint();
    case 'optdel': S.edit.options.splice(+el.dataset.i, 1); return paint();
    case 'editopen': { const l = L(); S.edit = {id:l.id, type:l.type, title:l.title, body:l.body||'', due:l.due||'', to:partIds(l), options:(l.options||[]).map(o => ({id:o.id, label:o.label})), busy:false, err:''}; S.q = ''; window.scrollTo(0,0); return paint(); }
    case 'editcancel': S.edit = null; S.q = ''; return paint();
    case 'editsave': return saveEdit();
    case 'noteedit': { const l = L(), n = l && (l.replies||{})[el.dataset.id]; if(n) S.noteEdit = {id:el.dataset.id, text:n.text}; return paint(); }
    case 'notecancel': S.noteEdit = null; return paint();
    case 'notesave': { const ne = S.noteEdit; if(!ne || !ne.text.trim()) return; await act('notes.edit', {noteId:ne.id, text:ne.text.trim()}, 'Note updated').then(() => { S.noteEdit = null; paint(); }).catch(() => {}); return; }
    case 'send': return sendDraft();
    case 'seenall': { const ids = S.loops.filter(l => needsMe(l) && isDigest(l)).map(l => l.id); if(ids.length) await act('loops.seen', {ids}, 'Marked '+ids.length+' as seen').catch(() => {}); return; }
    case 'note': { const text = S.reply.trim(), fileIds = attReady('reply'); if((!text && !fileIds.length) || attBusy('reply')) return; await act('loops.note', {id:S.loopId, text, fileIds}, 'Note posted').then(() => { S.reply = ''; attClear('reply'); paint(); }).catch(() => {}); return; }
    case 'answer': { const text = S.reply.trim(); if(!text) return toast('Write your answer, then send.'); if(attBusy('reply')) return toast('Wait for the upload to finish.'); await act('loops.answer', {id:S.loopId, text, fileIds:attReady('reply')}, 'Answered · loop closed').then(() => { S.reply = ''; attClear('reply'); paint(); }).catch(() => {}); return; }
    case 'choose': { if(!S.pick) return; if(attBusy('reply')) return toast('Wait for the upload to finish.'); await act('loops.choose', {id:S.loopId, optionId:S.pick, note:S.reply.trim(), fileIds:attReady('reply')}, 'Decided · recorded in the ledger').then(() => { S.reply = ''; attClear('reply'); paint(); }).catch(() => {}); return; }
    case 'done': if(attBusy('reply')) return toast('Wait for the upload to finish.'); await act('loops.done', {id:S.loopId, note:S.reply.trim(), fileIds:attReady('reply')}, 'Marked done').then(() => { S.reply = ''; attClear('reply'); paint(); }).catch(() => {}); return;
    case 'attpick': return pickFiles(el.dataset.ctx);
    case 'attremove': { const list = attList(el.dataset.ctx), a = list[+el.dataset.i]; if(a){ list.splice(+el.dataset.i, 1); if(a.thumb) URL.revokeObjectURL(a.thumb); if(a.id && a.status !== 'error') api('files.discard', {id:a.id}).catch(() => {}); } return paint(); }
    case 'fileopen': return openFile(el.dataset.id);
    case 'fileview': S.viewer = el.dataset.id; S.confirmRemove = null; return paint();
    case 'viewclose': S.viewer = null; S.confirmRemove = null; return paint();
    case 'filesave': return saveFile(el.dataset.id);
    case 'filekeep': { const id = el.dataset.id; try{ await api('files.keep', {id}); const f = fileIndex[id]; if(f){ f.keptBy = S.me.id; f.expiresAt = null; } toast('Kept for good'); paint(); refreshAfterFileChange(); }catch(e){ toast(e.message); } return; }
    case 'fileremove': S.confirmRemove = el.dataset.id; return paint();
    case 'fileremoveyes': { const id = el.dataset.id; try{ await api('files.remove', {id}); const f = fileIndex[id]; if(f) f.state = 'removed'; S.viewer = null; S.confirmRemove = null; toast('Removed'); paint(); refreshAfterFileChange(); }catch(e){ toast(e.message); } return; }
    case 'storecheck': { S.storeCheck = 'busy'; paint(); try{ const r = await api('files.check', {}); S.storeCheck = r.steps; }catch(e){ S.storeCheck = [{step:'Reach the file store', ok:false, detail:e.message}]; } return paint(); }
    case 'nudge': await act('loops.nudge', {id:S.loopId}, 'Nudged. It moves to the top of their list.').catch(() => {}); return;
    case 'sendnow': await act('loops.sendnow', {id:S.loopId}, 'Delivered now').catch(() => {}); return;
    case 'withdraw': S.confirmWithdraw = true; return paint();
    case 'withdrawno': S.confirmWithdraw = false; return paint();
    case 'withdrawyes': S.confirmWithdraw = false; await act('loops.withdraw', {id:S.loopId}, 'Withdrawn').then(() => go(S.from || 'sent')).catch(() => {}); return;
    case 'authback': S.auth = {stage:'email', email:S.auth.email, code:'', busy:false, err:''}; return paint();
    case 'resend': return requestCode();
    case 'retry': S.loadErr = ''; paint(); return sync();
    case 'thread': return openThread(el.dataset.t);
    case 'threadback': S.talk = null; S.view = S.from && !['thread','new','loop'].includes(S.from) ? S.from : 'talk'; window.scrollTo(0,0); sync(); return paint();
    case 'dmopen': S.dmPick = true; S.q = ''; paint(); { const i = document.getElementById('dm-q'); if(i) i.focus(); } return;
    case 'dmclose': S.dmPick = false; S.q = ''; return paint();
    case 'dmstart': S.dmPick = false; S.q = ''; S.from = 'talk'; return openThread(dmThread(S.me.id, el.dataset.id));
    case 'addpeople': S.addPeople = el.dataset.id; S.q = ''; paint(); { const i = document.getElementById('ap-q'); if(i) i.focus(); } return;
    case 'addpeopleclose': S.addPeople = null; S.q = ''; return paint();
    case 'addmember': { const id = el.dataset.id; await act('spaces.addMember', {id:S.spaceId, personId:id}, fullName(id)+' added').catch(() => {}); S.q = ''; paint(); return; }
    case 'spacejoin': { const id = el.dataset.id; await act('spaces.join', {id}, 'Joined').catch(() => {}); if(S.view === 'thread') openThread('s:'+id); return; }
    case 'spaceleave': await act('spaces.leave', {id:el.dataset.id}, 'Left the space').catch(() => {}); return;
    case 'talksend': return talkSend();
    case 'msgmenu': S.msgMenu = S.msgMenu === el.dataset.id ? null : el.dataset.id; return paint();
    case 'msgloop': { const m = S.talk.messages.find(x => x.id === el.dataset.id); if(m) loopFromMessage(m); return; }
    case 'msgedit': { const m = S.talk.messages.find(x => x.id === el.dataset.id); if(m) S.msgEdit = {id:m.id, text:m.text}; S.msgMenu = null; return paint(); }
    case 'msgeditcancel': S.msgEdit = null; return paint();
    case 'msgeditsave': { const e = S.msgEdit; if(!e || !e.text.trim()) return; try{ await api('talk.edit', {id:e.id, text:e.text.trim()}); const m = S.talk.messages.find(x => x.id === e.id); if(m){ m.text = e.text.trim(); m.editedAt = now(); } S.msgEdit = null; paint(); }catch(err){ toast(err.message); } return; }
    case 'msgdel': { const id = el.dataset.id; try{ await api('talk.delete', {id}); const m = S.talk.messages.find(x => x.id === id); if(m){ m.kind = 'deleted'; m.text = ''; } S.msgMenu = null; paint(); }catch(err){ toast(err.message); } return; }
    case 'mentionpick': S.mentionPick = !S.mentionPick; S.mq = ''; paint(); { const i = document.getElementById('m-q'); if(i) i.focus(); } return;
    case 'mention': { const id = el.dataset.id; S.talkMentions[id] = true; S.talkText = (S.talkText.replace(/\s*$/, '') + ' @' + fullName(id) + ' ').replace(/^ /, ''); S.mentionPick = false; paint(); const t = document.getElementById('talk-text'); if(t){ t.focus(); t.setSelectionRange(t.value.length, t.value.length); } return; }
    case 'sugguse': { const sg = S.talk.suggestions.find(x => x.id === el.dataset.id); if(sg) loopFromSuggestion(sg); return; }
    case 'suggdismiss': { const id = el.dataset.id; S.talk.suggestions = S.talk.suggestions.filter(x => x.id !== id); paint(); api('suggestions.update', {id, status:'dismissed'}).catch(() => {}); return; }
    case 'search': if(S.view !== 'search') S.from = S.view; S.view = 'search'; paint(); { const i = document.getElementById('s-q'); if(i) i.focus(); } return;
    case 'pushon': return pushOn();
    case 'pushoff': return pushOff();
    case 'guestopen': S.guestAdd = {name:'', email:'', busy:false, err:''}; return paint();
    case 'guestcancel': S.guestAdd = null; S.q = ''; return paint();
    case 'guestadd': return addGuest();
    case 'people': S.pp = {list:null, q:'', add:{name:'', email:'', title:'', group:'', role:'member', invite:true}, adding:false, edit:null, busy:false, err:'', imp:null, impInvite:true}; go('people'); return loadPeople();
    case 'padd': S.pp.adding = !S.pp.adding; S.pp.err = ''; return paint();
    case 'pedit': { const p = S.pp.list.find(x => x.id === el.dataset.id); S.pp.edit = S.pp.edit && S.pp.edit.id === p.id ? null : {...p}; S.pp.err = ''; return paint(); }
    case 'pinvite': { const id = el.dataset.id; el.disabled = true; try{ await api('people.invite', {id}); toast('Invitation sent'); }catch(e){ toast(e.message); } el.disabled = false; return; }
    case 'pactive': { const e = S.pp.edit; if(!e) return; return savePerson({id:e.id, active:!e.active}, e.active ? 'Turned off. They’re signed out.' : 'Turned back on'); }
    case 'pimport': return pickCsv();
    case 'pimpclose': S.pp.imp = null; return paint();
    case 'wswadd': { const w = S.wsx.f.windows; w.push(w.length ? '17:30' : '11:30'); return paint(); }
    case 'wswdel': { S.wsx.f.windows.splice(+el.dataset.i, 1); return paint(); }
    case 'wsexport': {
      el.disabled = true;
      try{
        const r = await api('ws.export', {});
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([JSON.stringify(r.export, null, 2)], {type:'application/json'}));
        a.download = r.filename; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        toast('Export downloaded');
      }catch(e){ toast(e.message); }
      el.disabled = false; return;
    }
    case 'logout': try{ await api('auth.logout', {}); }catch(e){} signedOut(); return;
  }
});

document.addEventListener('input', ev => {
  const el = ev.target, b = el.dataset && el.dataset.bind; if(!b) return;
  setBind(b, el.type === 'checkbox' ? el.checked : el.value);
  if(b === 'q' || b === 'mq' || b === 'pp.q') return paint();
  if(b === 'wsx.del'){ const btn = document.querySelector('#f-wsdel button[type=submit]'); if(btn) btn.disabled = el.value.trim() !== S.wsx.ws.slug; }
  if(b === 'searchQ'){ S.searchRes = null; return runSearch(); }
  if(b === 'talkText'){ const sb = document.querySelector('[data-act="talksend"]'); if(sb) sb.disabled = !(S.talkText.trim() || attReady('talk').length) || attBusy('talk'); el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 160) + 'px'; }
  if(b === 'reply'){ document.querySelectorAll('[data-act="note"]').forEach(x => x.disabled = !(S.reply.trim() || attReady('reply').length) || attBusy('reply')); document.querySelectorAll('[data-act="answer"]').forEach(x => x.disabled = !S.reply.trim()); }
  if(b === 'draft.text'){ const dr = document.querySelector('[data-act="draft"]'); if(dr) dr.disabled = S.draft.busy || !S.draft.text.trim(); }
  if(b === 'auth.code' && el.value.replace(/\D/g,'').length === 6) verifyCode();
});
document.addEventListener('keydown', ev => {
  if(ev.target && ev.target.id === 'talk-text' && ev.key === 'Enter' && !ev.shiftKey && matchMedia('(hover: hover)').matches){ ev.preventDefault(); talkSend(); }
});
document.addEventListener('change', ev => {
  const t = ev.target;
  if(t.dataset && t.dataset.wsw !== undefined && S.wsx){ S.wsx.f.windows[+t.dataset.wsw] = t.value; return; }
  if(t.dataset && t.dataset.wsd !== undefined && S.wsx){ const d = +t.dataset.wsd, k = S.wsx.f.skipDays; t.checked ? (k.includes(d) || k.push(d)) : k.splice(k.indexOf(d), 1); return paint(); }
  const el = ev.target, b = el.dataset && el.dataset.bind; if(!b) return;
  if(el.tagName === 'SELECT' || el.type === 'checkbox' || el.type === 'date'){ setBind(b, el.type === 'checkbox' ? el.checked : el.value); paint(); }
});
document.addEventListener('submit', async ev => {
  ev.preventDefault();
  const id = ev.target.id;
  if(id === 'f-email') return requestCode();
  if(id === 'f-code') return verifyCode();
  if(id === 'f-spaceedit'){
    const e = S.spaceEdit; if(!e) return;
    if(!e.name.trim()){ e.err = 'Give the space a name.'; return paint(); }
    e.busy = true; e.err = ''; paint();
    try{ await api('spaces.update', {id:e.id, name:e.name.trim(), kind:e.kind}); S.spaceEdit = null; toast('Space updated'); await sync(); }
    catch(err){ e.busy = false; e.err = err.message; paint(); }
    return;
  }
  if(id === 'f-space'){
    const name = document.getElementById('sp-name').value.trim(), kind = document.getElementById('sp-kind').value;
    if(!name) return toast('Give the space a name.');
    await act('spaces.create', {name, kind}, 'Space added').catch(() => {});
  }
  if(id === 'f-padd'){
    const a = S.pp.add;
    if(!a.name.trim() || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(a.email.trim())){ S.pp.err = 'Add a name and a valid email.'; return paint(); }
    S.pp.busy = true; S.pp.err = ''; paint();
    try{
      const r = await api('people.add', {name:a.name.trim(), email:a.email.trim(), title:a.title.trim(), group:a.group.trim(), role:a.role, invite:a.invite});
      toast(r.state === 'updated' ? 'Already on Loop, details updated' : (r.invited ? 'Added and invited' : 'Added'));
      S.pp.add = {name:'', email:'', title:'', group:'', role:a.role, invite:a.invite};
      await loadPeople(); sync();
    }catch(e){ S.pp.err = e.message; }
    S.pp.busy = false; return paint();
  }
  if(id === 'f-pedit'){
    const e = S.pp.edit; if(!e) return;
    return savePerson({id:e.id, name:e.name.trim(), email:e.email.trim(), title:e.title.trim(), group:e.group.trim(), role:e.role}, 'Saved');
  }
  if(id === 'f-ws'){
    const X = S.wsx; if(!X || !X.f) return;
    X.busy = true; X.err = ''; paint();
    try{ const r = await api('ws.save', X.f); X.ws = r.ws; X.f.windows = r.ws.windows.slice(); X.f.selfJoin = !!r.ws.joinDomain; toast('Workspace saved'); await sync(); }
    catch(e){ X.err = e.message; }
    X.busy = false; return paint();
  }
  if(id === 'f-wsdel'){
    const X = S.wsx; if(!X || X.del.trim() !== X.ws.slug) return;
    try{
      const r = await api('ws.delete', {confirm:X.del.trim()});
      store(CACHE_KEY, null); store('loop.org', null);
      app.innerHTML = '<div class="head"><div class="eyebrow">LOOP</div><h1>'+esc(X.ws.orgName)+' has been deleted.</h1><p class="muted">Everything in it has been erased. Thanks for trying LOOP.</p></div><a class="btn" href="'+esc(r.home)+'" style="align-self:flex-start;text-decoration:none">Start a new workspace</a>';
      nav.hidden = true;
    }catch(e){ toast(e.message); }
    return;
  }
  if(id === 'f-me'){
    const notifyEmail = document.getElementById('me-notify').checked;
    const nameEl = document.getElementById('me-name');
    if(nameEl && !nameEl.value.trim()) return toast('Add your name.');
    await act('me.update', nameEl ? {notifyEmail, name:nameEl.value.trim()} : {notifyEmail}, 'Saved').catch(() => {});
  }
});

async function requestCode(){
  const a = S.auth; a.err = '';
  const email = (a.email || '').trim().toLowerCase();
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ a.err = 'Enter a valid email address.'; return paint(); }
  a.busy = true; paint();
  try{ await api('auth.request', {email}); store('loop.email', email); S.auth = {stage:'code', email, code:'', busy:false, err:''}; paint(); toast('Code sent. Check your email.'); }
  catch(e){ a.busy = false; a.err = e.message; paint(); }
}
let verifying = false;
async function verifyCode(){
  const a = S.auth; if(verifying || !a) return;
  const code = (a.code || '').replace(/\D/g,'');
  if(code.length !== 6){ a.err = 'Enter the 6-digit code from the email.'; return paint(); }
  verifying = true; a.busy = true; a.err = ''; paint();
  try{
    await api('auth.verify', {email:a.email, code});
    S.view = 'home'; S.auth = null;
    await sync();
  }catch(e){ a.busy = false; a.err = e.message; paint(); }
  verifying = false;
}

window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); S.installEvt = e; if(S.view === 'home') paint(); });
document.addEventListener('visibilitychange', () => { if(document.visibilityState === 'visible' && S.ready) sync(); });
window.addEventListener('online', () => { if(S.ready) sync(); });
setInterval(() => { if(document.visibilityState === 'visible' && S.ready && S.view !== 'login' && S.view !== 'thread') sync(); }, POLL_MS);
setInterval(() => { if(document.visibilityState === 'visible' && S.view === 'thread' && S.talk && !S.talk.locked) loadTalk(false); }, TALK_POLL_MS);
function openDeepLink(params){
  const t = params.get('t'), l = params.get('l');
  if(t){ S.from = 'talk'; openThread(t); }
  else if(l){ S.from = 'home'; openLoop(l); }
}
if('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('message', ev => {
  if(ev.data && ev.data.type === 'open' && S.ready){ try{ openDeepLink(new URL(ev.data.url).searchParams); }catch(e){} }
});

/* ---------- boot ---------- */
if('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
const cached = load(CACHE_KEY);
if(cached){ try{ applyData(JSON.parse(cached)); S.ready = true; }catch(e){} }
paint();
sync().then(() => {
  const p = new URLSearchParams(location.search);
  if(S.ready && S.me && (p.get('t') || p.get('l'))){ openDeepLink(p); history.replaceState(null, '', location.pathname); }
});
})();
