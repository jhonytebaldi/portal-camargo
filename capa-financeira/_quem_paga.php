<?php
/* =====================================================================
   capa-financeira/_quem_paga.php — "Quem pagou as parcelas?" nas capas enviadas (.xlsx).
   Toda capa com contas a receber precisa da resposta antes de confirmar:
     cliente      → as contas a receber ficam no nome do comprador (padrão da capa)
     construtora  → as parcelas ficam no cadastro da construtora no Omie; as linhas marcadas
                    como SINAL (arras pago direto pelo cliente) continuam no nome do comprador
   Resposta em cf_capas.quem_paga (JSON). Em capa já confirmada (lista das antigas), linha já
   enviada ao Omie que mudar de cliente entra em "Alterações depois da exportação" (aplicar pela API).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/vendas/_vendas.php';

/** linhas a receber "vivas" da capa */
function cf_qp_linhas(PDO $pdo, int $capaId): array
{
    $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND tipo = 'R' AND status IN ('revisao','confirmado','exportado') ORDER BY data_prevista, linha_xlsx");
    $st->execute([$capaId]);
    return $st->fetchAll();
}

function cf_qp_atual(array $capa): ?array
{
    $q = json_decode((string)($capa['quem_paga'] ?? ''), true);
    return is_array($q) && !empty($q['modo']) ? $q : null;
}

/** dados para a pergunta na tela: linhas, sugestão (versão anterior da capa > histórico da construtora), sinal padrão */
function cf_qp_contexto(PDO $pdo, array $capa): array
{
    $linhas = cf_qp_linhas($pdo, (int)$capa['id']);
    $atual = cf_qp_atual($capa);
    $ant = null;
    if (!$atual && $capa['capa_anterior_id']) { $q = $pdo->prepare('SELECT quem_paga FROM cf_capas WHERE id = ?'); $q->execute([$capa['capa_anterior_id']]); $ant = cf_qp_atual(['quem_paga' => $q->fetchColumn()]); }
    $sug = trim((string)$capa['construtora']) !== '' ? cv_construtora_sugestao((string)$capa['construtora'], (string)$capa['empresa']) : null;
    $lim = $capa['data_venda'] ? date('Y-m-d', strtotime($capa['data_venda'] . ' +' . Fluxo::DIAS_ARRAS . ' days')) : null;
    return ['capa_id' => (int)$capa['id'], 'construtora' => (string)$capa['construtora'], 'empresa' => (string)$capa['empresa'], 'cliente' => (string)$capa['cliente'],
            'atual' => $atual, 'anterior' => $ant, 'sugestao' => $sug, 'confirmada' => $capa['status'] === 'confirmada',
            'linhas' => array_map(fn($l) => ['id' => (int)$l['id'], 'data' => $l['data_prevista'], 'valor' => (float)$l['valor'], 'status' => $l['status'], 'codigo' => $l['codigo_integracao'],
                                             'sinal_padrao' => $lim && $l['data_prevista'] && $l['data_prevista'] <= $lim], $linhas)];
}

/**
 * Grava a resposta e ajusta o cliente das contas a receber.
 * @return array{alteradas:int, pos_exportacao:int}
 */
function cf_qp_aplicar(PDO $pdo, array $capa, string $modo, array $sinalIds, ?array $cad, array $u): array
{
    if (!in_array($capa['status'], ['revisao', 'confirmada'], true)) throw new RuntimeException('capa descartada/substituída — abra a versão atual');
    if (!in_array($modo, ['cliente', 'construtora'], true)) throw new RuntimeException('escolha quem pagou as parcelas');
    $linhas = cf_qp_linhas($pdo, (int)$capa['id']);
    if (!$linhas) throw new RuntimeException('esta capa não tem contas a receber');
    $antes = cf_qp_atual($capa);
    $valorDe = function (?array $c): string { $d = preg_replace('/\D+/', '', (string)($c['doc'] ?? '')); return in_array(strlen($d), [11, 14], true) ? $d : mb_substr((string)($c['nome'] ?? ''), 0, 60); };
    $novo = '';
    if ($modo === 'construtora') {
        if (!$cad || empty($cad['codigo']) || trim((string)($cad['nome'] ?? '')) === '') throw new RuntimeException('escolha o cadastro da construtora no Omie');
        $novo = $valorDe($cad);
    }
    $velho = $antes && $antes['modo'] === 'construtora' ? $valorDe($antes['cad'] ?? null) : '';
    $sinalIds = array_map('intval', $sinalIds);
    $mesmo = fn($a, $b) => preg_replace('/\D+/', '', (string)$a) !== '' ? preg_replace('/\D+/', '', (string)$a) === preg_replace('/\D+/', '', (string)$b) : (string)$a === (string)$b;
    $n = 0; $pos = 0;
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE cf_lancamentos SET cliente_omie = ?, snapshot_exp = COALESCE(snapshot_exp, ?), alteracao_pos_exportacao = ? WHERE id = ?');
        foreach ($linhas as $l) {
            $at = (string)$l['cliente_omie'];
            $ehSinal = in_array((int)$l['id'], $sinalIds, true);
            if ($modo === 'construtora' && !$ehSinal) $alvo = $novo;
            else $alvo = ($velho !== '' && $mesmo($at, $velho)) || ($novo !== '' && $mesmo($at, $novo)) ? '' : $at;   // volta ao comprador só se estava na construtora
            if ($alvo === $at || ($alvo !== '' && $at !== '' && $mesmo($alvo, $at))) continue;
            $exp = $l['status'] === 'exportado';
            $up->execute([$alvo !== '' ? $alvo : null, $exp ? json_encode(cf_retrato($l), JSON_UNESCAPED_UNICODE) : null, $exp ? 1 : (int)$l['alteracao_pos_exportacao'], (int)$l['id']]);
            $n++; if ($exp) $pos++;
        }
        $contado = !empty($antes['contado']) && $antes['modo'] === $modo;
        $qp = ['modo' => $modo, 'sinal_ids' => $modo === 'construtora' ? array_values($sinalIds) : [], 'cad' => $modo === 'construtora' ? ['codigo' => (int)$cad['codigo'], 'nome' => (string)$cad['nome'], 'doc' => (string)($cad['doc'] ?? '')] : null,
               'por' => (string)$u['nome'], 'em' => date('Y-m-d H:i:s'), 'contado' => $contado];
        // capa já confirmada: a decisão já vale → entra no histórico da construtora agora (na revisão, entra ao confirmar)
        if ($capa['status'] === 'confirmada' && !$contado) { cf_qp_contar($capa, $qp, $u); $qp['contado'] = true; }
        $pdo->prepare('UPDATE cf_capas SET quem_paga = ? WHERE id = ?')->execute([json_encode($qp, JSON_UNESCAPED_UNICODE), (int)$capa['id']]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['alteradas' => $n, 'pos_exportacao' => $pos];
}

/** decisão confirmada → histórico da construtora (sugestão das próximas capas e vendas) */
function cf_qp_contar(array $capa, array $qp, array $u): void
{
    if (trim((string)$capa['construtora']) === '') return;
    $sinal = $qp['modo'] === 'construtora' && empty($qp['sinal_ids']) ? 'construtora' : 'cliente';
    cv_construtora_gravar((string)$capa['construtora'], $qp['modo'], $sinal, null, $qp['cad'] ?? null, $u, true);
}
