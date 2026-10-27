<?php
require_once __DIR__ . '/lib.php';
if (!is_local_request()) { http_response_code(403); exit('Vetëm nga ky kompjuter.'); }
db(); // krijon databazen herën e parë
$v = filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="sq">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kontratat e Studentëve – CIT</title>
<link rel="stylesheet" href="assets/style.css?v=<?= $v ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
</head>
<body>
<header class="top">
  <div class="wrap">
    <h1>📝 Kontratat e Studentëve <small>CIT</small></h1>
    <span class="chip" id="year-chip"></span>
    <nav>
      <button type="button" class="tab active" data-tab="form">➕ Kontratë e re</button>
      <button type="button" class="tab" data-tab="list">📋 Studentët <b id="count">0</b></button>
      <button type="button" class="tab" data-tab="cert">💶 Vërtetim pagese</button>
      <button type="button" class="tab" data-tab="settings">⚙ Cilësimet</button>
    </nav>
  </div>
</header>

<main class="wrap">
  <div id="sync-msg" class="sync-msg" hidden></div>
  <!-- ================= KONTRATË E RE ================= -->
  <section id="tab-form" class="panel">
    <form id="f" autocomplete="off" novalidate>
      <input type="hidden" name="id">
      <div id="msg" class="msg" hidden></div>
      <div class="card choose">
        <div class="seg-group">
          <span class="lbl">1. Studenti</span>
          <div class="seg" data-name="type">
            <button type="button" data-v="vendas">🏠 Vendas</button>
            <button type="button" data-v="nderkombetar">🌍 Ndërkombëtar</button>
          </div>
        </div>
        <div class="seg-group">
          <span class="lbl">2. Niveli</span>
          <div class="seg" data-name="level">
            <button type="button" data-v="bachelor">🎓 Bachelor</button>
            <button type="button" data-v="master">🎓 Master</button>
          </div>
        </div>
        <div class="seg-group">
          <span class="lbl">3. Raundi <small class="hint">(vetëm për ju, nuk del në kontratë)</small></span>
          <div class="seg" data-name="round_no">
            <button type="button" data-v="1">Raundi 1</button>
            <button type="button" data-v="2">Raundi 2</button>
            <button type="button" data-v="3">Raundi 3</button>
          </div>
        </div>
        <div class="tpl-info" id="tpl-info"></div>
        <div class="edit-banner" id="edit-banner" hidden>
          ✎ Po ndryshoni: <b id="edit-name"></b>
          <button type="button" class="btn small ghost" id="cancel-edit">Anulo</button>
        </div>
      </div>

      <div class="card">
        <h2>Të dhënat e studentit</h2>
        <div class="grid">
          <label>Emri *<input name="first_name" required></label>
          <label>Mbiemri *<input name="last_name" required></label>
          <div class="field">
            <span class="flabel">Gjinia</span>
            <div class="seg small" data-name="gender">
              <button type="button" data-v="M">Mashkull</button>
              <button type="button" data-v="F">Femër</button>
            </div>
          </div>
          <label>Atësia (emri i babait)<input name="father"></label>
          <label>Emri i nënës<input name="mother"></label>
          <label class="intl-only">Shtetësia<input name="nationality" list="countries"></label>
          <label>Vendlindja<input name="birth_place"></label>
          <label>Datëlindja<div class="date-wrap"><input name="birth_date" class="date" placeholder="dd/mm/vvvv" inputmode="numeric" maxlength="10" autocomplete="off"><button type="button" class="date-btn" tabindex="-1" title="Zgjidh nga kalendari">📅</button><input type="date" class="date-pick" tabindex="-1" aria-hidden="true"></div><small class="hint" id="age-hint"></small></label>
          <label><span id="doc-label">Nr. i dokumentit (ID)</span><input name="doc_no"></label>
          <label class="span2">Adresa e plotë e banimit<input name="address"></label>
          <label>Telefoni personal<input name="phone" type="tel"></label>
          <label><span id="phone2-label">Numri i dytë familjar</span><input name="phone2" type="tel"></label>
          <label class="span2">Email-i universitar <small class="hint">(krijohet vetë nga emri)</small><input name="email" type="email"></label>
        </div>
      </div>

      <div class="card" id="parent-card" hidden>
        <h2>Prindi / Përfaqësuesi ligjor <small class="hint">(për studentët nën 18 vjeç)</small></h2>
        <div class="grid">
          <label>Emri dhe mbiemri<input name="parent_name"></label>
          <label>Vendlindja<input name="parent_birth_place"></label>
          <label>Datëlindja<div class="date-wrap"><input name="parent_birth_date" class="date" placeholder="dd/mm/vvvv" inputmode="numeric" maxlength="10" autocomplete="off"><button type="button" class="date-btn" tabindex="-1" title="Zgjidh nga kalendari">📅</button><input type="date" class="date-pick" tabindex="-1" aria-hidden="true"></div></label>
          <label class="span2">Adresa e banimit<input name="parent_address"></label>
          <label>Nr. i dokumentit<input name="parent_doc"></label>
          <label>Nr. kontakti<input name="parent_phone" type="tel"></label>
        </div>
      </div>

      <div class="card">
        <h2>Programi dhe tarifa</h2>
        <div class="grid">
          <label class="span2">Programi i studimit *<select name="program_id" required></select></label>
          <label>Data e kontratës<div class="date-wrap"><input name="contract_date" class="date" placeholder="dd/mm/vvvv" inputmode="numeric" maxlength="10" autocomplete="off"><button type="button" class="date-btn" tabindex="-1" title="Zgjidh nga kalendari">📅</button><input type="date" class="date-pick" tabindex="-1" aria-hidden="true"></div></label>
          <label>Tarifa e plotë (€)<input name="fee" inputmode="decimal"><small class="hint" id="fee-words"></small></label>
          <label>Bursa / Zbritja (%)<input name="discount" inputmode="decimal" placeholder="0"></label>
          <label>Tarifa pas zbritjes (€)<input name="fee_net" inputmode="decimal"><small class="hint" id="net-words"></small></label>
          <label class="span3">📝 Shënime <small class="hint">(vetëm për ju – nuk dalin në kontratë; shfaqen te 📋 Studentët)</small><textarea name="notes" rows="3" placeholder="p.sh. mungon diploma, paguan këstin më 20/10…"></textarea></label>
        </div>
        <label class="check" id="parent-toggle-wrap"><input type="checkbox" id="parent-toggle"> Shto prindin / përfaqësuesin ligjor (studenti nën 18 vjeç)</label>
      </div>

      <div class="actions">
        <button type="submit" class="btn primary big" id="save-btn">💾 Ruaj kontratën <kbd>Ctrl</kbd>+<kbd>Enter</kbd></button>
      </div>
    </form>
  </section>

  <!-- ================= LISTA ================= -->
  <section id="tab-list" class="panel" hidden>
    <div class="card">
      <div class="list-tools">
        <input type="search" id="q" placeholder="🔎 Kërko emër, email, nr. dokumenti, program…">
        <select id="f-type"><option value="">Të gjithë</option><option value="vendas">Vendas</option><option value="nderkombetar">Ndërkombëtar</option></select>
        <select id="f-level"><option value="">Bachelor + Master</option><option value="bachelor">Bachelor</option><option value="master">Master</option></select>
        <select id="f-round"><option value="">Të gjitha raundet</option><option value="1">Raundi 1</option><option value="2">Raundi 2</option><option value="3">Raundi 3</option></select>
        <select id="f-year"></select>
        <button type="button" class="btn" id="export-btn">📊 Excel</button>
        <button type="button" class="btn" id="folder-btn">📂 Dosja e kontratave</button>
      </div>
      <div class="sync-line" id="sync-line">
        <span>🔄 Lista lexohet vetë nga dosja e kontratave: kontrata e re në dosje = studenti shtohet, kontrata e fshirë = studenti hiqet.</span>
        <small class="hint" id="sync-info"></small>
        <button type="button" class="btn small" id="sync-btn" title="Lexo përsëri dosjen e kontratave">🔄 Rifresko</button>
      </div>
      <div class="stats" id="stats"></div>
      <div class="table-wrap">
        <table id="list">
          <thead><tr><th>#</th><th>Studenti</th><th>Lloji</th><th>Programi</th><th>Tarifa</th><th>Data</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
      </div>
      <p class="empty" id="empty" hidden>Asnjë student ende.</p>
    </div>
  </section>

  <!-- ================= VËRTETIM PAGESE ================= -->
  <section id="tab-cert" class="panel" hidden>
    <div class="cert-layout">
      <form id="cf" class="card cert-form" autocomplete="off" novalidate>
        <h2>💶 Vërtetim pagese <small class="hint">shkruani emrin, zgjidhni pagesën dhe printoni</small></h2>
        <div class="grid two">
          <label>Emri *<input name="first_name" id="c-first"></label>
          <label>Mbiemri *<input name="last_name" id="c-last"></label>
        </div>
        <div class="cert-matches" id="cert-matches" hidden></div>
        <div class="field">
          <span class="flabel">Zotëri / Zonjë <small class="hint">(plotësohet vetë nëse studenti është në listë)</small></span>
          <div class="seg small" id="cert-gender">
            <button type="button" data-v="M">Z. – Zotëri</button>
            <button type="button" data-v="F">Znj. – Zonjë</button>
          </div>
        </div>
        <div class="field">
          <span class="flabel">Detyrimet financiare *</span>
          <div class="seg pay cert-pay" id="cert-pay">
            <button type="button" data-v="plote">✔ Të plota</button>
            <button type="button" data-v="pjesshem">◐ Të pjesshme</button>
          </div>
        </div>
        <div class="cert-preview" id="cert-preview"></div>
        <div class="actions cert-actions">
          <button type="submit" class="btn primary big" id="cert-save">💾 Ruaj <kbd>Enter</kbd></button>
          <button type="button" class="btn big" id="cert-open">📄 Hap në Word</button>
          <button type="button" class="btn ghost" id="cert-clear">Pastro</button>
        </div>
      </form>

      <div class="card">
        <h2>Vërtetimet e fundit <small class="hint" id="cert-count"></small></h2>
        <div class="table-wrap">
          <table id="cert-list"><tbody></tbody></table>
        </div>
        <p class="empty" id="cert-empty">Asnjë vërtetim ende.</p>
      </div>
    </div>
  </section>

  <!-- ================= CILËSIMET ================= -->
  <section id="tab-settings" class="panel" hidden>
    <p class="autosave-note">✔ Ndryshimet ruhen vetë.</p>

    <div class="settings-top">
      <div class="card">
        <h2>📅 Të përgjithshme</h2>
        <div class="stack">
          <label>Viti akademik<input id="s-year" placeholder="2026-2027"></label>
          <label>Dosja ku ruhen kontratat Word
            <div class="input-btn">
              <input id="s-dir" placeholder="Kontratat">
              <button type="button" class="btn" id="open-out-dir" title="Hap dosjen">📂</button>
            </div>
            <small class="hint"><code>Kontratat</code> = brenda dosjes së programit (në USB). Ose p.sh. <code>D:\Kontratat</code>.<br><span id="s-dir-full"></span></small>
          </label>
        </div>
      </div>

      <div class="card">
        <h2>🖨 Printimi</h2>
        <div class="setting-line">
          <div><b>Kopje të kontratës</b><small class="hint">Kontrata hartohet në dy ekzemplarë: studenti + CIT.</small></div>
          <div class="stepper" id="s-copies">
            <button type="button" data-step="-1">−</button><span>2</span><button type="button" data-step="1">+</button>
          </div>
        </div>
        <h3>Dokumente shtesë <small class="hint">krijohen vetë bashkë me kontratën</small></h3>
        <div id="docs-list" class="docs-list"></div>
        <div class="row-links">
          <button type="button" class="btn small" id="open-templates">📂 Dosja e modeleve</button>
          <small class="hint">Shtoni modelet tuaja Word me fjalët <code>{{EMRI_PLOTE}}</code>, <code>{{PROGRAMI}}</code>… (shih LEXO-MUA.txt)</small>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>🎓 Programet e studimit dhe tarifat</h2>
        <input type="search" id="prog-q" placeholder="🔎 Kërko program…">
        <button type="button" class="btn primary" id="add-prog">➕ Shto program</button>
      </div>
      <div id="fee-alert" class="fee-alert" hidden></div>
      <p class="hint">Shkruani tarifat direkt këtu (€). Tarifa del vetë në formular kur zgjidhni programin. Çelësi majtas e fsheh programin nga lista pa e fshirë.</p>
      <div class="prog-cols">
        <div><h3>Bachelor <span class="count" id="cnt-bachelor"></span></h3><div class="prog-list" id="prog-bachelor"></div></div>
        <div><h3>Master <span class="count" id="cnt-master"></span></h3><div class="prog-list" id="prog-master"></div></div>
      </div>
    </div>
  </section>
</main>

<datalist id="countries">
  <option>Turqi</option><option>Kosovë</option><option>Maqedoni e Veriut</option><option>Mal i Zi</option><option>Itali</option>
  <option>Greqi</option><option>Ukrainë</option><option>Rusi</option><option>Iran</option><option>Irak</option><option>Indi</option>
  <option>Pakistan</option><option>Bangladesh</option><option>Nigeri</option><option>Egjipt</option><option>Marok</option><option>Algjeri</option>
  <option>Azerbajxhan</option><option>Kazakistan</option><option>Uzbekistan</option><option>Gjermani</option><option>SHBA</option>
</datalist>
<dialog id="print-ask" class="ask">
  <form method="dialog">
    <div class="ask-icon" id="ask-icon">✔</div>
    <h2 id="ask-title">Kontrata u ruajt</h2>
    <p id="ask-name"></p>
    <p class="hint" id="ask-what"></p>
    <label class="ask-printer">Printeri
      <select id="ask-printer"><option value="">Printeri kryesor i Windows-it</option></select>
    </label>
    <div class="ask-buttons">
      <button type="button" data-ans="no" class="btn big">Jo, vazhdo <kbd>Esc</kbd></button>
      <button type="button" data-ans="print" class="btn primary big" id="ask-print" autofocus>🖨 Po, printo <kbd>Enter</kbd></button>
    </div>
  </form>
</dialog>
<dialog id="cert-ask" class="ask">
  <form method="dialog">
    <div class="ask-icon" id="cert-ask-icon">✔</div>
    <h2 id="cert-ask-title">Vërtetimi u ruajt</h2>
    <p id="cert-ask-name"></p>
    <p class="hint" id="cert-ask-what"></p>
    <div class="grid two ask-printer">
      <label>Printeri<select id="cert-printer"><option value="">Printeri kryesor i Windows-it</option></select></label>
      <label>Kopje<select id="cert-copies"><option>1</option><option>2</option><option>3</option></select></label>
    </div>
    <div class="ask-buttons">
      <button type="button" data-cans="no" class="btn big">Jo, vazhdo <kbd>Esc</kbd></button>
      <button type="button" data-cans="print" class="btn primary big" id="cert-ask-print">🖨 Po, printo <kbd>Enter</kbd></button>
    </div>
  </form>
</dialog>
<dialog id="prog-dialog">
  <form method="dialog" id="prog-form" autocomplete="off">
    <h2 id="prog-title">Program i ri</h2>
    <input type="hidden" name="id">
    <div class="field">
      <span class="flabel">Niveli</span>
      <div class="seg small" id="prog-level">
        <button type="button" data-v="bachelor">Bachelor</button>
        <button type="button" data-v="master">Master</button>
      </div>
    </div>
    <div class="grid two">
      <label>Programi (shqip) *<input name="name_sq" required placeholder="p.sh. Inxhinieri Software"></label>
      <label>Programi (anglisht)<input name="name_en" placeholder="p.sh. Software Engineering"></label>
      <label>Profili (shqip) <small class="hint">bosh = pa profil</small><input name="profile_sq"></label>
      <label>Profili (anglisht)<input name="profile_en"></label>
      <label>Tarifa vendas (€)<input name="fee_vendas" inputmode="decimal"></label>
      <label>Tarifa ndërkombëtare (€)<input name="fee_nderkombetar" inputmode="decimal"></label>
    </div>
    <div class="actions dialog-actions">
      <button type="button" class="btn danger ghost" id="prog-delete">🗑 Fshi programin</button>
      <span class="spacer"></span>
      <button type="button" class="btn" id="prog-cancel">Anulo</button>
      <button type="submit" class="btn primary">💾 Ruaj</button>
    </div>
  </form>
</dialog>
<div id="toast" class="toast" hidden></div>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
