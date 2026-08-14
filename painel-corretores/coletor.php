<?php
/* =====================================================================
   painel-corretores/coletor.php — coletor do Painel de Presença (GHL).

   Porta em PHP do coletor Python. Roda no servidor (cron diário) e também
   pode ser disparado por um admin no navegador ("Atualizar agora").

   Metodologia (mesma do relatório original):
     • Varre TODAS as conversas ativas da location (não por dono atual).
     • Atribui cada mensagem ao AUTOR (userId), não ao dono da conversa.
     • Exclui automação (source = workflow).
     • Presença = mensagens manuais ao cliente (app/api client)
       + notas internas (TYPE_INTERNAL_COMMENT) + ligações (TYPE_CALL).
     • Janela = mês corrente até hoje (ou YYYY-MM-DD YYYY-MM-DD via argv).

   Escreve o agregado em  PAINEL_DATA_DIR/presenca_agg.json  (fora da web).
   O viewer (index.php) lê esse arquivo e filtra por permissão do usuário.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

$CLI = (PHP_SAPI === 'cli');

/* No navegador, só admin pode disparar (é uma operação cara). */
if (!$CLI) {
    require_once __DIR__ . '/../lib/auth.php';
    $u = current_user();
    if (!$u || $u['papel'] !== 'admin') { http_response_code(403); exit('Apenas admin.'); }
    @set_time_limit(0);
    header('Content-Type: text/plain; charset=utf-8');
}
portal_load_config();

function saida(string $s): void { echo $s . "\n"; @flush(); }

$TZ  = new DateTimeZone('-03:00');
$now = new DateTime('now', $TZ);

/* ---- Período ---------------------------------------------------------- */
$argvv = $CLI ? array_slice($argv, 1) : [];
if (count($argvv) >= 2) {
    $dStart = DateTime::createFromFormat('!Y-m-d', $argvv[0], $TZ);
    $dEnd   = DateTime::createFromFormat('!Y-m-d', $argvv[1], $TZ);
} else {
    $dStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
    $dEnd   = clone $now;
}
$dStart->setTime(0, 0, 0);
$dEnd->setTime(23, 59, 59);
$START = $dStart->getTimestamp() * 1000;
$END   = $dEnd->getTimestamp() * 1000;

/* ---- Config / constantes --------------------------------------------- */
$TOKEN = GHL_TOKEN;
$LOC   = GHL_LOCATION;
$HDR   = [
    'Authorization: Bearer ' . $TOKEN,
    'Version: 2021-07-28',
    'Accept: application/json',
    'Content-Type: application/json',
];
$CLIENT = [
    'TYPE_WHATSAPP' => 1, 'TYPE_CUSTOM_SMS' => 1, 'TYPE_SMS' => 1, 'TYPE_EMAIL' => 1,
    'TYPE_FACEBOOK' => 1, 'TYPE_INSTAGRAM' => 1, 'TYPE_GMB' => 1, 'TYPE_LIVE_CHAT' => 1,
];
$CONCURRENCY = 8;

/* ---- Corretores (do banco; atribui por autor) ------------------------- */
$pdo = db();
$brokersRows = $pdo->query('SELECT id, nome, email FROM brokers ORDER BY nome')->fetchAll();
$BIDS = [];
foreach ($brokersRows as $b) $BIDS[$b['id']] = true;
if (!$BIDS) { saida('Nenhum corretor na tabela brokers. Rode a sincronização do GHL primeiro.'); exit(1); }

/* ---- Helpers HTTP ----------------------------------------------------- */
function ghl_get(string $url, array $hdr, int $tries = 4): ?array {
    for ($k = 0; $k < $tries; $k++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 429) { sleep(2 * ($k + 1)); continue; }
        if ($code !== 200 || !$res) { usleep(300000); continue; }
        $j = json_decode($res, true);
        return is_array($j) ? $j : null;
    }
    return null;
}
function parse_ms(string $s): int {
    // ISO8601 -> epoch ms
    $t = strtotime($s);
    return $t ? $t * 1000 : 0;
}

/* ===================================================================== */
/* 1) Todas as conversas ativas (location-wide), paginando por sort()     */
/* ===================================================================== */
$base = "https://services.leadconnectorhq.com/conversations/search?locationId={$LOC}&limit=100&sortBy=last_message_date&sort=desc";
$cursor = null; $convIds = []; $pages = 0;
while ($pages < 400) {
    $url = $cursor === null ? $base : $base . '&startAfterDate=' . urlencode((string)$cursor);
    $j = ghl_get($url, $HDR);
    $cs = ($j['conversations'] ?? []);
    if (!$cs) break;
    $pages++;
    $allOld = true;
    foreach ($cs as $c) {
        $lmd = (int)($c['lastMessageDate'] ?? 0);
        if ($lmd >= $START) { $convIds[] = $c['id']; $allOld = false; }
        elseif ($lmd >= $START) $allOld = false;
    }
    // se a página inteira já é anterior ao START, paramos
    $anyRecent = false;
    foreach ($cs as $c) if ((int)($c['lastMessageDate'] ?? 0) >= $START) { $anyRecent = true; break; }
    if (!$anyRecent) break;
    $last = end($cs);
    $nc = $last['sort'][0] ?? null;
    if ($nc === null || $nc === $cursor) break;
    $cursor = $nc;
}
$convIds = array_values(array_unique($convIds));
saida('conversas ativas: ' . count($convIds));

/* ===================================================================== */
/* 2) Mensagens por conversa — pool curl_multi, paginação por conversa    */
/* ===================================================================== */
// Acumulador: acc[uid][YYYY-MM-DD] = {...}
$acc = [];
$novoDia = function () {
    return [
        'n' => 0, 'app' => 0, 'api' => 0, 'ic' => 0, 'cl' => 0,
        'first' => null, 'last' => null, 'pfirst' => null, 'plast' => null,
        'mh' => array_fill(0, 24, 0), 'ah' => array_fill(0, 24, 0),
    ];
};

$msgUrl = function (string $cid, ?string $lastId): string {
    $u = "https://services.leadconnectorhq.com/conversations/{$cid}/messages?limit=100";
    if ($lastId) $u .= '&lastMessageId=' . urlencode($lastId);
    return $u;
};

$processMsgs = function (array $j) use (&$acc, $BIDS, $CLIENT, $START, $END, $TZ, $novoDia): int {
    // retorna o timestamp (ms) mais antigo visto nesta página (para decidir parada)
    $data = $j['messages'] ?? [];
    $msgs = $data['messages'] ?? [];
    $oldest = PHP_INT_MAX;
    foreach ($msgs as $m) {
        $da = $m['dateAdded'] ?? null;
        if (!$da) continue;
        $e = parse_ms($da);
        if ($e < $oldest) $oldest = $e;
        if (($m['direction'] ?? '') !== 'outbound') continue;
        $uid = $m['userId'] ?? null;
        if ($uid === null || !isset($BIDS[$uid])) continue;
        if (!($START <= $e && $e < $END)) continue;
        $src = $m['source'] ?? '';
        $mt  = $m['messageType'] ?? '';
        $dloc = (new DateTime('@' . intdiv($e, 1000)))->setTimezone($TZ);
        $key = $dloc->format('Y-m-d');
        $hr  = (int)$dloc->format('G');
        if (!isset($acc[$uid])) $acc[$uid] = [];
        if (!isset($acc[$uid][$key])) $acc[$uid][$key] = $novoDia();
        $d =& $acc[$uid][$key];
        $human = false;
        if ($src === 'workflow') {
            $d['ah'][$hr]++;
        } elseif (($src === 'app' || $src === 'api') && isset($CLIENT[$mt])) {
            $d['n']++; $d['mh'][$hr]++; $human = true;
            if ($src === 'app') $d['app']++; else $d['api']++;
            if ($d['first'] === null || $e < $d['first']) $d['first'] = $e;
            if ($d['last']  === null || $e > $d['last'])  $d['last']  = $e;
        } elseif ($mt === 'TYPE_INTERNAL_COMMENT') {
            $d['ic']++; $human = true;
        } elseif ($mt === 'TYPE_CALL') {
            $d['cl']++; $human = true;
        }
        if ($human) {
            if ($d['pfirst'] === null || $e < $d['pfirst']) $d['pfirst'] = $e;
            if ($d['plast']  === null || $e > $d['plast'])  $d['plast']  = $e;
        }
        unset($d);
    }
    return $oldest === PHP_INT_MAX ? 0 : $oldest;
};

$queue = $convIds;              // conversas ainda não iniciadas
$state = [];                    // handle => ['cid'=>, 'page'=>]
$mh = curl_multi_init();
$done = 0; $total = count($convIds);

$addHandle = function (string $cid, ?string $lastId, int $page) use ($mh, &$state, $msgUrl, $HDR) {
    $ch = curl_init($msgUrl($cid, $lastId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $HDR,
        CURLOPT_TIMEOUT        => 25,
    ]);
    curl_multi_add_handle($mh, $ch);
    $state[(int)$ch] = ['cid' => $cid, 'page' => $page];
};

// preenche o pool inicial
while (count($state) < $CONCURRENCY && $queue) {
    $cid = array_shift($queue);
    $addHandle($cid, null, 0);
}

do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 1.0);
    while ($info = curl_multi_info_read($mh)) {
        $ch = $info['handle'];
        $id = (int)$ch;
        $st = $state[$id] ?? null;
        unset($state[$id]);
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $advance = true;   // por padrão, encerra esta conversa e puxa a próxima
        if ($st) {
            if ($code === 429) {
                // recoloca a mesma página no fim da fila (backoff leve)
                usleep(500000);
                $addHandle($st['cid'], null, $st['page']); // reinicia conversa
                $advance = false;
            } elseif ($code === 200 && $body) {
                $j = json_decode($body, true);
                if (is_array($j)) {
                    $oldest = $processMsgs($j);
                    $data = $j['messages'] ?? [];
                    $lastId  = $data['lastMessageId'] ?? null;
                    $hasNext = !empty($data['nextPage']);
                    $moreOld = ($oldest === 0) || ($oldest >= $START);
                    if ($lastId && $hasNext && $moreOld && $st['page'] < 6) {
                        $addHandle($st['cid'], $lastId, $st['page'] + 1);
                        $advance = false;
                    }
                }
            }
        }
        if ($advance) {
            $done++;
            if ($done % 300 === 0) saida("proc {$done}/{$total}");
            if ($queue) { $cid = array_shift($queue); $addHandle($cid, null, 0); }
        }
    }
} while ($running || $state || $queue);
curl_multi_close($mh);
saida("mensagens processadas — conversas: {$total}");

/* ===================================================================== */
/* 3) Monta o agregado e grava JSON                                       */
/* ===================================================================== */
$hm = function (?int $ms) use ($TZ): ?string {
    if (!$ms) return null;
    return (new DateTime('@' . intdiv($ms, 1000)))->setTimezone($TZ)->format('H:i');
};
$out = [];
foreach ($brokersRows as $b) {
    $u = $acc[$b['id']] ?? [];
    $days = [];
    foreach ($u as $k => $d) {
        $days[$k] = [
            'n' => $d['n'], 'app' => $d['app'], 'api' => $d['api'], 'ic' => $d['ic'], 'cl' => $d['cl'],
            'first' => $hm($d['first']), 'last' => $hm($d['last']),
            'pfirst' => $hm($d['pfirst']), 'plast' => $hm($d['plast']),
            'mh' => $d['mh'], 'ah' => $d['ah'],
        ];
    }
    $out[] = ['id' => $b['id'], 'name' => $b['nome'], 'email' => $b['email'], 'days' => $days];
}
$WD = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
$sd = (clone $dStart); $ed = (clone $dEnd);
$alldays = [];
$dd = clone $sd;
while ($dd->getTimestamp() <= $ed->getTimestamp()) {
    $w = (int)$dd->format('N') - 1; // 0=seg
    $alldays[] = [
        'key' => $dd->format('Y-m-d'), 'label' => $dd->format('d/m'),
        'wd' => $WD[$w], 'weekend' => $w >= 5,
    ];
    $dd->modify('+1 day');
}
$agg = [
    'period' => [
        'start' => $START, 'end' => $END,
        'start_str' => $sd->format('d/m/Y'), 'end_str' => $ed->format('d/m/Y'),
        'generated' => $now->format('d/m/Y H:i'),
    ],
    'brokers' => $out,
    'alldays' => $alldays,
];

$dir = defined('PAINEL_DATA_DIR') ? PAINEL_DATA_DIR : (dirname(__DIR__, 2) . '/painel-dados');
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$file = rtrim($dir, '/') . '/presenca_agg.json';
$json = json_encode($agg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ok = @file_put_contents($file, $json);
if ($ok === false) { saida('ERRO ao gravar ' . $file . ' (permissão?).'); exit(1); }
saida('OK — agregado gravado em ' . $file . ' (' . strlen($json) . ' bytes).');

if (!$CLI) saida("\nPronto. Volte ao painel: /painel-corretores/");
