<?php
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_id($n): string { return 'Rp '.number_format((float)$n, 0, ',', '.'); }
function bo_url(string $path=''): string { $base = rtrim(bo_config()['app']['base_url'] ?? '', '/'); return $base . '/' . ltrim($path, '/'); }
function active_page(string $p): string { return ($_GET['p'] ?? 'dashboard') === $p ? 'active' : ''; }
function json_out(array $data, int $code=200): never { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
