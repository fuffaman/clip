<?php
$uri = $_SERVER["REQUEST_URI"];
$path = parse_url($uri, PHP_URL_PATH);

if (file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path)) {
    return false;
}

if (preg_match('/^\/c\//', $path) || $path === '/admin' || $path === '/') {
    include __DIR__ . '/index.html';
    return true;
}

return false;
