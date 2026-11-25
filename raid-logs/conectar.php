<?php
/* raid-logs/conectar.php — inicia (ou desfaz) a conexão com o Warcraft Logs.
   Só admin: o token autorizado aqui é o que todos os usuários da ferramenta usam. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = rl_guard();
if (!is_admin($u)) { http_response_code(403); exit('Só o administrador conecta o Warcraft Logs.'); }

if (isset($_GET['sair'])) { rl_del('_token'); header('Location: index.php'); exit; }

$b64 = fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$verifier = $b64(random_bytes(48));
$state = $b64(random_bytes(24));
$_SESSION['rl_pkce'] = ['v' => $verifier, 's' => $state];
header('Location: ' . RL_WCL . '/oauth/authorize?' . http_build_query([
    'response_type' => 'code', 'client_id' => RL_CLIENT_ID, 'redirect_uri' => RL_REDIRECT,
    'code_challenge' => $b64(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256', 'state' => $state,
]));
