<?php
$shd_db_host = '127.0.0.1';
$shd_db_name = 'shahd_news';
$shd_db_user = 'root';
$shd_db_pass = '';

try {
  $shd_link = new PDO("mysql:host=$shd_db_host;dbname=$shd_db_name;charset=utf8mb4",
    $shd_db_user, $shd_db_pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) { http_response_code(500); echo 'DB error'; exit; }


if (session_status() !== PHP_SESSION_ACTIVE) {
  $params = session_get_cookie_params();
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => $params['path'] ?: '/',
    'domain'   => $params['domain'] ?: '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}


function shd_uid(){ return isset($_SESSION['shd_uid']) ? (int)$_SESSION['shd_uid'] : null; }
function shd_need(){ if(!shd_uid()){ header("Location: ?p=login"); exit; } }
