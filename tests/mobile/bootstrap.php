<?php
// CI-only visual harness identity. Inert unless MOBILE_TEST_ENV=1 and the request is loopback.
declare(strict_types=1);
if (getenv('MOBILE_TEST_ENV') !== '1') return;
if (!in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('test_loopback_only'); }
$identity = (string) (getenv('MOBILE_TEST_IDENTITY') ?: 'logged_out');
$users = ['owner' => [901, 'owner_admin'], 'front' => [902, 'front_desk_admin'], 'marketing' => [903, 'marketing_sales'], 'packer' => [904, 'packer']];
if (!isset($users[$identity])) return;
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
[$id, $roleKey] = $users[$identity];
$now = new DateTimeImmutable('now', new DateTimeZone('Africa/Windhoek'));
$_SESSION['user'] = ['id' => $id, 'name' => 'Synthetic ' . ucfirst($identity), 'role_key' => $roleKey];
$_SESSION['authenticated_at'] = $now->format(DATE_ATOM);
$_SESSION['absolute_expires_at'] = time() + 3600;
$_SESSION['last_activity_at'] = $now->format(DATE_ATOM);
$_SESSION['login_date'] = $now->format('Y-m-d');
$_SESSION['session_user_id'] = $id;
$_SESSION['session_identifier'] = hash('sha256', session_id());
