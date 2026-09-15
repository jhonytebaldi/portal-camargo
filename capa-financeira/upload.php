<?php
/* =====================================================================
   capa-financeira/upload.php — recebe a capa (.xlsx), extrai os
   lançamentos, cruza com o que já existe (duplicatas/alterações) e grava
   tudo em "revisão". Depois manda para revisar.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /capa-financeira/'); exit; }
csrf_check();
$pdo = db();

$empresa = (string)($_POST['empresa'] ?? '');
$empresas = array_column(cf_config('empresas', []), null, 'id');
if (!isset($empresas[$empresa])) { portal_header('Capa Financeira', $u); echo '<div class="erro">Escolha a empresa (Vertical ou Camargo).</div>'; portal_footer(); exit; }

$f = $_FILES['capa'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { portal_header('Capa Financeira', $u); echo '<div class="erro">Envio falhou. Selecione um arquivo .xlsx.</div>'; portal_footer(); exit; }
$nomeOrig = (string)$f['name'];
if (!preg_match('/\.xlsx$/i', $nomeOrig)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Só arquivos .xlsx (a capa exportada do Excel/Google Sheets).</div>'; portal_footer(); exit; }

/* ---- guarda o arquivo fora da web ---- */
$dir = cf_data_dir() . '/capas/' . date('Y/m');
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$seguro = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $nomeOrig) ?: 'capa.xlsx';
$destino = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '-' . $seguro;
if (!move_uploaded_file($f['tmp_name'], $destino)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Não consegui gravar o arquivo no servidor.</div>'; portal_footer(); exit; }

/* ---- parse ---- */
try { $res = cf_parser()->parse($destino); }
catch (Throwable $e) {
    @unlink($destino);
    portal_header('Capa Financeira', $u);
    echo '<div class="erro">Não consegui ler a planilha: ' . h($e->getMessage()) . '</div>';
    portal_footer(); exit;
}
$capa = $res['capa'];
$capaFlags = $res['capa_flags'];

/* ---- arquivo idêntico já enviado? ---- */
$st = $pdo->prepare('SELECT id, status, criado_em FROM cf_capas WHERE sha256 = ? ORDER BY id DESC LIMIT 1');
$st->execute([$res['sha256']]);
if ($ig = $st->fetch()) $capaFlags[] = "ARQUIVO_IDENTICO_JA_ENVIADO (capa #{$ig['id']}, {$ig['status']}, {$ig['criado_em']})";

/* ---- versão anterior da mesma capa (mesmo COD) ---- */
$anterior = null; $versao = 1;
if ($capa['cod'] !== null) {
    $st = $pdo->prepare("SELECT * FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada') ORDER BY versao DESC, id DESC LIMIT 1");
    $st->execute([(string)$capa['cod']]);
    $anterior = $st->fetch() ?: null;
    if ($anterior) $versao = (int)$anterior['versao'] + 1;
}

/* ---- pessoas (resolução exata) ---- */
$pessoas = cf_pessoas(false);   // desligados também: quem saiu ainda recebe parcelas

$pdo->beginTransaction();
try {
    $st = $pdo->prepare('INSERT INTO cf_capas (cod, versao, empresa, cliente, construtora, bairro, unidade, data_venda, valor_contrato, vgv, valor_bonus, obs_capa,
        arquivo_nome, arquivo_path, sha256, capa_flags, total_pagar, total_receber, status, capa_anterior_id, enviado_por)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'revisao\',?,?)');
    $st->execute([
        $capa['cod'] !== null ? (string)$capa['cod'] : null, $versao, $empresa, $capa['cliente'], $capa['construtora'], $capa['bairro'], $capa['unidade'],
        $capa['data_venda'], $capa['valor_contrato'], $capa['vgv'], $capa['valor_bonus'], $capa['obs_c8'],
        $nomeOrig, $destino, $res['sha256'], json_encode($capaFlags, JSON_UNESCAPED_UNICODE),
        $res['total_pagar'] ?? 0, $res['total_receber'] ?? 0, $anterior['id'] ?? null, $u['id'],
    ]);
    $capaId = (int)$pdo->lastInsertId();

    // linhas da versão anterior (para diff) e de outras capas (possível duplicata)
    $antLinhas = [];
    if ($anterior) {
        $q = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status <> 'removido'");
        $q->execute([$anterior['id']]);
        foreach ($q->fetchAll() as $l) $antLinhas[$l['chave_estavel']] = $l;
    }
    $qDup = $pdo->prepare("SELECT l.id, l.capa_id, c.cod, c.cliente FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id
        WHERE l.capa_id <> ? AND (c.cod IS NULL OR c.cod <> ?) AND l.status IN ('confirmado','exportado','revisao')
          AND l.tipo = ? AND l.valor = ? AND l.data_prevista <=> ? AND UPPER(l.cf_raw) = ? LIMIT 1");

    $ins = $pdo->prepare('INSERT INTO cf_lancamentos (capa_id, linha_xlsx, tipo, ordinal, chave_estavel, chave_exata, data_raw, cf_raw, favorecido_hist,
        funcao_raw, natureza_raw, clientes_hist, construtora_hist, unidade, status_imovel, data_venda_hist, col_g, prefixo, valor, parcela, total_parcelas,
        rotulo_fluxo, flags, data_prevista, pessoa_id, funcao, natureza, categoria, nota_fiscal, condicao, chave_pix, status, dup_tipo, dup_de)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'revisao\',?,?)');

    $ordinais = [];
    $vistos = [];
    foreach ($res['linhas'] as $l) {
        $tipo = $l['tipo'] === 'PAGAR' ? 'P' : 'R';
        $gk = $tipo . '|' . CapaParser::key($l['cf']) . '|' . ($l['funcao'] ?? '') . '|' . ($l['natureza'] ?? '');
        $ordinais[$gk] = ($ordinais[$gk] ?? 0) + 1;
        $flags = $l['flags'];
        $pessoaId = $tipo === 'P' ? cf_resolver_pessoa($l['cf'], $pessoas) : null;
        if ($tipo === 'P' && $pessoaId === null) $flags[] = 'PESSOA_NAO_IDENTIFICADA';
        // diff com a versão anterior
        $dupTipo = null; $dupDe = null;
        if ($anterior) {
            $a = $antLinhas[$l['chave_estavel']] ?? null;
            if (!$a) {
                // 2ª tentativa: mesma pessoa, função e valor (mudou só natureza/ordem)
                foreach ($antLinhas as $cand) {
                    if (isset($vistos[$cand['id']])) continue;
                    if ($cand['tipo'] === $tipo && CapaParser::key($cand['cf_raw']) === CapaParser::key($l['cf']) && ($cand['funcao_raw'] ?? '') === ($l['funcao'] ?? '')
                        && abs((float)$cand['valor'] - (float)$l['valor']) < 0.005) { $a = $cand; break; }
                }
            }
            if ($a) { $dupDe = (int)$a['id']; $dupTipo = ($a['chave_exata'] === $l['chave_exata']) ? 'IGUAL' : 'ALTERADA'; $vistos[$a['id']] = true; }
            else $dupTipo = 'NOVA';
        } else {
            $qDup->execute([$capaId, (string)($capa['cod'] ?? ''), $tipo, $l['valor'], $l['data'], mb_strtoupper($l['cf'], 'UTF-8')]);
            if ($d = $qDup->fetch()) { $dupTipo = 'POSSIVEL_DUPLICATA'; $dupDe = (int)$d['id']; $flags[] = "GRAVE:POSSIVEL_DUPLICATA (mesmo favorecido, valor e data na capa #{$d['capa_id']} {$d['cliente']})"; }
        }
        $categoria = cf_categoria($tipo, $l['funcao'], $l['natureza'], $l['status_imovel']);
        $ins->execute([
            $capaId, $l['linha_xlsx'], $tipo, $ordinais[$gk], $l['chave_estavel'], $l['chave_exata'], $l['data_raw'], $l['cf'], $l['favorecido_hist'],
            $l['funcao'], $l['natureza'], $l['clientes_hist'], $l['construtora_hist'], $l['unidade'], $l['status_imovel'], $l['data_venda_hist'],
            $l['col_g'], $l['prefixo'], $l['valor'], $l['parcela'] ?? null, $l['total_parcelas'] ?? null, $l['rotulo_fluxo'] ?? null,
            json_encode($flags, JSON_UNESCAPED_UNICODE),
            $l['data'], $pessoaId, $l['funcao'], $l['natureza'], $categoria, $l['nota_fiscal'], $l['condicao'],
            ($pessoaId && !empty($pessoas[$pessoaId]['chave_pix'])) ? $pessoas[$pessoaId]['chave_pix'] : null, $dupTipo, $dupDe,
        ]);
    }
    // linhas da versão anterior que sumiram
    if ($anterior) {
        $removidas = array_filter($antLinhas, fn($a) => !isset($vistos[$a['id']]));
        if ($removidas) {
            $capaFlags[] = 'LINHAS_REMOVIDAS_NA_NOVA_VERSAO (' . count($removidas) . ')';
            $pdo->prepare('UPDATE cf_capas SET capa_flags = ? WHERE id = ?')->execute([json_encode($capaFlags, JSON_UNESCAPED_UNICODE), $capaId]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    @unlink($destino);
    portal_header('Capa Financeira', $u);
    echo '<div class="erro">Erro ao gravar: ' . h($e->getMessage()) . '</div>';
    portal_footer(); exit;
}
header('Location: /capa-financeira/revisar.php?id=' . $capaId);
