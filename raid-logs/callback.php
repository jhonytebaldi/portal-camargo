<?php
/* raid-logs/callback.php — volta do Warcraft Logs: troca o código pelo token (PKCE). */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('raid-logs');
if (!is_admin($u)) { http_response_code(403); exit('Só o administrador conecta o Warcraft Logs.'); }

$p = $_SESSION['rl_pkce'] ?? null; unset($_SESSION['rl_pkce']);
$code = (string)($_GET['code'] ?? '');
if (!$p || $code === '' || !hash_equals($p['s'], (string)($_GET['state'] ?? ''))) {
    http_response_code(400); exit('Conexão não concluída. Volte para a ferramenta e tente de novo.');
}
$t = rl_token_post(['grant_type' => 'authorization_code', 'client_id' => RL_CLIENT_ID, 'redirect_uri' => RL_REDIRECT,
    'code_verifier' => $p['v'], 'code' => $code]);
if (!$t) { http_response_code(502); exit('O Warcraft Logs recusou a troca do código. Confira se a redirect URL ' . h(RL_REDIRECT) . ' está cadastrada no client.'); }
rl_token_salvar($t);
header('Location: ./');
