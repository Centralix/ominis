<?php
// Development server: expose only the front controller and reviewed static assets.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~^/assets/[a-zA-Z0-9_-]+\.(css|js|svg)$~D', $path) && is_file(__DIR__ . $path)) return false;
if ($path === '/' || $path === '/index.php') { require __DIR__ . '/index.php'; return true; }
http_response_code(404); echo 'Page introuvable.'; return true;
