<?php
/* =====================================================================
   painel-corretores/coletor.php — coletor do Painel de Presença (GHL).

   Metodologia (aproximação de presença; ver disclaimer no painel):
     • Varre conversas ativas da location e atribui cada mensagem ao
       AUTOR (userId), não ao dono atual da conversa.
     • Exclui automação (source = workflow).
     • Presença = mensagens manuais ao cliente (app/api client-facing)
       + notas internas (TYPE_INTERNAL_COMMENT) + ligações (TYPE_CALL).

   INCREMENTAL (padrão):
     Dias passados são imutáveis (autor não muda; mensagens só somam), então
     ficam "congelados" no cache. Cada rodada recomputa só uma JANELA recente
     (últimos dias) — muito menos conversas e chamadas de API.
     Rebuild completo automático quando muda o mês (ou com --full).

   Uso:
     php coletor.php                 # incremental (mês corrente até hoje)
     php coletor.php --full          # força varredura completa do mês
     php coletor.php 2026-08-01 2026-08-14   # intervalo específico (completo)

   Arquivos (em PAINEL_DATA_DIR, fora da web):
     presenca_agg.json    → agregado que o painel lê
     presenca_cache.json  → dias congelados por corretor (estado incremental)
     status.json          → estado da última/atual coleta (para o painel)
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

const PAINEL_CONCURRENCY = 8;

/* --------------------------------------------------------------------- */
function painel_data_dir(): string {
    $dir = defined('PAINEL_DATA_DIR') ? PAINEL_DATA_DIR : (dirname(__DIR__, 2) . '/painel-dados');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return rtrim($dir, '/');
}
function painel_log(string $s): void { echo $s . "\n"; @flush(); }

function status_write(array $data): void {
    @file_put_contents(painel_data_dir() . '/status.json',
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function status_read(): ?array {
    $f = painel_data_dir() . '/status.json';
    if (!is_readable($f)) return null;
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function ghl_headers(): array {
    return [
        'Authorization: Bearer ' . GHL_TOKEN,
        'Version: 2021-07-28',
        'Accept: application/json',
        'Content-Type: application/json',
    ];
}
function ghl_get(string $url, array $hdr, int $tries = 4): ?array {
    for ($k = 0; $k < $tries; $k++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 25]);
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($code === 429) { sleep(2 * ($k + 1)); continue; }
        if ($code !== 200 || !$res) { usleep(300000); continue; }
        $j = json_decode($res, true);
        return is_array($j) ? $j : null;
    }
    return null;
}
function parse_ms(string $s): int { $t = strtotime($s); return $t ? $t * 1000 : 0; }

/* =====================================================================
   Coleta principal. $mode: 'incremental' | 'full' | 'range'
   ===================================================================== */
function run_collection(string $mode, ?string $rangeA = null, ?string $rangeB = null): void {
    portal_load_config();
    $TZ  = new DateTimeZone('-03:00');
    $now = new DateTime('now', $TZ);
    $dir = painel_data_dir();

    /* Trava simples contra execuções concorrentes (cron + manual). */
    $lockF = fopen($dir . '/coletor.lock', 'c');
    if ($lockF && !flock($lockF, LOCK_EX | LOCK_NB)) {
        painel_log('Outra coleta já está em andamento. Saindo.');
        return;
    }

    /* ---- Período (mês corrente, ou intervalo explícito) ---- */
    if ($mode === 'range' && $rangeA && $rangeB) {
        $mStart = DateTime::createFromFormat('!Y-m-d', $rangeA, $TZ);
        $mEnd   = DateTime::createFromFormat('!Y-m-d', $rangeB, $TZ);
    } else {
        $mStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $mEnd   = clone $now;
    }
    $mStart->setTime(0, 0, 0); $mEnd->setTime(23, 59, 59);
    $monthKey   = $mStart->format('Y-m');
    $monthStartD= $mStart->format('Y-m-d');
    $todayD     = $mEnd->format('Y-m-d');
    $END        = $mEnd->getTimestamp() * 1000;

    /* ---- Cache / decisão de modo ---- */
    $cacheFile = $dir . '/presenca_cache.json';
    $cache = null;
    if ($mode !== 'range' && is_readable($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['month'] ?? '') === $monthKey) $cache = $c;
    }
    if ($mode === 'range') { $incremental = false; }
    elseif ($mode === 'full' || !$cache) { $incremental = false; }
    else { $incremental = true; }

    /* Janela a recomputar */
    if ($incremental) {
        $winStart = (clone $mEnd)->modify('-2 days')->setTime(0, 0, 0);          // hoje, ontem, anteontem
        if (!empty($cache['last_run_date'])) {                                   // cobre eventuais rodadas perdidas
            $gap = DateTime::createFromFormat('!Y-m-d', $cache['last_run_date'], $TZ);
            if ($gap) { $gap->modify('-1 day'); if ($gap < $winStart) $winStart = $gap; }
        }
        if ($winStart < $mStart) $winStart = clone $mStart;                       // não passa do início do mês
    } else {
        $winStart = clone $mStart;
    }
    $winStartD  = $winStart->format('Y-m-d');
    $winStartMs = $winStart->getTimestamp() * 1000;

    status_write(['state' => 'running', 'mode' => $incremental ? 'incremental' : 'full',
        'started' => $now->format('d/m H:i'), 'since_win' => $winStartD]);
    painel_log(sprintf('modo=%s  janela=%s..%s', $incremental ? 'incremental' : 'full', $winStartD, $todayD));

    /* ---- Corretores ---- */
    $pdo = db();
    $brokersRows = $pdo->query('SELECT id, nome, email FROM brokers ORDER BY nome')->fetchAll();
    $BIDS = [];
    foreach ($brokersRows as $b) $BIDS[$b['id']] = true;
    if (!$BIDS) { painel_log('Sem corretores na tabela brokers.'); status_write(['state'=>'error','msg'=>'sem corretores']); return; }

    $HDR = ghl_headers();
    $LOC = GHL_LOCATION;
    $CLIENT = ['TYPE_WHATSAPP'=>1,'TYPE_CUSTOM_SMS'=>1,'TYPE_SMS'=>1,'TYPE_EMAIL'=>1,
               'TYPE_FACEBOOK'=>1,'TYPE_INSTAGRAM'=>1,'TYPE_GMB'=>1,'TYPE_LIVE_CHAT'=>1];

    /* ===== 1) Conversas ativas dentro da janela ===== */
    $base = "https://services.leadconnectorhq.com/conversations/search?locationId={$LOC}&limit=100&sortBy=last_message_date&sort=desc";
    $cursor = null; $convIds = []; $pages = 0;
    while ($pages < 400) {
        $url = $cursor === null ? $base : $base . '&startAfterDate=' . urlencode((string)$cursor);
        $j = ghl_get($url, $HDR);
        $cs = ($j['conversations'] ?? []);
        if (!$cs) break;
        $pages++; $anyRecent = false;
        foreach ($cs as $c) {
            $lmd = (int)($c['lastMessageDate'] ?? 0);
            if ($lmd >= $winStartMs) { $convIds[] = $c['id']; $anyRecent = true; }
        }
        if (!$anyRecent) break;
        $last = end($cs); $nc = $last['sort'][0] ?? null;
        if ($nc === null || $nc === $cursor) break;
        $cursor = $nc;
    }
    $convIds = array_values(array_unique($convIds));
    painel_log('conversas na janela: ' . count($convIds));

    /* ===== 2) Mensagens por conversa (pool curl_multi) ===== */
    $acc = [];
    $novoDia = fn() => ['n'=>0,'app'=>0,'api'=>0,'ic'=>0,'cl'=>0,'first'=>null,'last'=>null,
                        'pfirst'=>null,'plast'=>null,'mh'=>array_fill(0,24,0),'ah'=>array_fill(0,24,0)];
    $msgUrl = function (string $cid, ?string $lastId): string {
        $u = "https://services.leadconnectorhq.com/conversations/{$cid}/messages?limit=100";
        if ($lastId) $u .= '&lastMessageId=' . urlencode($lastId);
        return $u;
    };
    $processMsgs = function (array $j) use (&$acc, $BIDS, $CLIENT, $winStartMs, $END, $TZ, $novoDia): int {
        $data = $j['messages'] ?? []; $msgs = $data['messages'] ?? [];
        $oldest = PHP_INT_MAX;
        foreach ($msgs as $m) {
            $da = $m['dateAdded'] ?? null; if (!$da) continue;
            $e = parse_ms($da); if ($e < $oldest) $oldest = $e;
            if (($m['direction'] ?? '') !== 'outbound') continue;
            $uid = $m['userId'] ?? null; if ($uid === null || !isset($BIDS[$uid])) continue;
            if (!($winStartMs <= $e && $e < $END)) continue;
            $src = $m['source'] ?? ''; $mt = $m['messageType'] ?? '';
            $dloc = (new DateTime('@' . intdiv($e, 1000)))->setTimezone($TZ);
            $key = $dloc->format('Y-m-d'); $hr = (int)$dloc->format('G');
            if (!isset($acc[$uid])) $acc[$uid] = [];
            if (!isset($acc[$uid][$key])) $acc[$uid][$key] = $novoDia();
            $d =& $acc[$uid][$key]; $human = false;
            if ($src === 'workflow') { $d['ah'][$hr]++; }
            elseif (($src === 'app' || $src === 'api') && isset($CLIENT[$mt])) {
                $d['n']++; $d['mh'][$hr]++; $human = true;
                if ($src === 'app') $d['app']++; else $d['api']++;
                if ($d['first'] === null || $e < $d['first']) $d['first'] = $e;
                if ($d['last']  === null || $e > $d['last'])  $d['last']  = $e;
            } elseif ($mt === 'TYPE_INTERNAL_COMMENT') { $d['ic']++; $human = true; }
            elseif ($mt === 'TYPE_CALL') { $d['cl']++; $human = true; }
            if ($human) {
                if ($d['pfirst'] === null || $e < $d['pfirst']) $d['pfirst'] = $e;
                if ($d['plast']  === null || $e > $d['plast'])  $d['plast']  = $e;
            }
            unset($d);
        }
        return $oldest === PHP_INT_MAX ? 0 : $oldest;
    };

    $queue = $convIds; $state = []; $mh = curl_multi_init(); $done = 0; $total = count($convIds);
    $addHandle = function (string $cid, ?string $lastId, int $page) use ($mh, &$state, $msgUrl, $HDR) {
        $ch = curl_init($msgUrl($cid, $lastId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $HDR, CURLOPT_TIMEOUT => 25]);
        curl_multi_add_handle($mh, $ch); $state[(int)$ch] = ['cid' => $cid, 'page' => $page];
    };
    while (count($state) < PAINEL_CONCURRENCY && $queue) { $addHandle(array_shift($queue), null, 0); }
    do {
        curl_multi_exec($mh, $running); curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle']; $id = (int)$ch; $st = $state[$id] ?? null; unset($state[$id]);
            $body = curl_multi_getcontent($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            $advance = true;
            if ($st) {
                if ($code === 429) { usleep(500000); $addHandle($st['cid'], null, $st['page']); $advance = false; }
                elseif ($code === 200 && $body) {
                    $j = json_decode($body, true);
                    if (is_array($j)) {
                        $oldest = $processMsgs($j); $data = $j['messages'] ?? [];
                        $lastId = $data['lastMessageId'] ?? null; $hasNext = !empty($data['nextPage']);
                        $moreOld = ($oldest === 0) || ($oldest >= $winStartMs);
                        if ($lastId && $hasNext && $moreOld && $st['page'] < 6) { $addHandle($st['cid'], $lastId, $st['page'] + 1); $advance = false; }
                    }
                }
            }
            if ($advance) { $done++; if ($done % 300 === 0) painel_log("proc {$done}/{$total}"); if ($queue) $addHandle(array_shift($queue), null, 0); }
        }
    } while ($running || $state || $queue);
    curl_multi_close($mh);
    painel_log("mensagens processadas — conversas: {$total}");

    /* ===== 3) Converte janela + funde com dias congelados ===== */
    $hm = fn(?int $ms) => $ms ? (new DateTime('@' . intdiv($ms, 1000)))->setTimezone($TZ)->format('H:i') : null;
    $winDays = [];
    foreach ($acc as $bid => $days) {
        foreach ($days as $k => $d) {
            $winDays[$bid][$k] = ['n'=>$d['n'],'app'=>$d['app'],'api'=>$d['api'],'ic'=>$d['ic'],'cl'=>$d['cl'],
                'first'=>$hm($d['first']),'last'=>$hm($d['last']),'pfirst'=>$hm($d['pfirst']),'plast'=>$hm($d['plast']),
                'mh'=>$d['mh'],'ah'=>$d['ah']];
        }
    }
    $final = [];
    if ($incremental && isset($cache['days']) && is_array($cache['days'])) {
        foreach ($cache['days'] as $bid => $days) {
            if (!is_array($days)) continue;
            foreach ($days as $day => $rec) { if ($day < $winStartD) $final[$bid][$day] = $rec; }   // congelados
        }
    }
    foreach ($winDays as $bid => $days) {
        foreach ($days as $day => $rec) $final[$bid][$day] = $rec;                                   // recomputados
    }

    /* Grava cache (só para mês corrente; range é consulta avulsa). */
    if ($mode !== 'range') {
        @file_put_contents($cacheFile, json_encode(
            ['month' => $monthKey, 'last_run_date' => $todayD, 'days' => $final],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* ===== 4) Monta agregado do painel ===== */
    $out = [];
    foreach ($brokersRows as $b) {
        $days = $final[$b['id']] ?? [];
        $out[] = ['id' => $b['id'], 'name' => $b['nome'], 'email' => $b['email'], 'days' => (object)$days];
    }
    $WD = ['seg','ter','qua','qui','sex','sab','dom']; $alldays = []; $dd = clone $mStart;
    while ($dd->getTimestamp() <= $mEnd->getTimestamp()) {
        $w = (int)$dd->format('N') - 1;
        $alldays[] = ['key'=>$dd->format('Y-m-d'),'label'=>$dd->format('d/m'),'wd'=>$WD[$w],'weekend'=>$w>=5];
        $dd->modify('+1 day');
    }
    $agg = ['period' => ['start' => $mStart->getTimestamp()*1000, 'end' => $END,
                'start_str' => $mStart->format('d/m/Y'), 'end_str' => $mEnd->format('d/m/Y'),
                'generated' => $now->format('d/m/Y H:i')],
            'brokers' => $out, 'alldays' => $alldays];
    $json = json_encode($agg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($dir . '/presenca_agg.json', $json) === false) {
        painel_log('ERRO ao gravar presenca_agg.json'); status_write(['state'=>'error','msg'=>'falha ao gravar']); return;
    }
    $fim = new DateTime('now', $TZ);
    status_write(['state' => 'done', 'mode' => $incremental ? 'incremental' : 'full',
        'started' => $now->format('d/m H:i'), 'finished' => $fim->format('d/m H:i'),
        'conversas' => $total, 'janela' => $winStartD . '..' . $todayD]);
    painel_log('OK — ' . strlen($json) . ' bytes · conversas ' . $total);
    if ($lockF) { flock($lockF, LOCK_UN); fclose($lockF); }
}

/* =====================================================================
   Disparo em segundo plano (para o botão "atualizar agora" do admin)
   ===================================================================== */
function spawn_background(string $log): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disabled_functions')));
    if (in_array('exec', $disabled, true)) return false;
    $cmd = sprintf('nohup /usr/bin/php %s --incr >> %s 2>&1 & echo ok',
        escapeshellarg(__FILE__), escapeshellarg($log));
    @exec($cmd, $o, $rc);
    return $rc === 0;
}
function render_started_page(bool $spawned): void {
    $css = '/assets/portal.css?v=' . (@filemtime(dirname(__DIR__) . '/assets/portal.css') ?: 1);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta http-equiv="refresh" content="6;url=/painel-corretores/">'
       . '<title>Atualizando…</title><link rel="stylesheet" href="' . $css . '"></head>'
       . '<body class="tela-login"><div class="cartao-login" style="max-width:460px">'
       . '<h1>Atualização iniciada</h1>'
       . '<p class="sub">A coleta está rodando no servidor em segundo plano. '
       . 'Ela leva alguns minutos; a <b>data de atualização</b> no painel muda quando terminar.</p>'
       . '<p class="sub">Você será levado de volta ao painel em instantes…</p>'
       . '<p><a href="/painel-corretores/">Voltar ao painel agora</a></p></div></body></html>';
}

/* =====================================================================
   Roteamento CLI x Web
   ===================================================================== */
if (PHP_SAPI === 'cli') {
    portal_load_config();
    $args = array_slice($argv, 1); $mode = 'incremental'; $ra = $rb = null;
    foreach ($args as $a) {
        if ($a === '--full') $mode = 'full';
        elseif ($a === '--incr') $mode = 'incremental';
        elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $a)) { if (!$ra) $ra = $a; else $rb = $a; }
    }
    if ($ra && $rb) run_collection('range', $ra, $rb);
    else run_collection($mode);
    exit;
}

/* --- Web: só admin, dispara em segundo plano e responde na hora --- */
require_once __DIR__ . '/../lib/auth.php';
$u = current_user();
if (!$u || $u['papel'] !== 'admin') { http_response_code(403); exit('Apenas admin.'); }
portal_load_config();
header('Content-Type: text/html; charset=utf-8');
$log = painel_data_dir() . '/coletor.log';
status_write(['state' => 'running', 'mode' => 'manual', 'started' => (new DateTime('now', new DateTimeZone('-03:00')))->format('d/m H:i')]);
$spawned = spawn_background($log);
render_started_page($spawned);
if (!$spawned) {   // sem exec: termina a resposta e roda inline
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    @set_time_limit(0); @ignore_user_abort(true);
    run_collection('incremental');
}
