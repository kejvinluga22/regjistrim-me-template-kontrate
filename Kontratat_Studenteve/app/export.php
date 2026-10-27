<?php
// Lista e studentëve në Excel (.xlsx) – hapet në çdo Excel pa probleme me shkronjat ë / ç
require_once __DIR__ . '/lib.php';
if (!is_local_request()) { http_response_code(403); exit('Vetëm nga ky kompjuter.'); }

$cols = [
    'Nr' => null, 'Emri' => 'first_name', 'Mbiemri' => 'last_name', 'Gjinia' => 'gender',
    'Lloji' => fn($s) => TYPES[$s['type']] ?? $s['type'], 'Niveli' => fn($s) => LEVELS[$s['level']] ?? $s['level'],
    'Viti akademik' => 'academic_year', 'Raundi' => fn($s) => $s['round_no'] !== '' ? 'Raundi ' . $s['round_no'] : '', 'Programi' => 'program_sq', 'Profili' => 'profile_sq', 'Program (EN)' => 'program_en',
    'Atësia' => 'father', 'Emri i nënës' => 'mother', 'Vendlindja' => 'birth_place', 'Datëlindja' => fn($s) => fmt_date($s['birth_date']),
    'Shtetësia' => 'nationality', 'Adresa' => 'address', 'Nr. dokumenti' => 'doc_no', 'Telefoni' => 'phone', 'Telefoni 2' => 'phone2',
    'Email' => 'email', 'Pagesa (ndërk.)' => fn($s) => PAYMENTS[$s['payment']] ?? '', 'Tarifa (€)' => 'fee', 'Zbritja (%)' => 'discount', 'Tarifa pas zbritjes (€)' => 'fee_net',
    'Data e kontratës' => fn($s) => fmt_date($s['contract_date']),
    'Prindi / Përfaqësuesi' => 'parent_name', 'Tel. prindi' => 'parent_phone', 'Shënime' => 'notes', 'Regjistruar më' => 'created_at',
];

$where = ['hidden = 0'];   // studentet qe jane ne dosjen e kontratave
$args = [];
foreach (['type', 'level', 'round_no', 'academic_year'] as $f) {
    if (($_GET[$f] ?? '') !== '') { $where[] = "$f = ?"; $args[] = $_GET[$f]; }
}
$q = db()->prepare('SELECT * FROM students' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY type, level, last_name, first_name');
$q->execute($args);
$students = $q->fetchAll();

$x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$colName = function (int $i): string {
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
};
$cell = function (int $c, int $r, $v, int $style = 0) use ($x, $colName): string {
    $ref = $colName($c) . $r;
    $st = $style ? " s=\"$style\"" : '';
    if (is_int($v) || is_float($v)) return "<c r=\"$ref\"$st><v>$v</v></c>";
    return "<c r=\"$ref\" t=\"inlineStr\"$st><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
};

$rows = [];
$r = 1;
$line = '';
foreach (array_keys($cols) as $c => $label) $line .= $cell($c, $r, $label, 1);
$rows[] = "<row r=\"$r\">$line</row>";
foreach ($students as $n => $s) {
    $r++;
    $line = '';
    $c = 0;
    foreach ($cols as $label => $src) {
        if ($src === null) $v = $n + 1;
        elseif ($src instanceof Closure) $v = $src($s);
        else $v = $s[$src];
        if (in_array($src, ['fee', 'discount', 'fee_net'], true)) $v = $v === null || $v === '' ? '' : (float)$v;
        $line .= $cell($c++, $r, $v ?? '');
    }
    $rows[] = "<row r=\"$r\">$line</row>";
}
$widths = '';
foreach (array_keys($cols) as $c => $label) {
    $w = in_array($label, ['Programi', 'Adresa', 'Program (EN)', 'Email'], true) ? 34 : max(10, mb_strlen($label) + 4);
    $widths .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . "\" width=\"$w\" customWidth=\"1\"/>";
}
$last = $colName(count($cols) - 1) . max(1, $r);

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
    . "<cols>$widths</cols><sheetData>" . implode('', $rows) . '</sheetData>'
    . "<autoFilter ref=\"A1:$last\"/></worksheet>";

$files = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Studentet" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">Studentet!$A$1:$' . preg_replace('/\d+$/', '', $last) . '$' . max(1, $r) . '</definedName></definedNames></workbook>',
    'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
    'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFB3121B"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>',
    'xl/worksheets/sheet1.xml' => $sheet,
];

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
$zip->open($tmp, ZipArchive::OVERWRITE);
foreach ($files as $name => $content) $zip->addFromString($name, $content);
$zip->close();

$name = 'Studentet_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
