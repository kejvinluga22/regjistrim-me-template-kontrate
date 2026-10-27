<?php
// Të gjitha veprimet e faqes (JSON): lista, ruajtja + krijimi i kontratës, fshirja, cilësimet, programet
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $msg, int $code = 400): void
{
    out(['ok' => false, 'error' => $msg], $code);
}

// Vetem nga ky kompjuter dhe vetem nga faqja e programit (jo nga faqe te tjera interneti)
if (!is_local_request()) fail('Vetëm nga ky kompjuter.', 403);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?$#', $origin)) fail('Ndalohet.', 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'kontratat') fail('Ndalohet.', 403);

$a = $_GET['a'] ?? '';
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;

function num_or_null($v): ?float
{
    if ($v === null) return null;
    $v = str_replace([' ', ','], ['', '.'], trim((string)$v));
    return $v === '' || !is_numeric($v) ? null : round((float)$v, 2);
}

function student_out(array $s): array
{
    $file = resolve_path($s['docx']);
    $s['file_exists'] = $file !== '' && is_file($file);
    $s['file_name'] = $file !== '' ? basename($file) : '';
    $s['docs'] = array_map(fn($d) => ['name' => $d['name'], 'exists' => is_file(resolve_path($d['path']))],
        json_decode($s['extra_docs'] ?: '[]', true) ?: []);
    return $s;
}


try {
    switch ($a) {
        case 'bootstrap':
            out([
                'ok' => true,
                'programs' => programs(),
                'settings' => ['academic_year' => academic_year(), 'out_dir' => out_dir_setting(), 'out_dir_full' => out_dir(),
                    'copies' => contract_copies(), 'docs' => docs_config()],
                'count' => (int)db()->query('SELECT COUNT(*) FROM students WHERE hidden = 0')->fetchColumn(),
            ]);

        case 'list':
            // Lista lexohet nga dosja e kontratave: kontratat e reja shtohen, te fshirat hiqen
            try {
                $sync = sync_folder();
            } catch (Throwable $e) {
                $sync = ['added' => [], 'removed' => [], 'restored' => [], 'folder' => out_dir(), 'error' => $e->getMessage()];
            }
            $rows = db()->query('SELECT * FROM students WHERE hidden = 0 ORDER BY id DESC')->fetchAll();
            out(['ok' => true, 'students' => array_map('student_out', $rows), 'sync' => $sync]);

        case 'save':
            $s = [];
            foreach (STUDENT_FIELDS as $f) $s[$f] = is_string($in[$f] ?? null) ? trim($in[$f]) : ($in[$f] ?? '');
            if (!isset(TYPES[$s['type']]) || !isset(LEVELS[$s['level']])) fail('Zgjidhni llojin (Vendas / Ndërkombëtar) dhe nivelin (Bachelor / Master).');
            $s['first_name'] = clean_name($s['first_name']);
            $s['last_name'] = clean_name($s['last_name']);
            if ($s['first_name'] === '' || $s['last_name'] === '') fail('Shkruani emrin dhe mbiemrin e studentit.');
            if (!in_array($s['gender'], ['M', 'F', ''], true)) $s['gender'] = '';
            if (!in_array($s['round_no'], ['1', '2', '3', ''], true)) $s['round_no'] = '';
            $s['payment'] = '';   // pagesa vendoset te faqja "💶 Vërtetim pagese"
            // Prindi / perfaqesuesi ligjor: vetem kontrata Bachelor vendas e ka
            $hasParent = $s['type'] === 'vendas' && $s['level'] === 'bachelor' && (!empty($s['parent_rep']) || trim((string)$s['parent_name']) !== '');
            $s['parent_rep'] = $hasParent ? 1 : 0;
            if (!$hasParent) foreach (['parent_name', 'parent_birth_place', 'parent_birth_date', 'parent_address', 'parent_doc', 'parent_phone'] as $f) $s[$f] = '';
            foreach (['father', 'mother', 'birth_place', 'parent_name', 'parent_birth_place'] as $f) {
                if ($s[$f] !== '' && ($s[$f] === mb_strtolower($s[$f]) || $s[$f] === mb_strtoupper($s[$f]))) $s[$f] = clean_name($s[$f]); // "ARBEN" -> "Arben"
            }
            foreach (['birth_date', 'contract_date', 'parent_birth_date'] as $f) {
                if ($s[$f] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s[$f])) fail('Data nuk është e saktë.');
            }
            if ($s['contract_date'] === '') $s['contract_date'] = date('Y-m-d');
            if ($s['academic_year'] === '') $s['academic_year'] = academic_year();
            if ($s['email'] === '') $s['email'] = make_email($s['first_name'], $s['last_name']);
            $s['email'] = mb_strtolower($s['email']);

            // Programi
            $q = db()->prepare('SELECT * FROM programs WHERE id = ?');
            $q->execute([(int)$s['program_id']]);
            $p = $q->fetch();
            if (!$p) fail('Zgjidhni programin e studimit.');
            if ($p['level'] !== $s['level']) fail('Programi i zgjedhur nuk është ' . LEVELS[$s['level']] . '.');
            $s['program_id'] = (int)$p['id'];
            foreach (['name_sq' => 'program_sq', 'name_en' => 'program_en', 'profile_sq' => 'profile_sq', 'profile_en' => 'profile_en'] as $from => $to) $s[$to] = $p[$from];

            // Tarifa
            $s['fee'] = num_or_null($s['fee']);
            $s['discount'] = max(0, min(100, num_or_null($s['discount']) ?? 0));
            $net = num_or_null($s['fee_net']);
            $s['fee_net'] = $net ?? ($s['fee'] === null ? null : round($s['fee'] * (1 - $s['discount'] / 100), 2));

            $id = (int)($in['id'] ?? 0);
            $pdo = db();
            $pdo->beginTransaction();
            if ($id) {
                $old = student($id);
                if (!$old) fail('Studenti nuk u gjet.', 404);
                $s['docx'] = $old['docx'];
                $s['extra_docs'] = $old['extra_docs'];
                $s['uid'] = $old['uid'] ?: new_uid();
                $s['payment'] = $s['type'] === 'nderkombetar' ? $old['payment'] : '';
                $s['created_at'] = $old['created_at'];
                $sets = implode(', ', array_map(fn($f) => "$f = :$f", STUDENT_FIELDS));
                $pdo->prepare("UPDATE students SET $sets, updated_at = datetime('now','localtime') WHERE id = :id")
                    ->execute(array_intersect_key($s, array_flip(STUDENT_FIELDS)) + ['id' => $id]);
            } else {
                $s['docx'] = '';
                $s['extra_docs'] = '[]';
                $s['uid'] = new_uid();
                $s['created_at'] = date('Y-m-d H:i:s');
                $fields = array_merge(STUDENT_FIELDS, ['uid', 'created_at']);
                $cols = implode(', ', $fields);
                $vals = implode(', ', array_map(fn($f) => ":$f", $fields));
                $pdo->prepare("INSERT INTO students ($cols) VALUES ($vals)")->execute(array_intersect_key($s, array_flip($fields)));
                $id = (int)$pdo->lastInsertId();
            }
            try {
                $files = generate_all($s);
            } catch (Throwable $e) {
                $pdo->rollBack();
                fail($e->getMessage(), 500);
            }
            $path = $files['contract'];
            $pdo->prepare('UPDATE students SET docx = ?, extra_docs = ?, uid = ?, hidden = 0, has_meta = 1 WHERE id = ?')
                ->execute([stored_path($path), json_encode($files['docs'], JSON_UNESCAPED_UNICODE), $s['uid'], $id]);
            $pdo->commit();

            // Paralajmerime (jo pengesa)
            $warn = [];
            $dup = $pdo->prepare("SELECT first_name || ' ' || last_name FROM students WHERE id <> ? AND hidden = 0 AND ((doc_no <> '' AND doc_no = ?) OR email = ?)");
            $dup->execute([$id, $s['doc_no'], $s['email']]);
            if ($n = $dup->fetchColumn()) $warn[] = "Kujdes: i njëjti nr. dokumenti ose email ekziston edhe te $n.";
            if ($s['fee'] === null) $warn[] = 'Tarifa nuk është vendosur – në kontratë ka mbetur bosh.';
            if ($in['open'] ?? false) win_open($path);

            out(['ok' => true, 'student' => student_out(student($id)), 'warnings' => $warn]);

        case 'delete':
            $s = student((int)($in['id'] ?? 0));
            if (!$s) fail('Studenti nuk u gjet.', 404);
            $file = resolve_path($s['docx']);
            if ($file !== '' && is_file($file) && !@unlink($file)) fail('Kontrata është e hapur në Word. Mbylleni dhe provoni përsëri.');
            foreach (json_decode($s['extra_docs'] ?: '[]', true) ?: [] as $d) @unlink(resolve_path($d['path']));
            db()->prepare('DELETE FROM students WHERE id = ?')->execute([$s['id']]);
            out(['ok' => true]);

        case 'open':      // hap kontraten (ose dokumentin shtese nr. "doc") ne Word
        case 'folder':    // hap dosjen ne Explorer
        case 'print':     // printon kontraten + dokumentet shtese ne printerin kryesor (me Word, pa e hapur)
            $s = student((int)($in['id'] ?? 0));
            if ($a === 'folder' && !$s) {
                if (!is_dir(out_dir())) @mkdir(out_dir(), 0777, true);
                win_open(out_dir());
                out(['ok' => true]);
            }
            if (!$s) fail('Studenti nuk u gjet.', 404);
            $files = ensure_files($s);
            if ($a === 'print') {
                refresh_print_docs($s, $files);
                win_print(print_jobs($files['contract'], $files['docs']), trim((string)($in['printer'] ?? '')));
                out(['ok' => true, 'student' => student_out(student($s['id']))]);
            } else {
                $file = $files['contract'];
                if (isset($in['doc_name'])) {   // dokumenti shtese sipas emrit, p.sh. "Vërtetim pagese"
                    $d = array_column($files['docs'], null, 'name')[(string)$in['doc_name']] ?? null;
                    if (!$d) fail('Dokumenti nuk u gjet.', 404);
                    $file = resolve_path($d['path']);
                } elseif (isset($in['doc'])) {
                    $d = $files['docs'][(int)$in['doc']] ?? null;
                    if (!$d) fail('Dokumenti nuk u gjet.', 404);
                    $file = resolve_path($d['path']);
                }
                win_open($file, $a === 'folder');
            }
            out(['ok' => true]);

        case 'certs':   // vertetimet e fundit te pageses
            $rows = db()->query('SELECT * FROM certs ORDER BY updated_at DESC, id DESC LIMIT 100')->fetchAll();
            foreach ($rows as &$r) $r['exists'] = $r['file'] !== '' && is_file(resolve_path($r['file']));
            out(['ok' => true, 'certs' => $rows, 'academic_year' => academic_year()]);

        case 'cert':   // krijon Vërtetimin e pagesës dhe e printon ('do' => print) ose e hap në Word ('do' => open)
            $first = clean_name((string)($in['first_name'] ?? ''));
            $last = clean_name((string)($in['last_name'] ?? ''));
            $gender = in_array($in['gender'] ?? '', ['M', 'F'], true) ? $in['gender'] : '';
            $payment = (string)($in['payment'] ?? '');
            if ($first === '' || $last === '') fail('Shkruani emrin dhe mbiemrin.');
            if (!isset(PAYMENTS[$payment])) fail('Zgjidhni: "Të plota" ose "Të pjesshme".');
            $year = academic_year();
            $path = make_payment_cert($first, $last, $gender, $payment, $year);
            // Ruhet te lista e vertetimeve (i njejti student ne te njejtin vit -> perditesohet)
            $id = 0;
            foreach (db()->query('SELECT id, first_name, last_name, academic_year FROM certs')->fetchAll() as $r) {
                if (mb_strtolower($r['first_name'] . ' ' . $r['last_name']) === mb_strtolower("$first $last") && $r['academic_year'] === $year) { $id = (int)$r['id']; break; }
            }
            if ($id) db()->prepare("UPDATE certs SET first_name=?, last_name=?, gender=?, payment=?, file=?, updated_at=datetime('now','localtime') WHERE id=?")
                ->execute([$first, $last, $gender, $payment, stored_path($path), $id]);
            else db()->prepare('INSERT INTO certs (first_name, last_name, gender, payment, academic_year, file) VALUES (?,?,?,?,?,?)')
                ->execute([$first, $last, $gender, $payment, $year, stored_path($path)]);
            // Studenti nderkombetar me te njejtin emer: pagesa shfaqet edhe te 📋 Studentët
            foreach (db()->query("SELECT * FROM students WHERE type = 'nderkombetar' AND hidden = 0")->fetchAll() as $st) {
                if (mb_strtolower($st['first_name'] . ' ' . $st['last_name']) !== mb_strtolower("$first $last") || $st['payment'] === $payment) continue;
                db()->prepare('UPDATE students SET payment = ? WHERE id = ?')->execute([$payment, $st['id']]);
                $st['payment'] = $payment;
                $c = resolve_path($st['docx']);
                if ($c !== '' && is_file($c)) write_meta($c, $st);
            }
            // 'save' = vetem ruhet;  'print' = printohet;  'open' = hapet ne Word
            if (($in['do'] ?? '') === 'print') win_print([['path' => $path, 'copies' => max(1, min(5, (int)($in['copies'] ?? 1)))]], trim((string)($in['printer'] ?? '')));
            elseif (($in['do'] ?? '') === 'open') win_open($path);
            out(['ok' => true, 'file' => basename($path)]);

        case 'cert_delete':
            $q = db()->prepare('SELECT * FROM certs WHERE id = ?');
            $q->execute([(int)($in['id'] ?? 0)]);
            $r = $q->fetch();
            if (!$r) fail('Vërtetimi nuk u gjet.', 404);
            $f = resolve_path($r['file']);
            if ($f !== '' && is_file($f) && !@unlink($f)) fail('Vërtetimi është i hapur në Word. Mbylleni dhe provoni përsëri.');
            db()->prepare('DELETE FROM certs WHERE id = ?')->execute([$r['id']]);
            out(['ok' => true]);

        case 'printers':   // printerat e kompjuterit (per zgjedhjen para printimit)
            out(['ok' => true, 'printers' => printers()]);

        case 'print_settings':   // kopjet e kontrates + dokumentet shtese (aktiv, per ke, printo, kopje)
            set_setting('copies', (string)max(1, min(5, (int)($in['copies'] ?? 2))));
            $cfg = [];
            foreach ($in['docs'] ?? [] as $d) {
                $f = basename((string)($d['file'] ?? ''));
                if ($f === '' || !is_file(DOCS_DIR . DIRECTORY_SEPARATOR . $f)) continue;
                $cfg[$f] = ['enabled' => !empty($d['enabled']), 'print' => !empty($d['print']),
                    'scope' => in_array($d['scope'] ?? '', ['all', 'vendas', 'nderkombetar'], true) ? $d['scope'] : 'all',
                    'copies' => max(1, min(5, (int)($d['copies'] ?? 1)))];
            }
            set_setting('docs', json_encode($cfg, JSON_UNESCAPED_UNICODE));
            backup_settings();
            out(['ok' => true, 'copies' => contract_copies(), 'docs' => docs_config()]);

        case 'template':   // hap modelin Word te nje dokumenti (per ta ndryshuar) ose dosjen e modeleve
            if (!is_dir(DOCS_DIR)) @mkdir(DOCS_DIR, 0777, true);
            $f = basename((string)($in['file'] ?? ''));
            if ($f !== '' && is_file(DOCS_DIR . DIRECTORY_SEPARATOR . $f)) win_open(DOCS_DIR . DIRECTORY_SEPARATOR . $f);
            else win_open(DOCS_DIR);
            out(['ok' => true]);

        case 'settings':
            $year = trim($in['academic_year'] ?? '');
            if (!preg_match('/^\d{4}-\d{4}$/', $year)) fail('Viti akademik duhet të jetë si 2026-2027.');
            $dir = trim($in['out_dir'] ?? '') ?: DEFAULT_OUT_DIR;
            $full = is_abs_path($dir) ? $dir : root_dir() . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($full) && !@mkdir($full, 0777, true)) fail('Dosja nuk u krijua: ' . $full);
            set_setting('academic_year', $year);
            set_setting('out_dir', $dir);
            backup_settings();
            out(['ok' => true]);

        case 'program_save':   // shton / ndryshon nje program
            $level = ($in['level'] ?? '') === 'master' ? 'master' : 'bachelor';
            $vals = [$level, trim($in['name_sq'] ?? ''), trim($in['name_en'] ?? ''), trim($in['profile_sq'] ?? ''), trim($in['profile_en'] ?? ''),
                num_or_null($in['fee_vendas'] ?? null), num_or_null($in['fee_nderkombetar'] ?? null), empty($in['active']) ? 0 : 1];
            if ($vals[1] === '') fail('Shkruani emrin e programit.');
            if (!empty($in['id'])) {
                db()->prepare('UPDATE programs SET level=?, name_sq=?, name_en=?, profile_sq=?, profile_en=?, fee_vendas=?, fee_nderkombetar=?, active=? WHERE id=?')
                    ->execute([...$vals, (int)$in['id']]);
            } else {
                $sort = (int)db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM programs')->fetchColumn();
                db()->prepare('INSERT INTO programs (level, name_sq, name_en, profile_sq, profile_en, fee_vendas, fee_nderkombetar, active, sort) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([...$vals, $sort]);
            }
            backup_settings();
            out(['ok' => true, 'programs' => programs()]);

        case 'program_delete':   // fshihet nese s'ka studente, perndryshe vetem caktivizohet
            $id = (int)($in['id'] ?? 0);
            $used = db()->prepare('SELECT COUNT(*) FROM students WHERE program_id = ?');
            $used->execute([$id]);
            db()->prepare($used->fetchColumn() ? 'UPDATE programs SET active = 0 WHERE id = ?' : 'DELETE FROM programs WHERE id = ?')->execute([$id]);
            backup_settings();
            out(['ok' => true, 'programs' => programs()]);

        default:
            fail('Veprim i panjohur.', 404);
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    fail('Gabim: ' . $e->getMessage(), 500);
}
