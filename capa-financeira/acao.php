<?php
/* =====================================================================
   capa-financeira/acao.php — ações da revisão (JSON, POST).
   acao: editar | remover | restaurar | confirmar | descartar | editar_capa
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

header('Content-Type: application/json; charset=utf-8');
$u = require_tool('capa-financeira');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'erro' => 'POST'])); }
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$_POST['csrf'] = $in['csrf'] ?? '';
csrf_check();
$pdo = db();

function falha(string $msg, int $code = 400): never { http_response_code($code); exit(json_encode(['ok' => false, 'erro' => $msg], JSON_UNESCAPED_UNICODE)); }

$capaId = (int)($in['capa_id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM cf_capas WHERE id = ?'); $st->execute([$capaId]);
$capa = $st->fetch();
if (!$capa) falha('capa não encontrada', 404);
if ($capa['status'] !== 'revisao') falha('esta capa não está mais em revisão');

/** Pendências que impedem a confirmação. */
function cf_pendencias(PDO $pdo, array $capa): array
{
    $p = [];
    if ($capa['cod'] === null || $capa['cod'] === '') $p[] = 'Capa sem COD do imóvel — informe o código (Robust)';
    $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao' ORDER BY linha_xlsx");
    $st->execute([$capa['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $l) {
        $n++;
        $fl = json_decode((string)$l['flags'], true) ?: [];
        $graves = array_filter($fl, fn($f) => str_starts_with($f, 'GRAVE:'));
        if ($l['tipo'] === 'P' && !$l['pessoa_id']) $p[] = "Linha {$l['linha_xlsx']}: favorecido não identificado";
        if (!$l['data_prevista']) $p[] = "Linha {$l['linha_xlsx']}: sem data prevista";
        if (!$l['categoria']) $p[] = "Linha {$l['linha_xlsx']}: sem categoria";
        if ($graves && !(int)$l['revisado']) $p[] = "Linha {$l['linha_xlsx']}: alerta grave sem revisão";
    }
    if ($n === 0) $p[] = 'Nenhum lançamento para confirmar';
    return $p;
}
function cf_linha(PDO $pdo, int $id, int $capaId): array
{
    $st = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE id = ? AND capa_id = ?'); $st->execute([$id, $capaId]);
    $l = $st->fetch(); if (!$l) falha('linha não encontrada', 404);
    return $l;
}
function cf_tem_grave_pendente(array $l): bool
{
    $fl = json_decode((string)$l['flags'], true) ?: [];
    return (bool)array_filter($fl, fn($f) => str_starts_with($f, 'GRAVE:')) && !(int)$l['revisado'];
}

$acao = (string)($in['acao'] ?? '');
$resp = ['ok' => true];

switch ($acao) {
case 'editar':
    $l = cf_linha($pdo, (int)($in['id'] ?? 0), $capaId);
    if ($l['status'] !== 'revisao') falha('linha não está em revisão');
    $campo = (string)($in['campo'] ?? ''); $valor = $in['valor'] ?? null;
    switch ($campo) {
        case 'data_prevista':
            $v = is_string($valor) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) ? $valor : null;
            $pdo->prepare('UPDATE cf_lancamentos SET data_prevista = ? WHERE id = ?')->execute([$v, $l['id']]);
            break;
        case 'pessoa_id':
            $pid = (int)$valor ?: null;
            if ($pid) { $q = $pdo->prepare('SELECT id FROM cf_pessoas WHERE id = ? AND ativo = 1'); $q->execute([$pid]); if (!$q->fetch()) falha('pessoa inválida'); }
            $pdo->prepare('UPDATE cf_lancamentos SET pessoa_id = ? WHERE id = ?')->execute([$pid, $l['id']]);
            if ($pid && !empty($in['salvar_alias'])) cf_add_alias($pid, $l['cf_raw']);
            if ($pid && !empty($in['aplicar_todas'])) {
                // mesma pessoa em todas as linhas em revisão desta capa com o mesmo favorecido (coluna C)
                $q = $pdo->prepare("SELECT id, cf_raw FROM cf_lancamentos WHERE capa_id = ? AND tipo = 'P' AND status = 'revisao' AND id <> ?");
                $q->execute([$capaId, $l['id']]);
                $ids = [];
                foreach ($q->fetchAll() as $o) if (CapaParser::key($o['cf_raw']) === CapaParser::key($l['cf_raw'])) $ids[] = (int)$o['id'];
                if ($ids) {
                    $pdo->prepare('UPDATE cf_lancamentos SET pessoa_id = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$pid]);
                }
                $resp['aplicado_em'] = $ids;
            }
            break;
        case 'funcao':
        case 'natureza':
            $v = CapaParser::key((string)$valor);
            if ($campo === 'natureza' && !in_array($v, ['COMISSAO', 'BONUS'], true)) falha('natureza inválida');
            $novo = [$campo === 'funcao' ? 'funcao' : 'natureza' => $v];
            $funcao = $campo === 'funcao' ? $v : $l['funcao']; $nat = $campo === 'natureza' ? $v : $l['natureza'];
            $cat = cf_categoria('P', $funcao, $nat, $l['status_imovel']);
            $flags = [];
            [$nf] = cf_parser()->buildNf($nat, $l['col_g'], $l['prefixo'], $flags);
            $pdo->prepare("UPDATE cf_lancamentos SET funcao = ?, natureza = ?, categoria = ?, nota_fiscal = ? WHERE id = ?")->execute([$funcao, $nat, $cat, $nf, $l['id']]);
            $resp['categoria'] = $cat; $resp['nota_fiscal'] = $nf;
            break;
        case 'categoria':
            $v = CapaParser::norm((string)$valor);
            if (!in_array($v, array_values(cf_config('categorias', [])), true)) falha('categoria fora do dicionário');
            $pdo->prepare('UPDATE cf_lancamentos SET categoria = ? WHERE id = ?')->execute([$v, $l['id']]);
            break;
        case 'nota_fiscal':
            $v = mb_substr(CapaParser::norm((string)$valor), 0, 20, 'UTF-8');
            $pdo->prepare('UPDATE cf_lancamentos SET nota_fiscal = ? WHERE id = ?')->execute([$v !== '' ? $v : null, $l['id']]);
            break;
        case 'revisado':
            $pdo->prepare('UPDATE cf_lancamentos SET revisado = ? WHERE id = ?')->execute([(int)$valor ? 1 : 0, $l['id']]);
            break;
        default: falha('campo inválido');
    }
    $l = cf_linha($pdo, (int)$l['id'], $capaId);
    $resp['tem_grave_pendente'] = cf_tem_grave_pendente($l);
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;

case 'remover':
case 'restaurar':
    $l = cf_linha($pdo, (int)($in['id'] ?? 0), $capaId);
    $novo = $acao === 'remover' ? 'removido' : 'revisao';
    if (!in_array($l['status'], ['revisao', 'removido'], true)) falha('linha não pode ser alterada');
    $pdo->prepare('UPDATE cf_lancamentos SET status = ? WHERE id = ?')->execute([$novo, $l['id']]);
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;

case 'editar_capa':
    $campo = (string)($in['campo'] ?? '');
    if ($campo !== 'cod') falha('campo inválido');
    $v = preg_replace('/\D+/', '', (string)($in['valor'] ?? '')) ?: null;
    $pdo->prepare('UPDATE cf_capas SET cod = ? WHERE id = ?')->execute([$v, $capaId]);
    $capa['cod'] = $v;
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;

case 'descartar':
    $pdo->prepare("UPDATE cf_capas SET status = 'descartada' WHERE id = ?")->execute([$capaId]);
    break;

case 'confirmar':
    $pend = cf_pendencias($pdo, $capa);
    if ($pend) falha('Ainda há pendências: ' . implode('; ', array_slice($pend, 0, 5)));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao' ORDER BY linha_xlsx");
        $st->execute([$capaId]);
        $linhas = $st->fetchAll();
        $seq = $pdo->prepare('INSERT INTO cf_sequencias (cod, tipo, ultimo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ultimo = ultimo + 1');
        $seqGet = $pdo->prepare('SELECT ultimo FROM cf_sequencias WHERE cod = ? AND tipo = ?');
        $upd = $pdo->prepare("UPDATE cf_lancamentos SET status = 'confirmado', codigo_integracao = ?, alteracao_pos_exportacao = ? WHERE id = ?");
        $ant = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE id = ?');
        $subst = $pdo->prepare("UPDATE cf_lancamentos SET status = 'substituido' WHERE id = ? AND status = 'confirmado'");
        foreach ($linhas as $l) {
            $codigo = null; $posExp = 0;
            if ($l['dup_de'] && in_array($l['dup_tipo'], ['IGUAL', 'ALTERADA'], true)) {
                $ant->execute([$l['dup_de']]); $a = $ant->fetch();
                if ($a && $a['codigo_integracao']) {
                    $codigo = $a['codigo_integracao'];
                    if ($a['status'] === 'exportado' && $l['dup_tipo'] === 'ALTERADA') $posExp = 1;
                    $subst->execute([$a['id']]);
                }
            }
            if ($codigo === null) {
                $seq->execute([(string)$capa['cod'], $l['tipo']]);
                $seqGet->execute([(string)$capa['cod'], $l['tipo']]);
                $n = (int)$seqGet->fetchColumn();
                $codigo = strtoupper((string)$capa['cod']) . '-' . $l['tipo'] . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
            }
            $upd->execute([$codigo, $posExp, $l['id']]);
        }
        // versão anterior: capa e linhas que não vieram na nova versão
        if ($capa['capa_anterior_id']) {
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'substituido' WHERE capa_id = ? AND status = 'confirmado'")->execute([$capa['capa_anterior_id']]);
            $pdo->prepare("UPDATE cf_capas SET status = 'substituida' WHERE id = ? AND status IN ('revisao','confirmada')")->execute([$capa['capa_anterior_id']]);
        }
        $pdo->prepare("UPDATE cf_capas SET status = 'confirmada', confirmado_em = NOW(), confirmado_por = ? WHERE id = ?")->execute([$u['id'], $capaId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); falha('erro ao confirmar: ' . $e->getMessage(), 500); }
    break;

default: falha('ação inválida');
}
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
