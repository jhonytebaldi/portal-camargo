<?php
/* =====================================================================
   geocodificar.php — converte o endereço dos imóveis em coordenadas.

   Por que existe: só ~25% dos imóveis têm latitude/longitude preenchidas
   no Robust, mas quase todos têm rua e número. Sem coordenada, os cards
   só conseguem dizer "mesmo bairro" em vez da distância até o shopping,
   o terminal, a escola.

   Usa o Nominatim (OpenStreetMap), que é gratuito e exige:
     - no máximo 1 consulta por segundo
     - User-Agent identificando quem está chamando
   Por isso o script é lento de propósito (~2s por imóvel).

   COMO USAR
     Teste primeiro (5 imóveis, não grava nada):
       https://seusite.com.br/geocodificar.php?teste=1
     Rodar de verdade:
       https://seusite.com.br/geocodificar.php?executar=1
     Pelo terminal/cron:
       php geocodificar.php --executar

   É seguro repetir: endereços já resolvidos ficam em cache e não são
   consultados de novo. Se parar no meio, é só rodar outra vez.
   ===================================================================== */

require_once __DIR__ . '/config.php';

$viaCli = php_sapi_name() === 'cli';
if (!$viaCli) {
    session_start();
    if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Faça login primeiro.'); }
    header('Content-Type: text/plain; charset=utf-8');
    while (ob_get_level()) ob_end_flush();
    ob_implicit_flush(true);
}

$args     = $viaCli ? implode(' ', array_slice($argv, 1)) : '';
$soTeste  = $viaCli ? str_contains($args, '--teste')   : !empty($_GET['teste']);
$executar = $viaCli ? str_contains($args, '--executar'): !empty($_GET['executar']);
$limite   = (int)($viaCli ? 0 : ($_GET['limite'] ?? 0));

$diagnostico = $viaCli ? str_contains($args, '--situacao') : !empty($_GET['situacao']);

if (!$soTeste && !$executar && !$diagnostico) {
    echo "Nada foi feito.\n\n";
    echo "  ?situacao=1  mostra o que já foi feito, sem consultar nada\n";
    echo "  ?teste=1     consulta 5 endereços e mostra o resultado, sem gravar\n";
    echo "  ?executar=1  processa todos os pendentes e grava\n";
    echo "  &limite=30   processa no máximo 30 nesta rodada\n";
    echo "  ?limpar=1    apaga as tentativas que falharam, para começar do zero\n";
    exit;
}

define('ARQ_GEO', DATA_DIR . '/geocode.json');

// Limites de Joinville e arredores. Resultado fora daqui é descartado:
// endereço mal escrito costuma cair em outra cidade com nome parecido.
const LAT_MIN = -26.90, LAT_MAX = -25.90, LNG_MIN = -49.40, LNG_MAX = -48.40;

$cache = file_exists(ARQ_GEO) ? (json_decode(@file_get_contents(ARQ_GEO), true) ?: []) : [];

// Apaga só as tentativas sem resultado, preservando as que deram certo.
if ($viaCli ? str_contains($args, '--limpar') : !empty($_GET['limpar'])) {
    $antes = count($cache);
    foreach ($cache as $k => $v) if (empty($v['la'])) unset($cache[$k]);
    file_put_contents(ARQ_GEO, json_encode($cache, JSON_UNESCAPED_UNICODE));
    echo 'Removidas ' . ($antes - count($cache)) . " tentativas sem resultado.\n";
    echo "Coordenadas boas mantidas: " . count($cache) . "\n\n";
}

if (!file_exists(ARQ_MERGE)) exit("Base vazia. Rode o sync antes.\n");
$imoveis = json_decode(file_get_contents(ARQ_MERGE), true)['imoveis'] ?? [];

/** Reduz o endereço ao essencial, para saber se mudou desde a última vez. */
function normalizaParaComparar($e) {
    $e = strtolower(trim((string)$e));
    $de = ['á','à','ã','â','é','ê','í','ó','ô','õ','ú','ç'];
    $para = ['a','a','a','a','e','e','i','o','o','o','u','c'];
    $e = str_replace($de, $para, $e);
    $e = preg_replace('/[^a-z0-9 ]/', ' ', $e);
    return trim(preg_replace('/\s+/', ' ', $e));
}

/** Separa o endereço em rua e número. */
function partesEndereco(array $x): ?array {
    $rua = trim((string)($x['e'] ?? ''));
    if ($rua === '') return null;
    // "Rua Willy Tilp 768, Unidade 03" -> descarta o complemento
    $rua = preg_split('/,|\s+-\s+|\s+(?:apto|ap|casa|unidade|bloco|qd|lt)\b/i', $rua)[0];
    $rua = trim(preg_replace('/\s+/', ' ', $rua));
    if ($rua === '') return null;

    // O número vem colado no fim ("Rua Martin Pescador 463"). O Nominatim
    // precisa dele separado por vírgula, senão trata tudo como nome de rua
    // e não encontra nada.
    $num = null;
    if (preg_match('/^(.*?)[,\s]+(\d{1,6})$/u', $rua, $m)) {
        $rua = trim($m[1]);
        $num = $m[2];
    }
    return ['rua' => $rua, 'num' => $num,
            'bairro' => trim((string)($x['b'] ?? '')),
            'cidade' => trim((string)($x['ci'] ?? '')) ?: 'Joinville'];
}

/** Monta as variações a tentar, da mais precisa para a mais tolerante. */
function tentativasEndereco(array $p): array {
    $t = [];
    $b = $p['bairro'] !== '' ? $p['bairro'] . ', ' : '';
    if ($p['num']) {
        $t[] = "{$p['rua']}, {$p['num']}, {$b}{$p['cidade']}, SC, Brasil";
        $t[] = "{$p['rua']}, {$p['num']}, {$p['cidade']}, SC, Brasil";
    }
    // Sem número: cai no meio da rua, o que ainda é muito melhor que nada.
    $t[] = "{$p['rua']}, {$b}{$p['cidade']}, SC, Brasil";
    $t[] = "{$p['rua']}, {$p['cidade']}, SC, Brasil";
    return array_values(array_unique($t));
}

/** Consulta o Nominatim. Devolve [lat, lng, qualidade] ou null. */
function geocodificar(string $endereco): ?array {
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $endereco, 'format' => 'json', 'limit' => 1,
        'countrycodes' => 'br', 'addressdetails' => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_USERAGENT      => 'ImobiliariaCamargo-BuscaImoveis/1.0 (contato: comercial@imobcamargo.com.br)',
        CURLOPT_HTTPHEADER     => ['Accept-Language: pt-BR'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) return null;

    $j = json_decode($resp, true);
    if (empty($j[0]['lat'])) return null;
    $la = (float)$j[0]['lat']; $lo = (float)$j[0]['lon'];
    if ($la < LAT_MIN || $la > LAT_MAX || $lo < LNG_MIN || $lo > LNG_MAX) return null;

    // Qualidade: acertar o número da casa é bem diferente de acertar só a rua.
    $end = $j[0]['address'] ?? [];
    $q = !empty($end['house_number']) ? 'numero'
       : (!empty($end['road']) ? 'rua' : 'aproximado');
    return [$la, $lo, $q];
}

$pendentes = [];
$jaOk = 0; $jaFalhou = 0;
foreach ($imoveis as $x) {
    $cod = (string)$x['c'];
    if (!empty($x['lat'])) continue;                     // já veio do CRM
    // ATENÇÃO: só pulamos quem JÁ TEM coordenada no cache. Uma tentativa que
    // falhou antes precisa ser refeita — senão um erro no script (como a
    // vírgula que faltava antes do número) fica gravado para sempre e
    // nenhuma correção posterior tem efeito.
    // Só pula se a coordenada guardada corresponde ao endereço ATUAL. Se o
    // endereço mudou no CRM, refazemos — do contrário, corrigir o cadastro
    // no Robust nunca teria efeito aqui.
    $endAtual = trim((string)($x['e'] ?? ''));
    if (!empty($cache[$cod]['la'])
        && normalizaParaComparar($cache[$cod]['ruaUsada'] ?? '') === normalizaParaComparar($endAtual)) {
        $jaOk++; continue;
    }
    if (isset($cache[$cod])) $jaFalhou++;
    $p = partesEndereco($x);
    if ($p === null) continue;
    $p['bruto'] = $endAtual;
    $pendentes[$cod] = $p;
}

$comCrm = 0;
foreach ($imoveis as $x) if (!empty($x['lat'])) $comCrm++;

echo "Imóveis na base: " . count($imoveis) . "\n";
echo "Com coordenada do CRM: $comCrm\n";
echo "Já resolvidos por aqui: $jaOk\n";
echo "Falharam antes (serão tentados de novo): $jaFalhou\n";
echo "Total a processar agora: " . count($pendentes) . "\n\n";

if ($diagnostico) {
    echo "--- SITUAÇÃO ---\n";
    echo 'Arquivo de coordenadas: ' . (file_exists(ARQ_GEO) ? ARQ_GEO . ' (' . filesize(ARQ_GEO) . " bytes)\n" : "NÃO EXISTE\n");
    $porQ = [];
    foreach ($cache as $v) { $q = $v['q'] ?? '?'; $porQ[$q] = ($porQ[$q] ?? 0) + 1; }
    foreach ($porQ as $q => $qtd) echo "  $q: $qtd\n";
    echo "\nExemplos de falha (se houver):\n";
    $i = 0;
    foreach ($cache as $cod => $v) {
        if (!empty($v['la'])) continue;
        echo "  #$cod  " . ($v['end'] ?? '') . "\n";
        if (++$i >= 5) break;
    }
    if (!$i) echo "  nenhuma\n";
    echo "\nSe houver falhas antigas, rode ?limpar=1 e depois ?executar=1.\n";
    exit;
}

if (!$pendentes) { echo "Nada a fazer.\n"; exit; }

if ($soTeste) {
    echo "--- TESTE: 5 endereços, nada será gravado ---\n\n";
    $n = 0;
    foreach ($pendentes as $cod => $p) {
        if ($n++ >= 5) break;
        echo "#$cod\n";
        $achou = null; $usado = '';
        foreach (tentativasEndereco($p) as $end) {
            echo "  tentando: $end\n";
            $r = geocodificar($end);
            sleep(2);
            if ($r) { $achou = $r; $usado = $end; break; }
        }
        echo '  -> ' . ($achou
            ? sprintf('%.6f, %.6f  (%s)  via: %s', $achou[0], $achou[1], $achou[2], $usado)
            : 'NÃO ENCONTRADO em nenhuma variação') . "\n\n";
    }
    echo "Confira no Google Maps se as coordenadas batem.\n";
    echo "Se estiver bom, rode com ?executar=1\n";
    exit;
}

@set_time_limit(0);
$ok = 0; $falhou = 0; $n = 0;
foreach ($pendentes as $cod => $p) {
    if ($limite && $n >= $limite) { echo "\nLimite de $limite atingido. Rode de novo para continuar.\n"; break; }
    $n++;
    $r = null; $end = '';
    foreach (tentativasEndereco($p) as $tent) {
        $end = $tent;
        $r = geocodificar($tent);
        sleep(2);
        if ($r) break;
    }
    if ($r) {
        $cache[$cod] = ['la' => round($r[0], 6), 'lo' => round($r[1], 6),
                        'q' => $r[2], 'end' => $end,
                        'ruaUsada' => $p['bruto'] ?? '', 'em' => date('c')];
        $ok++;
        echo sprintf("[%3d] #%-6s %-9s %s\n", $n, $cod, $r[2], $end);
    } else {
        $cache[$cod] = ['la' => null, 'lo' => null, 'q' => 'falhou', 'end' => $end,
                        'ruaUsada' => $p['bruto'] ?? '', 'em' => date('c')];
        $falhou++;
        echo sprintf("[%3d] #%-6s FALHOU    %s\n", $n, $cod, $end);
    }
    // Grava a cada 10 para não perder tudo se a conexão cair no meio.
    if ($n % 10 === 0) file_put_contents(ARQ_GEO, json_encode($cache, JSON_UNESCAPED_UNICODE));
}

file_put_contents(ARQ_GEO, json_encode($cache, JSON_UNESCAPED_UNICODE));
log_sync("geocodificação: $ok encontrados, $falhou sem resultado");

echo "\n----------------------------------------\n";
echo "Encontrados: $ok\n";
echo "Sem resultado: $falhou\n";
echo "\nAgora rode o Sincronizar na ferramenta para as coordenadas entrarem nos cards.\n";
