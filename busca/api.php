<?php
/* =====================================================================
   api.php — endpoints usados pela ferramenta.
     ?acao=dados    devolve a base já mesclada (API + XLS)
     ?acao=salvar   recebe o XLS já processado e grava PARA TODOS
     ?acao=sync     dispara o sync da API na hora
     ?acao=status   informações da última atualização
   Tudo exige sessão autenticada.
   ===================================================================== */

require_once __DIR__ . '/_auth.php';
$u = busca_exige_acesso_api();          // login (portal ou próprio) + nível 1
require_once __DIR__ . '/config.php';   // caminhos/base da Busca

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
$acao = $_GET['acao'] ?? 'dados';

/* Ações que alteram a base para TODOS (upload de planilha, sync do CRM,
   pontos de referência) ficam restritas a admin/gestor. Corretores (viewer)
   podem consultar e gerar links de seleção, mas não sobrescrever a base.
   (No modo standalone o papel é 'admin', então nada muda no uso antigo.) */
if (in_array($acao, ['salvar', 'sync', 'salvarrefs'], true)
    && !in_array(($u['papel'] ?? ''), ['admin', 'gestor'], true)) {
    http_response_code(403);
    echo json_encode(['erro' => 'apenas administrador ou gestor pode alterar a base']);
    exit;
}

switch ($acao) {

case 'dados':
    if (!file_exists(ARQ_MERGE)) {
        echo json_encode(['meta' => ['total' => 0, 'vazio' => true], 'imoveis' => []]);
        exit;
    }
    readfile(ARQ_MERGE);
    break;

case 'salvar':
    // Recebe o JSON já processado pelo pipeline no navegador e grava no
    // servidor. A partir daqui, todo mundo que abrir o link vê esta base.
    $bruto = file_get_contents('php://input');
    if (strlen($bruto) > 30 * 1024 * 1024) {
        http_response_code(413);
        echo json_encode(['erro' => 'arquivo grande demais']);
        exit;
    }
    $j = json_decode($bruto, true);
    if (!is_array($j) || empty($j['imoveis']) || !is_array($j['imoveis'])) {
        http_response_code(400);
        echo json_encode(['erro' => 'formato inválido']);
        exit;
    }
    // Proteção: exige um mínimo de registros com código válido.
    $validos = 0;
    foreach ($j['imoveis'] as $x) if (!empty($x['c'])) $validos++;
    if ($validos < 5) {
        http_response_code(400);
        echo json_encode(['erro' => "só $validos imóveis válidos — base anterior mantida"]);
        exit;
    }

    // Guarda uma cópia da versão anterior antes de substituir.
    if (file_exists(ARQ_XLS)) @copy(ARQ_XLS, ARQ_XLS . '.bak');

    $j['gerado_em'] = date('c');
    $tmp = ARQ_XLS . '.tmp';
    file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE));
    rename($tmp, ARQ_XLS);
    log_sync("XLS enviado: $validos imóveis (arquivo: " . ($j['nome'] ?? '?') . ')');

    // Refaz o merge imediatamente, sem rebuscar a API: o que mudou foi a
    // planilha, não o CRM. Buscar de novo levaria ~40s e estouraria o tempo
    // limite do servidor (erro 504).
    ob_start(); $GLOBALS['SYNC_SOMENTE_MERGE'] = 1;
    try { include __DIR__ . '/sync.php'; } catch (Throwable $e) {}
    ob_end_clean(); unset($GLOBALS['SYNC_SOMENTE_MERGE']);

    $meta = file_exists(ARQ_MERGE)
        ? (json_decode(file_get_contents(ARQ_MERGE), true)['meta'] ?? [])
        : [];
    echo json_encode(['ok' => true, 'validos' => $validos, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
    break;

case 'sync':
    // ?completo=1 forca o sync pesado (detalhes e fotos). O padrao e o rapido,
    // que cabe no tempo de uma requisicao web.
    if (!empty($_GET['completo'])) $_GET['completo'] = 1;
    ob_start();
    try { include __DIR__ . '/sync.php'; $out = ob_get_clean(); }
    catch (Throwable $e) { ob_end_clean(); $out = json_encode(['ok' => false, 'erro' => $e->getMessage()]); }
    echo $out;
    break;

case 'status':
    $meta = file_exists(ARQ_MERGE)
        ? (json_decode(file_get_contents(ARQ_MERGE), true)['meta'] ?? [])
        : ['vazio' => true];
    echo json_encode($meta, JSON_UNESCAPED_UNICODE);
    break;

case 'salvarrefs':
    // Recebe a planilha de pontos de referência já processada no navegador.
    // Fica no servidor para todo mundo, igual ao XLS de imóveis.
    $j = json_decode(file_get_contents('php://input'), true);
    $pts = $j['pontos'] ?? null;
    if (!is_array($pts) || count($pts) < 3) {
        http_response_code(400);
        echo json_encode(['erro' => 'planilha sem pontos válidos']);
        exit;
    }
    $validos = [];
    foreach ($pts as $p) {
        $la = $p['la'] ?? null; $lo = $p['lo'] ?? null;
        if (empty($p['n'])) continue;
        // Coordenada fora de Joinville é erro de digitação: descarta o ponto
        // em vez de espalhar distância errada pelos cards.
        if ($la !== null && ($la < -26.9 || $la > -25.9 || $lo < -49.4 || $lo > -48.4)) {
            $la = null; $lo = null;
        }
        $validos[] = ['n' => $p['n'], 'c' => $p['c'] ?? '', 'b' => $p['b'] ?? '',
                      'e' => $p['e'] ?? '', 'la' => $la, 'lo' => $lo];
    }
    if (count($validos) < 3) { http_response_code(400); echo json_encode(['erro' => 'nenhum ponto válido']); exit; }

    if (file_exists(DATA_DIR . '/referencias.json')) @copy(DATA_DIR . '/referencias.json', DATA_DIR . '/referencias.json.bak');
    file_put_contents(DATA_DIR . '/referencias.json',
        json_encode(['gerado_em' => date('c'), 'nome' => $j['nome'] ?? '', 'pontos' => $validos], JSON_UNESCAPED_UNICODE));
    log_sync('pontos de referência enviados: ' . count($validos));

    ob_start(); $GLOBALS['SYNC_SOMENTE_MERGE'] = 1;
    try { include __DIR__ . '/sync.php'; } catch (Throwable $e) {}
    ob_end_clean(); unset($GLOBALS['SYNC_SOMENTE_MERGE']);
    $comCoord = count(array_filter($validos, function ($p) { return $p['la'] !== null; }));
    echo json_encode(['ok' => true, 'total' => count($validos), 'com_coordenada' => $comCoord], JSON_UNESCAPED_UNICODE);
    break;

case 'compartilhar':
    // Cria um link público com a seleção. Guarda uma CÓPIA enxuta dos imóveis:
    // se o cadastro mudar depois, o cliente continua vendo o que foi enviado —
    // e nada além do que é material de divulgação vai para o arquivo.
    $bruto = file_get_contents('php://input');
    $j = json_decode($bruto, true);
    $cods = array_slice(array_map('intval', $j['cods'] ?? []), 0, 20);
    if (!$cods) { http_response_code(400); echo json_encode(['erro' => 'nenhum imóvel selecionado']); exit; }

    if (!file_exists(ARQ_MERGE)) { http_response_code(500); echo json_encode(['erro' => 'base vazia']); exit; }
    $base = json_decode(file_get_contents(ARQ_MERGE), true)['imoveis'] ?? [];
    $porCod = [];
    foreach ($base as $x) $porCod[(int)$x['c']] = $x;

    // Campos permitidos. Tudo que não estiver aqui NÃO vai para a página do
    // cliente: proprietário, captador, datas, pendências, telefone, coordenadas.
    $publicos = ['c','ti','t','b','ci','r','p','q','su','ba','v','a','ea','em','d','fotos','am'];

    // Pontos de referência: entram já calculados, para a página do cliente não
    // precisar de nenhuma lógica. Coordenada nos dois lados vira distância;
    // senão, vira "no bairro".
    $refs = [];
    if (file_exists(DATA_DIR . '/referencias.json')) {
        $rj = json_decode(@file_get_contents(DATA_DIR . '/referencias.json'), true);
        $refs = $rj['pontos'] ?? [];
    }
    $pesoCat = ['Terminal de ônibus'=>1,'Shopping'=>2,'Supermercado'=>3,'Atacarejo'=>4,
                'Hospital'=>5,'Hospital / Maternidade'=>5,'Faculdade / Universidade'=>6,
                'Lazer / Parque'=>7,'Grande empresa / Indústria'=>8];
    $distKm = function ($a, $b) {
        $R = 6371; $d1 = deg2rad($b[0]-$a[0]); $d2 = deg2rad($b[1]-$a[1]);
        $x = sin($d1/2)**2 + cos(deg2rad($a[0]))*cos(deg2rad($b[0]))*sin($d2/2)**2;
        return 2 * $R * asin(sqrt($x));
    };
    $semAcento = function ($t) {
        return strtolower(strtr(trim((string)$t),
            ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o',
             'õ'=>'o','ú'=>'u','ç'=>'c','Á'=>'a','Ã'=>'a','Â'=>'a','É'=>'e','Ê'=>'e','Í'=>'i',
             'Ó'=>'o','Ô'=>'o','Õ'=>'o','Ú'=>'u','Ç'=>'c']));
    };

    $itens = [];
    foreach ($cods as $c) {
        if (!isset($porCod[$c])) continue;
        $lim = [];
        foreach ($publicos as $k) if (isset($porCod[$c][$k])) $lim[$k] = $porCod[$c][$k];

        $orig = $porCod[$c];
        $perto = [];
        foreach ($refs as $p) {
            if (!empty($orig['lat']) && $p['la'] !== null) {
                $d = $distKm([$orig['lat'], $orig['lng']], [$p['la'], $p['lo']]);
                if ($d <= 3) $perto[] = ['n' => $p['n'], 'km' => round($d, 2),
                                         'w' => $pesoCat[$p['c']] ?? 20];
            } elseif ($semAcento($p['b']) === $semAcento($orig['b'] ?? '')) {
                $perto[] = ['n' => $p['n'], 'km' => null, 'w' => $pesoCat[$p['c']] ?? 20];
            }
        }
        usort($perto, function ($a, $b) {
            $ka = $a['km'] ?? 99; $kb = $b['km'] ?? 99;
            return $ka <=> $kb ?: $a['w'] <=> $b['w'];
        });
        $lim['perto'] = array_map(function ($p) { return ['n' => $p['n'], 'km' => $p['km']]; },
                                  array_slice($perto, 0, 5));
        // Marca se a posição do imóvel é aproximada (veio do meio da rua).
        $lim['aprox'] = (($orig['geo'] ?? '') === 'rua') ? 1 : 0;
        $itens[] = $lim;
    }
    if (!$itens) { http_response_code(404); echo json_encode(['erro' => 'imóveis não encontrados']); exit; }

    $token = bin2hex(random_bytes(16));
    file_put_contents(DATA_DIR . '/sel_' . $token . '.json',
        json_encode(['criado_em' => date('c'), 'imoveis' => $itens], JSON_UNESCAPED_UNICODE));

    // Limpa seleções com mais de 90 dias.
    foreach (glob(DATA_DIR . '/sel_*.json') as $velho) {
        if (filemtime($velho) < time() - 90 * 86400) @unlink($velho);
    }

    $base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
              . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    log_sync('link de seleção criado: ' . count($itens) . ' imóveis');
    echo json_encode(['ok' => true, 'url' => $base_url . '/ver.php?s=' . $token,
                      'total' => count($itens)], JSON_UNESCAPED_UNICODE);
    break;

case 'sair':
    session_destroy();
    echo json_encode(['ok' => true]);
    break;

default:
    http_response_code(404);
    echo json_encode(['erro' => 'ação desconhecida']);
}
