<?php
// ====== KONTRATAT E STUDENTËVE – CIT ======
// Databaza: SQLite (app/data/kontratat.sqlite) – nuk ka nevojë për XAMPP / MySQL.
// Modelet Word: app/templates/*.docx me fjalët {{EMRI_PLOTE}}, {{PROGRAMI}} ... (shih LEXO-MUA.txt)

date_default_timezone_set('Europe/Tirane');

const ROOT = __DIR__ . DIRECTORY_SEPARATOR . '..';               // dosja Kontratat_Studenteve
const DATA_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'data';
const DB_FILE = DATA_DIR . DIRECTORY_SEPARATOR . 'kontratat.sqlite';
const TEMPLATES_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'templates';
const DOCS_DIR = TEMPLATES_DIR . DIRECTORY_SEPARATOR . 'dokumente';   // dokumentet shtese (Letër pranimi, ...)
const EMAIL_DOMAIN = 'cit.edu.al';
const DEFAULT_OUT_DIR = 'Kontratat';                               // relative = brenda dosjes se programit
const DEFAULT_ACADEMIC_YEAR = '2026-2027';
const BLANK = '____________';                                      // fushat bosh mbeten per t'u plotesuar me dore

const TYPES = ['vendas' => 'Vendas', 'nderkombetar' => 'Ndërkombëtar'];
const LEVELS = ['bachelor' => 'Bachelor', 'master' => 'Master'];

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// ---------- Databaza ----------
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
    $new = !is_file(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = DELETE; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
        CREATE TABLE IF NOT EXISTS programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            level TEXT NOT NULL,                 -- bachelor | master
            name_sq TEXT NOT NULL, name_en TEXT NOT NULL DEFAULT '',
            profile_sq TEXT NOT NULL DEFAULT '', profile_en TEXT NOT NULL DEFAULT '',
            fee_vendas REAL, fee_nderkombetar REAL,
            sort INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS students (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL, level TEXT NOT NULL, academic_year TEXT NOT NULL DEFAULT '',
            first_name TEXT NOT NULL, last_name TEXT NOT NULL, gender TEXT NOT NULL DEFAULT '',
            father TEXT NOT NULL DEFAULT '', mother TEXT NOT NULL DEFAULT '',
            birth_place TEXT NOT NULL DEFAULT '', birth_date TEXT NOT NULL DEFAULT '',
            nationality TEXT NOT NULL DEFAULT '', address TEXT NOT NULL DEFAULT '', doc_no TEXT NOT NULL DEFAULT '',
            phone TEXT NOT NULL DEFAULT '', phone2 TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
            program_id INTEGER, program_sq TEXT NOT NULL DEFAULT '', program_en TEXT NOT NULL DEFAULT '',
            profile_sq TEXT NOT NULL DEFAULT '', profile_en TEXT NOT NULL DEFAULT '',
            fee REAL, discount REAL NOT NULL DEFAULT 0, fee_net REAL,
            contract_date TEXT NOT NULL DEFAULT '',
            parent_name TEXT NOT NULL DEFAULT '', parent_birth_place TEXT NOT NULL DEFAULT '',
            parent_birth_date TEXT NOT NULL DEFAULT '', parent_address TEXT NOT NULL DEFAULT '',
            parent_doc TEXT NOT NULL DEFAULT '', parent_phone TEXT NOT NULL DEFAULT '',
            notes TEXT NOT NULL DEFAULT '', docx TEXT NOT NULL DEFAULT '',
            created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
        );
        CREATE INDEX IF NOT EXISTS ix_students_name ON students (last_name, first_name);
    ");
    // Fushat e shtuara me vone (databazat e vjetra marrin kolonen vete)
    $cols = array_column($pdo->query('PRAGMA table_info(students)')->fetchAll(), 'name');
    if (!in_array('round_no', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN round_no TEXT NOT NULL DEFAULT ''"); // Raundi 1/2/3 (vetem per evidence, jo ne kontrate)
    if (!in_array('parent_rep', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN parent_rep INTEGER NOT NULL DEFAULT 0"); // 1 = ka prind / perfaqesues ligjor
    if (!in_array('extra_docs', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN extra_docs TEXT NOT NULL DEFAULT '[]'"); // [{name, path}] dokumentet shtese te krijuara
    if (!in_array('payment', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN payment TEXT NOT NULL DEFAULT ''"); // nderkombetar: plote | pjesshem (Vërtetimi i pagesës)
    if (!in_array('uid', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN uid TEXT NOT NULL DEFAULT ''"); // kodi i studentit, ruhet edhe brenda kontrates Word
    if (!in_array('hidden', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN hidden INTEGER NOT NULL DEFAULT 0"); // 1 = kontrata u hoq nga dosja (rikthehet kur kthehet skedari)
    if (!in_array('has_meta', $cols, true)) $pdo->exec("ALTER TABLE students ADD COLUMN has_meta INTEGER NOT NULL DEFAULT 0"); // 1 = te dhenat jane ruajtur brenda kontrates Word
    // Vërtetimet e pagesës (faqja 💶 Vërtetim pagese)
    $pdo->exec("CREATE TABLE IF NOT EXISTS certs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT NOT NULL, last_name TEXT NOT NULL, gender TEXT NOT NULL DEFAULT '',
        payment TEXT NOT NULL, academic_year TEXT NOT NULL DEFAULT '', file TEXT NOT NULL DEFAULT '',
        created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
    )");
    foreach ($pdo->query("SELECT id FROM students WHERE uid = ''")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->prepare('UPDATE students SET uid = ? WHERE id = ?')->execute([new_uid(), $id]);
    }
    if ($new || !(int)$pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn()) {
        // Kompjuter i ri: programet, tarifat dhe cilesimet merren nga dosja e kontratave (nese jane ruajtur aty)
        if (!restore_settings($pdo)) seed_programs($pdo);
    }
    return $pdo;
}

// ---------- Kopja e cilesimeve ne dosjen e kontratave (per nje kompjuter tjeter) ----------
const SETTINGS_FILE = '_cilesimet_e_programit.json';

function settings_file(?string $dir = null): string
{
    return ($dir ?? out_dir()) . DIRECTORY_SEPARATOR . SETTINGS_FILE;
}

// Ruan programet, tarifat dhe cilesimet te dosja e kontratave (vetem kur ndryshojne)
function backup_settings(): void
{
    if (!is_dir(out_dir())) return;
    $keys = array_flip(['level', 'name_sq', 'name_en', 'profile_sq', 'profile_en', 'fee_vendas', 'fee_nderkombetar', 'sort', 'active']);
    $data = [
        'shenim' => 'Kopje automatike e programeve dhe cilësimeve (Kontratat e Studentëve). Përdoret kur programi hapet në një kompjuter të ri.',
        'programs' => array_map(fn($p) => array_intersect_key($p, $keys), programs()),
        'settings' => ['academic_year' => academic_year(), 'copies' => setting('copies'), 'docs' => setting('docs')],
    ];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $file = settings_file();
    if (@file_get_contents($file) !== $json) @file_put_contents($file, $json);
}

function restore_settings(PDO $pdo): bool
{
    $file = settings_file(root_dir() . DIRECTORY_SEPARATOR . DEFAULT_OUT_DIR);
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($data) || empty($data['programs']) || !is_array($data['programs'])) return false;
    $q = $pdo->prepare('INSERT INTO programs (level, name_sq, name_en, profile_sq, profile_en, fee_vendas, fee_nderkombetar, sort, active) VALUES (?,?,?,?,?,?,?,?,?)');
    foreach ($data['programs'] as $i => $p) {
        if (!isset(LEVELS[$p['level'] ?? '']) || trim((string)($p['name_sq'] ?? '')) === '') continue;
        $fee = fn($v) => $v === null || $v === '' ? null : (float)$v;
        $q->execute([$p['level'], $p['name_sq'], (string)($p['name_en'] ?? ''), (string)($p['profile_sq'] ?? ''), (string)($p['profile_en'] ?? ''),
            $fee($p['fee_vendas'] ?? null), $fee($p['fee_nderkombetar'] ?? null), (int)($p['sort'] ?? $i + 1), empty($p['active']) ? 0 : 1]);
    }
    $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v');
    foreach (['academic_year', 'copies', 'docs'] as $k) {
        if (isset($data['settings'][$k]) && is_string($data['settings'][$k])) $set->execute([$k, $data['settings'][$k]]);
    }
    return (int)$pdo->query('SELECT COUNT(*) FROM programs')->fetchColumn() > 0;
}

function new_uid(): string
{
    return bin2hex(random_bytes(8));
}

// Programet e CIT (ndryshohen te ⚙ Cilësimet). Tarifat vendase plotësohen nga administratori.
function seed_programs(PDO $pdo): void
{
    $rows = [
        ['bachelor', 'Inxhinieri Software', 'Software Engineering', '', ''],
        ['bachelor', 'Inxhinieri Kompjuterike dhe Teknologji Informacioni', 'Computer Engineering and Information Technology', '', ''],
        ['bachelor', 'Inteligjencë Artificiale dhe Shkencë e të Dhënave', 'Artificial Intelligence and Data Science', '', ''],
        ['bachelor', 'Inxhinieri Elektronike', 'Electronic Engineering', '', ''],
        ['bachelor', 'Inxhinieri Robotike dhe Mekatronike', 'Robotics and Mechatronics Engineering', '', ''],
        ['bachelor', 'Inxhinieri Telekomunikacioni', 'Telecommunication Engineering', '', ''],
        ['bachelor', 'Administrim Biznesi dhe Teknologji Informacioni', 'Business Administration and Information Technology', '', ''],
        ['bachelor', 'Administrim Biznesi', 'Business Administration', 'Marketing Dixhital', 'Digital Marketing'],
        ['bachelor', 'Administrim Biznesi', 'Business Administration', 'Analitikë Biznesi', 'Business Analytics'],
        ['bachelor', 'Financë dhe Kontabilitet', 'Finance and Accounting', 'FinTech dhe Investime', 'FinTech and Investment'],
        ['bachelor', 'Financë dhe Kontabilitet', 'Finance and Accounting', 'Financë Ndërkombëtare dhe Ekonomi', 'International Finance and Economics'],
        ['master', 'Inxhinieri Software', 'Software Engineering', '', ''],
        ['master', 'Inxhinieri Kompjuterike dhe Teknologji Informacioni', 'Computer Engineering and Information Technology', 'Inxhinieri Kompjuterike dhe Big Data', 'Computer Engineering and Big Data'],
        ['master', 'Inxhinieri Kompjuterike dhe Teknologji Informacioni', 'Computer Engineering and Information Technology', 'Rrjete Kompjuterike dhe Siguri Kibernetike', 'Computer Network and Cybersecurity'],
        ['master', 'Inxhinieri Kompjuterike dhe Teknologji Informacioni', 'Computer Engineering and Information Technology', 'Dizajn Multimedial', 'Multimedia Design'],
        ['master', 'Inteligjencë Artificiale', 'Artificial Intelligence', '', ''],
        ['master', 'Administrim Biznesi', 'Business Administration', '', ''],
        ['master', 'Teknologji Informacioni në Biznes', 'Business Information Technology', '', ''],
        ['master', 'Marketing Dixhital', 'Digital Marketing', '', ''],
        ['master', 'Financë dhe Kontabilitet', 'Finance and Accounting', 'Kontabilitet dhe Auditim', 'Accounting and Auditing'],
        ['master', 'Financë dhe Kontabilitet', 'Finance and Accounting', 'Financë dhe Bankë', 'Finance and Banking'],
    ];
    $q = $pdo->prepare('INSERT INTO programs (level, name_sq, name_en, profile_sq, profile_en, fee_vendas, fee_nderkombetar, sort) VALUES (?,?,?,?,?,NULL,3500,?)');
    foreach ($rows as $i => $r) $q->execute([...$r, $i + 1]);
}

function setting(string $k, ?string $default = null): ?string
{
    $q = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $k, ?string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$k, $v]);
}

function academic_year(): string
{
    return setting('academic_year', DEFAULT_ACADEMIC_YEAR);
}

function programs(bool $activeOnly = false): array
{
    return db()->query('SELECT * FROM programs' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY level, sort, id')->fetchAll();
}

// ---------- Dosjet ----------
function is_abs_path(string $p): bool
{
    return (bool)preg_match('#^([A-Za-z]:[\\\\/]|\\\\\\\\|/)#', $p);
}

function root_dir(): string
{
    return realpath(ROOT) ?: ROOT;
}

// Dosja ku ruhen kontratat (e ndryshueshme te Cilësimet). Relative = brenda dosjes se programit (USB).
function out_dir_setting(): string
{
    return setting('out_dir', DEFAULT_OUT_DIR);
}

function out_dir(): string
{
    $d = out_dir_setting();
    return is_abs_path($d) ? $d : root_dir() . DIRECTORY_SEPARATOR . $d;
}

// Skedari i ruajtur ne databaze: relativ ndaj dosjes se programit (qe te punoje nga cdo germe USB-je)
function stored_path(string $abs): string
{
    $root = root_dir() . DIRECTORY_SEPARATOR;
    $abs = str_replace('/', DIRECTORY_SEPARATOR, $abs);
    return stripos($abs, $root) === 0 ? substr($abs, strlen($root)) : $abs;
}

function resolve_path(string $stored): string
{
    if ($stored === '') return '';
    return is_abs_path($stored) ? $stored : root_dir() . DIRECTORY_SEPARATOR . $stored;
}

function safe_file_part(string $s): string
{
    $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s), ' .');
}

// ---------- Emrat / emaili ----------
function translit_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $groups = [
        'a' => 'àáâãäåāăą', 'A' => 'ÀÁÂÃÄÅĀĂĄ', 'c' => 'çćĉċč', 'C' => 'ÇĆĈĊČ',
        'd' => 'ďđð', 'D' => 'ĎĐÐ', 'e' => 'èéêëēĕėęě', 'E' => 'ÈÉÊËĒĔĖĘĚ',
        'g' => 'ĝğġģ', 'G' => 'ĜĞĠĢ', 'h' => 'ĥħ', 'H' => 'ĤĦ',
        'i' => 'ìíîïĩīĭįı', 'I' => 'ÌÍÎÏĨĪĬĮİ', 'j' => 'ĵ', 'J' => 'Ĵ', 'k' => 'ķ', 'K' => 'Ķ',
        'l' => 'ĺļľŀł', 'L' => 'ĹĻĽĿŁ', 'n' => 'ñńņňŉ', 'N' => 'ÑŃŅŇ',
        'o' => 'òóôõöøōŏő', 'O' => 'ÒÓÔÕÖØŌŎŐ', 'r' => 'ŕŗř', 'R' => 'ŔŖŘ',
        's' => 'śŝşšș', 'S' => 'ŚŜŞŠȘ', 't' => 'ţťŧț', 'T' => 'ŢŤŦȚ',
        'u' => 'ùúûüũūŭůűų', 'U' => 'ÙÚÛÜŨŪŬŮŰŲ', 'w' => 'ŵ', 'W' => 'Ŵ',
        'y' => 'ýÿŷ', 'Y' => 'ÝŸŶ', 'z' => 'źżž', 'Z' => 'ŹŻŽ',
    ];
    $map = ['æ' => 'ae', 'Æ' => 'Ae', 'œ' => 'oe', 'Œ' => 'Oe', 'ß' => 'ss', 'þ' => 'th', 'Þ' => 'Th'];
    foreach ($groups as $plain => $chars) {
        foreach (mb_str_split($chars) as $ch) $map[$ch] = $plain;
    }
    return $map;
}

// "aNA  maria" -> "Ana Maria"
function clean_name(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    return mb_convert_case(mb_strtolower($s, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
}

// "Yağmur Deniz" + "Özcan" -> "yagmurdeniz.ozcan@cit.edu.al" (i njejte me makeEmail() ne app.js)
function make_email(string $first, string $last): string
{
    $part = fn($s) => strtolower(preg_replace('/[^A-Za-z]/', '', strtr($s, translit_map())));
    return $part($first) . '.' . $part($last) . '@' . EMAIL_DOMAIN;
}

// "2008-07-21" -> "21.07.2008"
function fmt_date(string $iso): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) ? "$m[3].$m[2].$m[1]" : $iso;
}

// ---------- Shumat ----------
function fmt_money(?float $n): string
{
    if ($n === null) return '';
    return abs($n - round($n)) < 0.005 ? (string)(int)round($n) : number_format($n, 2, ',', '');
}

// 3500 -> "tremijë e pesëqind"
function words_sq(int $n): string
{
    if ($n === 0) return 'zero';
    $u = ['', 'një', 'dy', 'tre', 'katër', 'pesë', 'gjashtë', 'shtatë', 'tetë', 'nëntë'];
    $t = [2 => 'njëzet', 3 => 'tridhjetë', 4 => 'dyzet', 5 => 'pesëdhjetë', 6 => 'gjashtëdhjetë', 7 => 'shtatëdhjetë', 8 => 'tetëdhjetë', 9 => 'nëntëdhjetë'];
    $b100 = function (int $n) use ($u, $t): string {
        if ($n < 10) return $u[$n];
        if ($n === 10) return 'dhjetë';
        if ($n < 20) return $u[$n - 10] . 'mbëdhjetë';
        return $t[intdiv($n, 10)] . ($n % 10 ? ' e ' . $u[$n % 10] : '');
    };
    $b1000 = function (int $n) use ($u, $b100): string {
        $parts = [];
        if ($n >= 100) $parts[] = $u[intdiv($n, 100)] . 'qind';
        if ($n % 100) $parts[] = $b100($n % 100);
        return implode(' e ', $parts);
    };
    $parts = [];
    if ($n >= 1000000) {
        $m = intdiv($n, 1000000);
        $parts[] = ($m === 1 ? 'një' : $b1000($m)) . ' milion';
        $n %= 1000000;
    }
    if ($n >= 1000) {
        $k = intdiv($n, 1000);
        $parts[] = $k < 10 ? $u[$k] . 'mijë' : $b1000($k) . ' mijë';
        $n %= 1000;
    }
    if ($n) $parts[] = $b1000($n);
    return implode(' e ', $parts);
}

// 3500 -> "Three thousand five hundred"
function words_en(int $n): string
{
    if ($n === 0) return 'Zero';
    $u = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
        'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $t = [2 => 'twenty', 3 => 'thirty', 4 => 'forty', 5 => 'fifty', 6 => 'sixty', 7 => 'seventy', 8 => 'eighty', 9 => 'ninety'];
    $b1000 = function (int $n) use ($u, $t): string {
        $parts = [];
        if ($n >= 100) { $parts[] = $u[intdiv($n, 100)] . ' hundred'; $n %= 100; }
        if ($n >= 20) $parts[] = $t[intdiv($n, 10)] . ($n % 10 ? '-' . $u[$n % 10] : '');
        elseif ($n) $parts[] = $u[$n];
        return implode(' ', $parts);
    };
    $parts = [];
    foreach ([1000000 => 'million', 1000 => 'thousand'] as $div => $name) {
        if ($n >= $div) { $parts[] = $b1000(intdiv($n, $div)) . ' ' . $name; $n %= $div; }
    }
    if ($n) $parts[] = $b1000($n);
    return ucfirst(implode(' ', $parts));
}

function money_words(?float $n, string $lang): string
{
    if ($n === null) return '';
    $int = (int)floor($n + 0.0000001);
    $cents = (int)round(($n - $int) * 100);
    if ($lang === 'sq') return words_sq($int) . ($cents ? ' e ' . words_sq($cents) . ' cent' : '');
    return words_en($int) . ($cents ? ' and ' . strtolower(words_en($cents)) . ' cents' : '');
}

// ---------- Studentet ----------
const STUDENT_FIELDS = ['type', 'level', 'round_no', 'academic_year', 'first_name', 'last_name', 'gender', 'father', 'mother',
    'birth_place', 'birth_date', 'nationality', 'address', 'doc_no', 'phone', 'phone2', 'email',
    'program_id', 'program_sq', 'program_en', 'profile_sq', 'profile_en', 'fee', 'discount', 'fee_net', 'contract_date',
    'parent_rep', 'parent_name', 'parent_birth_place', 'parent_birth_date', 'parent_address', 'parent_doc', 'parent_phone', 'notes', 'payment'];

const PAYMENTS = ['plote' => 'Të plota', 'pjesshem' => 'Të pjesshme'];   // Vërtetim pagese (faqja 💶)
const PAY_TEMPLATE = DOCS_DIR . DIRECTORY_SEPARATOR . 'Vërtetim pagese.docx';   // modeli mbetet aty ku eshte, por nuk printohet me kontraten
const PAY_DIR = 'Vërtetime pagese';   // nendosja e vitit akademik ku ruhen vertetimet

// Fjalet e Vërtetimit te pagesës: {{Z_ZNJ}} {{MR_MS}} {{PAGESA_SQ}} {{PAGESA_EN}} {{DATA_SOT}} {{VITI_AKADEMIK_EN}}
function payment_values(string $gender, string $payment, string $year): array
{
    return [
        'Z_ZNJ'            => $gender === 'M' ? 'Z.' : ($gender === 'F' ? 'Znj.' : 'Z/Znj.'),
        'MR_MS'            => $gender === 'M' ? 'Mr.' : ($gender === 'F' ? 'Ms.' : 'Mr./Ms.'),
        'PAGESA_SQ'        => ['plote' => 'të plota', 'pjesshem' => 'të pjesshme'][$payment] ?? '(të plota / të pjesshme)',
        'PAGESA_EN'        => ['plote' => 'full', 'pjesshem' => 'partial'][$payment] ?? '(full/partial)',
        'DATA_SOT'         => date('d.m.Y'),
        'VITI_AKADEMIK_EN' => str_replace('-', '–', $year),
    ];
}

// Krijon Vërtetimin e pagesës: Kontratat\2026-2027\Vërtetime pagese\Emri Mbiemri - Vërtetim pagese.docx
function make_payment_cert(string $first, string $last, string $gender, string $payment, string $year): string
{
    if (!is_file(PAY_TEMPLATE)) throw new RuntimeException('Modeli Word mungon: ' . basename(PAY_TEMPLATE));
    $dir = out_dir() . DIRECTORY_SEPARATOR . safe_file_part($year) . DIRECTORY_SEPARATOR . PAY_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) throw new RuntimeException('Nuk u krijua dosja: ' . $dir);
    $name = trim($first . ' ' . $last);
    $path = $dir . DIRECTORY_SEPARATOR . (safe_file_part($name) ?: 'Student') . ' - Vërtetim pagese.docx';
    fill_docx(PAY_TEMPLATE, $path, ['EMRI_PLOTE' => $name, 'VITI_AKADEMIK' => $year] + payment_values($gender, $payment, $year));
    return $path;
}

function student(int $id): ?array
{
    $q = db()->prepare('SELECT * FROM students WHERE id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function template_file(array $s): string
{
    return TEMPLATES_DIR . DIRECTORY_SEPARATOR . $s['type'] . '_' . $s['level'] . '.docx';
}

// Vlerat qe futen ne kontrate. Fusha bosh -> "____________" (plotesohet me dore).
function contract_values(array $s): array
{
    $v = fn($x) => trim((string)$x) === '' ? BLANK : trim((string)$x);
    $g = $s['gender'];
    $isMaster = $s['level'] === 'master';
    $programSq = $s['program_sq'];
    $programEn = $s['program_en'];
    // Bachelor: profili shkon bashke me programin; Master: ka vendin e vet ("profili: ...")
    if (!$isMaster && $s['profile_sq'] !== '') $programSq .= ', profili ' . $s['profile_sq'];
    if (!$isMaster && $s['profile_en'] !== '') $programEn .= ', ' . $s['profile_en'] . ' profile';
    $fee = $s['fee'] === null || $s['fee'] === '' ? null : (float)$s['fee'];
    $net = $s['fee_net'] === null || $s['fee_net'] === '' ? null : (float)$s['fee_net'];
    $name = trim($s['first_name'] . ' ' . $s['last_name']);
    $profSq = $isMaster && $s['profile_sq'] !== '' ? ', profili: ' . $s['profile_sq'] : '';
    $profEn = $isMaster && $s['profile_en'] !== '' ? ', profile: ' . $s['profile_en'] : '';
    $k1 = $net === null ? null : round($net / 2, 2);
    $nationality = $s['nationality'] !== '' ? $s['nationality'] : ($s['type'] === 'vendas' ? 'Shqiptare' : '');
    return [
        // Paragrafet me {{?PRINDI}} hiqen nga kontrata kur studenti s'ka prind / perfaqesues ligjor
        '?PRINDI'            => !empty($s['parent_rep']) || trim((string)($s['parent_name'] ?? '')) !== '',
        // Paragrafet me {{?BURSA}} (kontrata nderkombetare) mbeten vetem kur studenti ka burse / zbritje
        '?BURSA'             => (float)$s['discount'] > 0 || ($fee !== null && $net !== null && $net < $fee),
        // Per dokumentet shtese (Letër pranimi, Vërtetim, Fletë pagese)
        'NIVELI_SQ'          => $isMaster ? 'Master i Shkencave' : 'Bachelor',
        'LEVEL_EN'           => $isMaster ? 'Master of Science' : 'Bachelor',
        'KOHEZGJATJA'        => $isMaster ? '2 vite akademike (4 semestra, 120 ECTS)' : '3 vite akademike (6 semestra, 180 ECTS)',
        'DURATION_EN'        => $isMaster ? '2 academic years (4 semesters, 120 ECTS)' : '3 academic years (6 semesters, 180 ECTS)',
        'PROGRAMI_PLOTE'     => $v($programSq) . $profSq,
        'PROGRAM_EN_PLOTE'   => $v($programEn) . $profEn,
        'I_NDERUAR'          => ($g === 'M' ? 'I nderuar z. ' : ($g === 'F' ? 'E nderuar znj. ' : 'I/E nderuar ')) . $name,
        'DEAR'               => ($g === 'M' ? 'Dear Mr. ' : ($g === 'F' ? 'Dear Ms. ' : 'Dear ')) . $name,
        'I_LINDUR'           => $g === 'M' ? 'i lindur' : ($g === 'F' ? 'e lindur' : 'i/e lindur'),
        'I_REGJISTRUAR'      => $g === 'M' ? 'i regjistruar' : ($g === 'F' ? 'e regjistruar' : 'i/e regjistruar'),
        'TARIFA_REGJISTRIMI' => $s['type'] === 'vendas' ? '300' : '500',   // si ne kontratat (Neni 6 / Neni 7)
        'KESTI_1'            => $k1 === null ? BLANK : fmt_money($k1),
        'KESTI_2'            => $k1 === null ? BLANK : fmt_money($net - $k1),
        'RAUNDI'             => ($s['round_no'] ?? '') !== '' ? 'Raundi ' . $s['round_no'] : BLANK,
        // Te njejtat fjale si Vërtetimi i pagesës (nese perdoren ne nje model tjeter)
        ...payment_values($g, (string)($s['payment'] ?? ''), $v($s['academic_year'])),

        'DATA'              => $v(fmt_date($s['contract_date'])),
        'STUDENTI_JA'       => $g === 'M' ? 'Studenti' : ($g === 'F' ? 'Studentja' : 'Studenti/ja'),
        'BIRI_BIJA'         => $g === 'M' ? 'i biri' : ($g === 'F' ? 'e bija' : 'i biri/ e bija'),
        'BANUES'            => $g === 'F' ? 'banuese' : 'banues',
        'SON_DAUGHTER'      => $g === 'M' ? 'son' : ($g === 'F' ? 'daughter' : 'daughter/son'),
        'EMRI_PLOTE'        => $v($s['first_name'] . ' ' . $s['last_name']),
        'ATESIA'            => $v($s['father']),
        'EMRI_NENES'        => $v($s['mother']),
        'VENDLINDJA'        => $v($s['birth_place']),
        'DATELINDJA'        => $v(fmt_date($s['birth_date'])),
        'ADRESA'            => $v($s['address']),
        'DOKUMENTI'         => $v($s['doc_no']),
        'TELEFONI'          => $v($s['phone']),
        'TELEFONI2'         => $v($s['phone2']),
        'EMAIL'             => $v($s['email']),
        'PROGRAMI'          => $v($programSq),
        'PROGRAM_EN'        => $v($programEn),
        'PROFILI_FRAZE'     => $profSq,
        'PROFILE_EN_FRAZE'  => $profEn,
        'TARIFA'            => $fee === null ? BLANK : fmt_money($fee),
        'TARIFA_FJALE'      => $fee === null ? BLANK : money_words($fee, 'sq'),
        'TARIFA_WORDS'      => $fee === null ? BLANK : money_words($fee, 'en'),
        'ZBRITJA'           => fmt_money((float)$s['discount']),
        'TARIFA_NETO'       => $net === null ? BLANK : fmt_money($net),
        'TARIFA_NETO_FJALE' => $net === null ? BLANK : money_words($net, 'sq'),
        'TARIFA_NETO_WORDS' => $net === null ? BLANK : money_words($net, 'en'),
        'VITI_AKADEMIK'     => $v($s['academic_year']),
        'SHTETESIA'         => $v($nationality),
        'PRINDI_EMRI'       => $v($s['parent_name']),
        'PRINDI_VENDLINDJA' => $v($s['parent_birth_place']),
        'PRINDI_DATELINDJA' => $v(fmt_date($s['parent_birth_date'])),
        'PRINDI_ADRESA'     => $v($s['parent_address']),
        'PRINDI_DOKUMENTI'  => $v($s['parent_doc']),
        'PRINDI_TEL'        => $v($s['parent_phone']),
    ];
}

// Paragrafi qe permban {{?EMRI}} mbetet (pa shenjen) vetem kur $values['?EMRI'] eshte "po", perndryshe hiqet i teri
function conditional_paragraphs(string $xml, array $values): string
{
    $from = 0;
    while (preg_match('/\{\{\?([A-Z0-9_]+)\}\}/', $xml, $m, PREG_OFFSET_CAPTURE, $from)) {
        [$token, $pos] = $m[0];
        if (!empty($values['?' . $m[1][0]])) {
            $xml = substr_replace($xml, '', $pos, strlen($token));
            $from = $pos;
            continue;
        }
        $start = max((int)strrpos(substr($xml, 0, $pos), '<w:p>'), (int)strrpos(substr($xml, 0, $pos), '<w:p '));
        $end = strpos($xml, '</w:p>', $pos);
        if ($end === false) break;
        $xml = substr_replace($xml, '', $start, $end + 6 - $start);
        $from = $start;
    }
    return $xml;
}

// Fjalët {{...}} që Word i ka ndarë në disa pjesë (<w:r>) bashkohen në një
function merge_tokens(string $xml): string
{
    return preg_replace_callback('/\{(?:<[^>]*>)*\{(?:[^{}<]|<[^>]*>)*?\}(?:<[^>]*>)*\}/', fn($m) => strip_tags($m[0]), $xml);
}

// Plotëson modelin Word. Fjalët {{...}} që Word i ka ndarë në disa pjesë bashkohen më parë.
// $meta: të dhënat e studentit që ruhen brenda dokumentit (vetëm te kontrata) – shih put_meta()
function fill_docx(string $template, string $outPath, array $values, ?array $meta = null): void
{
    if (!is_file($template)) throw new RuntimeException('Modeli Word mungon: ' . basename($template));
    $tmp = $outPath . '.tmp';
    if (!copy($template, $tmp)) throw new RuntimeException('Nuk u krijua skedari në: ' . dirname($outPath));
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) throw new RuntimeException('Nuk u hap modeli Word.');
    $map = [];
    foreach ($values as $k => $val) {
        if ($k[0] !== '?') $map['{{' . $k . '}}'] = htmlspecialchars((string)$val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    foreach (['word/document.xml', 'word/header1.xml', 'word/header2.xml', 'word/header3.xml', 'word/footer1.xml', 'word/footer2.xml', 'word/footer3.xml'] as $part) {
        $xml = $zip->getFromName($part);
        if ($xml === false || strpos($xml, '{') === false) continue;
        $xml = merge_tokens($xml);
        // Paragraf me kusht: {{?EMRI}} -> paragrafi mbetet (pa shenjen) vetem kur vlera eshte "po"
        $xml = conditional_paragraphs($xml, $values);
        $zip->addFromString($part, strtr($xml, $map));
    }
    if ($meta !== null) put_meta($zip, $meta);
    $zip->close();
    if (is_file($outPath) && !@unlink($outPath)) {
        @unlink($tmp);
        throw new RuntimeException('"' . basename($outPath) . '" është i hapur në Word. Mbylleni dokumentin dhe provoni përsëri.');
    }
    rename($tmp, $outPath);
}

// Krijon (ose rikrijon) kontratën Word të studentit dhe kthen rrugën e plotë
function generate_contract(array $s): string
{
    $dir = out_dir() . DIRECTORY_SEPARATOR . safe_file_part($s['academic_year'] ?: academic_year())
        . DIRECTORY_SEPARATOR . TYPES[$s['type']] . DIRECTORY_SEPARATOR . LEVELS[$s['level']];
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) throw new RuntimeException('Nuk u krijua dosja: ' . $dir);

    $base = safe_file_part($s['first_name'] . ' ' . $s['last_name']) ?: 'Student';
    $old = resolve_path($s['docx'] ?? '');
    $path = $dir . DIRECTORY_SEPARATOR . $base . ' - Kontrata.docx';
    // Nje student tjeter me te njejtin emer -> "(2)", "(3)"...
    for ($i = 2; is_file($path) && !same_file($path, $old); $i++) {
        $path = $dir . DIRECTORY_SEPARATOR . $base . " ($i) - Kontrata.docx";
    }
    fill_docx(template_file($s), $path, contract_values($s), student_meta($s));
    if ($old !== '' && is_file($old) && !same_file($old, $path)) @unlink($old); // emri / lloji ndryshoi
    return $path;
}

// ---------- Të dhënat e studentit brenda kontratës Word ----------
// Ruhen te "Vetitë e dokumentit" (File -> Info -> Properties), të padukshme në faqe dhe në printim.
// Kështu lista e studentëve rindërtohet nga dosja e kontratave në çdo kompjuter (shih sync_folder()).
const META_PREFIX = 'CIT_Kontrata_';
const META_FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';

function student_meta(array $s): array
{
    $m = ['v' => 1, 'uid' => (string)($s['uid'] ?? '')];
    foreach (STUDENT_FIELDS as $f) $m[$f] = $s[$f] ?? '';
    $m['created_at'] = $s['created_at'] ?? date('Y-m-d H:i:s');
    return $m;
}

function put_meta(ZipArchive $zip, array $meta): void
{
    $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
    $xml = $zip->getFromName('docProps/custom.xml');
    if ($xml === false) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"></Properties>';
    }
    $xml = preg_replace('#<Properties([^>]*)/>#', '<Properties$1></Properties>', $xml);
    $xml = preg_replace('#<property [^>]*name="' . META_PREFIX . '\d+"[^>]*>.*?</property>#s', '', $xml);
    preg_match_all('/\bpid="(\d+)"/', $xml, $m);
    $pid = max(1, ...array_map('intval', $m[1] ?: [1]));
    $props = '';
    foreach (mb_str_split($json, 240) as $i => $chunk) {   // Word i shkurton vetitë më të gjata se 255 shkronja
        $props .= '<property fmtid="' . META_FMTID . '" pid="' . (++$pid) . '" name="' . META_PREFIX . sprintf('%02d', $i + 1) . '">'
            . '<vt:lpwstr>' . htmlspecialchars($chunk, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</vt:lpwstr></property>';
    }
    $zip->addFromString('docProps/custom.xml', str_replace('</Properties>', $props . '</Properties>', $xml));
    $ct = $zip->getFromName('[Content_Types].xml');
    if ($ct !== false && strpos($ct, '/docProps/custom.xml') === false) {
        $zip->addFromString('[Content_Types].xml', str_replace('</Types>',
            '<Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/></Types>', $ct));
    }
    $rels = $zip->getFromName('_rels/.rels');
    if ($rels !== false && strpos($rels, 'docProps/custom.xml') === false) {
        $zip->addFromString('_rels/.rels', str_replace('</Relationships>',
            '<Relationship Id="rIdCitMeta" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties" Target="docProps/custom.xml"/></Relationships>', $rels));
    }
}

// Shkruan të dhënat në një kontratë ekzistuese (pa e ndryshuar tekstin). false = s'u shkrua (p.sh. e hapur në Word)
function write_meta(string $path, array $s): bool
{
    $zip = new ZipArchive();
    if (@$zip->open($path) !== true) return false;
    put_meta($zip, student_meta($s));
    return @$zip->close();
}

// Të dhënat e studentit nga kontrata Word (null = kontratë e vjetër / dokument tjetër)
function read_meta(string $path): ?array
{
    $zip = new ZipArchive();
    if (@$zip->open($path, ZipArchive::RDONLY) !== true) return null;
    $xml = $zip->getFromName('docProps/custom.xml');
    $zip->close();
    if ($xml === false) return null;
    preg_match_all('#<property [^>]*name="' . META_PREFIX . '(\d+)"[^>]*>\s*<vt:lpwstr>(.*?)</vt:lpwstr>#s', $xml, $m, PREG_SET_ORDER);
    if (!$m) return null;
    usort($m, fn($a, $b) => (int)$a[1] <=> (int)$b[1]);
    $d = json_decode(implode('', array_map(fn($x) => html_entity_decode($x[2], ENT_XML1 | ENT_QUOTES, 'UTF-8'), $m)), true);
    if (!is_array($d) || trim((string)($d['first_name'] ?? '')) === '' || !isset(TYPES[$d['type'] ?? ''], LEVELS[$d['level'] ?? ''])) return null;
    return $d;
}

// ---------- Leximi i kontratave të vjetra (pa të dhëna brenda) ----------
// Teksti i çdo paragrafi të dokumentit Word
function docx_paragraphs(string $xml): array
{
    preg_match_all('#<w:p(?: [^>]*)?(?<!/)>.*?</w:p>#s', merge_tokens($xml), $m);
    $out = [];
    foreach ($m[0] as $p) {
        $p = str_replace(['<w:tab/>', '<w:br/>'], ["\t", ' '], $p);
        preg_match_all('#<w:t(?: [^>]*)?>([^<]*)</w:t>#', $p, $t);
        $s = trim(html_entity_decode(implode('', $t[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($s !== '') $out[] = $s;
    }
    return $out;
}

// Për çdo model kontrate: paragrafët me {{...}} si shprehje kërkimi, p.sh. "Unë, (.*?), deklaroj se:"
function contract_patterns(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $special = [
        'STUDENTI_JA' => 'Studenti\/ja|Studentja|Studenti', 'BIRI_BIJA' => 'i biri\/ e bija|i biri|e bija',
        'BANUES' => 'banuese|banues', 'SON_DAUGHTER' => 'daughter\/son|daughter|son',
        'PROFILI_FRAZE' => '(?:, profili: .*?)?', 'PROFILE_EN_FRAZE' => '(?:, profile: .*?)?',
    ];
    $cache = [];
    foreach (array_keys(TYPES) as $t) {
        foreach (array_keys(LEVELS) as $l) {
            $zip = new ZipArchive();
            if ($zip->open(TEMPLATES_DIR . DIRECTORY_SEPARATOR . "{$t}_{$l}.docx", ZipArchive::RDONLY) !== true) continue;
            $xml = (string)$zip->getFromName('word/document.xml');
            $zip->close();
            $list = [];
            foreach (docx_paragraphs($xml) as $p) {
                $p = trim(preg_replace('/\{\{\?[A-Z0-9_]+\}\}/', '', $p));
                if (strpos($p, '{{') === false) continue;
                $parts = preg_split('/\{\{([A-Z0-9_]+)\}\}/', $p, -1, PREG_SPLIT_DELIM_CAPTURE);
                $re = '';
                $names = [];
                foreach ($parts as $i => $part) {
                    if ($i % 2 === 0) { $re .= preg_quote($part, '/'); continue; }
                    $names[] = $part;
                    $re .= '(' . ($special[$part] ?? '.*?') . ')';
                }
                $list[] = ['re' => '/^' . $re . '$/su', 'names' => $names, 'prefix' => $parts[0]];
            }
            $cache["{$t}_{$l}"] = $list;
        }
    }
    return $cache;
}

// "Ana Maria Hoxha" + "anamaria.hoxha@..." -> ["Ana Maria", "Hoxha"]
function split_name(string $full, string $email): array
{
    $w = preg_split('/\s+/u', trim($full));
    if (count($w) < 2) return [trim($full), ''];
    $local = strtolower(explode('@', $email)[0]);
    $part = fn($s) => strtolower(preg_replace('/[^A-Za-z]/', '', strtr($s, translit_map())));
    for ($i = 1; $i < count($w); $i++) {
        $f = implode(' ', array_slice($w, 0, $i));
        $l = implode(' ', array_slice($w, $i));
        if ($part($f) . '.' . $part($l) === $local) return [$f, $l];
    }
    return [implode(' ', array_slice($w, 0, -1)), end($w)];
}

// Programi i studentit sipas emrit (ID-të ndryshojnë nga një kompjuter në tjetrin)
function find_program(string $level, string $nameSq, string $profileSq): ?array
{
    foreach (programs() as $p) {
        if ($p['level'] === $level && mb_strtolower(trim($p['name_sq'])) === mb_strtolower(trim($nameSq))
            && mb_strtolower(trim($p['profile_sq'])) === mb_strtolower(trim($profileSq))) return $p;
    }
    return null;
}

// Lexon të dhënat e studentit nga teksti i një kontrate të krijuar nga programi (pa të dhëna brenda)
function parse_contract(string $path, string $hintKey = ''): ?array
{
    $zip = new ZipArchive();
    if (@$zip->open($path, ZipArchive::RDONLY) !== true) return null;
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return null;
    $paras = docx_paragraphs($xml);
    $best = null;
    foreach (contract_patterns() as $key => $list) {
        if (!$list) continue;
        $vals = [];
        $hits = 0;
        foreach ($list as $pat) {
            foreach ($paras as $p) {
                if ($pat['prefix'] !== '' && strncmp($p, $pat['prefix'], strlen($pat['prefix'])) !== 0) continue;
                if (@preg_match($pat['re'], $p, $m) !== 1) continue;
                $hits++;
                foreach ($pat['names'] as $i => $n) {
                    if (($vals[$n] ?? '') === '') $vals[$n] = trim($m[$i + 1]);
                }
                break;
            }
        }
        $score = $hits / count($list) + ($key === $hintKey ? 0.001 : 0);
        if (!$best || $score > $best['score']) $best = ['key' => $key, 'score' => $score, 'vals' => $vals];
    }
    if (!$best || $best['score'] < 0.5) return null;
    $v = $best['vals'];
    $b = function (string $k) use ($v): string {
        $x = trim($v[$k] ?? '');
        return $x === '' || preg_match('/^[_.\s]+$/u', $x) ? '' : $x;
    };
    $name = $b('EMRI_PLOTE');
    if (!preg_match('/\p{L}/u', $name)) return null;
    $date = fn($k) => preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $b($k), $mm) ? "$mm[3]-$mm[2]-$mm[1]" : '';
    $money = function (string $k) use ($b): ?float {
        $x = str_replace([' ', ','], ['', '.'], $b($k));
        return $x === '' || !is_numeric($x) ? null : (float)$x;
    };
    [$type, $level] = explode('_', $best['key']);
    $isMaster = $level === 'master';
    $sj = $b('STUDENTI_JA');
    $bb = $b('BIRI_BIJA');
    $gender = $sj === 'Studentja' || $bb === 'e bija' ? 'F' : ($sj === 'Studenti' || $bb === 'i biri' ? 'M' : '');
    $email = mb_strtolower($b('EMAIL'));
    [$first, $last] = split_name($name, $email);

    // Programi: Bachelor "Emri, profili X";  Master "Emri" + ", profili: X"
    $progText = $b('PROGRAMI');
    $profile = $isMaster ? preg_replace('/^, profili: /u', '', $b('PROFILI_FRAZE')) : '';
    $nameSq = $progText;
    if (!$isMaster && preg_match('/^(.*), profili (.*)$/u', $progText, $mm)) [$nameSq, $profile] = [$mm[1], $mm[2]];
    $p = find_program($level, $nameSq, $profile) ?? (!$isMaster && $profile !== '' ? find_program($level, $progText, '') : null);

    $fee = $money('TARIFA');
    $s = [
        'type' => $type, 'level' => $level, 'first_name' => $first, 'last_name' => $last, 'gender' => $gender,
        'father' => $b('ATESIA'), 'mother' => $b('EMRI_NENES'), 'birth_place' => $b('VENDLINDJA'), 'birth_date' => $date('DATELINDJA'),
        'address' => $b('ADRESA'), 'doc_no' => $b('DOKUMENTI'), 'phone' => $b('TELEFONI'), 'phone2' => $b('TELEFONI2'), 'email' => $email,
        'program_id' => $p['id'] ?? null,
        'program_sq' => $p['name_sq'] ?? $nameSq, 'program_en' => $p['name_en'] ?? $b('PROGRAM_EN'),
        'profile_sq' => $p['profile_sq'] ?? $profile, 'profile_en' => $p['profile_en'] ?? preg_replace('/^, profile: /u', '', $b('PROFILE_EN_FRAZE')),
        'fee' => $fee, 'discount' => $money('ZBRITJA') ?? 0,
        'fee_net' => $money('TARIFA_NETO') ?? $fee,
        'contract_date' => $date('DATA') ?: date('Y-m-d', (int)filemtime($path)),
        'parent_name' => $b('PRINDI_EMRI'), 'parent_birth_place' => $b('PRINDI_VENDLINDJA'), 'parent_birth_date' => $date('PRINDI_DATELINDJA'),
        'parent_address' => $b('PRINDI_ADRESA'), 'parent_doc' => $b('PRINDI_DOKUMENTI'), 'parent_phone' => $b('PRINDI_TEL'),
        'notes' => 'Lexuar automatikisht nga kontrata Word – kontrolloni të dhënat (✎).',
    ];
    $s['parent_rep'] = $s['parent_name'] !== '' ? 1 : 0;
    return $s;
}

// ---------- Lista e studentëve = kontratat në dosje ----------
// Çdo herë që hapet lista: kontrata e re në dosje -> studenti shtohet; kontrata e fshirë -> studenti hiqet nga lista
// (ruhet i fshehur dhe rikthehet vetë nëse skedari kthehet). Dosja mungon (p.sh. USB e hequr) -> nuk ndryshon asgjë.
// Rruga si celes krahasimi (Windows nuk dallon germat e medha). Te gjitha rruget nisen nga root_dir() / out_dir(),
// prandaj nuk nevojitet realpath() (i ngadalte me mijera skedare).
function path_key(string $p): string
{
    $p = preg_replace('#[\\\\/]+#', '\\', $p);
    return mb_strtolower(str_replace('\\.\\', '\\', rtrim($p, '\\')));
}

// Dokumentet shtesë pranë kontratës: "Emri Mbiemri - Fletë pagese.docx" ...
function sibling_docs(string $contract): array
{
    $dir = dirname($contract);
    $base = preg_replace('/( - Kontrata)?\.docx$/iu', '', basename($contract));
    $docs = [];
    foreach (@scandir($dir) ?: [] as $f) {
        if (strpos($f, $base . ' - ') !== 0 || !preg_match('/\.docx$/i', $f) || same_file($dir . DIRECTORY_SEPARATOR . $f, $contract)) continue;
        $docs[] = ['name' => substr($f, strlen($base) + 3, -5), 'path' => stored_path($dir . DIRECTORY_SEPARATOR . $f)];
    }
    return $docs;
}

// Skedari është dokument shtesë i krijuar nga programi ("... - Fletë pagese.docx")?
function is_extra_doc_file(string $name): bool
{
    static $docs = null;
    $docs ??= docs_config();
    foreach ($docs as $d) {
        if (mb_strtolower(mb_substr($name, -mb_strlen(' - ' . $d['file']))) === mb_strtolower(' - ' . $d['file'])) return true;
    }
    return false;
}

function scan_docx(string $root): array
{
    $out = [];
    try {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $n = $f->getFilename();
            if ($f->isFile() && preg_match('/\.docx$/i', $n) && strpos($n, '~$') !== 0) $out[] = $f->getPathname();
        }
    } catch (Throwable $e) { /* dosje pa leje leximi */ }
    sort($out);
    return $out;
}

function import_student(array $d, string $file, bool $hasMeta): array
{
    $s = [];
    foreach (STUDENT_FIELDS as $f) $s[$f] = $d[$f] ?? '';
    if (!in_array($s['round_no'], ['1', '2', '3', ''], true)) $s['round_no'] = '';
    if (!isset(PAYMENTS[$s['payment']]) || $s['type'] !== 'nderkombetar') $s['payment'] = '';
    foreach (['fee', 'fee_net'] as $f) $s[$f] = $s[$f] === '' || $s[$f] === null ? null : (float)$s[$f];
    // Programi sipas emrit (ID-ja e kompjuterit tjeter nuk vlen ketu); nese mungon ne kete kompjuter, shtohet
    $p = find_program($s['level'], (string)$s['program_sq'], (string)$s['profile_sq']);
    if (!$p && trim((string)$s['program_sq']) !== '') {
        $sort = (int)db()->query('SELECT COALESCE(MAX(sort), 0) + 1 FROM programs')->fetchColumn();
        db()->prepare('INSERT INTO programs (level, name_sq, name_en, profile_sq, profile_en, fee_vendas, fee_nderkombetar, sort) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$s['level'], $s['program_sq'], (string)$s['program_en'], (string)$s['profile_sq'], (string)$s['profile_en'],
                $s['type'] === 'vendas' ? $s['fee'] : null, $s['type'] === 'nderkombetar' ? $s['fee'] : 3500, $sort]);
        $p = ['id' => (int)db()->lastInsertId()];
    }
    $s['program_id'] = $p['id'] ?? null;
    $s['discount'] = (float)($s['discount'] ?: 0);
    $s['parent_rep'] = (int)!empty($s['parent_rep']);
    if ($s['academic_year'] === '') {
        $s['academic_year'] = preg_match('#[\\\\/](\d{4}-\d{4})[\\\\/]#', $file, $m) ? $m[1] : academic_year();
    }
    if ($s['contract_date'] === '') $s['contract_date'] = date('Y-m-d', (int)filemtime($file));
    $s['email'] = $s['email'] !== '' ? $s['email'] : make_email($s['first_name'], $s['last_name']);
    $s['uid'] = !empty($d['uid']) ? (string)$d['uid'] : new_uid();
    $s['docx'] = stored_path($file);
    $s['extra_docs'] = json_encode(sibling_docs($file), JSON_UNESCAPED_UNICODE);
    $s['has_meta'] = $hasMeta ? 1 : 0;
    $s['created_at'] = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string)($d['created_at'] ?? '')) ? $d['created_at'] : date('Y-m-d H:i:s');
    $cols = array_merge(STUDENT_FIELDS, ['uid', 'docx', 'extra_docs', 'has_meta', 'created_at']);
    db()->prepare('INSERT INTO students (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map(fn($c) => ":$c", $cols)) . ')')
        ->execute(array_intersect_key($s, array_flip($cols)));
    $s['id'] = (int)db()->lastInsertId();
    // Kontrata e vjeter: te dhenat ruhen brenda saj qe heren tjeter te lexohen sakte
    if (!$hasMeta && write_meta($file, $s)) db()->prepare('UPDATE students SET has_meta = 1 WHERE id = ?')->execute([$s['id']]);
    return $s;
}

function sync_folder(): array
{
    $res = ['added' => [], 'removed' => [], 'restored' => [], 'folder' => out_dir(), 'more' => false];
    $root = out_dir();
    if (!is_dir($root)) return $res;
    // Me shume kontrata te reja (p.sh. kompjuter i ri me mijera studente): lexohen me radhe, ~12 sekonda per here;
    // faqja e therret perseri vete derisa te mbarojne ('more' => true)
    @set_time_limit(120);
    $deadline = microtime(true) + 12;
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS scan_ignore (path TEXT PRIMARY KEY, stamp TEXT NOT NULL)');
    $rows = $pdo->query('SELECT * FROM students')->fetchAll();
    $setHidden = $pdo->prepare('UPDATE students SET hidden = ? WHERE id = ?');
    $relink = $pdo->prepare('UPDATE students SET docx = ?, extra_docs = ?, hidden = 0 WHERE id = ?');
    $known = [];
    $byUid = [];
    $before = [];
    foreach ($rows as $i => $s) {
        $before[$i] = (int)$s['hidden'];
        $abs = resolve_path($s['docx']);
        $exists = $abs !== '' && is_file($abs);
        $rows[$i]['exists'] = $exists;
        if ($exists) $known[path_key($abs)] = true;
        foreach (json_decode($s['extra_docs'] ?: '[]', true) ?: [] as $d) $known[path_key(resolve_path($d['path']))] = true;
        if ($s['docx'] !== '' && !$exists && !$s['hidden']) { $setHidden->execute([1, $s['id']]); $rows[$i]['hidden'] = 1; }
        if ($exists && $s['hidden']) { $setHidden->execute([0, $s['id']]); $rows[$i]['hidden'] = 0; }
        if ($exists && !$s['has_meta'] && microtime(true) < $deadline && write_meta($abs, $s)) $pdo->prepare('UPDATE students SET has_meta = 1 WHERE id = ?')->execute([$s['id']]);
        if ($s['uid'] !== '') $byUid[$s['uid']] = $i;
    }
    $ignored = $pdo->query('SELECT path, stamp FROM scan_ignore')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (scan_docx($root) as $file) {
        $key = path_key($file);
        if (isset($known[$key])) continue;
        if (microtime(true) > $deadline) { $res['more'] = true; break; }
        $name = basename($file);
        if (preg_match('/ - Vërtetim pagese\.docx$/iu', $name)) continue;   // vertetimet e pageses (faqja 💶)
        $isContract = (bool)preg_match('/ - Kontrata\.docx$/iu', $name);
        if (!$isContract && is_extra_doc_file($name)) continue;
        $stamp = filemtime($file) . ':' . filesize($file);
        if (($ignored[$key] ?? '') === $stamp) continue;
        $known[$key] = true;
        $meta = read_meta($file);
        if ($meta && isset($byUid[$meta['uid']])) {
            $i = $byUid[$meta['uid']];
            if ($rows[$i]['exists']) continue;   // kopje e nje kontrate qe eshte ne liste -> nuk shtohet dy here
            $relink->execute([stored_path($file), json_encode(sibling_docs($file), JSON_UNESCAPED_UNICODE), $rows[$i]['id']]);
            $rows[$i]['exists'] = true;
            $rows[$i]['hidden'] = 0;
            continue;
        }
        if (!$meta) {
            // Kontrata e nje studenti te hequr u kthye me te njejtin emer (p.sh. dosja u zhvendos)
            foreach ($rows as $i => $s) {
                if (!$s['exists'] && $s['hidden'] && $s['docx'] !== '' && strcasecmp(basename(resolve_path($s['docx'])), $name) === 0) {
                    $relink->execute([stored_path($file), json_encode(sibling_docs($file), JSON_UNESCAPED_UNICODE), $s['id']]);
                    $rows[$i]['exists'] = true;
                    $rows[$i]['hidden'] = 0;
                    continue 2;
                }
            }
            $parts = preg_split('#[\\\\/]#', $file);
            $hintType = in_array('Ndërkombëtar', $parts, true) ? 'nderkombetar' : (in_array('Vendas', $parts, true) ? 'vendas' : '');
            $hintLevel = in_array('Master', $parts, true) ? 'master' : (in_array('Bachelor', $parts, true) ? 'bachelor' : '');
            $data = parse_contract($file, $hintType && $hintLevel ? "{$hintType}_{$hintLevel}" : '');
            if (!$data) {   // nuk eshte kontrate e programit -> nuk provohet perseri derisa te ndryshoje skedari
                $pdo->prepare('INSERT INTO scan_ignore (path, stamp) VALUES (?, ?) ON CONFLICT(path) DO UPDATE SET stamp = excluded.stamp')->execute([$key, $stamp]);
                continue;
            }
        }
        $s = import_student($meta ?: $data, $file, (bool)$meta);
        $res['added'][] = $s['first_name'] . ' ' . $s['last_name'];
        foreach (json_decode($s['extra_docs'], true) as $d) $known[path_key(resolve_path($d['path']))] = true;
    }
    foreach ($rows as $i => $s) {
        if ($s['hidden'] && !$before[$i]) $res['removed'][] = $s['first_name'] . ' ' . $s['last_name'];
        if (!$s['hidden'] && $before[$i]) $res['restored'][] = $s['first_name'] . ' ' . $s['last_name'];
    }
    backup_settings();
    return $res;
}

// ---------- Dokumentet shtese (app/templates/dokumente) ----------
// Te Cilesimet: aktiv / per ke (te gjithe, vendas, nderkombetar) / printohet me kontraten / sa kopje
const DOC_DEFAULTS = [
    'Letër pranimi.docx'        => ['scope' => 'nderkombetar', 'print' => true],
    'Fletë pagese.docx'         => ['scope' => 'all', 'print' => true],
    'Vërtetim regjistrimi.docx' => ['scope' => 'all', 'print' => false],
];

function docs_config(): array
{
    $saved = json_decode(setting('docs', '{}'), true) ?: [];
    $files = is_dir(DOCS_DIR) ? array_filter(scandir(DOCS_DIR), fn($f) => preg_match('/\.docx$/i', $f) && strpos($f, '~$') !== 0
        && $f !== 'Vërtetim pagese.docx') : [];   // Vërtetim pagese ka faqen e vet (💶)
    $out = [];
    foreach ($files as $f) {
        $c = ($saved[$f] ?? []) + (DOC_DEFAULTS[$f] ?? []) + ['enabled' => false, 'scope' => 'all', 'print' => false, 'copies' => 1];
        $out[] = ['file' => $f, 'name' => preg_replace('/\.docx$/i', '', $f), 'enabled' => (bool)$c['enabled'],
            'scope' => in_array($c['scope'], ['all', 'vendas', 'nderkombetar'], true) ? $c['scope'] : 'all',
            'print' => (bool)$c['print'], 'copies' => max(1, min(5, (int)$c['copies']))];
    }
    return $out;
}

// Printerat e ketij kompjuteri: [['name' => ..., 'default' => bool], ...]
function printers(): array
{
    $ps = "[Console]::OutputEncoding = [Text.Encoding]::UTF8; Get-CimInstance Win32_Printer | Select-Object Name, Default | ConvertTo-Json -Compress";
    $h = popen('powershell -NoProfile -NonInteractive -Command "' . $ps . '" 2>nul', 'r');
    $json = trim((string)stream_get_contents($h));
    pclose($h);
    $rows = json_decode($json, true);
    if (!is_array($rows)) return [];
    if (isset($rows['Name'])) $rows = [$rows];
    $out = array_map(fn($r) => ['name' => (string)$r['Name'], 'default' => !empty($r['Default'])], $rows);
    usort($out, fn($a, $b) => [$b['default'], $a['name']] <=> [$a['default'], $b['name']]);
    return $out;
}

// Sa kopje te kontrates printohen (kontrata hartohet ne dy ekzemplare)
function contract_copies(): int
{
    return max(1, min(5, (int)setting('copies', '2')));
}

function docs_for(array $s): array
{
    return array_values(array_filter(docs_config(), fn($d) => $d['enabled'] && ($d['scope'] === 'all' || $d['scope'] === $s['type'])));
}

// Kontrata + dokumentet shtese aktive. Kthen ['contract' => rruga, 'docs' => [['name', 'path'], ...]]
function generate_all(array $s): array
{
    $contract = generate_contract($s);
    return ['contract' => $contract, 'docs' => generate_docs($s, $contract)];
}

// Dokumentet shtese aktive prane kontrates. $missingOnly: krijon vetem ato qe mungojne (te tjerat nuk preken)
function generate_docs(array $s, string $contract, bool $missingOnly = false): array
{
    $dir = dirname($contract);
    $base = preg_replace('/( - Kontrata)?\.docx$/iu', '', basename($contract));
    $values = contract_values($s);
    $docs = [];
    foreach (docs_for($s) as $d) {
        $path = $dir . DIRECTORY_SEPARATOR . $base . ' - ' . $d['name'] . '.docx';
        if (!$missingOnly || !is_file($path)) fill_docx(DOCS_DIR . DIRECTORY_SEPARATOR . $d['file'], $path, $values);
        $docs[] = ['name' => $d['name'], 'path' => stored_path($path)];
    }
    // Dokumentet e meparshme: fshihen nese studenti ndryshoi emer / lloj; ruhen nese thjesht u caktivizuan
    foreach (json_decode($s['extra_docs'] ?? '[]', true) ?: [] as $old) {
        if (in_array($old['path'], array_column($docs, 'path'), true)) continue;
        $abs = resolve_path($old['path']);
        if (!is_file($abs)) continue;
        if ($missingOnly || (same_file(dirname($abs), $dir) && strpos(basename($abs), $base . ' - ') === 0)) $docs[] = $old;
        else @unlink($abs);
    }
    return $docs;
}

// Cfare printohet per studentin: kontrata (N kopje) + dokumentet shtese me "printo me kontraten"
function print_jobs(string $contract, array $docs): array
{
    $jobs = [['path' => $contract, 'copies' => contract_copies()]];
    $cfg = array_column(docs_config(), null, 'name');
    foreach ($docs as $d) {
        $c = $cfg[$d['name']] ?? null;
        $abs = resolve_path($d['path']);
        if ($c && $c['enabled'] && $c['print'] && is_file($abs)) $jobs[] = ['path' => $abs, 'copies' => $c['copies']];
    }
    return $jobs;
}

// Kontrata + dokumentet shtese te studentit. Kontrata krijohet perseri vetem nese mungon;
// dokumentet shtese qe mungojne (p.sh. u fshine, ose u aktivizua nje dokument i ri) krijohen pa prekur te tjerat.
function ensure_files(array $s): array
{
    $file = resolve_path($s['docx']);
    $docs = json_decode($s['extra_docs'] ?: '[]', true) ?: [];
    if ($file === '' || !is_file($file)) {
        $r = generate_all($s);
    } else {
        $have = [];
        foreach ($docs as $d) if (is_file(resolve_path($d['path']))) $have[$d['name']] = true;
        $missing = count($have) < count($docs);
        foreach (docs_for($s) as $d) $missing = $missing || !isset($have[$d['name']]);
        if (!$missing) return ['contract' => $file, 'docs' => $docs];
        $r = ['contract' => $file, 'docs' => generate_docs($s, $file, true)];
    }
    db()->prepare('UPDATE students SET docx = ?, extra_docs = ?, hidden = 0 WHERE id = ?')
        ->execute([stored_path($r['contract']), json_encode($r['docs'], JSON_UNESCAPED_UNICODE), $s['id']]);
    return $r;
}

// Modeli perdor keto fjale? (p.sh. Vërtetimi i pagesës: {{PAGESA_SQ}}, {{DATA_SOT}})
function template_uses(string $file, array $tokens): bool
{
    $zip = new ZipArchive();
    if (@$zip->open($file, ZipArchive::RDONLY) !== true) return false;
    $xml = merge_tokens((string)$zip->getFromName('word/document.xml'));
    $zip->close();
    foreach ($tokens as $t) if (strpos($xml, '{{' . $t) !== false) return true;
    return false;
}

// Para printimit: dokumentet me pagesën / datën e sotme (Vërtetim pagese) rikrijohen me të dhënat e fundit
function refresh_print_docs(array $s, array $files): void
{
    $values = contract_values($s);
    $cfg = array_column(docs_for($s), null, 'name');
    foreach ($files['docs'] as $d) {
        $c = $cfg[$d['name']] ?? null;
        if (!$c || !$c['print']) continue;
        $tpl = DOCS_DIR . DIRECTORY_SEPARATOR . $c['file'];
        if (template_uses($tpl, ['PAGESA_', 'DATA_SOT'])) fill_docx($tpl, resolve_path($d['path']), $values);
    }
}

function same_file(string $a, string $b): bool
{
    if ($a === '' || $b === '') return false;
    $ra = realpath($a) ?: $a;
    $rb = realpath($b) ?: $b;
    return strcasecmp(str_replace('/', '\\', $ra), str_replace('/', '\\', $rb)) === 0;
}

function is_local_request(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

// Hap skedar / dosje ne Windows (Word, Explorer)
function win_open(string $path, bool $select = false): void
{
    $path = str_replace('/', '\\', $path);
    $cmd = $select ? 'start "" explorer.exe /select,' . escapeshellarg($path) : 'start "" ' . escapeshellarg($path);
    pclose(popen($cmd, 'r'));
}

// Printon dokumentet ne printerin kryesor te Windows-it me Word (pa e shfaqur), me numrin e kopjeve.
// Punon ne sfond qe faqja te mos prese; nese deshton, Windows shfaq nje mesazh.
function win_print(array $jobs, string $printer = ''): void
{
    if (!$jobs) return;
    $q = fn($p) => "'" . str_replace("'", "''", str_replace('/', '\\', $p)) . "'";
    $list = implode(', ', array_map(fn($j) => '@{ p = ' . $q($j['path']) . '; c = ' . (int)$j['copies'] . ' }', $jobs));
    $ps = <<<'PS'
$ErrorActionPreference = 'Stop'
$jobs = @(__JOBS__)
$printer = __PRINTER__
$w = $null; $err = $null
try {
    try { $w = New-Object -ComObject Word.Application } catch { $w = $null }
    if ($w) {
        $w.Visible = $false; $w.DisplayAlerts = 0
        if ($printer) {
            # Printeri vetem per kete Word, pa ndryshuar printerin kryesor te Windows-it
            $wb = $w.WordBasic
            [void]$wb.GetType().InvokeMember('FilePrintSetup', [Reflection.BindingFlags]::InvokeMethod, $null, $wb, @($printer, 1), $null, $null, [string[]]@('Printer', 'DoNotSetAsSysDefault'))
        }
        foreach ($j in $jobs) {
            $d = $w.Documents.Open($j.p, $false, $true)
            $d.PrintOut($false, $false, 0, '', '', '', 0, $j.c)
            $d.Close(0)
        }
    } else {
        # Pa Word: programi i Windows-it per .docx (1 kopje cdo here)
        foreach ($j in $jobs) { for ($i = 0; $i -lt $j.c; $i++) {
            if ($printer) { Start-Process -FilePath $j.p -Verb PrintTo -ArgumentList ('"' + $printer + '"') -WindowStyle Hidden }
            else { Start-Process -FilePath $j.p -Verb Print -WindowStyle Hidden }
            Start-Sleep -Seconds 4
        } }
    }
} catch { $err = $_.Exception.Message }
finally {
    if ($w) { try { $w.Quit() } catch {}; try { [void][Runtime.InteropServices.Marshal]::ReleaseComObject($w) } catch {} }
    Remove-Item -LiteralPath $PSCommandPath -ErrorAction SilentlyContinue
}
if ($err) {
    Add-Type -AssemblyName PresentationFramework
    [void][System.Windows.MessageBox]::Show("Printimi nuk u krye:`n$err", 'Kontratat e Studenteve')
}
PS;
    $ps = str_replace(['__JOBS__', '__PRINTER__'], [$list, $printer === '' ? "''" : $q($printer)], $ps);
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
    $file = DATA_DIR . DIRECTORY_SEPARATOR . 'print_' . uniqid() . '.ps1';
    file_put_contents($file, "\xEF\xBB\xBF" . $ps); // BOM: PowerShell lexon sakte emrat me ë, ç, ğ
    pclose(popen('start "" /b powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File ' . escapeshellarg($file) . ' >nul 2>nul', 'r'));
}
