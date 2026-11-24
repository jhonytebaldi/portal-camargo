<?php
/* raid-logs/_comum.php — armazenamento (tabela rl_kv) e token do Warcraft Logs.
   O token fica só no servidor (chave '_token'); a API de cache nunca expõe
   chaves que começam com '_'. O client do WCL é público (PKCE, sem secret). */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/auth.php';

const RL_WCL = 'https://www.warcraftlogs.com';
const RL_CLIENT_ID = '01a20b96-fdb4-7372-9f3c-960b1975d757';
const RL_REDIRECT = 'https://portal.imobcamargo.com.br/raid-logs/callback.php';

function rl_get(string $nome): ?string {
    $st = db()->prepare('SELECT conteudo FROM rl_kv WHERE nome = ?');
    $st->execute([$nome]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null) return null;
    $d = @gzuncompress($v);
    return $d === false ? null : $d;
}
function rl_put(string $nome, string $conteudo): void {
    $st = db()->prepare('INSERT INTO rl_kv (nome, conteudo, atualizado) VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE conteudo = VALUES(conteudo), atualizado = NOW()');
    $st->bindValue(1, $nome);
    $st->bindValue(2, gzcompress($conteudo, 6), PDO::PARAM_LOB);
    $st->execute();
}
function rl_del(string $nome): void {
    db()->prepare('DELETE FROM rl_kv WHERE nome = ?')->execute([$nome]);
}

/** POST x-www-form-urlencoded para o endpoint de token do WCL. */
function rl_token_post(array $campos): ?array {
    $ch = curl_init(RL_WCL . '/oauth/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($campos),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = is_string($body) ? json_decode($body, true) : null;
    return ($code === 200 && is_array($j) && !empty($j['access_token'])) ? $j : null;
}
function rl_token_salvar(array $t): void {
    $t['expires_at'] = time() + (int)($t['expires_in'] ?? 0);
    rl_put('_token', json_encode($t));
}
/** Access token válido (renova sozinho) ou null se a ferramenta não está conectada. */
function rl_access_token(): ?string {
    $raw = rl_get('_token');
    $t = $raw ? json_decode($raw, true) : null;
    if (!is_array($t) || empty($t['access_token'])) return null;
    if (($t['expires_at'] ?? 0) - 300 < time()) {
        if (empty($t['refresh_token'])) return null;
        $n = rl_token_post(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'], 'client_id' => RL_CLIENT_ID]);
        if (!$n) return null;
        if (empty($n['refresh_token'])) $n['refresh_token'] = $t['refresh_token'];
        rl_token_salvar($n);
        return $n['access_token'];
    }
    return $t['access_token'];
}
function rl_json(int $status, $dados): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo is_string($dados) ? $dados : json_encode($dados);
    exit;
}
