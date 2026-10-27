// Kontratat e Studentëve – CIT
'use strict';

const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const form = $('#f');
const F = name => form.elements[name];

const TYPES = { vendas: 'Vendas', nderkombetar: 'Ndërkombëtar' };
const LEVELS = { bachelor: 'Bachelor', master: 'Master' };
const TEMPLATE_NAMES = {
  vendas_bachelor: 'Kontrata Bachelor (shqip)',
  vendas_master: 'Kontrata e Masterit (shqip)',
  nderkombetar_bachelor: 'Kontrata ndërkombëtare Bachelor (shqip + anglisht)',
  nderkombetar_master: 'Kontrata ndërkombëtare Master (shqip + anglisht)',
};

let programs = [];
let settings = {};
let students = [];
const seg = { type: '', level: '', round_no: '', gender: '' };
const PAYMENTS = { plote: 'Të plota', pjesshem: 'Të pjesshme' };
const touched = { email: false, fee: false, net: false };

// ---------- Ndihmes ----------
async function api(action, data) {
  const opt = data === undefined ? {} : {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'kontratat' },
    body: JSON.stringify(data),
  };
  let res;
  try {
    res = await fetch('api.php?a=' + action, opt);
  } catch (e) {
    throw new Error('Programi nuk përgjigjet. A është hapur dritarja e zezë (START.bat)?');
  }
  const json = await res.json().catch(() => ({ ok: false, error: 'Përgjigje e pakuptueshme nga serveri.' }));
  if (!json.ok) throw new Error(json.error || 'Gabim.');
  return json;
}

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

let toastTimer;
function toast(text) {
  const t = $('#toast');
  t.textContent = text;
  t.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.hidden = true), 2600);
}

const store = {
  get(k, d) { try { return localStorage.getItem('kontratat.' + k) ?? d; } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem('kontratat.' + k, v); } catch (e) { /* pa localStorage */ } },
};

function todayISO() {
  const d = new Date();
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

// Datat shfaqen dita/muaji/viti: "2008-07-21" -> "21/07/2008"
function fmtDate(iso) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
  return m ? `${m[3]}/${m[2]}/${m[1]}` : (iso || '');
}

// "21/7/2008", "21.07.2008", "21-07-08" -> "2008-07-21";  bosh -> '';  e pasakte -> null
function isoOf(text) {
  const v = String(text || '').trim();
  if (!v) return '';
  const m = /^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/.exec(v);
  if (!m) return null;
  let [, d, mo, y] = m.map(Number);
  if (m[3].length === 2) y += y > new Date().getFullYear() % 100 ? 1900 : 2000;
  const dt = new Date(y, mo - 1, d);
  if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d || y < 1900) return null;
  return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

const DATE_FIELDS = ['birth_date', 'contract_date', 'parent_birth_date'];

// Fushat e dates: shkruani vetem shifrat (21072008) – vizat "/" vendosen vete; 📅 hap kalendarin
$$('.date-wrap').forEach(w => {
  const input = $('input.date', w), pick = $('.date-pick', w);
  input.addEventListener('input', e => {
    let v = input.value.replace(/[.\-\s]/g, '/').replace(/[^\d/]/g, '').replace(/\/{2,}/g, '/');
    if (!(e.inputType || '').startsWith('delete')) {
      const p = v.split('/');
      if (p.length === 1 && p[0].length > 2) v = p[0].slice(0, 2) + '/' + p[0].slice(2);
      const q = v.split('/');
      if (q.length === 2 && q[1].length > 2) v = q[0] + '/' + q[1].slice(0, 2) + '/' + q[1].slice(2);
      if (/^\d{2}$/.test(v) || /^\d{1,2}\/\d{2}$/.test(v)) v += '/';
    }
    input.value = v.slice(0, 10);
    if (isoOf(input.value)) updateParent(input.name === 'birth_date');
  });
  input.addEventListener('blur', () => {
    const iso = isoOf(input.value);
    if (iso) input.value = fmtDate(iso); // "1/2/2008" -> "01/02/2008"
    input.classList.toggle('bad', iso === null);
  });
  $('.date-btn', w).addEventListener('click', () => {
    pick.value = isoOf(input.value) || '';
    try { pick.showPicker(); } catch (e) { pick.focus(); pick.click(); }
  });
  pick.addEventListener('change', () => {
    if (!pick.value) return;
    input.value = fmtDate(pick.value);
    input.classList.remove('bad');
    input.dispatchEvent(new Event('change', { bubbles: true }));
    updateParent(input.name === 'birth_date');
  });
});

function num(v) {
  v = String(v ?? '').replace(/\s/g, '').replace(',', '.');
  return v === '' || isNaN(v) ? null : Number(v);
}

function fmtMoney(n) {
  if (n === null || n === undefined || n === '') return '';
  n = Number(n);
  return Math.abs(n - Math.round(n)) < 0.005 ? String(Math.round(n)) : n.toFixed(2).replace('.', ',');
}

// Shkronjat me shenja -> te thjeshta (njesoj si translit_map() ne lib.php)
const TRANSLIT = (() => {
  const g = {
    a: 'àáâãäåāăą', A: 'ÀÁÂÃÄÅĀĂĄ', c: 'çćĉċč', C: 'ÇĆĈĊČ', d: 'ďđð', D: 'ĎĐÐ', e: 'èéêëēĕėęě', E: 'ÈÉÊËĒĔĖĘĚ',
    g: 'ĝğġģ', G: 'ĜĞĠĢ', h: 'ĥħ', H: 'ĤĦ', i: 'ìíîïĩīĭįı', I: 'ÌÍÎÏĨĪĬĮİ', j: 'ĵ', J: 'Ĵ', k: 'ķ', K: 'Ķ',
    l: 'ĺļľŀł', L: 'ĹĻĽĿŁ', n: 'ñńņňŉ', N: 'ÑŃŅŇ', o: 'òóôõöøōŏő', O: 'ÒÓÔÕÖØŌŎŐ', r: 'ŕŗř', R: 'ŔŖŘ',
    s: 'śŝşšș', S: 'ŚŜŞŠȘ', t: 'ţťŧț', T: 'ŢŤŦȚ', u: 'ùúûüũūŭůűų', U: 'ÙÚÛÜŨŪŬŮŰŲ', w: 'ŵ', W: 'Ŵ',
    y: 'ýÿŷ', Y: 'ÝŸŶ', z: 'źżž', Z: 'ŹŻŽ',
  };
  const m = { 'æ': 'ae', 'Æ': 'Ae', 'œ': 'oe', 'Œ': 'Oe', 'ß': 'ss', 'þ': 'th', 'Þ': 'Th' };
  for (const [plain, chars] of Object.entries(g)) for (const ch of chars) m[ch] = plain;
  return m;
})();
const emailPart = s => [...s].map(ch => TRANSLIT[ch] ?? ch).join('').replace(/[^A-Za-z]/g, '').toLowerCase();
const makeEmail = (first, last) => (first.trim() && last.trim()) ? `${emailPart(first)}.${emailPart(last)}@cit.edu.al` : '';

// "aNA maria" -> "Ana Maria"
const titleCase = s => s.trim().replace(/\s+/g, ' ').toLocaleLowerCase('sq').replace(/(^|[\s\-'])(\p{L})/gu, (m, a, b) => a + b.toLocaleUpperCase('sq'));

// Shumat me fjale (njesoj si words_sq / words_en ne lib.php)
function wordsSq(n) {
  if (n === 0) return 'zero';
  const u = ['', 'një', 'dy', 'tre', 'katër', 'pesë', 'gjashtë', 'shtatë', 'tetë', 'nëntë'];
  const t = { 2: 'njëzet', 3: 'tridhjetë', 4: 'dyzet', 5: 'pesëdhjetë', 6: 'gjashtëdhjetë', 7: 'shtatëdhjetë', 8: 'tetëdhjetë', 9: 'nëntëdhjetë' };
  const b100 = n => n < 10 ? u[n] : n === 10 ? 'dhjetë' : n < 20 ? u[n - 10] + 'mbëdhjetë' : t[Math.floor(n / 10)] + (n % 10 ? ' e ' + u[n % 10] : '');
  const b1000 = n => [n >= 100 ? u[Math.floor(n / 100)] + 'qind' : '', n % 100 ? b100(n % 100) : ''].filter(Boolean).join(' e ');
  const parts = [];
  if (n >= 1e6) { const m = Math.floor(n / 1e6); parts.push((m === 1 ? 'një' : b1000(m)) + ' milion'); n %= 1e6; }
  if (n >= 1000) { const k = Math.floor(n / 1000); parts.push(k < 10 ? u[k] + 'mijë' : b1000(k) + ' mijë'); n %= 1000; }
  if (n) parts.push(b1000(n));
  return parts.join(' e ');
}
function wordsEn(n) {
  if (n === 0) return 'Zero';
  const u = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
  const t = { 2: 'twenty', 3: 'thirty', 4: 'forty', 5: 'fifty', 6: 'sixty', 7: 'seventy', 8: 'eighty', 9: 'ninety' };
  const b1000 = n => {
    const p = [];
    if (n >= 100) { p.push(u[Math.floor(n / 100)] + ' hundred'); n %= 100; }
    if (n >= 20) p.push(t[Math.floor(n / 10)] + (n % 10 ? '-' + u[n % 10] : ''));
    else if (n) p.push(u[n]);
    return p.join(' ');
  };
  const parts = [];
  for (const [div, name] of [[1e6, 'million'], [1000, 'thousand']]) {
    if (n >= div) { parts.push(b1000(Math.floor(n / div)) + ' ' + name); n %= div; }
  }
  if (n) parts.push(b1000(n));
  const s = parts.join(' ');
  return s[0].toUpperCase() + s.slice(1);
}
function moneyWords(n, lang) {
  if (n === null) return '';
  const int = Math.floor(n + 1e-7), cents = Math.round((n - int) * 100);
  if (lang === 'sq') return wordsSq(int) + (cents ? ' e ' + wordsSq(cents) + ' cent' : '');
  return wordsEn(int) + (cents ? ' and ' + wordsEn(cents).toLowerCase() + ' cents' : '');
}

function programLabel(p, withEn) {
  let s = p.name_sq + (p.profile_sq ? ' – profili ' + p.profile_sq : '');
  if (withEn && p.name_en) s += '  (' + p.name_en + (p.profile_en ? ', ' + p.profile_en : '') + ')';
  return s;
}

// ---------- Butonat e zgjedhjes (Vendas / Ndërkombëtar, Bachelor / Master, Gjinia) ----------
function setSeg(name, value) {
  seg[name] = value;
  $$(`.seg[data-name="${name}"] button`).forEach(b => b.classList.toggle('on', b.dataset.v === value));
  $(`.seg[data-name="${name}"]`)?.classList.remove('need');
  if (name === 'round_no') store.set('round', value);
  if (name === 'type' || name === 'level') {
    if (value) store.set(name, value);
    onTypeLevelChange();
  }
}

$$('.seg[data-name]').forEach(g => g.addEventListener('click', e => {
  const b = e.target.closest('button');
  if (!b) return;
  const name = g.dataset.name;
  setSeg(name, name === 'gender' && seg.gender === b.dataset.v ? '' : b.dataset.v);
}));

function onTypeLevelChange() {
  const intl = seg.type === 'nderkombetar';
  document.body.classList.toggle('is-intl', intl);
  $('#doc-label').textContent = intl ? 'Nr. i pasaportës / dokumentit' : 'Nr. i dokumentit (ID)';
  $('#phone2-label').textContent = intl ? 'Numri i dytë (familjar / agjencia)' : 'Numri i dytë familjar';
  const key = seg.type && seg.level ? seg.type + '_' + seg.level : '';
  const extra = activeDocs().map(d => d.name);
  $('#tpl-info').innerHTML = key ? `Do të krijohet: <b>${TEMPLATE_NAMES[key]}</b>${extra.length ? ' + ' + extra.map(esc).join(', ') : ''}`
    : 'Zgjidhni llojin e studentit dhe nivelin.';
  fillProgramSelect();
  updateParent();
  if (!touched.fee) applyProgramFee();
  updateMoney();
}

// Dokumentet shtese aktive per llojin e zgjedhur (Vendas / Nderkombetar)
function activeDocs() {
  return (settings.docs || []).filter(d => d.enabled && (d.scope === 'all' || d.scope === seg.type));
}

function docButtons(s, cls) {
  return (s.docs || []).map((d, i) =>
    `<button type="button" class="btn ${cls}" data-act="open" data-id="${s.id}" data-doc="${esc(d.name)}" title="Hap në Word">📄 ${esc(d.name)}</button>`).join('');
}

function fillProgramSelect() {
  const sel = F('program_id');
  const current = sel.value;
  const list = programs.filter(p => (p.active == 1 || p.id == current) && (!seg.level || p.level === seg.level));
  sel.innerHTML = '<option value="">— Zgjidhni programin —</option>' + list.map(p =>
    `<option value="${p.id}">${esc(programLabel(p, true))}</option>`).join('');
  if (list.some(p => p.id == current)) sel.value = current;
}

function selectedProgram() {
  return programs.find(p => p.id == F('program_id').value);
}

function applyProgramFee() {
  const p = selectedProgram();
  if (!p || !seg.type) return;
  const fee = seg.type === 'vendas' ? p.fee_vendas : p.fee_nderkombetar;
  F('fee').value = fee === null || fee === '' ? '' : fmtMoney(fee);
  touched.net = false;
}

function updateMoney() {
  const fee = num(F('fee').value);
  const intl = seg.type === 'nderkombetar';
  if (!touched.net) {
    const d = Math.min(100, Math.max(0, num(F('discount').value) || 0));
    F('fee_net').value = fee === null ? '' : fmtMoney(Math.round(fee * (1 - d / 100) * 100) / 100);
  }
  $('#fee-words').textContent = fee === null ? '' : '(' + moneyWords(fee, 'sq') + ')' + (intl ? ' / (' + moneyWords(fee, 'en') + ')' : '');
  const net = num(F('fee_net').value);
  $('#net-words').textContent = net === null ? '' : '(' + moneyWords(net, 'sq') + ')' + (intl ? ' / (' + moneyWords(net, 'en') + ')' : '');
}

// Mosha ne daten e kontrates -> prindi / perfaqesuesi ligjor (vetem kontrata Bachelor vendase e ka)
function ageAt(birth, at) {
  const b = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birth || ''), a = /^(\d{4})-(\d{2})-(\d{2})$/.exec(at || todayISO());
  if (!b || !a) return null;
  let age = a[1] - b[1];
  if (a[2] + a[3] < b[2] + b[3]) age--;
  return age;
}

function updateParent(fromBirth) {
  const allowed = seg.type === 'vendas' && seg.level === 'bachelor';
  $('#parent-toggle-wrap').hidden = !allowed;
  const age = ageAt(isoOf(F('birth_date').value), isoOf(F('contract_date').value));
  const hint = $('#age-hint');
  hint.textContent = age === null ? '' : age + ' vjeç' + (age < 18 ? ' – nën 18!' : '');
  hint.style.color = age !== null && age < 18 ? 'var(--err)' : '';
  if (fromBirth && allowed && age !== null && age < 18) $('#parent-toggle').checked = true;
  $('#parent-card').hidden = !(allowed && $('#parent-toggle').checked);
}

// ---------- Formulari ----------
form.addEventListener('input', e => {
  const n = e.target.name;
  if (n === 'email') touched.email = e.target.value.trim() !== '';
  if ((n === 'first_name' || n === 'last_name') && !touched.email) F('email').value = makeEmail(F('first_name').value, F('last_name').value);
  if (n === 'fee') { touched.fee = true; touched.net = false; }
  if (n === 'discount') touched.net = false;
  if (n === 'fee_net') touched.net = e.target.value.trim() !== '';
  if (['fee', 'discount', 'fee_net'].includes(n)) updateMoney();
  e.target.classList.remove('bad');
});
form.addEventListener('change', e => {
  const n = e.target.name;
  if (n === 'program_id') { touched.fee = false; applyProgramFee(); updateMoney(); }
  if (n === 'birth_date' || n === 'contract_date') updateParent(n === 'birth_date');
});
$('#parent-toggle').addEventListener('change', () => updateParent());

// Emrat me shkronje te madhe ne fillim
['first_name', 'last_name', 'father', 'mother', 'birth_place', 'parent_name', 'parent_birth_place'].forEach(n =>
  F(n).addEventListener('blur', e => {
    const v = e.target.value;
    if (v.trim() && (v === v.toLowerCase() || v === v.toUpperCase() || /\s{2,}|^\s|\s$/.test(v))) e.target.value = titleCase(v);
  }));

// Enter -> fusha tjeter (shpejt); Ctrl+Enter -> ruaj & printo
form.addEventListener('keydown', e => {
  if (e.key !== 'Enter' || e.target.tagName === 'BUTTON') return;
  if (e.target.tagName === 'TEXTAREA' && !(e.ctrlKey || e.metaKey)) return; // Enter = rresht i ri te shenimet
  e.preventDefault();
  if (e.ctrlKey || e.metaKey) return form.requestSubmit();
  const fields = $$('input:not([type=hidden]):not([type=checkbox]):not(.date-pick), select, textarea', form).filter(el => el.offsetParent !== null);
  const i = fields.indexOf(e.target);
  if (i >= 0 && fields[i + 1]) fields[i + 1].focus();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !$('#tab-form').hidden && !form.contains(e.target) && !document.querySelector('dialog[open]')) form.requestSubmit();
});

function clearForm(keepChoice = true) {
  const type = seg.type, level = seg.level, round = seg.round_no, date = F('contract_date').value;
  form.reset();
  F('id').value = '';
  Object.keys(touched).forEach(k => (touched[k] = false));
  setSeg('gender', '');
  $('#parent-toggle').checked = false;
  $('#edit-banner').hidden = true;
  $('#save-btn').firstChild.textContent = '💾 Ruaj kontratën ';
  $$('.bad', form).forEach(el => el.classList.remove('bad'));
  F('contract_date').value = keepChoice && date ? date : fmtDate(todayISO());
  if (keepChoice) { setSeg('type', type); setSeg('level', level); setSeg('round_no', round); } else onTypeLevelChange();
  updateParent();
  updateMoney();
}

$('#cancel-edit').addEventListener('click', () => { clearForm(); $('#msg').hidden = true; });

function collect() {
  const data = { type: seg.type, level: seg.level, round_no: seg.round_no, gender: seg.gender };
  for (const el of form.elements) if (el.name) data[el.name] = el.value;
  for (const k of DATE_FIELDS) data[k] = isoOf(data[k]) || '';
  if (!(seg.type === 'vendas' && seg.level === 'bachelor' && $('#parent-toggle').checked)) {
    ['parent_name', 'parent_birth_place', 'parent_birth_date', 'parent_address', 'parent_doc', 'parent_phone'].forEach(k => (data[k] = ''));
  }
  data.parent_rep = seg.type === 'vendas' && seg.level === 'bachelor' && $('#parent-toggle').checked;
  if (seg.type !== 'nderkombetar') data.nationality = '';
  return data;
}

form.addEventListener('submit', async e => {
  e.preventDefault();
  let first = null;
  const need = el => { el.classList.add('bad'); first = first || el; };
  if (!seg.type) { $('.seg[data-name="type"]').classList.add('need'); first = first || $('.seg[data-name="type"] button'); }
  if (!seg.level) { $('.seg[data-name="level"]').classList.add('need'); first = first || $('.seg[data-name="level"] button'); }
  ['first_name', 'last_name', 'program_id'].forEach(n => { if (!F(n).value.trim()) need(F(n)); });
  if (first) { first.focus(); return showMsg('err', 'Plotësoni fushat e shënuara me të kuqe.'); }
  DATE_FIELDS.forEach(n => { if (isoOf(F(n).value) === null) need(F(n)); });
  if (first) { first.focus(); return showMsg('err', 'Data nuk është e saktë. Shkruajeni si dita/muaji/viti, p.sh. 21/07/2008.'); }

  const btn = $('#save-btn');
  btn.disabled = true;
  try {
    const wasEdit = !!F('id').value;
    const res = await api('save', collect());
    const s = res.student;
    upsertStudent(s);
    // Kontrata e re hapet vete: formulari bosh, lart, gati per studentin tjeter
    clearForm();
    showSaved(s, res.warnings || [], wasEdit, false);
    window.scrollTo({ top: 0, behavior: 'smooth' });
    askPrint(s);
  } catch (err) {
    showMsg('err', err.message);
  } finally {
    btn.disabled = false;
  }
});

// Mesazhi i gjelber zhduket vete pas 3 sekondash
let msgTimer;

function showMsg(kind, html) {
  clearTimeout(msgTimer);
  const m = $('#msg');
  m.className = 'msg ' + kind;
  m.innerHTML = kind === 'err' ? '⚠ ' + esc(html) : html;
  m.hidden = false;
  m.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
}

function showSaved(s, warnings, wasEdit, printed) {
  showMsg('ok', `✔ <b>${esc(s.first_name + ' ' + s.last_name)}</b> – kontrata u ${wasEdit ? 'përditësua' : 'ruajt'}${printed ? ' dhe po <b>printohet</b> 🖨' : ''}: <code>${esc(s.file_name)}</code>
    <div>Formulari është gati për studentin tjetër.</div>
    ${warnings.map(w => `<div class="warn">⚠ ${esc(w)}</div>`).join('')}
    <div class="row">
      <button type="button" class="btn small" data-act="print" data-id="${s.id}">🖨 Printo përsëri</button>
      <button type="button" class="btn small" data-act="open" data-id="${s.id}">📄 Kontrata</button>
      ${docButtons(s, 'small')}
      <a class="btn small" href="download.php?id=${s.id}">⬇ Shkarko</a>
      <button type="button" class="btn small" data-act="folder" data-id="${s.id}">📂 Hap dosjen</button>
      <button type="button" class="btn small" data-act="edit" data-id="${s.id}">✎ Ndrysho</button>
    </div>`);
  msgTimer = setTimeout(() => ($('#msg').hidden = true), 3000);
}

function loadIntoForm(s) {
  clearForm(false);
  F('id').value = s.id;
  for (const el of form.elements) {
    if (el.name && el.name !== 'id' && s[el.name] !== undefined && s[el.name] !== null) el.value = s[el.name];
  }
  for (const k of DATE_FIELDS) F(k).value = fmtDate(s[k]);
  setSeg('type', s.type);
  setSeg('level', s.level);
  setSeg('round_no', s.round_no || '');
  setSeg('gender', s.gender || '');
  F('program_id').value = s.program_id;
  F('fee').value = fmtMoney(s.fee);
  F('discount').value = Number(s.discount) ? fmtMoney(s.discount) : '';
  F('fee_net').value = fmtMoney(s.fee_net);
  touched.email = s.email !== makeEmail(s.first_name, s.last_name); touched.fee = true; touched.net = true;
  $('#parent-toggle').checked = s.parent_rep == 1 || !!s.parent_name;
  updateParent();
  updateMoney();
  $('#edit-name').textContent = s.first_name + ' ' + s.last_name;
  $('#edit-banner').hidden = false;
  $('#save-btn').firstChild.textContent = '💾 Ruaj ndryshimet ';
  $('#msg').hidden = true;
  showTab('form');
  F('first_name').focus();
}

// ---------- Printimi ----------
// Printerat e kompjuterit merren nje here; i fundit i zgjedhur mbahet mend
let printers = [];
async function loadPrinters() {
  try { printers = (await api('printers')).printers || []; } catch (e) { printers = []; }
  fillPrinterSelect();
}
function fillPrinterSelect() {
  const last = store.get('printer', '');
  const sel = $('#ask-printer');
  sel.innerHTML = printers.length
    ? printers.map(p => `<option value="${esc(p.name)}">${esc(p.name)}${p.default ? ' (kryesor)' : ''}</option>`).join('')
    : '<option value="">Printeri kryesor i Windows-it</option>';
  if (printers.some(p => p.name === last)) sel.value = last;
  $('#cert-printer').innerHTML = sel.innerHTML;
  $('#cert-printer').value = sel.value;
}

// Printon kontraten (+ dokumentet shtese) direkt ne printerin e zgjedhur
async function printStudent(id, printer) {
  if (printer) store.set('printer', printer);
  const res = await api('print', { id, printer: printer || '' });
  if (res.student) upsertStudent(res.student);
  toast('🖨 Po printohet' + (printer ? ' në: ' + printer : '') + '…');
}

// Dokumentet shtese qe printohen per kete lloj studenti (Vendas / Nderkombetar)
function printDocsFor(type) {
  return (settings.docs || []).filter(d => d.enabled && d.print && (d.scope === 'all' || d.scope === type)).map(d => d.name);
}


// Pas ruajtjes: "Të printohet?"  Enter = Po, Esc = Jo
let askFor = null;
function askPrint(s, saved = true) {
  const dlgAsk = $('#print-ask');
  $('#ask-icon').hidden = !saved;
  $('#ask-title').textContent = saved ? 'Kontrata u ruajt' : 'Printo kontratën';
  $('#ask-print').firstChild.textContent = saved ? '🖨 Po, printo ' : '🖨 Printo ';
  $('[data-ans="no"]').firstChild.textContent = saved ? 'Jo, vazhdo ' : 'Anulo ';
  fillPrinterSelect();
  const extra = s.type ? printDocsFor(s.type) : (s.docs || []).map(d => d.name);
  $('#ask-name').innerHTML = `<b>${esc(s.first_name + ' ' + s.last_name)}</b>`;
  $('#ask-what').textContent = `Printohet: kontrata ×${settings.copies || 2}` + (extra.length ? ' + ' + extra.join(', ') : '');
  askFor = s.id;
  dlgAsk.dataset.opened = Date.now();
  dlgAsk.showModal();
  $('#ask-print').focus();
}
function answerPrint(ans) {
  const id = askFor;
  askFor = null;
  $('#print-ask').close();
  F('first_name').focus({ preventScroll: true });
  if (ans === 'print' && id) printStudent(id, $('#ask-printer').value).catch(err => showMsg('err', err.message));
}
$('#print-ask').addEventListener('click', e => {
  const b = e.target.closest('[data-ans]');
  if (b) answerPrint(b.dataset.ans);
});
$('#print-ask').addEventListener('keydown', e => {
  if (e.key === 'Escape') { e.preventDefault(); answerPrint('no'); return; }
  // Enter-i i mbajtur nga Ctrl+Enter nuk printon pa dashje
  if (e.key === 'Enter' && (e.repeat || Date.now() - Number(e.currentTarget.dataset.opened) < 400)) e.preventDefault();
});

// ---------- Veprimet (Word, dosja, ndrysho, fshi) ----------
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-act]');
  if (!b) return;
  const id = Number(b.dataset.id);
  const s = students.find(x => x.id == id);
  try {
    if (b.dataset.act === 'open') {
      await api('open', b.dataset.doc !== undefined ? { id, doc_name: b.dataset.doc } : { id });
      toast('Po hapet në Word…');
    }
    if (b.dataset.act === 'folder') { await api('folder', { id }); toast('Po hapet dosja…'); }
    if (b.dataset.act === 'print') {
      b.disabled = true;
      try { askPrint(s || { id, first_name: '', last_name: '', docs: [] }, false); } finally { setTimeout(() => (b.disabled = false), 1000); }
    }
    if (b.dataset.act === 'edit' && s) loadIntoForm(s);
    if (b.dataset.act === 'delete' && s) {
      if (!confirm(`Të fshihet ${s.first_name} ${s.last_name} dhe dokumentet e tij/saj Word?`)) return;
      await api('delete', { id });
      students = students.filter(x => x.id != id);
      if (F('id').value == id) clearForm();
      renderList();
      toast('U fshi.');
    }
  } catch (err) {
    alert(err.message);
  }
});

// ---------- Lista ----------
function upsertStudent(s) {
  const i = students.findIndex(x => x.id == s.id);
  if (i >= 0) students[i] = s; else students.unshift(s);
  renderList();
}

function renderList() {
  $('#count').textContent = students.length;
  const q = $('#q').value.trim().toLowerCase();
  const qa = emailPart(q);
  const ft = $('#f-type').value, fl = $('#f-level').value, fr = $('#f-round').value, fy = $('#f-year').value;
  const rows = students.filter(s => (!ft || s.type === ft) && (!fl || s.level === fl) && (!fr || s.round_no === fr) && (!fy || s.academic_year === fy) && (!q ||
    [s.first_name + ' ' + s.last_name, s.last_name + ' ' + s.first_name, s.email, s.doc_no, s.program_sq, s.program_en, s.phone, s.nationality, s.notes]
      .some(v => String(v || '').toLowerCase().includes(q) || (qa && emailPart(String(v || '')).includes(qa)))));

  $('#list tbody').innerHTML = rows.map((s, i) => `
    <tr>
      <td>${rows.length - i}</td>
      <td class="who"><b>${esc(s.first_name + ' ' + s.last_name)}</b><small>${esc(s.email)}${s.doc_no ? ' · ' + esc(s.doc_no) : ''}${s.phone ? ' · ' + esc(s.phone) : ''}</small>${s.notes ? `<div class="note">📝 ${esc(s.notes)}</div>` : ''}</td>
      <td><span class="tag ${s.type === 'vendas' ? 'vendas' : 'intl'}">${TYPES[s.type]}</span><span class="tag">${LEVELS[s.level]}</span>${s.round_no ? `<span class="tag">Raundi ${esc(s.round_no)}</span>` : ''}${s.type === 'nderkombetar' && PAYMENTS[s.payment] ? `<span class="tag pay-${esc(s.payment)}">💶 ${PAYMENTS[s.payment]}</span>` : ''}${s.nationality ? `<br><small>${esc(s.nationality)}</small>` : ''}</td>
      <td>${esc(s.program_sq)}${s.profile_sq ? `<br><small>profili ${esc(s.profile_sq)}</small>` : ''}</td>
      <td>${s.fee_net !== null && s.fee_net !== '' ? fmtMoney(s.fee_net) + ' €' : '<span class="missing">pa tarifë</span>'}${Number(s.discount) ? `<br><small>−${fmtMoney(s.discount)}%</small>` : ''}</td>
      <td>${fmtDate(s.contract_date)}<br><small>${esc(s.academic_year)}</small></td>
      <td><div class="row-actions">
        <button type="button" class="btn small" data-act="open" data-id="${s.id}" title="Hap në Word">📄 Word</button>
        <button type="button" class="btn small" data-act="print" data-id="${s.id}" title="Printo">🖨</button>
        <a class="btn small" href="download.php?id=${s.id}" title="Shkarko">⬇</a>
        <button type="button" class="btn small" data-act="edit" data-id="${s.id}" title="Ndrysho">✎</button>
        <button type="button" class="btn small" data-act="delete" data-id="${s.id}" title="Fshi">🗑</button>
      </div>${s.docs && s.docs.length ? `<div class="doc-links">${docButtons(s, 'small')}</div>` : ''}${s.file_exists ? '' : '<div class="missing">skedari mungon – krijohet kur e hapni</div>'}</td>
    </tr>`).join('');
  $('#empty').hidden = rows.length > 0;
  $('#empty').textContent = students.length ? 'Asnjë student nuk përputhet me kërkimin.' : 'Asnjë student ende.';

  const c = (t, l) => rows.filter(s => s.type === t && (!l || s.level === l)).length;
  $('#stats').innerHTML = `Gjithsej: <b>${rows.length}</b>
    <span>Vendas: <b>${c('vendas')}</b> (Bachelor ${c('vendas', 'bachelor')}, Master ${c('vendas', 'master')})</span>
    <span>Ndërkombëtar: <b>${c('nderkombetar')}</b> (Bachelor ${c('nderkombetar', 'bachelor')}, Master ${c('nderkombetar', 'master')})</span>`;

  const years = [...new Set(students.map(s => s.academic_year).concat(settings.academic_year || []))].filter(Boolean).sort().reverse();
  const ySel = $('#f-year');
  const cur = ySel.value;
  ySel.innerHTML = '<option value="">Të gjitha vitet</option>' + years.map(y => `<option${y === cur ? ' selected' : ''}>${esc(y)}</option>`).join('');
}
['#q', '#f-type', '#f-level', '#f-round', '#f-year'].forEach(s => $(s).addEventListener('input', renderList));

$('#export-btn').addEventListener('click', () => {
  const p = new URLSearchParams({ type: $('#f-type').value, level: $('#f-level').value, round_no: $('#f-round').value, academic_year: $('#f-year').value });
  location.href = 'export.php?' + p;
});
$('#folder-btn').addEventListener('click', () => api('folder', { id: 0 }).then(() => toast('Po hapet dosja…')).catch(e => alert(e.message)));

// ---------- Lista nga dosja e kontratave ----------
// Lista rilexohet kur riktheheni te programi (p.sh. pasi shtuat / fshitë kontrata ne dosje) dhe çdo 30 sekonda
let syncBusy = false, lastSync = 0, syncAdded = 0;
async function refreshList(manual = false) {
  if (syncBusy) return;
  syncBusy = true;
  try {
    const l = await api('list');
    students = l.students;
    renderList();
    lastSync = Date.now();
    const sy = l.sync || {};
    const t = new Date().toLocaleTimeString('sq', { hour: '2-digit', minute: '2-digit', hour12: false });
    $('#sync-info').textContent = (sy.folder ? 'Dosja: ' + sy.folder + ' · ' : '') + 'lexuar në ' + t;
    const parts = [];
    if (sy.added && sy.added.length) parts.push(`➕ U shtuan nga dosja (${sy.added.length}): ${someNames(sy.added)}`);
    if (sy.restored && sy.restored.length) parts.push(`↩ U rikthyen (${sy.restored.length}): ${someNames(sy.restored)}`);
    if (sy.removed && sy.removed.length) parts.push(`➖ U hoqën nga lista – kontrata nuk është më në dosje (${sy.removed.length}): ${someNames(sy.removed)}`);
    if (sy.error) parts.push('⚠ ' + sy.error);
    if (sy.more) {   // shume kontrata te reja: vazhdon leximin vete
      syncAdded += (sy.added || []).length;
      showSync([`⏳ Po lexohen kontratat nga dosja… ${syncAdded} studentë deri tani. Prisni pak.`]);
      setTimeout(() => refreshList().catch(() => {}), 50);
      return;
    }
    if (syncAdded && sy.added) { sy.added.length && parts.shift(); parts.unshift(`➕ U shtuan nga dosja ${syncAdded + sy.added.length} studentë.`); syncAdded = 0; }
    if (parts.length) showSync(parts);
    else if (manual) toast('✔ Lista është e përditësuar (' + students.length + ' studentë)');
  } catch (e) {
    if (manual) alert(e.message);
    else throw e;
  } finally {
    syncBusy = false;
  }
}
// Emrat ne mesazh: deri ne 15, pastaj "… dhe N te tjere"
const someNames = list => list.slice(0, 15).join(', ') + (list.length > 15 ? ` … dhe ${list.length - 15} të tjerë` : '');
function showSync(parts) {
  const box = $('#sync-msg');
  box.innerHTML = '<div class="sync-text">' + parts.map(p => `<div>${esc(p)}</div>`).join('') + '</div><button type="button" class="btn small" id="sync-msg-x">OK</button>';
  box.hidden = false;
}
document.addEventListener('click', e => { if (e.target.id === 'sync-msg-x') $('#sync-msg').hidden = true; });
$('#sync-btn').addEventListener('click', () => refreshList(true));
const autoRefresh = () => { if (!document.hidden && Date.now() - lastSync > 3000) refreshList().catch(() => {}); };
window.addEventListener('focus', autoRefresh);
document.addEventListener('visibilitychange', autoRefresh);
// Çdo 30 sekonda vetem kur lista 📋 Studentët eshte e hapur (qe formulari te mos ngadalesohet kurre)
setInterval(() => { if (!document.hidden && !$('#tab-list').hidden) refreshList().catch(() => {}); }, 30000);

// ---------- Skedat ----------
function showTab(name) {
  $$('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  $$('.panel').forEach(p => (p.hidden = p.id !== 'tab-' + name));
  if (name === 'list') { $('#q').focus(); if (Date.now() - lastSync > 3000) refreshList().catch(() => {}); }
  if (name === 'cert') { loadCerts(); $('#c-first').focus(); }
  window.scrollTo(0, 0);
}
$$('.tab').forEach(t => t.addEventListener('click', () => {
  if (t.dataset.tab === 'form') return newContract();
  showTab(t.dataset.tab);
}));

// "➕ Kontratë e re": formular i ri bosh (si rifreskim i faqes). Lloji, niveli dhe raundi mbeten te zgjedhura.
function newContract() {
  const typed = ['first_name', 'last_name', 'father', 'mother', 'birth_place', 'birth_date', 'address', 'doc_no', 'phone', 'phone2']
    .some(n => F(n).value.trim() !== '');
  if (typed && !confirm(F('id').value ? 'Të anulohen ndryshimet e paruajtura?' : 'Të fshihen të dhënat e paruajtura dhe të hapet kontratë e re?')) return showTab('form');
  clearForm();
  $('#msg').hidden = true;
  showTab('form');
  F('contract_date').value = fmtDate(todayISO());
  F('first_name').focus();
}

// ---------- 💶 Vërtetim pagese (faqe më vete): emri, mbiemri, të plota / të pjesshme ----------
const cf = $('#cf');
const cert = { gender: '', payment: '' };
let certs = [];

function setCertSeg(name, v) {
  cert[name] = v;
  $$(`#cert-${name === 'payment' ? 'pay' : 'gender'} button`).forEach(b => b.classList.toggle('on', b.dataset.v === v));
  $('#cert-pay').classList.remove('need');
  certPreview();
}
$('#cert-gender').addEventListener('click', e => { const b = e.target.closest('button'); if (b) setCertSeg('gender', cert.gender === b.dataset.v ? '' : b.dataset.v); });
$('#cert-pay').addEventListener('click', e => { const b = e.target.closest('button'); if (b) setCertSeg('payment', b.dataset.v); });

// Si do të dalë në vërtetim (shqip + anglisht)
function certPreview() {
  const name = titleCase($('#c-first').value) + ' ' + titleCase($('#c-last').value);
  const g = cert.gender, p = cert.payment;
  const z = g === 'M' ? 'Z.' : g === 'F' ? 'Znj.' : 'Z/Znj.', mr = g === 'M' ? 'Mr.' : g === 'F' ? 'Ms.' : 'Mr./Ms.';
  const sq = p === 'plote' ? 'të plota' : p === 'pjesshem' ? 'të pjesshme' : '<i>(zgjidhni pagesën)</i>';
  const en = p === 'plote' ? 'full' : p === 'pjesshem' ? 'partial' : '<i>(…)</i>';
  const n = name.trim() ? esc(name.trim()) : '<i>Emri Mbiemri</i>';
  const y = esc(settings.academic_year || '');
  $('#cert-preview').innerHTML = `<div>Me anë të cilit vërtetojmë se <b>${z} ${n}</b> ka paguar detyrimet financiare <b>${sq}</b> për vitin akademik ${y}…</div>
    <div class="en">This is to verify that <b>${mr} ${n}</b> has paid the <b>${en}</b> financial obligations for the academic year ${y.replace('-', '–')}…</div>`;
}

// Studentët në listë që përputhen me emrin -> klikim = plotësohet emri, mbiemri, gjinia
function certMatches() {
  const q = (emailPart($('#c-first').value) + ' ' + emailPart($('#c-last').value)).trim();
  const box = $('#cert-matches');
  if (q.length < 2) { box.hidden = true; return; }
  const words = q.split(' ').filter(Boolean);
  const found = students.filter(s => words.every(w => emailPart(s.first_name + s.last_name).includes(w)))
    .sort((a, b) => (b.type === 'nderkombetar') - (a.type === 'nderkombetar')).slice(0, 6);
  box.hidden = !found.length;
  box.innerHTML = '<span class="hint">Në listë:</span>' + found.map(s =>
    `<button type="button" class="btn small" data-pick="${s.id}">${esc(s.first_name + ' ' + s.last_name)} <small>${TYPES[s.type]} · ${LEVELS[s.level]}</small></button>`).join('');
}
$('#cert-matches').addEventListener('click', e => {
  const b = e.target.closest('[data-pick]');
  const s = b && students.find(x => x.id == b.dataset.pick);
  if (!s) return;
  $('#c-first').value = s.first_name;
  $('#c-last').value = s.last_name;
  setCertSeg('gender', s.gender || '');
  if (PAYMENTS[s.payment]) setCertSeg('payment', s.payment);
  $('#cert-matches').hidden = true;
  $('#cert-pay button').focus();
});
cf.addEventListener('input', e => { e.target.classList.remove('bad'); certMatches(); certPreview(); });
['#c-first', '#c-last'].forEach(s => $(s).addEventListener('blur', e => {
  const v = e.target.value;
  if (v.trim() && (v === v.toLowerCase() || v === v.toUpperCase() || /\s{2,}|^\s|\s$/.test(v))) { e.target.value = titleCase(v); certPreview(); }
}));
// Enter: Emri -> Mbiemri -> printo
cf.addEventListener('keydown', e => {
  if (e.key !== 'Enter' || e.target.tagName === 'BUTTON') return;
  e.preventDefault();
  if (e.target.id === 'c-first') return $('#c-last').focus();
  cf.requestSubmit();
});

function clearCert() {
  cf.reset();
  setCertSeg('gender', '');
  setCertSeg('payment', '');
  $('#cert-matches').hidden = true;
  $$('.bad', cf).forEach(el => el.classList.remove('bad'));
  certPreview();
  $('#c-first').focus();
}
$('#cert-clear').addEventListener('click', clearCert);

// what: 'save' (vetem ruaj) | 'print' | 'open' (Word)
async function makeCert(what, data) {
  const res = await api('cert', { ...data, do: what, printer: $('#cert-printer').value, copies: Number($('#cert-copies').value) });
  if (what === 'print') {
    if ($('#cert-printer').value) store.set('printer', $('#cert-printer').value);
    toast(`🖨 Po printohet vërtetimi: ${data.first_name} ${data.last_name} (${PAYMENTS[data.payment].toLowerCase()})`);
  } else if (what === 'open') toast('Po hapet në Word…');
  loadCerts();
  refreshList().catch(() => {});   // pagesa del edhe te 📋 Studentët
  return res;
}

async function submitCert(what) {
  let first = null;
  const need = el => { el.classList.add('bad'); first = first || el; };
  if (!$('#c-first').value.trim()) need($('#c-first'));
  if (!$('#c-last').value.trim()) need($('#c-last'));
  if (!cert.payment) { $('#cert-pay').classList.add('need'); first = first || $('#cert-pay button'); }
  if (first) { first.focus(); toast('Plotësoni emrin, mbiemrin dhe zgjidhni: Të plota / Të pjesshme'); return; }
  const btns = $$('#cert-save, #cert-open');
  btns.forEach(b => (b.disabled = true));
  try {
    const data = { first_name: titleCase($('#c-first').value), last_name: titleCase($('#c-last').value), gender: cert.gender, payment: cert.payment };
    await makeCert(what, data);
    clearCert();
    if (what === 'save') askCertPrint(data, true);   // "Të printohet?"  Enter = Po, Esc = Jo
  } catch (err) {
    alert(err.message);
  } finally {
    btns.forEach(b => (b.disabled = false));
  }
}
cf.addEventListener('submit', e => { e.preventDefault(); submitCert('save'); });

// Pas ruajtjes (ose 🖨 te lista): "Të printohet?"
let certAskData = null;
function askCertPrint(data, saved) {
  const d = $('#cert-ask');
  certAskData = data;
  $('#cert-ask-icon').hidden = !saved;
  $('#cert-ask-title').textContent = saved ? 'Vërtetimi u ruajt' : 'Printo vërtetimin';
  $('#cert-ask-print').firstChild.textContent = saved ? '🖨 Po, printo ' : '🖨 Printo ';
  $('[data-cans="no"]').firstChild.textContent = saved ? 'Jo, vazhdo ' : 'Anulo ';
  $('#cert-ask-name').innerHTML = `<b>${esc(data.first_name + ' ' + data.last_name)}</b>`;
  $('#cert-ask-what').textContent = 'Vërtetim pagese – detyrimet financiare ' + PAYMENTS[data.payment].toLowerCase();
  d.dataset.opened = Date.now();
  d.showModal();
  $('#cert-ask-print').focus();
}
function answerCert(ans) {
  const data = certAskData;
  certAskData = null;
  $('#cert-ask').close();
  $('#c-first').focus({ preventScroll: true });
  if (ans === 'print' && data) makeCert('print', data).catch(err => alert(err.message));
}
$('#cert-ask').addEventListener('click', e => {
  const b = e.target.closest('[data-cans]');
  if (b) answerCert(b.dataset.cans);
});
$('#cert-ask').addEventListener('keydown', e => {
  if (e.key === 'Escape') { e.preventDefault(); answerCert('no'); return; }
  // Enter-i i mbajtur nga ruajtja nuk printon pa dashje
  if (e.key === 'Enter' && (e.repeat || Date.now() - Number(e.currentTarget.dataset.opened) < 400)) e.preventDefault();
});
$('#cert-open').addEventListener('click', () => submitCert('open'));

async function loadCerts() {
  try { certs = (await api('certs')).certs || []; } catch (e) { certs = []; }
  $('#cert-count').textContent = certs.length ? `(${certs.length})` : '';
  $('#cert-empty').hidden = certs.length > 0;
  $('#cert-list tbody').innerHTML = certs.map(c => `
    <tr>
      <td class="who"><b>${esc(c.first_name + ' ' + c.last_name)}</b><small>${esc(c.academic_year)}</small></td>
      <td><span class="tag pay-${esc(c.payment)}">${PAYMENTS[c.payment] || ''}</span></td>
      <td><small>${fmtDate(c.updated_at.slice(0, 10))} ${esc(c.updated_at.slice(11, 16))}</small></td>
      <td><div class="row-actions">
        <button type="button" class="btn small" data-cert="print" data-cid="${c.id}" title="Printo përsëri">🖨</button>
        <button type="button" class="btn small" data-cert="open" data-cid="${c.id}" title="Hap në Word">📄</button>
        <button type="button" class="btn small" data-cert="edit" data-cid="${c.id}" title="Ndrysho (p.sh. pagoi pjesën e mbetur)">✎</button>
        <button type="button" class="btn small" data-cert="delete" data-cid="${c.id}" title="Fshi">🗑</button>
      </div></td>
    </tr>`).join('');
}
$('#cert-list').addEventListener('click', async e => {
  const b = e.target.closest('[data-cert]');
  const c = b && certs.find(x => x.id == b.dataset.cid);
  if (!c) return;
  const data = { first_name: c.first_name, last_name: c.last_name, gender: c.gender, payment: c.payment };
  try {
    if (b.dataset.cert === 'print') askCertPrint(data, false);
    if (b.dataset.cert === 'open') {
      b.disabled = true;
      try { await makeCert('open', data); } finally { setTimeout(() => (b.disabled = false), 1000); }
    }
    if (b.dataset.cert === 'edit') {
      $('#c-first').value = c.first_name;
      $('#c-last').value = c.last_name;
      setCertSeg('gender', c.gender || '');
      setCertSeg('payment', c.payment);
      $('#cert-matches').hidden = true;
      window.scrollTo({ top: 0, behavior: 'smooth' });
      $('#cert-pay button').focus();
    }
    if (b.dataset.cert === 'delete') {
      if (!confirm(`Të fshihet vërtetimi i ${c.first_name} ${c.last_name}?`)) return;
      await api('cert_delete', { id: c.id });
      loadCerts();
      toast('U fshi.');
    }
  } catch (err) { alert(err.message); }
});

// ---------- Cilësimet (ruhen vetë) ----------
const SCOPES = { all: 'Të gjithë', vendas: 'Vendas', nderkombetar: 'Ndërkombëtar' };
let saveTimer;
const saved = (text = 'U ruajt ✔') => toast(text);

// --- Te pergjithshme ---
async function saveGeneral() {
  try {
    await api('settings', { academic_year: $('#s-year').value.trim(), out_dir: $('#s-dir').value.trim() });
    const b = await api('bootstrap');
    settings = b.settings;
    renderGeneral();
    renderList();
    saved();
  } catch (e) { alert(e.message); renderGeneral(); }
}
$('#s-year').addEventListener('change', saveGeneral);
$('#s-dir').addEventListener('change', saveGeneral);
$('#open-out-dir').addEventListener('click', () => api('folder', { id: 0 }).then(() => toast('Po hapet dosja…')).catch(e => alert(e.message)));

function renderGeneral() {
  $('#s-year').value = settings.academic_year;
  $('#s-dir').value = settings.out_dir;
  $('#s-dir-full').textContent = 'Tani: ' + settings.out_dir_full;
  $('#year-chip').textContent = 'Viti ' + settings.academic_year;
}

// --- Printimi dhe dokumentet shtese ---
function switchHtml(attrs, on) {
  return `<label class="switch"><input type="checkbox" ${attrs}${on ? ' checked' : ''}><span></span></label>`;
}

function renderDocsSettings() {
  $('#s-copies span').textContent = settings.copies || 2;
  $('#docs-list').innerHTML = (settings.docs || []).map(d => `
    <div class="doc-item ${d.enabled ? '' : 'off'}" data-file="${esc(d.file)}">
      ${switchHtml('data-k="enabled" title="Aktiv"', d.enabled)}
      <div class="doc-name"><b>${esc(d.name)}</b>
        <small>${d.enabled ? 'Krijohet për: ' + SCOPES[d.scope] : 'Joaktiv'}</small></div>
      <div class="doc-opts">
        <select data-k="scope" title="Për kë krijohet">${Object.entries(SCOPES).map(([k, v]) => `<option value="${k}"${d.scope === k ? ' selected' : ''}>${v}</option>`).join('')}</select>
        <label class="mini-switch" title="Printohet bashkë me kontratën">${switchHtml('data-k="print"', d.print)} Printo</label>
        <select data-k="copies" title="Kopje">${[1, 2, 3, 4, 5].map(n => `<option value="${n}"${d.copies == n ? ' selected' : ''}>${n}×</option>`).join('')}</select>
        <button type="button" class="btn small" data-open-tpl="${esc(d.file)}" title="Ndrysho modelin në Word">✎</button>
      </div>
    </div>`).join('') || '<p class="hint">Asnjë model te dosja app\\templates\\dokumente.</p>';
}

async function savePrintSettings() {
  const docs = $$('#docs-list .doc-item').map(el => {
    const d = { file: el.dataset.file };
    $$('[data-k]', el).forEach(i => (d[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value));
    return d;
  });
  try {
    const res = await api('print_settings', { copies: Number($('#s-copies span').textContent), docs });
    settings.copies = res.copies;
    settings.docs = res.docs;
    renderDocsSettings();
    onTypeLevelChange();
    saved();
  } catch (e) { alert(e.message); }
}
$('#s-copies').addEventListener('click', e => {
  const step = Number(e.target.dataset.step || 0);
  if (!step) return;
  const span = $('#s-copies span');
  const n = Math.min(5, Math.max(1, Number(span.textContent) + step));
  if (n === Number(span.textContent)) return;
  span.textContent = n;
  clearTimeout(saveTimer);
  saveTimer = setTimeout(savePrintSettings, 400);
});
$('#docs-list').addEventListener('change', savePrintSettings);
$('#docs-list').addEventListener('click', e => {
  const f = e.target.closest('[data-open-tpl]')?.dataset.openTpl;
  if (f) api('template', { file: f }).then(() => toast('Modeli po hapet në Word…')).catch(err => alert(err.message));
});
$('#open-templates').addEventListener('click', () => api('template', {}).then(() => toast('Po hapet dosja…')).catch(e => alert(e.message)));

// --- Programet ---
function progRow(p) {
  const fee = (k, label) => `<label class="fee${p[k] === null || p[k] === '' ? ' fee-missing' : ''}"><span>${label}</span>
      <input data-fee="${k}" value="${esc(fmtMoney(p[k]))}" inputmode="decimal" placeholder="—"><i>€</i></label>`;
  return `<div class="prog-row ${p.active == 0 ? 'off' : ''}" data-id="${p.id}">
    ${switchHtml('data-prog-active title="Shfaqet në listën e programeve"', p.active != 0)}
    <div class="prog-name" data-edit-prog title="Kliko për të ndryshuar emrin">
      <b>${esc(p.name_sq)}</b>${p.profile_sq ? `<span class="chip">profili ${esc(p.profile_sq)}</span>` : ''}
      <small>${esc(p.name_en)}${p.profile_en ? ' · ' + esc(p.profile_en) : ''}</small>
    </div>
    <div class="fees">${fee('fee_vendas', 'Vendas')}${fee('fee_nderkombetar', 'Ndërk.')}</div>
    <button type="button" class="btn small ghost" data-edit-prog title="Ndrysho">✎</button>
  </div>`;
}

function renderPrograms() {
  const q = ($('#prog-q').value || '').trim().toLowerCase();
  const match = p => !q || [p.name_sq, p.name_en, p.profile_sq, p.profile_en].some(v => String(v || '').toLowerCase().includes(q));
  for (const level of ['bachelor', 'master']) {
    const list = programs.filter(p => p.level === level);
    const shown = list.filter(match);
    $('#prog-' + level).innerHTML = shown.map(progRow).join('') || '<p class="hint">Asnjë program.</p>';
    $('#cnt-' + level).textContent = list.filter(p => p.active != 0).length + ' aktive';
  }
  const missing = programs.filter(p => p.active != 0 && (p.fee_vendas === null || p.fee_vendas === ''));
  $('#fee-alert').hidden = !missing.length;
  $('#fee-alert').textContent = `⚠ ${missing.length} programe aktive nuk kanë tarifë vendase – shkruajeni te kutia "Vendas".`;
}
$('#prog-q').addEventListener('input', renderPrograms);

async function saveProgram(data) {
  const res = await api('program_save', data);
  programs = res.programs;
  fillProgramSelect();
  return res;
}

// Tarifa dhe aktiv: ruhen menjehere
$('.prog-cols').addEventListener('change', async e => {
  const row = e.target.closest('.prog-row');
  if (!row) return;
  const p = programs.find(x => x.id == row.dataset.id);
  const data = { ...p };
  if (e.target.dataset.fee) {
    const v = e.target.value.trim();
    if (v !== '' && num(v) === null) { alert('Shkruani vetëm numër, p.sh. 2500'); e.target.value = fmtMoney(p[e.target.dataset.fee]); return; }
    data[e.target.dataset.fee] = v;
  }
  if (e.target.matches('[data-prog-active]')) data.active = e.target.checked;
  try {
    await saveProgram(data);
    renderPrograms();
    saved();
  } catch (err) { alert(err.message); renderPrograms(); }
});
$('.prog-cols').addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.dataset.fee) e.target.blur();
});
$('.prog-cols').addEventListener('click', e => {
  if (!e.target.closest('[data-edit-prog]')) return;
  openProgramDialog(programs.find(x => x.id == e.target.closest('.prog-row').dataset.id));
});

// Dritarja e programit (shto / ndrysho)
const dlg = $('#prog-dialog');
const pf = $('#prog-form');
let progLevel = 'bachelor';
function setProgLevel(l) {
  progLevel = l;
  $$('#prog-level button').forEach(b => b.classList.toggle('on', b.dataset.v === l));
}
$('#prog-level').addEventListener('click', e => { if (e.target.dataset.v) setProgLevel(e.target.dataset.v); });

function openProgramDialog(p) {
  pf.reset();
  const isNew = !p;
  p = p || { level: seg.level || 'bachelor', fee_nderkombetar: 3500 };
  pf.elements.id.value = p.id || '';
  for (const k of ['name_sq', 'name_en', 'profile_sq', 'profile_en']) pf.elements[k].value = p[k] || '';
  pf.elements.fee_vendas.value = fmtMoney(p.fee_vendas);
  pf.elements.fee_nderkombetar.value = fmtMoney(p.fee_nderkombetar);
  setProgLevel(p.level);
  $('#prog-title').textContent = isNew ? 'Program i ri' : 'Ndrysho programin';
  $('#prog-delete').hidden = isNew;
  dlg.showModal();
  pf.elements.name_sq.focus();
}
$('#add-prog').addEventListener('click', () => openProgramDialog(null));
$('#prog-cancel').addEventListener('click', () => dlg.close());
pf.addEventListener('submit', async e => {
  e.preventDefault();
  if (!pf.elements.name_sq.value.trim()) { pf.elements.name_sq.classList.add('bad'); pf.elements.name_sq.focus(); return; }
  const id = pf.elements.id.value;
  const old = programs.find(x => x.id == id);
  const data = { id: id || null, level: progLevel, active: old ? old.active != 0 : true };
  for (const k of ['name_sq', 'name_en', 'profile_sq', 'profile_en', 'fee_vendas', 'fee_nderkombetar']) data[k] = pf.elements[k].value;
  try {
    await saveProgram(data);
    dlg.close();
    renderPrograms();
    saved(id ? 'Programi u ndryshua ✔' : 'Programi u shtua ✔');
  } catch (err) { alert(err.message); }
});
$('#prog-delete').addEventListener('click', async () => {
  const id = pf.elements.id.value;
  if (!confirm('Të fshihet ky program? (Nëse ka studentë të regjistruar, vetëm fshihet nga lista.)')) return;
  try {
    const res = await api('program_delete', { id });
    programs = res.programs;
    fillProgramSelect();
    dlg.close();
    renderPrograms();
    saved('Programi u hoq ✔');
  } catch (err) { alert(err.message); }
});

function renderSettings() {
  renderGeneral();
  renderDocsSettings();
  renderPrograms();
}

// ---------- Fillimi ----------
async function boot() {
  const b = await api('bootstrap');
  programs = b.programs;
  settings = b.settings;
  renderSettings();
  await refreshList();
}

(async () => {
  F('contract_date').value = fmtDate(todayISO());
  try {
    await boot();
  } catch (e) {
    showMsg('err', e.message);
  }
  setSeg('type', store.get('type', ''));
  setSeg('level', store.get('level', ''));
  setSeg('round_no', store.get('round', ''));
  F('first_name').focus();
  certPreview();
  loadPrinters();
})();
