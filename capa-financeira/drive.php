<?php
/* =====================================================================
   capa-financeira/drive.php — capas direto da pasta do Google Drive.
     GET  ?acao=listar            lista os .xlsx da pasta (lida na hora, sem cópia) com a situação de cada um no portal
     POST {acao:'importar', ids, empresa, unico}   baixa e importa (mesmas regras do envio de vários arquivos)
   Ao listar, o arquivo de uma capa que já foi toda ao Omie ganha "ok - " no nome (a conta do portal precisa ser editora).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
header('Content-Type: application/json; charset=utf-8');
$sai = function (array $d, int $c = 200): never { http_response_code($c); exit(json_encode($d, JSON_UNESCAPED_UNICODE)); };
$falha = fn(string $m, int $c = 400) => $sai(['ok' => false, 'erro' => $m], $c);

try {
    if (!CfDrive::configurado()) $sai(['ok' => true, 'configurado' => false]);

    require_once __DIR__ . '/_importar.php';
    /* ---------- listar ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $l = CfDrive::listar();
        // capas ativas ligadas a um arquivo do Drive (a mais nova de cada arquivo) e nomes de arquivo já importados à mão
        $porDrive = []; $porNome = [];
        foreach ($pdo->query("SELECT c.id, c.status, c.drive_id, c.drive_md5, c.arquivo_nome, c.cod,
                                (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status = 'exportado') AS n_exp,
                                (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status IN ('confirmado','revisao')) AS n_pend
                              FROM cf_capas c WHERE c.status IN ('revisao','confirmada') ORDER BY c.id")->fetchAll() as $c) {
            if ($c['drive_id']) $porDrive[$c['drive_id']] = $c;
            $porNome[CfDrive::nomeBase((string)$c['arquivo_nome'])] = $c;
        }
        $renomeados = 0; $avisoRen = null; $out = [];
        foreach ($l['arquivos'] as $a) {
            $c = $porDrive[$a['id']] ?? null; $sit = 'pendente'; $txt = '';
            if (preg_match('/modelo|base-planilha/iu', $a['nome'])) $sit = 'modelo';
            elseif ($c) {
                $enviada = $c['status'] === 'confirmada' && (int)$c['n_exp'] > 0 && (int)$c['n_pend'] === 0;
                if ($enviada && !CfDrive::temOk($a['nome']) && $renomeados < 5 && $avisoRen === null) {
                    // capa toda no Omie: marca o arquivo com "ok - " (como já era feito à mão)
                    try { CfDrive::renomear($a['id'], 'ok - ' . ltrim($a['nome'])); $a['nome'] = 'ok - ' . ltrim($a['nome']); $renomeados++; }
                    catch (Throwable $e) { $avisoRen = 'Não consegui renomear arquivos para "ok - …": ' . $e->getMessage(); }
                }
                if ($a['md5'] !== '' && $c['drive_md5'] && $a['md5'] !== $c['drive_md5']) { $sit = 'alterada'; $txt = 'o arquivo mudou no Drive depois de importado'; }
                else { $sit = $enviada ? 'enviada' : ($c['status'] === 'confirmada' ? 'confirmada' : 'revisao'); }
            } elseif ($c = ($porNome[CfDrive::nomeBase($a['nome'])] ?? null)) { $sit = 'provavel'; $txt = 'há uma capa enviada pelo computador com este nome de arquivo'; }
            elseif (CfDrive::temOk($a['nome'])) $sit = 'ok';
            $out[] = ['id' => $a['id'], 'nome' => $a['nome'], 'subpasta' => $a['subpasta'], 'modificado' => $a['modificado'], 'sit' => $sit, 'txt' => $txt, 'capa' => $c ? (int)$c['id'] : null, 'md5' => $a['md5']];
        }
        // ficha de cada arquivo (cabeçalho da capa), para filtrar por construtora, COD, bairro…: usa o que já foi lido e lê mais alguns
        // a cada chamada (até ~9 s), começando pelos pendentes; a tela chama de novo enquanto faltar.
        $fichas = CfDrive::fichas(); $novas = []; $faltam = []; $tIdx = microtime(true);
        foreach ($out as $a) if ($a['sit'] !== 'modelo' && (!isset($fichas[$a['id']]) || ($fichas[$a['id']]['md5'] ?? '') !== $a['md5'] || ($fichas[$a['id']]['v'] ?? 1) !== CfDrive::FICHA_V)) $faltam[] = $a;
        usort($faltam, fn($x, $y) => (int)!in_array($x['sit'], ['pendente', 'alterada'], true) <=> (int)!in_array($y['sit'], ['pendente', 'alterada'], true));
        $restam = count($faltam);
        if (empty($_GET['sem_indexar'])) foreach ($faltam as $a) {
            if ((microtime(true) - $tIdx) > 9) break;
            $novas[$a['id']] = $fichas[$a['id']] = CfDrive::lerFicha($a['id'], $a['md5']); $restam--;
        }
        if ($novas || count($fichas) > count($out)) CfDrive::gravarFichas($novas, array_column($out, 'id'));
        foreach ($out as $i => $a) { $f = $fichas[$a['id']] ?? null; if ($f && empty($f['erro']) && ($f['md5'] ?? '') === $a['md5']) $out[$i]['ficha'] = array_diff_key($f, ['md5' => 1, 'v' => 1]); }

        // Vários arquivos da MESMA venda (mesmos compradores no nome), na pasta que for: só um vale.
        //  - havendo aditivo, vale o aditivo mais recente (substitui a capa original e os aditivos anteriores);
        //  - sem aditivo: se um dos arquivos já está no portal, os outros são cópia/versão dele; senão vale o modificado por último.
        // Quem não vale fica travado na tela, com o motivo e apontando para o que vale (dá para liberar).
        $vd = [];
        foreach ($out as $i => $a) if ($a['sit'] !== 'modelo') { $vd[$i] = CfDrive::venda($a['nome']); $out[$i]['aditivo'] = $vd[$i]['aditivo']; $out[$i]['nao_subir'] = $vd[$i]['nao_subir']; $out[$i]['distrato'] = $vd[$i]['distrato']; }
        $idx = array_keys($vd); $pai = array_combine($idx, $idx);
        $raiz = function (int $k) use (&$pai) { while ($pai[$k] !== $k) $k = $pai[$k]; return $k; };
        $porChave = []; foreach ($vd as $i => $x) if ($x['chave'] !== '') $porChave[$x['chave']][] = $i;
        foreach ($porChave as $ids) foreach ($ids as $i) $pai[$raiz($i)] = $raiz($ids[0]);                     // mesmo nome
        $chaves = array_keys($porChave);
        for ($a = 0, $n = count($chaves); $a < $n; $a++) for ($b = $a + 1; $b < $n; $b++) if (CfDrive::mesmaVenda($chaves[$a], $chaves[$b])) $pai[$raiz($porChave[$chaves[$b]][0])] = $raiz($porChave[$chaves[$a]][0]);
        // mesmo COD dentro da capa E clientes da mesma venda (COD sozinho não basta: há COD copiado de outra planilha)
        $porCod = []; foreach ($idx as $i) if (($out[$i]['ficha']['cod'] ?? '') !== '') $porCod[$out[$i]['ficha']['cod']][] = $i;
        foreach ($porCod as $ids) for ($a = 0, $n = count($ids); $a < $n; $a++) for ($b = $a + 1; $b < $n; $b++)
            if ($raiz($ids[$a]) !== $raiz($ids[$b]) && cf_mesmo_cliente((string)$out[$ids[$a]]['ficha']['cliente'], (string)$out[$ids[$b]]['ficha']['cliente'])) $pai[$raiz($ids[$b])] = $raiz($ids[$a]);
        $porGrupo = []; foreach ($idx as $i) if ($vd[$i]['chave'] !== '' || isset($out[$i]['ficha'])) $porGrupo[$raiz($i)][] = $i;
        $onde = fn(int $i) => ['nome' => $out[$i]['nome'], 'subpasta' => $out[$i]['subpasta']];
        $pend = fn(int $i) => in_array($out[$i]['sit'], ['pendente', 'alterada'], true);
        foreach ($porGrupo as $ids) {
            if (count($ids) < 2) continue;
            $ads = array_values(array_filter($ids, fn($i) => $vd[$i]['aditivo']));
            if ($ads) {
                usort($ads, fn($a, $b) => [$vd[$b]['n'], $out[$b]['modificado']] <=> [$vd[$a]['n'], $out[$a]['modificado']]);
                $vale = $ads[0];
                $capaAnt = null; foreach ($ids as $i) if ($i !== $vale && $out[$i]['capa']) $capaAnt = $out[$i]['capa'];
                $out[$vale]['substitui'] = ['n' => count($ids) - 1, 'capa' => $capaAnt];
                foreach ($ids as $i) if ($i !== $vale) $out[$i]['trava'] = ['motivo' => 'aditivo'] + $onde($vale);
                continue;
            }
            // distrato e arquivo marcado "incompleto" não disputam: não travam ninguém nem são travados, só ganham o aviso de que há outro arquivo
            $esp = array_values(array_filter($ids, fn($i) => $vd[$i]['distrato'] || $vd[$i]['incompleto']));
            if ($esp) {
                foreach ($ids as $i) $out[$i]['outros'] = array_values(array_map(fn($j) => $out[$j]['nome'] . ($out[$j]['subpasta'] !== '' ? ' (' . $out[$j]['subpasta'] . ')' : ''), array_filter($ids, fn($j) => $j !== $i)));
                $ids = array_values(array_diff($ids, $esp));
                if (count($ids) < 2) continue;
            }
            $noPortal = array_values(array_filter($ids, fn($i) => $out[$i]['capa'] !== null));
            if ($noPortal) {
                $ref = $noPortal[0];
                foreach ($ids as $i) if ($pend($i)) $out[$i]['trava'] = ['motivo' => ($out[$i]['md5'] !== '' && $out[$i]['md5'] === $out[$ref]['md5']) ? 'copia_importada' : 'ja_importada', 'capa' => $out[$ref]['capa']] + $onde($ref);
                continue;
            }
            $ord = $ids; usort($ord, fn($a, $b) => [$out[$b]['modificado'], strlen($out[$a]['nome'])] <=> [$out[$a]['modificado'], strlen($out[$b]['nome'])]);
            $vale = $ord[0];
            foreach ($ids as $i) if ($i !== $vale) $out[$i]['trava'] = ['motivo' => ($out[$i]['md5'] !== '' && $out[$i]['md5'] === $out[$vale]['md5']) ? 'copia' : 'mais_recente'] + $onde($vale);
        }
        foreach ($out as $i => $a) unset($out[$i]['md5']);
        $sai(['ok' => true, 'configurado' => true, 'pasta' => $l['pasta'], 'pasta_id' => CfDrive::pasta(), 'arquivos' => $out, 'renomeados' => $renomeados, 'aviso' => $avisoRen, 'lido_em' => date('H:i:s'), 'indexar_restam' => max(0, $restam), 'hoje' => date('Y-m-d')]);
    }

    /* ---------- importar ---------- */
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    if (($in['acao'] ?? '') !== 'importar') $falha('Ação inválida — recarregue a página e tente de novo.');
    $empresa = (string)($in['empresa'] ?? '');
    $empresas = array_column(cf_config('empresas', []), null, 'id');
    if (!isset($empresas[$empresa])) $falha('Escolha a empresa da importação (Vertical ou Camargo) antes de importar.');
    $ids = array_values(array_unique(array_filter(array_map('strval', (array)($in['ids'] ?? [])), fn($i) => (bool)preg_match('/^[A-Za-z0-9_-]{10,80}$/', $i))));
    if (!$ids) $falha('Nenhum arquivo marcado — marque pelo menos um na lista do Drive.');
    $unico = !empty($in['unico']) && count($ids) === 1;

    @set_time_limit(110);
    $t0 = microtime(true); $res = []; $restantes = [];
    $dir = cf_data_dir() . '/capas/' . date('Y/m');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $ja = $pdo->prepare("SELECT id, status, drive_md5 FROM cf_capas WHERE drive_id = ? AND status IN ('revisao','confirmada') ORDER BY id DESC LIMIT 1");
    $liga = $pdo->prepare('UPDATE cf_capas SET drive_id = ?, drive_md5 = ? WHERE id = ?');
    foreach ($ids as $id) {
        if ((microtime(true) - $t0) > 18) { $restantes[] = $id; continue; }   // a Hostinger corta requisições longas: o navegador manda o resto de novo
        $nome = $id;
        try {
            $m = CfDrive::meta($id); $nome = (string)($m['name'] ?? $id);
            if (!empty($m['trashed'])) { $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => 'O arquivo está na lixeira do Drive. Restaure-o ou atualize a lista.']; continue; }
            if (!preg_match('/\.xlsx$/i', $nome)) { $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => 'Não é um .xlsx. Planilha do Google precisa ser baixada como .xlsx e colocada na pasta.']; continue; }
            $ja->execute([$id]); $ex = $ja->fetch();
            if ($ex && ($m['md5Checksum'] ?? '') !== '' && $ex['drive_md5'] === $m['md5Checksum']) { $res[] = ['nome' => $nome, 'st' => 'ja', 'id' => (int)$ex['id'], 'msg' => 'Este arquivo já foi importado e não mudou desde então.']; continue; }
            $seguro = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $nome) ?: 'capa.xlsx';
            $destino = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '-' . $seguro;
            CfDrive::baixar($id, $destino);
            if ($unico || CfDrive::venda($nome)['aditivo']) {   // aditivo substitui a capa da mesma venda: entra como nova versão mesmo num lote
                // um arquivo só: pode virar nova versão de uma capa do mesmo cliente; o que pede decisão continua indo pelo campo de arquivo
                $capaUp = cf_parser()->parse($destino)['capa']; $codUp = $capaUp['cod'] ?? null; $r = null;
                if ($codUp !== null && $codUp !== '') {
                    $q = $pdo->prepare("SELECT COUNT(*) FROM cv_vendas WHERE cod_imovel = ? AND status <> 'cancelada'"); $q->execute([(string)$codUp]);
                    if ((int)$q->fetchColumn() > 0) $r = ['st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' é de uma venda feita pelo formulário. Baixe o arquivo e envie pelo campo de arquivo para ver o aviso e decidir.'];
                    elseif ($conf = cf_cod_outro_cliente($pdo, $capaUp)) $r = ['st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' já está na capa #' . (int)$conf['id'] . ' de OUTRO cliente (' . $conf['cliente'] . '). Corrija o COD na planilha do Drive; se for mesmo a mesma venda, baixe e envie pelo campo de arquivo para decidir.'];
                }
                if ($r) @unlink($destino);
                else {
                    $cid = cf_importar_capa($destino, $empresa, $nome, $u);
                    $q = $pdo->prepare('SELECT cliente, cod, versao FROM cf_capas WHERE id = ?'); $q->execute([$cid]); $c = $q->fetch();
                    $r = ['st' => 'ok', 'id' => $cid, 'msg' => ($c['cod'] ? 'COD ' . $c['cod'] : 'sem COD') . ' · ' . $c['cliente'] . ((int)$c['versao'] > 1 ? ' · versão ' . (int)$c['versao'] . ' (substitui a capa anterior deste COD)' : '')];
                }
            } else $r = cf_importar_item_lote($pdo, $destino, $empresa, $nome, $u);
            if ($r['st'] === 'ok') $liga->execute([$id, (string)($m['md5Checksum'] ?? '') ?: null, (int)$r['id']]);
            $res[] = ['nome' => $nome] + $r;
        } catch (Throwable $e) {
            $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => $e->getMessage()];
        }
    }
    $sai(['ok' => true, 'resultados' => $res, 'restantes' => $restantes]);
} catch (Throwable $e) {
    $m = $e->getMessage();
    $falha($m . (str_contains($m, 'SQLSTATE') ? ' É um erro do banco do portal: rode a migração em Admin › Migração do banco e tente de novo.' : ''), 500);
}
