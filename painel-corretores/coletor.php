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
require_once __DIR__ . '/../lib/blocklist.php';

const PAINEL_CONCURRENCY = 8;
// Horário comercial p/ tempo de resposta (horário de Brasília):
//   seg–sex 8h–20h · sábado 8h30–11h30 · domingo não conta.
// O tempo de resposta é medido em SEGUNDOS COMERCIAIS DECORRIDOS (noites e
// domingos não contam), então uma resposta que atravessa a madrugada não é
// penalizada — e a resposta genuinamente lenta deixa de ser descartada.
const RT_CAP     = 216000;               // teto sanitário: 5 dias úteis (60h comerciais)
const UNREAD_LOOKBACK_DAYS = 12;         // até onde varrer conversas p/ não-lidas

/* O instante (ms) caiu dentro do horário comercial? (seg–sex 8–20, sáb 8:30–11:30). */
function in_biz(int $ms, DateTimeZone $TZ): bool {
    $d = (new DateTime('@'.intdiv($ms,1000)))->setTimezone($TZ);
    $dow = (int)$d->format('N');
    $sod = (int)$d->format('G')*3600 + (int)$d->format('i')*60 + (int)$d->format('s');
    if ($dow >= 1 && $dow <= 5) return $sod >= 28800 && $sod < 72000;   // 8:00–20:00
    if ($dow === 6)             return $sod >= 30600 && $sod < 41400;   // 8:30–11:30
    return false;                                                       // domingo
}

/* Segundos de horário comercial decorridos entre dois instantes (ms). */
function biz_seconds(int $aMs, int $bMs, DateTimeZone $TZ): int {
    if ($bMs <= $aMs) return 0;
    $cur = intdiv($aMs, 1000); $end = intdiv($bMs, 1000); $sec = 0; $guard = 0;
    while ($cur < $end && $guard++ < 400) {
        $d   = (new DateTime('@'.$cur))->setTimezone($TZ);
        $dow = (int)$d->format('N');   // 1=seg … 7=dom
        $sod = (int)$d->format('G')*3600 + (int)$d->format('i')*60 + (int)$d->format('s');
        $nextMid = $cur - $sod + 86400;
        $stepEnd = min($end, $nextMid);
        if     ($dow >= 1 && $dow <= 5) { $ini = 28800; $fim = 72000; }  // 8:00–20:00
        elseif ($dow === 6)             { $ini = 30600; $fim = 41400; }  // 8:30–11:30
        else                            { $ini = 0;     $fim = 0;     }  // domingo
        if ($fim > $ini) {
            $lo = max($sod, $ini);
            $hi = min($sod + ($stepEnd - $cur), $fim);
            if ($hi > $lo) $sec += ($hi - $lo);
        }
        $cur = $stepEnd;
    }
    return $sec;
}

/* canais em que a mensagem é REALMENTE do cliente (não é atividade do sistema,
   comentário interno, "opportunity updated" nem registro de ligação). Usado
   na Fila de atendimento para mostrar a última fala de fato do cliente. */
const CLIENT_MSG_TYPES = [
    'TYPE_WHATSAPP','TYPE_CUSTOM_SMS','TYPE_SMS','TYPE_INSTAGRAM','TYPE_FACEBOOK',
    'TYPE_MESSENGER','TYPE_EMAIL','TYPE_CUSTOM_EMAIL','TYPE_GMB','TYPE_LIVE_CHAT',
    'TYPE_WEBCHAT','TYPE_REVIEW',
];

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

/* Escrita atômica (temp + rename): o modo 'fila' (a cada poucos min) e a coleta
   pesada podem gravar os mesmos arquivos de fila; rename evita arquivo pela metade. */
function atomic_put(string $path, string $data): bool {
    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $data) === false) return false;
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
}

/* =====================================================================
   Coleta principal. $mode: 'incremental' | 'full' | 'month' | 'fila'
   'fila' = delta leve: só a busca de conversas + a fila de quem aguarda
   (painel_live.json + aguardando.json), sem reprocessar o agregado pesado.
   ===================================================================== */
function run_collection(string $mode, ?string $monthArg = null): void {
    portal_load_config();
    $TZ  = new DateTimeZone('-03:00');
    $now = new DateTime('now', $TZ);
    $dir = painel_data_dir();

    // Backfill de mês passado usa uma trava PRÓPRIA, para não bloquear a coleta
    // do mês corrente (cron de hora em hora) enquanto roda.
    $isBackfill = ($mode === 'month' && $monthArg && $monthArg !== $now->format('Y-m'));
    // 'fila' compartilha a trava do mês corrente com a coleta pesada para não
    // gravarem os mesmos arquivos ao mesmo tempo.
    // Não-bloqueante: se já há uma coleta rodando (pesada OU fila), esta sai e
    // deixa a outra terminar — quem estiver rodando já produz dados frescos.
    // Assim nenhuma coleta espera nem sobrescreve a outra (a fila roda espaçada,
    // colisão é rara; a gravação dos arquivos é atômica de qualquer forma).
    $lockF = fopen($dir . '/' . ($isBackfill ? 'coletor_backfill.lock' : 'coletor.lock'), 'c');
    if ($lockF && !flock($lockF, LOCK_EX | LOCK_NB)) {
        painel_log(($mode === 'fila' ? 'fila: ' : '') . 'outra coleta em andamento. Saindo.');
        fclose($lockF); return;
    }

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

    // O modo 'fila' NÃO mexe no status.json: a "última coleta" mostrada no painel
    // deve continuar sendo a da coleta pesada (senão o delta deixa o status preso
    // em "running", já que ele não escreve o 'done' do fim).
    if ($mode !== 'fila') {
        status_write(['state' => 'running', 'mode' => ($incremental ? 'incremental' : 'full') . ($mode==='month'?" · {$monthKey}":''),
            'started' => $now->format('d/m H:i'), 'since_win' => $winStartD]);
    }
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
    $cursor = null; $convIds = []; $pages = 0; $CONV_ASS = []; $CONV_CONTACT = [];
    $unread_client = []; $unread_followup = []; $wait24 = []; $waitList = [];
    $nowMs = $now->getTimestamp()*1000; $dia1Ms = $nowMs - 86400*1000;
    // Lista de Bloqueio: se ligada para o painel, conversas de números bloqueados
    // são ignoradas por completo (não entram em nada).
    $BLOCK = blocklist_ativa('painel-corretores') ? blocklist_set() : [];
    if ($BLOCK) painel_log('lista de bloqueio ativa: '.count($BLOCK).' número(s)');
    $nBloq = 0;
    while ($pages < 500) {
        $url = $cursor === null ? $base : $base . '&startAfterDate=' . urlencode((string)$cursor);
        $j = ghl_get($url, $HDR); $cs = ($j['conversations'] ?? []);
        if (!$cs) break; $pages++; $algumRecente = false;
        foreach ($cs as $c) {
            if ($BLOCK && fone_bloqueado((string)($c['phone'] ?? ''), $BLOCK)) { $nBloq++; continue; }
            $lmd = (int)($c['lastMessageDate'] ?? 0);
            if ($lmd >= $winStartMs) {
                // No backfill de mês passado, só busca mensagens de conversas que
                // JÁ EXISTIAM no mês-alvo (dateAdded <= fim do mês). Evita varrer
                // as milhares de conversas criadas depois — que não têm mensagem
                // no mês e só encareceriam a coleta.
                $ok = $ehMesCorrente;
                if (!$ok) { $da = parse_ms((string)($c['dateAdded'] ?? '')); $ok = ($da === 0 || $da <= $END); }
                if ($ok) { $convIds[] = $c['id']; $CONV_ASS[$c['id']] = $c['assignedTo'] ?? null;
                           $CONV_CONTACT[$c['id']] = (string)($c['contactId'] ?? ''); }
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
                        $nm = (string)($c['contactName'] ?? ''); if ($nm === '') $nm = (string)($c['fullName'] ?? '');
                        $waitList[] = ['id'=>$c['id'], 'name'=>($nm !== '' ? $nm : 'Sem nome'),
                            'phone'=>(string)($c['phone'] ?? ''), 'ass'=>$ass, 'since'=>$lmd,
                            'type'=>(string)($c['lastMessageType'] ?? ''),
                            'contact'=>(string)($c['contactId'] ?? '')];
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
    painel_log('conversas na janela: ' . count($convIds) . ' · clientes aguardando: ' . array_sum($unread_client) . ' · follow-up: ' . array_sum($unread_followup) . ($BLOCK ? " · bloqueadas: $nBloq" : ''));

    /* ===== 2) Mensagens por conversa (pool) — acumula e processa a conversa =====
       Parte PESADA (reprocessa ~todas as conversas ativas da janela). Pulada no
       modo 'fila' — que só precisa do retrato de quem aguarda (passo 1 + 2.5). */
    if ($mode !== 'fila') {
    $acc = [];   // acc[uid][YYYY-MM-DD] = registro-dia
    // 'rt' = lista de tempos de resposta do dia (segundos comerciais decorridos);
    // guardar a lista crua permite mediana E percentil (P85) exatos no painel.
    // 'cts' = contatos distintos que o corretor atendeu no dia → {contactId: 0|1},
    // 1 = houve interação (o cliente também respondeu). Permite contar Conversas
    // e Conversas-com-interação distintas no período (união dos dias no painel).
    $novoDia = fn() => ['n'=>0,'app'=>0,'api'=>0,'ic'=>0,'cl'=>0,'in'=>0,'first'=>null,'last'=>null,
                        'pfirst'=>null,'plast'=>null,'mh'=>array_fill(0,24,0),'ah'=>array_fill(0,24,0),
                        'rt_n'=>0,'rt_sum'=>0,'rt'=>[], 'cts'=>[]];

    // $assBroker = corretor responsável pela conversa (assignedTo). Serve para
    // atribuir as mensagens RECEBIDAS do cliente (inbound) ao dia dele — isso
    // mede "demanda" (houve cliente falando) e gatilha o bloco de abandono só
    // quando de fato havia o que atender.
    $processConversa = function (array $msgs, ?string $assBroker, string $contactId = '') use (&$acc, $BIDS, $CUT, $CLIENT, $winStartMs, $END, $TZ, $novoDia) {
        /* ordena crescente por tempo */
        usort($msgs, fn($a,$b)=>$a['e'] <=> $b['e']);
        // a conversa teve recado do cliente na janela? (p/ "conversa com interação")
        $temInbound = false;
        foreach ($msgs as $m) {
            if (($m['dir'] ?? '')==='inbound' && isset($CLIENT[$m['mt']])
                && $winStartMs <= $m['e'] && $m['e'] < $END) { $temInbound = true; break; }
        }
        // Quem "fez" a mensagem outbound, para creditar por corretor:
        //  - autor conhecido (userId de corretor) → ele mesmo;
        //  - celular do corretor (STEVO, sem autor, assinatura "Source:") → dono
        //    do contato (assignedTo) no momento da coleta.
        $ator = function(array $m) use ($BIDS, $assBroker) {
            $uid = $m['uid'] ?? null;
            if ($uid !== null && isset($BIDS[$uid])) return $uid;
            if ($uid === null && !empty($m['stevo']) && $assBroker !== null && isset($BIDS[$assBroker])) return $assBroker;
            return null;
        };
        /* --- presença (contagem por autor) + demanda (inbound do cliente) --- */
        foreach ($msgs as $m) {
            $e = $m['e']; if (!($winStartMs <= $e && $e < $END)) continue;
            if ($m['dir'] === 'inbound') {
                // mensagem de fato do cliente (canal real) atribuída ao responsável
                if ($assBroker !== null && isset($BIDS[$assBroker]) && isset($CLIENT[$m['mt']])
                    && !(isset($CUT[$assBroker]) && $e > $CUT[$assBroker])) {
                    $dloc = (new DateTime('@'.intdiv($e,1000)))->setTimezone($TZ);
                    $key = $dloc->format('Y-m-d');
                    if (!isset($acc[$assBroker])) $acc[$assBroker] = [];
                    if (!isset($acc[$assBroker][$key])) $acc[$assBroker][$key] = $novoDia();
                    $acc[$assBroker][$key]['in']++;
                }
                continue;
            }
            if ($m['dir'] !== 'outbound') continue;
            $uid = $ator($m);                         // corretor a quem creditar (ou null)
            if ($uid === null) continue;
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
                // conversa: registra o contato atendido (1 se houve interação)
                if ($contactId !== '') $d['cts'][$contactId] = max($d['cts'][$contactId] ?? 0, $temInbound ? 1 : 0);
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
            $src = $m['src']; $mt = $m['mt'];
            $manual = ($src === 'app' || $src === 'api') && isset($CLIENT[$mt]);
            if (!$manual) continue;                       // automação não "responde"
            if ($pend === null) continue;                 // resposta sem inbound pendente
            $uid = $ator($m);                             // resposta do celular conta p/ o dono
            if ($uid === null) { $pend = null; continue; }
            if (isset($CUT[$uid]) && $e > $CUT[$uid]) { $pend = null; continue; }
            // Só conta recados que CHEGARAM em horário comercial (o cliente falou
            // durante o expediente). O tempo é medido em segundos COMERCIAIS
            // decorridos — noite/domingo não contam, então resposta que atravessa
            // a madrugada não é penalizada e a lenta deixa de ser descartada.
            // Teto sanitário de 5 dias úteis só p/ par quebrado.
            $delta = biz_seconds($pend, $e, $TZ);
            $iloc = (new DateTime('@'.intdiv($pend,1000)))->setTimezone($TZ);
            $ikey = $iloc->format('Y-m-d');
            if ($delta > 0 && $delta <= RT_CAP && in_biz($pend, $TZ)
                && $winStartMs <= $pend && $pend < $END) {
                if (!isset($acc[$uid])) $acc[$uid] = [];
                if (!isset($acc[$uid][$ikey])) $acc[$uid][$ikey] = $novoDia();
                $acc[$uid][$ikey]['rt_n']++;
                $acc[$uid][$ikey]['rt_sum'] += $delta;
                $acc[$uid][$ikey]['rt'][] = $delta;
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
                            // STEVO = mensagem que o corretor mandou do celular: outbound,
                            // source api, SEM userId, e com a assinatura "Source: <handle>"
                            // no corpo (as notificações de sistema da integração NÃO têm).
                            $stevo = (($m['source']??'')==='api') && empty($m['userId'])
                                  && ($m['direction']??'')==='outbound'
                                  && preg_match('/\n\s*Source:\s*\S+\s*$/', (string)($m['body']??''));
                            $buf[$cid][]=['e'=>$e,'dir'=>($m['direction']??''),'src'=>($m['source']??''),
                                          'mt'=>($m['messageType']??''),'uid'=>($m['userId']??null),
                                          'stevo'=>$stevo?1:0];
                        }
                        $lastId=$data['lastMessageId']??null; $hasNext=!empty($data['nextPage']);
                        $moreOld = ($oldest===PHP_INT_MAX) || ($oldest>=$winStartMs);
                        if ($lastId && $hasNext && $moreOld && $st['page']<$pageCap) { $addHandle($cid,$lastId,$st['page']+1); $advance=false; }
                    }
                }
            }
            if ($advance) {
                if ($st) { $cid=$st['cid']; if(!empty($buf[$cid])) $processConversa($buf[$cid], $CONV_ASS[$cid] ?? null, $CONV_CONTACT[$cid] ?? ''); unset($buf[$cid]); }
                $done++; if ($done%300===0) painel_log("proc {$done}/{$total}");
                if ($queue) { $ncid=array_shift($queue); $buf[$ncid]=[]; $addHandle($ncid,null,0); }
            }
        }
    } while ($running || $state || $queue);
    curl_multi_close($mh);
    painel_log("conversas processadas: {$total}");
    } // fim do bloco pesado (pulado no modo 'fila')

    /* ===== 2.5) Fila de atendimento: conteúdo da última msg de quem aguarda ===== */
    if ($ehMesCorrente && $waitList) {
        if (count($waitList) > 400) $waitList = array_slice($waitList, 0, 400);
        $bodies = []; $mh2 = curl_multi_init(); $st2 = []; $q2 = $waitList;
        $addB = function(array $item) use ($mh2, &$st2, $HDR) {
            // Busca as últimas mensagens (não só a última do fio, que pode ser
            // atividade do sistema / comentário interno) para achar a última
            // mensagem de FATO do cliente.
            $ch = curl_init("https://services.leadconnectorhq.com/conversations/{$item['id']}/messages?limit=25");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$HDR, CURLOPT_TIMEOUT=>20]);
            curl_multi_add_handle($mh2, $ch); $st2[(int)$ch] = $item;
        };
        while (count($st2) < PAINEL_CONCURRENCY && $q2) $addB(array_shift($q2));
        // limpa metadados que o GHL às vezes anexa ao corpo da mensagem
        $limpaBody = function(string $bd): string {
            $bd = preg_replace('/\s*\n\s*Source:.*$/is', '', $bd);
            $bd = preg_replace('/^\s*id:\s*\S+\s*\n/im', '', $bd);
            return trim($bd);
        };
        // Interpreta a resposta de /messages -> ['text','type','thread','ok'].
        // ok=false quando a chamada falhou (corpo vazio/sem JSON) — permite
        // um retry só das conversas que não vieram (evita "buracos" na fila).
        $parseMsgs = function(?string $body) use ($limpaBody, $TZ): array {
            $text=''; $realType=''; $thread=[]; $ok=false;
            if ($body) {
                $jj=json_decode($body,true);
                if (is_array($jj) && isset($jj['messages'])) {
                    $ok=true; $ms=$jj['messages']['messages']??[];
                    usort($ms, function($a,$b){
                        return (strtotime((string)($a['dateAdded']??'')) ?: 0)
                             - (strtotime((string)($b['dateAdded']??'')) ?: 0);
                    });
                    foreach ($ms as $m) {
                        $mdir = (($m['direction'] ?? '') === 'inbound') ? 'in' : 'out';
                        $mt  = (string)($m['messageType'] ?? '');
                        $bd  = $limpaBody((string)($m['body'] ?? ''));
                        if ($mt === 'TYPE_INTERNAL_COMMENT')         $kind = 'int';
                        elseif (strpos($mt, 'TYPE_ACTIVITY') === 0)  $kind = 'sys';
                        elseif ($mt === 'TYPE_CALL')                 $kind = 'call';
                        elseif (in_array($mt, CLIENT_MSG_TYPES, true)) $kind = 'msg';
                        else                                         $kind = 'sys';
                        if ($kind === 'call' && $bd === '') $bd = '(ligação)';
                        if ($bd === '' && $kind !== 'msg') continue;
                        $ts = strtotime((string)($m['dateAdded'] ?? ''));
                        $thread[] = ['dir'=>$mdir, 'kind'=>$kind,
                            't'=>$ts ? (new DateTime('@'.$ts))->setTimezone($TZ)->format('d/m H:i') : '',
                            'body'=>mb_substr($bd, 0, 600)];
                        if ($mdir === 'in' && $kind === 'msg' && $bd !== '') { $text=$bd; $realType=$mt; }
                    }
                }
            }
            return ['text'=>$text, 'type'=>$realType, 'thread'=>$thread, 'ok'=>$ok];
        };
        do {
            curl_multi_exec($mh2, $run2); curl_multi_select($mh2, 1.0);
            while ($info = curl_multi_info_read($mh2)) {
                $ch=$info['handle']; $item=$st2[(int)$ch]??null; unset($st2[(int)$ch]);
                $body=curl_multi_getcontent($ch); curl_multi_remove_handle($mh2,$ch); curl_close($ch);
                if ($item) $bodies[$item['id']] = $parseMsgs($body);
                if ($q2) $addB(array_shift($q2));
            }
        } while ($run2 || $st2 || $q2);
        curl_multi_close($mh2);
        // Retry (sequencial, timeout maior) das conversas cuja 1ª chamada falhou
        // ou veio sem nenhuma mensagem real — cobre timeouts/rate-limit pontuais.
        $retry = array_filter($waitList, function($it) use ($bodies) {
            $b = $bodies[$it['id']] ?? null;
            return !$b || !$b['ok'] || empty($b['thread']);
        });
        foreach ($retry as $it) {
            $ch = curl_init("https://services.leadconnectorhq.com/conversations/{$it['id']}/messages?limit=25");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>$HDR, CURLOPT_TIMEOUT=>30]);
            $body = curl_exec($ch); curl_close($ch);
            $r = $parseMsgs($body);
            if ($r['ok'] && (!isset($bodies[$it['id']]) || !empty($r['thread']))) $bodies[$it['id']] = $r;
        }
        $bn = []; foreach ($brokersRows as $b) $bn[$b['id']] = $b['nome'];
        $aguardando = [];
        foreach ($waitList as $it) {
            $bi = $bodies[$it['id']] ?? ['text'=>'','type'=>'','thread'=>[]];
            $aguardando[] = ['name'=>$it['name'], 'phone'=>$it['phone'], 'broker_id'=>$it['ass'],
                'broker'=>($bn[$it['ass']] ?? $it['ass']), 'since'=>$it['since'],
                'type'=>($bi['type'] !== '' ? $bi['type'] : $it['type']),
                'conv'=>$it['id'],
                'contact'=>($it['contact'] ?? ''),
                'text'=>trim(mb_substr(trim((string)($bi['text'] ?? '')), 0, 400)),
                'thread'=>($bi['thread'] ?? [])];
        }
        $agJson = json_encode(
            ['generated'=>$now->format('d/m/Y H:i'), 'gen_ms'=>$now->getTimestamp()*1000, 'items'=>$aguardando],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        atomic_put($dir.'/aguardando.json', (string)$agJson);
        painel_log('fila de atendimento: '.count($aguardando).' clientes aguardando');
    }

    /* ===== fila (delta leve): grava retrato ao vivo + fila e retorna, sem tocar
       no agregado pesado. Se ninguém aguarda, grava fila vazia (para "limpar"). */
    if ($mode === 'fila') {
        if (!$waitList) {
            atomic_put($dir.'/aguardando.json', (string)json_encode(
                ['generated'=>$now->format('d/m/Y H:i'),'gen_ms'=>$now->getTimestamp()*1000,'items'=>[]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $liveFile = $dir . '/painel_live.json';
        $prev = is_readable($liveFile) ? (json_decode((string)file_get_contents($liveFile), true) ?: []) : [];
        $live = ['generated'=>$now->format('d/m/Y H:i'),'unread_client'=>$unread_client,
                 'unread_followup'=>$unread_followup,'wait24'=>$wait24,'series'=>($prev['series'] ?? [])];
        atomic_put($liveFile, (string)json_encode($live,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        // NÃO escreve status.json: a "última coleta" continua sendo a pesada.
        painel_log('fila (delta) OK — '.count($unread_client).' corretor(es) com aguardando · '
                   .array_sum($unread_client).' cliente(s) · '.$pages.' págs de busca');
        if ($lockF) { flock($lockF, LOCK_UN); fclose($lockF); }
        return;
    }

    /* ===== 3) Converte janela + funde com dias congelados ===== */
    $hm = fn(?int $ms) => $ms ? (new DateTime('@'.intdiv($ms,1000)))->setTimezone($TZ)->format('H:i') : null;
    $winDays = [];
    foreach ($acc as $bid=>$days) {
        foreach ($days as $k=>$d) {
            $winDays[$bid][$k] = ['n'=>$d['n'],'app'=>$d['app'],'api'=>$d['api'],'ic'=>$d['ic'],'cl'=>$d['cl'],'in'=>$d['in'],
                'first'=>$hm($d['first']),'last'=>$hm($d['last']),'pfirst'=>$hm($d['pfirst']),'plast'=>$hm($d['plast']),
                'mh'=>$d['mh'],'ah'=>$d['ah'],'rt_n'=>$d['rt_n'],'rt_sum'=>$d['rt_sum'],'rt'=>$d['rt'],'cts'=>$d['cts']];
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
        atomic_put($liveFile, (string)json_encode($live, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
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
        elseif ($a === '--fila') $mode='fila';   // delta leve da fila (cron a cada 2 min)
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
// Recoleta COMPLETA do mês corrente (recomputa todos os dias, ignorando o
// cache) — usar quando muda o formato dos dados coletados (ex.: passou a
// contar mensagens recebidas por dia). ?full=1
// Delta leve da fila (só o retrato de quem aguarda) — ?fila=1. Não mexe no
// agregado pesado. Serve para testar manualmente; o cron usa a CLI (--fila).
if (($_GET['fila'] ?? '') === '1') {
    $spawned = spawn_background($log, '--fila');
    if (!$spawned) {
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(0); @ignore_user_abort(true);
        run_collection('fila');
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'fila: delta disparado' . ($spawned ? ' (segundo plano)' : ' (inline)');
    exit;
}
if (($_GET['full'] ?? '') === '1') {
    status_write(['state'=>'running','mode'=>'full','started'=>(new DateTime('now',new DateTimeZone('-03:00')))->format('d/m H:i')]);
    $spawned = spawn_background($log, '--full');
    render_started_page($spawned, ' (recoleta completa do mês)');
    if (!$spawned) {
        if (function_exists('litespeed_finish_request')) litespeed_finish_request();
        elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        @set_time_limit(0); @ignore_user_abort(true);
        run_collection('full');
    }
    exit;
}
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
