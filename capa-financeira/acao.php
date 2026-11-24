<?php
/* =====================================================================
   capa-financeira/acao.php — ações da revisão (JSON, POST).
   acao: editar | remover | restaurar | confirmar | descartar | editar_capa
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/_quem_paga.php';
require_once __DIR__ . '/_confirmar.php';

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
$acao = (string)($in['acao'] ?? '');
if ($acao === 'excluir') {
    // apaga de vez uma capa que nunca foi confirmada (em revisão ou descartada) — nada dela virou código de integração
    if (!in_array($capa['status'], ['revisao', 'descartada'], true)) falha('só dá pra excluir capa em revisão ou descartada; as confirmadas ficam no histórico');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE cf_lancamentos SET dup_de = NULL WHERE dup_de IN (SELECT id FROM (SELECT id FROM cf_lancamentos WHERE capa_id = ?) x)')->execute([$capaId]);
        $pdo->prepare('UPDATE cf_capas SET capa_anterior_id = NULL WHERE capa_anterior_id = ?')->execute([$capaId]);
        $pdo->prepare('DELETE FROM cf_lancamentos WHERE capa_id = ?')->execute([$capaId]);
        $pdo->prepare('DELETE FROM cf_capas WHERE id = ?')->execute([$capaId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); falha('erro ao excluir: ' . $e->getMessage(), 500); }
    if ($capa['arquivo_path'] && is_file($capa['arquivo_path']) && str_starts_with(realpath($capa['arquivo_path']) ?: '', realpath(cf_data_dir()) ?: '#')) @unlink($capa['arquivo_path']);
    exit(json_encode(['ok' => true, 'excluida' => $capaId]));
}
if ($acao === 'reabrir') {
    // capa confirmada volta para revisão; códigos de integração ficam; linhas já exportadas guardam um retrato para detectar alteração
    if ($capa['status'] !== 'confirmada') falha('só capas confirmadas podem ser reabertas');
    $q = $pdo->prepare("SELECT id FROM cf_capas WHERE capa_anterior_id = ? AND status IN ('revisao','confirmada')"); $q->execute([$capaId]);
    if ($q->fetch()) falha('esta capa já tem uma versão mais nova — edite a versão mais recente');
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status IN ('confirmado','exportado')"); $q->execute([$capaId]);
        $up = $pdo->prepare("UPDATE cf_lancamentos SET status = 'revisao', snapshot_exp = ? WHERE id = ?");
        foreach ($q->fetchAll() as $l) $up->execute([$l['status'] === 'exportado' ? json_encode(cf_retrato($l), JSON_UNESCAPED_UNICODE) : $l['snapshot_exp'], $l['id']]);
        $pdo->prepare("UPDATE cf_capas SET status = 'revisao', confirmado_em = NULL, confirmado_por = NULL WHERE id = ?")->execute([$capaId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); falha('erro ao reabrir: ' . $e->getMessage(), 500); }
    exit(json_encode(['ok' => true]));
}
if ($acao === 'quem_paga') {   // vale para capa em revisão e para capa já confirmada (lista das antigas)
    try { $r = cf_qp_aplicar($pdo, $capa, (string)($in['modo'] ?? ''), (array)($in['sinal_ids'] ?? []), is_array($in['cad'] ?? null) ? $in['cad'] : null, $u); }
    catch (Throwable $e) { falha($e->getMessage()); }
    $st = $pdo->prepare('SELECT * FROM cf_capas WHERE id = ?'); $st->execute([$capaId]); $capa = $st->fetch();
    exit(json_encode(['ok' => true] + $r + ['pendencias' => $capa['status'] === 'revisao' ? cf_pendencias($pdo, $capa) : [], 'quem_paga' => cf_qp_atual($capa)], JSON_UNESCAPED_UNICODE));
}

if ($capa['status'] !== 'revisao') falha('esta capa não está mais em revisão');

function cf_linha(PDO $pdo, int $id, int $capaId): array
{
    $st = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE id = ? AND capa_id = ?'); $st->execute([$id, $capaId]);
    $l = $st->fetch(); if (!$l) falha('linha não encontrada', 404);
    return $l;
}
function cf_tem_grave_pendente(array $l): bool
{
    $fl = json_decode((string)$l['flags'], true) ?: [];
    return (bool)cf_flags_alerta($fl) && !(int)$l['revisado'];
}

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
            if ($pid) { $q = $pdo->prepare('SELECT id FROM cf_pessoas WHERE id = ?'); $q->execute([$pid]); if (!$q->fetch()) falha('pessoa inválida'); }
            $pdo->prepare('UPDATE cf_lancamentos SET pessoa_id = ? WHERE id = ?')->execute([$pid, $l['id']]);
            // escolher a pessoa puxa a chave Pix cadastrada dela (sobrescreve a da linha); sem chave cadastrada, mantém o que está
            $pixPessoa = null;
            if ($pid) { $q = $pdo->prepare('SELECT chave_pix FROM cf_pessoas WHERE id = ?'); $q->execute([$pid]); $pixPessoa = $q->fetchColumn() ?: null; }
            if ($pixPessoa) { $pdo->prepare('UPDATE cf_lancamentos SET chave_pix = ? WHERE id = ?')->execute([$pixPessoa, $l['id']]); }
            $resp['chave_pix'] = $pixPessoa ?? ($l['chave_pix'] ?: '');
            $resp['pessoa_tem_pix'] = (bool)$pixPessoa;
            if ($pid && !empty($in['salvar_alias'])) cf_add_alias($pid, $l['cf_raw']);
            if ($pid && !empty($in['aplicar_todas'])) {
                // mesma pessoa em todas as linhas em revisão desta capa com o mesmo favorecido (coluna C)
                $q = $pdo->prepare("SELECT id, cf_raw FROM cf_lancamentos WHERE capa_id = ? AND tipo = 'P' AND status = 'revisao' AND id <> ?");
                $q->execute([$capaId, $l['id']]);
                $ids = [];
                foreach ($q->fetchAll() as $o) if (CapaParser::key($o['cf_raw']) === CapaParser::key($l['cf_raw'])) $ids[] = (int)$o['id'];
                if ($ids) {
                    $pdo->prepare('UPDATE cf_lancamentos SET pessoa_id = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$pid]);
                    if ($pixPessoa) $pdo->prepare('UPDATE cf_lancamentos SET chave_pix = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$pixPessoa]);
                }
                $resp['aplicado_em'] = $ids;
            }
            break;
        case 'funcao':
        case 'natureza':
            $v = CapaParser::key((string)$valor);
            if ($campo === 'natureza' && !in_array($v, ['COMISSAO', 'BONUS', 'REPASSE'], true)) falha('natureza inválida');
            $funcao = $campo === 'funcao' ? $v : $l['funcao']; $nat = $campo === 'natureza' ? $v : $l['natureza'];
            if ($nat === 'REPASSE') $funcao = null;
            $cat = cf_categoria('P', $funcao, $nat, $l['status_imovel']);
            $flags = [];
            if ($nat === 'REPASSE') $nf = 'REPASSE';
            else [$nf] = cf_parser()->buildNf($nat, $l['col_g'], $l['prefixo'], $flags);
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
        case 'chave_pix':
            $a = Pix::analisar((string)$valor);
            if (!$a['valido']) falha('Chave Pix inválida: ' . $a['erro']);
            $pdo->prepare('UPDATE cf_lancamentos SET chave_pix = ? WHERE id = ?')->execute([$a['valor'], $l['id']]);
            $resp['chave_pix'] = $a['valor']; $resp['pix_tipo'] = $a['tipo'];
            // guarda como padrão da pessoa se ela ainda não tem
            if ($a['valor'] && $l['pessoa_id'] && !empty($in['salvar_na_pessoa'])) {
                $pdo->prepare("UPDATE cf_pessoas SET chave_pix = ? WHERE id = ? AND (chave_pix IS NULL OR chave_pix = '')")->execute([$a['valor'], $l['pessoa_id']]);
            }
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

case 'revisar_varios':
    $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
    if ($ids) $pdo->prepare("UPDATE cf_lancamentos SET revisado = 1 WHERE capa_id = ? AND status = 'revisao' AND id IN (" . implode(',', $ids) . ')')->execute([$capaId]);
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

case 'conferir_capa_flag':
    // o usuário leu um aviso grave da capa (ex.: comprador com CNPJ) e confirmou que está certo
    $cod = preg_replace('/[^A-Z_]/', '', (string)($in['codigo'] ?? '')); if ($cod === '') falha('código do aviso vazio');
    $ok = json_decode((string)($capa['capa_flags_ok'] ?? ''), true) ?: [];
    if (!empty($in['desfazer'])) $ok = array_values(array_diff($ok, [$cod])); elseif (!in_array($cod, $ok, true)) $ok[] = $cod;
    $pdo->prepare('UPDATE cf_capas SET capa_flags_ok = ? WHERE id = ?')->execute([json_encode($ok), $capaId]);
    $capa['capa_flags_ok'] = json_encode($ok);
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;
case 'editar_capa':
    $campo = (string)($in['campo'] ?? '');
    if ($campo === 'cod') {
        $v = preg_replace('/\D+/', '', (string)($in['valor'] ?? '')) ?: null;
        $mudou = (string)$v !== (string)$capa['cod'];
        $pdo->prepare('UPDATE cf_capas SET cod = ? WHERE id = ?')->execute([$v, $capaId]);
        $capa['cod'] = $v;
        if ($mudou) {   // COD corrigido: o aviso "COD repetido em capa de outro cliente" deixa de valer
            $cfl = json_decode((string)($capa['capa_flags'] ?? ''), true) ?: [];
            $novo = array_values(array_filter($cfl, fn($f) => cf_flag_codigo($f) !== 'COD_REPETIDO_OUTRO_CLIENTE'));
            if (count($novo) !== count($cfl)) { $pdo->prepare('UPDATE cf_capas SET capa_flags = ? WHERE id = ?')->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), $capaId]); $capa['capa_flags'] = json_encode($novo, JSON_UNESCAPED_UNICODE); }
        }
    } elseif ($campo === 'cliente' || $campo === 'construtora') {
        $v = CapaParser::norm((string)($in['valor'] ?? '')); if ($v === '') falha('nome vazio');
        $pdo->prepare("UPDATE cf_capas SET $campo = ? WHERE id = ?")->execute([$v, $capaId]);
        // as linhas cujo único alerta era essa divergência ficam conferidas; as outras só perdem esse alerta
        $cods = $campo === 'cliente' ? ['CLIENTES_HIST_GRAFIA', 'CLIENTES_HIST_DIFEREM_CAPA'] : ['CONSTRUTORA_HIST_GRAFIA', 'CONSTRUTORA_HIST_DIFERE_CAPA'];
        $q = $pdo->prepare("SELECT id, flags FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao'"); $q->execute([$capaId]);
        $up = $pdo->prepare('UPDATE cf_lancamentos SET flags = ? WHERE id = ?');
        foreach ($q->fetchAll() as $l) {
            $fl = json_decode((string)$l['flags'], true) ?: [];
            $novo = array_values(array_filter($fl, fn($f) => !in_array(cf_flag_codigo($f), $cods, true)));
            if (count($novo) !== count($fl)) $up->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), $l['id']]);
        }
    } elseif ($campo === 'data_venda') {
        // aceita dd/mm/aaaa ou aaaa-mm-dd; vale para observação/recibo e recalcula "data anterior à venda" nas linhas
        $raw = trim((string)($in['valor'] ?? ''));
        $dt = DateTimeImmutable::createFromFormat('!d/m/Y', $raw) ?: DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$dt || $dt->format('Y') < 2000) falha('data inválida');
        $v = $dt->format('Y-m-d');
        $pdo->prepare('UPDATE cf_capas SET data_venda = ? WHERE id = ?')->execute([$v, $capaId]);
        $q = $pdo->prepare("SELECT id, flags, data_prevista FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao'"); $q->execute([$capaId]);
        $up = $pdo->prepare('UPDATE cf_lancamentos SET flags = ? WHERE id = ?');
        foreach ($q->fetchAll() as $l) {
            $fl = json_decode((string)$l['flags'], true) ?: [];
            $novo = array_values(array_filter($fl, fn($f) => !in_array(cf_flag_codigo($f), ['DATA_VENDA_HIST_DIFERE_G3', 'ANTERIOR_A_VENDA'], true)));
            if ($l['data_prevista'] && $l['data_prevista'] < $v) $novo[] = 'ANTERIOR_A_VENDA';
            if ($novo !== $fl) $up->execute([json_encode($novo, JSON_UNESCAPED_UNICODE), $l['id']]);
        }
    } else falha('campo inválido');
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;

case 'nova_pessoa':
    // cadastra direto da revisão e já associa à linha (e às demais do mesmo favorecido, se pedido)
    $l = cf_linha($pdo, (int)($in['id'] ?? 0), $capaId);
    $nome = CapaParser::norm((string)($in['nome'] ?? ''));
    if ($nome === '') falha('informe o nome');
    $key = CapaParser::key($nome);
    $q = $pdo->prepare('SELECT id FROM cf_pessoas WHERE nome_key = ?'); $q->execute([$key]);
    if ($ex = $q->fetchColumn()) falha('já existe uma pessoa com esse nome (id ' . (int)$ex . ') — selecione na lista');
    $dig = fn(string $x) => preg_replace('/\D+/', '', $x) ?? '';
    $cnpj = $dig((string)($in['cnpj'] ?? '')); $cpf = $dig((string)($in['cpf'] ?? ''));
    $fmtCnpj = fn(string $d) => strlen($d) === 14 ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d) : $d;
    $fmtCpf = fn(string $d) => strlen($d) === 11 ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d) : $d;
    $pagarPor = ($in['pagar_por'] ?? '') === 'CPF' || (!$cnpj && $cpf) ? 'CPF' : 'CNPJ';
    $px = Pix::analisar((string)($in['chave_pix'] ?? ''));
    if (!$px['valido']) falha('Chave Pix inválida: ' . $px['erro']);
    $pdo->prepare('INSERT INTO cf_pessoas (nome, nome_key, razao_social, cnpj, cpf, pagar_por, chave_pix, ativo) VALUES (?,?,?,?,?,?,?,1)')
        ->execute([$nome, $key, CapaParser::norm((string)($in['razao_social'] ?? '')) ?: null, $cnpj ? $fmtCnpj($cnpj) : null, $cpf ? $fmtCpf($cpf) : null, $pagarPor, $px['valor']]);
    $pid = (int)$pdo->lastInsertId();
    if (CapaParser::key($l['cf_raw']) !== $key) cf_add_alias($pid, $l['cf_raw']);
    $ids = [$l['id']];
    if (!empty($in['aplicar_todas'])) {
        $q = $pdo->prepare("SELECT id, cf_raw FROM cf_lancamentos WHERE capa_id = ? AND tipo = 'P' AND status = 'revisao' AND id <> ?");
        $q->execute([$capaId, $l['id']]);
        foreach ($q->fetchAll() as $o) if (CapaParser::key($o['cf_raw']) === CapaParser::key($l['cf_raw'])) $ids[] = (int)$o['id'];
    }
    $pdo->prepare('UPDATE cf_lancamentos SET pessoa_id = ? WHERE id IN (' . implode(',', $ids) . ')')->execute([$pid]);
    if ($px['valor']) $pdo->prepare('UPDATE cf_lancamentos SET chave_pix = ? WHERE id IN (' . implode(',', $ids) . ")")->execute([$px['valor']]);
    $resp['pessoa'] = ['id' => $pid, 'nome' => $nome, 'chave_pix' => $px['valor']];
    $resp['aplicado_em'] = array_values(array_filter($ids, fn($i) => $i !== (int)$l['id']));
    $resp['pendencias'] = cf_pendencias($pdo, $capa);
    break;

case 'descartar':
    $pdo->prepare("UPDATE cf_capas SET status = 'descartada' WHERE id = ?")->execute([$capaId]);
    break;

case 'confirmar':
    $pend = cf_pendencias($pdo, $capa);
    if ($pend) falha('Ainda há pendências: ' . implode('; ', array_slice($pend, 0, 5)));
    try { cf_confirmar_capa($pdo, $capa, $u); }
    catch (Throwable $e) { falha('erro ao confirmar: ' . $e->getMessage() . ' — nada foi gravado; tente de novo e, se repetir, avise o administrador com esta mensagem.', 500); }
    break;

default: falha('ação inválida');
}
echo json_encode($resp, JSON_UNESCAPED_UNICODE);
