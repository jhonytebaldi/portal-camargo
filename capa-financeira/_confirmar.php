<?php
/* =====================================================================
   capa-financeira/_confirmar.php — confirmação de uma capa em revisão.
   Usado pela tela de revisão (acao.php) e pelo formulário de vendas, que confirma sozinho a capa
   que ele mesmo gerou quando ela não tem nenhuma pendência nem aviso (cf_confirmar_se_limpa).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/_quem_paga.php';

/** Pendências que impedem a confirmação. */
function cf_pendencias(PDO $pdo, array $capa): array
{
    $p = [];
    if ($capa['cod'] === null || $capa['cod'] === '') $p[] = 'Capa sem COD do imóvel — informe o código (Robust)';
    $ok = json_decode((string)($capa['capa_flags_ok'] ?? ''), true) ?: [];
    foreach (json_decode((string)($capa['capa_flags'] ?? ''), true) ?: [] as $cf) if (str_starts_with($cf, 'GRAVE:') && !in_array(cf_flag_codigo($cf), $ok, true)) $p[] = 'Capa: ' . cf_flag_curto($cf) . ' — confirme no aviso da capa';
    if (!cf_qp_atual($capa) && cf_qp_linhas($pdo, (int)$capa['id'])) $p[] = 'Responda quem pagou as parcelas (cliente ou construtora) — quadro "Quem pagou as parcelas?"';
    $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao' ORDER BY linha_xlsx");
    $st->execute([$capa['id']]);
    $n = 0;
    foreach ($st->fetchAll() as $l) {
        $n++;
        $fl = json_decode((string)$l['flags'], true) ?: [];
        if ($l['tipo'] === 'P' && !$l['pessoa_id']) $p[] = "Linha {$l['linha_xlsx']}: favorecido não identificado";
        if (!$l['data_prevista']) $p[] = "Linha {$l['linha_xlsx']}: sem data prevista";
        if (!$l['categoria']) $p[] = "Linha {$l['linha_xlsx']}: sem categoria";
        if (cf_flags_alerta($fl) && !(int)$l['revisado']) $p[] = "Linha {$l['linha_xlsx']}: alerta sem conferir";
    }
    if ($n === 0) $p[] = 'Nenhum lançamento para confirmar';
    return $p;
}
/**
 * Tudo o que pediria o olhar de alguém antes de confirmar: as pendências + qualquer aviso da capa ainda não aceito
 * (mesmo os leves, que não travam a confirmação manual). Vazio = a capa pode ser confirmada sem ninguém abrir a revisão.
 */
function cf_avisos_para_confirmar(PDO $pdo, array $capa): array
{
    $p = cf_pendencias($pdo, $capa);
    $ok = json_decode((string)($capa['capa_flags_ok'] ?? ''), true) ?: [];
    foreach (json_decode((string)($capa['capa_flags'] ?? ''), true) ?: [] as $cf) {
        if (str_starts_with($cf, 'GRAVE:') || in_array(cf_flag_codigo($cf), $ok, true)) continue;   // as graves já estão nas pendências
        $p[] = 'Aviso da capa: ' . cf_flag_curto($cf);
    }
    return $p;
}

/**
 * Confirma a capa: cada linha em revisão ganha o código de integração (ou herda o da versão anterior) e vai para
 * "Exportar para o Omie"; a versão anterior fica substituída. Quem chama confere as pendências antes.
 * Tudo numa transação: se algo falhar, nada fica gravado (a exceção sobe para quem chamou).
 */
function cf_confirmar_capa(PDO $pdo, array $capa, array $u): void
{
    $capaId = (int)$capa['id'];
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status = 'revisao' ORDER BY linha_xlsx");
        $st->execute([$capaId]);
        $linhas = $st->fetchAll();
        $seq = $pdo->prepare('INSERT INTO cf_sequencias (cod, tipo, ultimo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ultimo = ultimo + 1');
        $seqGet = $pdo->prepare('SELECT ultimo FROM cf_sequencias WHERE cod = ? AND tipo = ?');
        $upd = $pdo->prepare("UPDATE cf_lancamentos SET status = ?, codigo_integracao = ?, alteracao_pos_exportacao = ? WHERE id = ?");
        $ant = $pdo->prepare('SELECT * FROM cf_lancamentos WHERE id = ?');
        $subst = $pdo->prepare("UPDATE cf_lancamentos SET status = 'substituido' WHERE id = ? AND status = 'confirmado'");
        $herda = $pdo->prepare('UPDATE cf_lancamentos SET exportacao_id = ?, omie_id = ?, snapshot_exp = ?, recibo_id = ? WHERE id = ?');
        foreach ($linhas as $l) {
            $codigo = null; $posExp = (int)$l['alteracao_pos_exportacao']; $novoStatus = 'confirmado';
            if ($l['codigo_integracao']) {
                // capa reaberta: mantém o código; se a linha já tinha ido para o Omie, volta como exportada e marca se mudou algo
                $codigo = $l['codigo_integracao'];
                if ($l['exportacao_id']) {
                    $novoStatus = 'exportado';
                    if (cf_diferencas_exp(json_decode((string)$l['snapshot_exp'], true), $l)) $posExp = 1;
                }
            } elseif ($l['dup_de'] && in_array($l['dup_tipo'], ['IGUAL', 'ALTERADA'], true)) {
                $ant->execute([$l['dup_de']]); $a = $ant->fetch();
                if ($a && $a['codigo_integracao']) {
                    $codigo = $a['codigo_integracao'];
                    if ($a['status'] === 'exportado' || $a['exportacao_id'] || $a['omie_id']) {
                        // já está no Omie: a linha da versão nova HERDA o envio (não volta para a fila de exportação nem gera
                        // recibo de novo). Se mudou algo relevante desde o envio, fica marcada como "alterada depois de exportar".
                        $novoStatus = 'exportado';
                        $snap = json_decode((string)$a['snapshot_exp'], true);
                        $posExp = $snap ? (cf_diferencas_exp($snap, $l) ? 1 : (int)$a['alteracao_pos_exportacao']) : ($l['dup_tipo'] === 'ALTERADA' ? 1 : (int)$a['alteracao_pos_exportacao']);
                        $herda->execute([$a['exportacao_id'], $a['omie_id'], $a['snapshot_exp'], $a['recibo_id'] ?? null, $l['id']]);
                        $pdo->prepare("UPDATE cf_lancamentos SET status = 'substituido' WHERE id = ?")->execute([$a['id']]);
                    } else {
                        $subst->execute([$a['id']]);
                    }
                }
            }
            if ($codigo === null) {
                $seq->execute([(string)$capa['cod'], $l['tipo']]);
                $seqGet->execute([(string)$capa['cod'], $l['tipo']]);
                $n = (int)$seqGet->fetchColumn();
                $codigo = strtoupper((string)$capa['cod']) . '-' . $l['tipo'] . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
            }
            $upd->execute([$novoStatus, $codigo, $posExp, $l['id']]);
        }
        // linhas exportadas que foram removidas na reabertura: precisam ser excluídas no Omie
        $pdo->prepare("UPDATE cf_lancamentos SET alteracao_pos_exportacao = 1 WHERE capa_id = ? AND status = 'removido' AND exportacao_id IS NOT NULL")->execute([$capaId]);
        // versão anterior: capa e linhas que não vieram na nova versão
        if ($capa['capa_anterior_id']) {
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'substituido' WHERE capa_id = ? AND status = 'confirmado'")->execute([$capa['capa_anterior_id']]);
            $pdo->prepare("UPDATE cf_capas SET status = 'substituida' WHERE id = ? AND status IN ('revisao','confirmada')")->execute([$capa['capa_anterior_id']]);
        }
        $pdo->prepare("UPDATE cf_capas SET status = 'confirmada', confirmado_em = NOW(), confirmado_por = ? WHERE id = ?")->execute([$u['id'], $capaId]);
        $qpC = cf_qp_atual($capa);
        if ($qpC && empty($qpC['contado'])) { cf_qp_contar($capa, $qpC, $u); $qpC['contado'] = true; $pdo->prepare('UPDATE cf_capas SET quem_paga = ? WHERE id = ?')->execute([json_encode($qpC, JSON_UNESCAPED_UNICODE), $capaId]); }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}

/**
 * Confirma a capa se ela não tiver nenhuma pendência nem aviso. Devolve [] quando confirmou; senão a lista do que
 * precisa ser visto na revisão (a capa continua em revisão, nada muda).
 */
function cf_confirmar_se_limpa(PDO $pdo, int $capaId, array $u): array
{
    $st = $pdo->prepare('SELECT * FROM cf_capas WHERE id = ?'); $st->execute([$capaId]);
    $capa = $st->fetch();
    if (!$capa) return ['capa #' . $capaId . ' não encontrada'];
    if ($capa['status'] !== 'revisao') return [];
    $av = cf_avisos_para_confirmar($pdo, $capa);
    if ($av) return $av;
    cf_confirmar_capa($pdo, $capa, $u);
    return [];
}
