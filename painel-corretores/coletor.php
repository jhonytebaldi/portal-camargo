<?php
/* =====================================================================
   painel-corretores/coletor.php — coletor do Painel de Presença (GHL).

   Metodologia (aproximação de presença; ver disclaimer no painel):
     • Varre conversas ativas da location e atribui cada mensagem ao
       AUTOR (userId), não ao dono atual da conversa.
     • Exclui automação (source = workflow).
     • Presença = mensagens manuais ao cliente (app/api client-facing)
       + notas internas (TYPE_INTERNAL_COMMENT) + ligações (TYPE_CALL).

   Também mede, por corretor:
     • Tempo de resposta (cliente → 1ª resposta manual), em horário comercial;
       guarda histograma por dia para calcular mediana/média em qualquer recorte.
     • Não lidas AGORA (unreadCount por corretor responsável = assignedTo) e
       clientes parados há +24h — retrato do momento, série horária à frente.

   ARQUIVOS por MÊS (em PAINEL_DATA_DIR, fora da web):
     presenca_<AAAA-MM>.json   → agregado que o painel lê (um por mês)
     cache_<AAAA-MM>.json      → dias congelados (estado incremental do mês)
     painel_live.json          → não lidas agora + série horária (só "agora")
     status.json               → estado da última/atual coleta

   Uso:
     php coletor.php                 # incremental (mês corrente)
     php coletor.php --full          # varredura completa do mês corrente
     php coletor.php 2026-06         # backfill completo de um mês (AAAA-MM)
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

const PAINEL_CONCURRENCY = 8;
const RT_BIZ_INI = 8, RT_BIZ_FIM = 20;   // horário comercial p/ tempo de resposta
const RT_CAP     = 43200;                // ignora respostas acima de 12h (cross-day)
const UNREAD_LOOKBACK_DAYS = 12;         // até onde varrer conversas p/ não-lidas

/* limites (superiores, em segundos) dos 10 baldes do histograma de resposta */
const RT_BOUNDS = [60,180,300,600,1200,1800,3600,7200,21600,43200];
function rt_bucket(int $sec): int {
    foreach (RT_BOUNDS as $i => $ub) if ($sec < $ub) return $i;
    return 9;
}

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
   Coleta principal. $mode: 'incremental' | 'full' | 'month'
   ===================================================================== */
function run_collection(string $mode, ?string $monthArg = null): void {
    portal_load_config();
    $TZ  = new DateTimeZone('-03:00');
    $now = new DateTime('now', $TZ);
    $dir = painel_data_dir();

    // Backfill de mês passado usa uma trava PRÓPRIA, para não bloquear a coleta
    // do mês corrente (cron de hora em hora) enquanto roda.
    $isBackfill = ($mode === 'month' && $monthArg && $monthArg !== $now->format('Y-m'));
    $lockF = fopen($dir . '/' . ($isBackfill ? 'coletor_backfill.lock' : 'coletor.lock'), 'c');
    if ($lockF && !flock($lockF, LOCK_EX | LOCK_NB)) { painel_log('Outra coleta em andamento. Saindo.'); return; }

    /* ---- Período (mês) ---- */
    if ($mode === 'month' && $monthArg && preg_match('/^\d{4}-\d{2}$/', $monthArg)) {
        $mStart = DateTime::createFromFormat('!Y-m-d', $monthArg . '-01', $TZ);
        $mEnd   = (clone $mStart)->modify('last day of this month');
        $ehMesCorrente = ($mStart->format('Y-m') === $now->format('Y-m'));
        if ($ehMesCorrente) $mEnd = clone $now;
    } else {
        $mStart = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $mEnd   = clone $now;
        $ehMesCorrente = true;
    }
    $mStart->setTime(0, 0, 0); $mEnd->setTime(23, 59, 59);
    $monthKey    = $mStart->format('Y-m');
    $todayD      = $mEnd->format('Y-m-d');
    $END         = $mEnd->getTimestamp() * 1000;

    $aggFile   = $dir . "/presenca_{$monthKey}.json";
    $cacheFile = $dir . "/cache_{$monthKey}.json";

    /* ---- Cache / modo ---- */
    $cache = null;
    if ($mode !== 'month' && is_readable($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['month'] ?? '') === $monthKey) $cache = $c;
    } elseif ($mode === 'month' && is_readable($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && ($c['month'] ?? '') === $monthKey && $ehMesCorrente) $cache = $c;
    }
    $incremental = ($mode === 'incremental' || ($mode === 'month' && $ehMesCorrente)) && $cache;

    if ($incremental) {
        $winStart = (clone $mEnd)->modify('-2 days')->setTime(0, 0, 0);
        if (!empty($cache['last_run_date'])) {
            $gap = DateTime::createFromFormat('!Y-m-d', $cache['last_run_date'], $TZ);
            if ($gap) { $gap->modify('-1 day'); if ($gap < $winStart) $winStart = $gap; }
        }
        if ($winStart < $mStart) $winStart = clone $mStart;
    } else {
        $winStart = clone $mStart;
    }
    $winStartD  = $winStart->format('Y-m-d');
    $winStartMs = $winStart->getTimestamp() * 1000;

    status_write(['state' => 'running', 'mode' => ($incremental ? 'incremental' : 'full') . ($mode==='month'?" · {$monthKey}":''),
        'started' => $now->format('d/m H:i'), 'since_win' => $winStartD]);
    painel_log(sprintf('mês=%s modo=%s janela=%s..%s', $monthKey, $incremental ? 'incremental' : 'full', $winStartD, $todayD));

    /* ---- Corretores (com desligamento) ---- */
    $pdo = db();
    $temColDesl = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='brokers' AND COLUMN_NAME='desligado_em'")->fetchColumn() > 0;
    $sel = $temColDesl ? 'SELECT id,nome,email,ativo,desligado_em FROM brokers ORDER BY nome'
                       : 'SELECT id,nome,email,ativo FROM brokers ORDER BY nome';
    $brokersRows = $pdo->query($sel)->fetchAll();
    $BIDS = [];             // todos (para atribuição histórica)
    $ATIVO = [];            // bid => 0/1
    $CUT = [];              // bid => corte (ms) se desligado com data
    foreach ($brokersRows as $b) {
        $BIDS[$b['id']] = true;
        $ATIVO[$b['id']] = (int)$b['ativo'];
        if (!$b['ativo'] && !empty($b['desligado_em'])) {
            $t = strtotime($b['desligado_em']); if ($t) $CUT[$b['id']] = $t * 1000;
        }
    }
    if (!$BIDS) { painel_log('Sem corretores.'); status_write(['state'=>'error','msg'=>'sem corretores']); if($lockF){flock($lockF,LOCK_UN);fclose($lockF);} return; }

    $HDR = ghl_headers(); $LOC = GHL_LOCATION;
    $CLIENT = ['TYPE_WHATSAPP'=>1,'TYPE_CUSTOM_SMS'=>1,'TYPE_SMS'=>1,'TYPE_EMAIL'=>1,
               'TYPE_FACEBOOK'=>1,'TYPE_INSTAGRAM'=>1,'TYPE_GMB'=>1,'TYPE_LIVE_CHAT'=>1];

    /* ===== 1) Conversas: fila de mensagens (janela) + retrato de não-lidas ===== */
    $unreadLookMs = $ehMesCorrente ? ($now->getTimestamp() - UNREAD_LOOKBACK_DAYS*86400) * 1000 : PHP_INT_MAX;
    $stopMs = min($winStartMs, $unreadLookMs);   // pagina conversas até o mais antigo dos dois
    $base = "https://services.leadconnectorhq.com/conversations/search?locationId={$LOC}&limit=100&sortBy=last_message_date&sort=desc";
    $cursor = null; $convIds = []; $pages = 0;
    $unread_client = []; $unread_followup = []; $wait24 = [];
    $nowMs = $now->getTimestamp()*1000; $dia1Ms = $nowMs - 86400*1000;
    while ($pages < 500) {
        $url = $cursor === null ? $base : $base . '&startAfterDate=' . urlencode((string)$cursor);
        $j = ghl_get($url, $HDR); $cs = ($j['conversations'] ?? []);
        if (!$cs) break; $pages++; $algumRecente = false;
        foreach ($cs as $c) {
            $lmd = (int)($c['lastMessageDate'] ?? 0);
            if ($lmd >= $winStartMs) {
                // No backfill de mês passado, só busca mensagens de conversas que
                // JÁ EXISTIAM no mês-alvo (dateAdded <= fim do mês). Evita varrer
                // as milhares de conversas criadas depois — que não têm mensagem
                // no mês e só encareceriam a coleta.
                $ok = $ehMesCorrente;
                if (!$ok) { $da = parse_ms((string)($c['dateAdded'] ?? '')); $ok = ($da === 0 || $da <= $END); }
                if ($ok) $convIds[] = $c['id'];
            }
            if ($lmd >= $stopMs) $algumRecente = true;
            /* retrato de não-lidas (só mês corrente): por corretor responsável.
               Separa CLIENTE AGUARDANDO (última msg é do cliente = inbound) de
               FOLLOW-UP (última é saída/automação/pós-ligação — virou "não lida"
               sem um recado novo do cliente esperando). Conta por CONVERSA. */
            if ($ehMesCorrente) {
                $ass = $c['assignedTo'] ?? null; $uc = (int)($c['unreadCount'] ?? 0);
                if ($ass && isset($ATIVO[$ass]) && $ATIVO[$ass] && $uc > 0) {
                    if (($c['lastMessageDirection'] ?? '') === 'inbound') {
                        $unread_client[$ass] = ($unread_client[$ass] ?? 0) + 1;
                        if ($lmd < $dia1Ms) $wait24[$ass] = ($wait24[$ass] ?? 0) + 1;
                    } else {
                        $unread_followup[$ass] = ($unread_followup[$ass] ?? 0) + 1;
                    }
                }
            }
        }
        if (!$algumRecente) break;
        $last = end($cs); $nc = $last['sort'][0] ?? null;
        if ($nc === null || $nc === $cursor) break; $cursor = $nc;
    }
    $convIds = array_values(array_unique($convIds));
    painel_log('conversas na janela: ' . count($convIds) . ' · clientes aguardando: ' . array_sum($unread_client) . ' · follow-up: ' . array_sum($unread_followup));

    /* ===== 2) Mensagens por conversa (pool) — acumula e processa a conversa ===== */
    $acc = [];   // acc[uid][YYYY-MM-DD] = registro-dia
    $novoDia = fn() => ['n'=>0,'app'=>0,'api'=>0,'ic'=>0,'cl'=>0,'first'=>null,'last'=>null,
                        'pfirst'=>null,'plast'=>null,'mh'=>array_fill(0,24,0),'ah'=>array_fill(0,24,0),
                        'rt_n'=>0,'rt_sum'=>0,'rt_hist'=>array_fill(0,10,0)];

    $processConversa = function (array $msgs) use (&$acc, $BIDS, $CUT, $CLIENT, $winStartMs, $END, $TZ, $novoDia) {
        /* ordena crescente por tempo */
        usort($msgs, fn($a,$b)=>$a['e'] <=> $b['e']);
        /* --- presença (contagem por autor) --- */
        foreach ($msgs as $m) {
            $e = $m['e']; if (!($winStartMs <= $e && $e < $END)) continue;
            if ($m['dir'] !== 'outbound') continue;
            $uid = $m['uid']; if ($uid === null || !isset($BIDS[$uid])) continue;
            if (isset($CUT[$uid]) && $e > $CUT[$uid]) continue;   // após desligamento, não conta
            $src = $m['src']; $mt = $m['mt'];
            $dloc = (new DateTime('@'.intdiv($e,1000)))->setTimezone($TZ);
            $key = $dloc->format('Y-m-d'); $hr = (int)$dloc->format('G');
            if (!isset($acc[$uid])) $acc[$uid] = [];
            if (!isset($acc[$uid][$key])) $acc[$uid][$key] = $novoDia();
            $d =& $acc[$uid][$key]; $human = false;
            if ($src === 'workflow') { $d['ah'][$hr]++; }
            elseif (($src === 'app' || $src === 'api') && isset($CLIENT[$mt])) {
                $d['n']++; $d['mh'][$hr]++; $human = true;
                if ($src === 'app') $d['app']++; else $d['api']++;
                if ($d['first']===null || $e<$d['first']) $d['first']=$e;
                if ($d['last'] ===null || $e>$d['last'])  $d['last'] =$e;
            } elseif ($mt === 'TYPE_INTERNAL_COMMENT') { $d['ic']++; $human=true; }
            elseif ($mt === 'TYPE_CALL') { $d['cl']++; $human=true; }
            if ($human) {
                if ($d['pfirst']===null || $e<$d['pfirst']) $d['pfirst']=$e;
                if ($d['plast'] ===null || $e>$d['plast'])  $d['plast'] =$e;
            }
            unset($d);
        }
        /* --- tempo de resposta: cliente inbound -> 1ª resposta manual --- */
        $pend = null;  // ts do 1º inbound do cliente ainda sem resposta
        foreach ($msgs as $m) {
            $e = $m['e'];
            if ($m['dir'] === 'inbound') {
                if ($pend === null) $pend = $e;
                continue;
            }
            // outbound
            $uid = $m['uid']; $src = $m['src']; $mt = $m['mt'];
            $manual = ($src === 'app' || $src === 'api') && isset($CLIENT[$mt]);
            if (!$manual) continue;                       // automação não "responde"
            if ($pend === null) continue;                 // resposta sem inbound pendente
            if ($uid === null || !isset($BIDS[$uid])) { $pend = null; continue; }
            if (isset($CUT[$uid]) && $e > $CUT[$uid]) { $pend = null; continue; }
            $delta = intdiv($e - $pend, 1000);            // segundos
            $iloc = (new DateTime('@'.intdiv($pend,1000)))->setTimezone($TZ);
            $ih = (int)$iloc->format('G');
            $ikey = $iloc->format('Y-m-d');
            $dentroBiz = ($ih >= RT_BIZ_INI && $ih < RT_BIZ_FIM);
            if ($dentroBiz && $delta > 0 && $delta <= RT_CAP
                && $winStartMs <= $pend && $pend < $END) {
                if (!isset($acc[$uid])) $acc[$uid] = [];
                if (!isset($acc[$uid][$ikey])) $acc[$uid][$ikey] = $novoDia();
                $acc[$uid][$ikey]['rt_n']++;
                $acc[$uid][$ikey]['rt_sum'] += $delta;
                $acc[$uid][$ikey]['rt_hist'][rt_bucket($delta)]++;
            }
            $pend = null;                                 // respondeu; zera pendência
        }
    };

    $msgUrl = function (string $cid, ?string $lastId): string {
        $u = "https://services.leadconnectorhq.com/conversations/{$cid}/messages?limit=100";
        if ($lastId) $u .= '&lastMessageId=' . urlencode($lastId);
        return $u;
    };
    // Backfill de mês passado precisa paginar mais fundo por conversa (mensagens
    // do mês-alvo podem estar sob meses mais novos); mês corrente cabe em 6.
    $pageCap = ($mode === 'month' && !$ehMesCorrente) ? 40 : 6;
    $queue = $convIds; $state = []; $buf = []; $mh = curl_multi_init(); $done=0; $total=count($convIds);
    $addHandle = function (string $cid, ?string $lastId, int $page) use ($mh, &$state, $msgUrl, $HDR) {
        $ch = curl_init($msgUrl($cid, $lastId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$HDR, CURLOPT_TIMEOUT=>25]);
        curl_multi_add_handle($mh, $ch); $state[(int)$ch] = ['cid'=>$cid,'page'=>$page];
    };
    while (count($state) < PAINEL_CONCURRENCY && $queue) { $cid=array_shift($queue); $buf[$cid]=[]; $addHandle($cid,null,0); }
    do {
        curl_multi_exec($mh, $running); curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) {
            $ch=$info['handle']; $id=(int)$ch; $st=$state[$id]??null; unset($state[$id]);
            $body=curl_multi_getcontent($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh,$ch); curl_close($ch);
            $advance=true;
            if ($st) {
                $cid=$st['cid'];
                if ($code===429) { usleep(500000); $buf[$cid]=[]; $addHandle($cid,null,0); $advance=false; }
                elseif ($code===200 && $body) {
                    $j=json_decode($body,true);
                    if (is_array($j)) {
                        $data=$j['messages']??[]; $msgs=$data['messages']??[]; $oldest=PHP_INT_MAX;
                        foreach ($msgs as $m) {
                            $da=$m['dateAdded']??null; if(!$da) continue; $e=parse_ms($da);
                            if ($e<$oldest) $oldest=$e;
                            $buf[$cid][]=['e'=>$e,'dir'=>($m['direction']??''),'src'=>($m['source']??''),
                                          'mt'=>($m['messageType']??''),'uid'=>($m['userId']??null)];
                        }
                        $lastId=$data['lastMessageId']??null; $hasNext=!empty($data['nextPage']);
                        $moreOld = ($oldest===PHP_INT_MAX) || ($oldest>=$winStartMs);
                        if ($lastId && $hasNext && $moreOld && $st['page']<$pageCap) { $addHandle($cid,$lastId,$st['page']+1); $advance=false; }
                    }
                }
            }
            if ($advance) {
                if ($st) { $cid=$st['cid']; if(!empty($buf[$cid])) $processConversa($buf[$cid]); unset($buf[$cid]); }
                $done++; if ($done%300===0) painel_log("proc {$done}/{$total}");
                if ($queue) { $ncid=array_shift($queue); $buf[$ncid]=[]; $addHandle($ncid,null,0); }
            }
        }
    } while ($running || $state || $queue);
    curl_multi_close($mh);
    painel_log("conversas processadas: {$total}");

    /* ===== 3) Converte janela + funde com dias congelados ===== */
    $hm = fn(?int $ms) => $ms ? (new DateTime('@'.intdiv($ms,1000)))->setTimezone($TZ)->format('H:i') : null;
    $winDays = [];
    foreach ($acc as $bid=>$days) {
        foreach ($days as $k=>$d) {
            $winDays[$bid][$k] = ['n'=>$d['n'],'app'=>$d['app'],'api'=>$d['api'],'ic'=>$d['ic'],'cl'=>$d['cl'],
                'first'=>$hm($d['first']),'last'=>$hm($d['last']),'pfirst'=>$hm($d['pfirst']),'plast'=>$hm($d['plast']),
                'mh'=>$d['mh'],'ah'=>$d['ah'],'rt_n'=>$d['rt_n'],'rt_sum'=>$d['rt_sum'],'rt_hist'=>$d['rt_hist']];
        }
    }
    $final = [];
    if ($incremental && isset($cache['days']) && is_array($cache['days'])) {
        foreach ($cache['days'] as $bid=>$days) {
            if (!is_array($days)) continue;
            foreach ($days as $day=>$rec) if ($day < $winStartD) $final[$bid][$day]=$rec;
        }
    }
    foreach ($winDays as $bid=>$days) foreach ($days as $day=>$rec) $final[$bid][$day]=$rec;

    @file_put_contents($cacheFile, json_encode(
        ['month'=>$monthKey,'last_run_date'=>$todayD,'days'=>$final],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    /* ===== 4) Monta agregado do mês ===== */
    $out = [];
    foreach ($brokersRows as $b) {
        $days = $final[$b['id']] ?? [];
        $out[] = ['id'=>$b['id'],'name'=>$b['nome'],'email'=>$b['email'],
                  'ativo'=>(int)$b['ativo'],'days'=>(object)$days];
    }
    $WD=['seg','ter','qua','qui','sex','sab','dom']; $alldays=[]; $dd=clone $mStart;
    while ($dd->getTimestamp() <= $mEnd->getTimestamp()) {
        $w=(int)$dd->format('N')-1;
        $alldays[]=['key'=>$dd->format('Y-m-d'),'label'=>$dd->format('d/m'),'wd'=>$WD[$w],'weekend'=>$w>=5];
        $dd->modify('+1 day');
    }
    /* ---- atualiza série horária de não-lidas (só mês corrente) ---- */
    $live = null;
    if ($ehMesCorrente) {
        $liveFile = $dir . '/painel_live.json';
        $prev = is_readable($liveFile) ? (json_decode((string)file_get_contents($liveFile), true) ?: []) : [];
        $series = $prev['series'] ?? [];
        $series[] = ['t'=>$now->format('Y-m-d H:i'), 'u'=>$unread_client];   // série = clientes aguardando
        $corte = ($now->getTimestamp() - 14*86400);   // mantém ~14 dias de pontos
        $series = array_values(array_filter($series, function($p) use ($corte,$TZ){
            $t=DateTime::createFromFormat('Y-m-d H:i',$p['t'],$TZ); return $t && $t->getTimestamp()>=$corte;
        }));
        $live = ['generated'=>$now->format('d/m/Y H:i'),'unread_client'=>$unread_client,
                 'unread_followup'=>$unread_followup,'wait24'=>$wait24,'series'=>$series];
        @file_put_contents($liveFile, json_encode($live, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $agg = ['period'=>['start'=>$mStart->getTimestamp()*1000,'end'=>$END,
                'start_str'=>$mStart->format('d/m/Y'),'end_str'=>$mEnd->format('d/m/Y'),
                'month'=>$monthKey,'generated'=>$now->format('d/m/Y H:i')],
            'brokers'=>$out,'alldays'=>$alldays,'live'=>$live];
    $json = json_encode($agg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($aggFile, $json) === false) {
        painel_log('ERRO ao gravar '.$aggFile); status_write(['state'=>'error','msg'=>'falha ao gravar']);
        if($lockF){flock($lockF,LOCK_UN);fclose($lockF);} return;
    }
    /* compat: mantém presenca_agg.json apontando para o mês corrente */
    if ($ehMesCorrente) @copy($aggFile, $dir.'/presenca_agg.json');

    $fim = new DateTime('now', $TZ);
    status_write(['state'=>'done','mode'=>($incremental?'incremental':'full').($mode==='month'?" · {$monthKey}":''),
        'started'=>$now->format('d/m H:i'),'finished'=>$fim->format('d/m H:i'),
        'conversas'=>$total,'mes'=>$monthKey]);
    painel_log('OK — '.strlen($json).' bytes · conversas '.$total.' · mês '.$monthKey);
    if ($lockF) { flock($lockF, LOCK_UN); fclose($lockF); }
}

/* =====================================================================
   Disparo em segundo plano (botão "atualizar agora" do admin)
   ===================================================================== */
function spawn_background(string $log, string $arg = '--incr'): bool {
    if (!function_exists('exec')) return false;
    $disabled = array_map('trim', explode(',', (string)ini_get('disabled_functions')));
    if (in_array('exec', $disabled, true)) return false;
    $cmd = sprintf('nohup /usr/bin/php %s %s >> %s 2>&1 & echo ok',
        escapeshellarg(__FILE__), escapeshellarg($arg), escapeshellarg($log));
    @exec($cmd, $o, $rc);
    return $rc === 0;
}
function render_started_page(bool $spawned, string $extra = ''): void {
    $css = '/assets/portal.css?v=' . (@filemtime(dirname(__DIR__) . '/assets/portal.css') ?: 1);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta http-equiv="refresh" content="6;url=/painel-corretores/">'
       . '<title>Atualizando…</title><link rel="stylesheet" href="' . $css . '"></head>'
       . '<body class="tela-login"><div class="cartao-login" style="max-width:460px">'
       . '<h1>Atualização iniciada</h1>'
       . '<p class="sub">A coleta está rodando no servidor em segundo plano' . $extra . '. '
       . 'Leva alguns minutos; a data de atualização muda quando terminar.</p>'
       . '<p><a href="/painel-corretores/">Voltar ao painel</a></p></div></body></html>';
}

/* =====================================================================
   Roteamento CLI x Web
   ===================================================================== */
if (PHP_SAPI === 'cli') {
    portal_load_config();
    $args = array_slice($argv, 1); $mode='incremental'; $monthArg=null;
    foreach ($args as $a) {
        if ($a === '--full') $mode='full';
        elseif ($a === '--incr') $mode='incremental';
        elseif (preg_match('/^\d{4}-\d{2}$/', $a)) { $mode='month'; $monthArg=$a; }
    }
    run_collection($mode, $monthArg);
    exit;
}

/* --- Web: só admin --- */
require_once __DIR__ . '/../lib/auth.php';
$u = current_user();
if (!$u || $u['papel'] !== 'admin') { http_response_code(403); exit('Apenas admin.'); }
portal_load_config();
header('Content-Type: text/html; charset=utf-8');
$log = painel_data_dir() . '/coletor.log';
$mes = $_GET['mes'] ?? '';
if (preg_match('/^\d{4}-\d{2}$/', $mes)) {
    status_write(['state'=>'running','mode'=>"backfill {$mes}",'started'=>(new DateTime('now',new DateTimeZone('-03:00')))->format('d/m H:i')]);
    $spawned = spawn_background($log, $mes);
    render_started_page($spawned, " (mês {$mes})");
    if (!$spawned) {
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(0); @ignore_user_abort(true);
        run_collection('month', $mes);
    }
    exit;
}
status_write(['state'=>'running','mode'=>'manual','started'=>(new DateTime('now',new DateTimeZone('-03:00')))->format('d/m H:i')]);
$spawned = spawn_background($log, '--incr');
render_started_page($spawned);
if (!$spawned) {
    if (function_exists('litespeed_finish_request')) litespeed_finish_request();
    elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    @set_time_limit(0); @ignore_user_abort(true);
    run_collection('incremental');
}
