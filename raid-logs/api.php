<?php
/* raid-logs/api.php — status, proxy do GraphQL do Warcraft Logs e cache.
   Tudo exige login + ferramenta 'raid-logs'. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = rl_guard();
$a = $_GET['a'] ?? '';
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($a === 'status') {
    rl_json(200, ['logado' => rl_access_token() !== null, 'admin' => is_admin($u)]);
}

if ($a === 'graphql' && $metodo === 'POST') {
    $tok = rl_access_token();
    if (!$tok) rl_json(401, ['erro' => 'sem_login']);
    $corpo = file_get_contents('php://input', false, null, 0, 300000);
    if (!is_array(json_decode((string)$corpo, true))) rl_json(400, ['erro' => 'corpo_invalido']);
    session_write_close();   // não segura a sessão enquanto o WCL responde
    $ch = curl_init(RL_WCL . '/api/v2/user');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $tok]]);
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!is_string($resp)) rl_json(502, ['errors' => [['message' => 'Sem resposta do Warcraft Logs.']]]);
    rl_json($code ?: 502, $resp);
}

if ($a === 'cache') {
    $n = (string)($_GET['n'] ?? '');
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,100}$/', $n)) rl_json(400, ['erro' => 'nome_invalido']);
    if ($metodo === 'PUT') {
        $corpo = (string)file_get_contents('php://input', false, null, 0, 8000000);
        if (json_decode($corpo) === null && trim($corpo) !== 'null') rl_json(400, ['erro' => 'json_invalido']);
        rl_put($n, $corpo);
        rl_json(200, ['ok' => true]);
    }
    $v = rl_get($n);
    if ($v === null) rl_json(404, 'null');
    rl_json(200, $v);
}

rl_json(404, ['erro' => 'acao_desconhecida']);
