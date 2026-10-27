<?php
// Shkarkon kontratën Word (ose ?doc=N një dokument shtesë) të studentit; krijohet përsëri nëse mungon
require_once __DIR__ . '/lib.php';
if (!is_local_request()) { http_response_code(403); exit('Vetëm nga ky kompjuter.'); }

$s = student((int)($_GET['id'] ?? 0));
if (!$s) { http_response_code(404); exit('Studenti nuk u gjet.'); }
$files = ensure_files($s);
$file = $files['contract'];
if (isset($_GET['doc'])) {   // dokument shtese (Letër pranimi, ...)
    $doc = $files['docs'][(int)$_GET['doc']] ?? null;
    if (!$doc) { http_response_code(404); exit('Dokumenti nuk u gjet.'); }
    $file = resolve_path($doc['path']);
}
$name = basename($file);
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9 ._()-]/', '_', strtr($name, translit_map())) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($file));
readfile($file);
