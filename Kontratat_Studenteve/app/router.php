<?php
// Router për serverin e PHP-së (START.bat): databaza dhe modelet nuk hapen direkt nga shfletuesi.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
if (preg_match('#^/+(data|templates)(/|$)|^/+(lib\.php|router\.php)$|/\.#i', $path)) {
    http_response_code(403);
    exit('Ndalohet.');
}
return false;
