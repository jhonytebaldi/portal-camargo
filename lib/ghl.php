<?php
/* lib/ghl.php — integração com a API do GoHighLevel (roda no servidor). */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** GET simples na API do GHL. Retorna array decodificado ou null. */
function ghl_api_get(string $path): ?array {
    portal_load_config();
    $url = 'https://services.leadconnectorhq.com' . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . GHL_TOKEN,
            'Version: 2021-07-28',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$res) return null;
    $j = json_decode($res, true);
    return is_array($j) ? $j : null;
}

/** Sincroniza a lista de corretores (usuários do GHL) para a tabela brokers. */
function ghl_sync_brokers(): array {
    portal_load_config();
    $data = ghl_api_get('/users/?locationId=' . urlencode(GHL_LOCATION));
    if (!$data || !isset($data['users'])) {
        return ['ok' => false, 'msg' => 'Não foi possível buscar os usuários do GHL (verifique o token).'];
    }
    $pdo = db();
    $up = $pdo->prepare(
        'INSERT INTO brokers (id, nome, email, ativo) VALUES (?,?,?,1)
         ON DUPLICATE KEY UPDATE nome=VALUES(nome), email=VALUES(email)'
    );
    $n = 0;
    foreach ($data['users'] as $u) {
        if (empty($u['id'])) continue;
        $up->execute([$u['id'], (string)($u['name'] ?? ''), (string)($u['email'] ?? '')]);
        $n++;
    }
    return ['ok' => true, 'n' => $n];
}
