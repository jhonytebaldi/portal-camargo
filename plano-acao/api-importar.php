<?php
/* =====================================================================
   plano-acao/api-importar.php — recebe o plano do dia (POST JSON).

   Chamado pela tarefa agendada (Claude) com header X-Portal-Token.
   Corpo:
   {
     "data": "2026-08-24",
     "clientes": [ { atendimento_id, cliente_id, nome, telefones,
                     robust_atendente, broker_id, stage, ghl_contact_id,
                     ghl_conv_id, last_msg_at, last_analise_at, resumo } ],
     "planos": [ { robust_atendente, broker_id, corretor_nome,
                   texto_whatsapp,
                   itens: [ { atendimento_id, cliente_nome, telefones, stage,
                              acao, titulo, justificativa, msg_sugerida,
                              score, faixa, origem } ] } ]
   }
   Reimportar o mesmo dia substitui o plano, PRESERVANDO os checks já
   feitos (casados por atendimento_id) — a rotina pode rodar 2x sem
   apagar o trabalho do corretor.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
header('Content-Type: application/json; charset=utf-8');
pa_exige_token();

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['data'])) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'erro' => 'JSON inválido ou sem "data"']));
}
$data = (string)$body['data'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    http_response_code(400);
    exit(json_encode(['ok' => false, 'erro' => 'data deve ser AAAA-MM-DD']));
}

$pdo = db();
$pdo->beginTransaction();
try {
    /* ---- upsert do cache de clientes ---- */
    $nCli = 0;
    if (!empty($body['clientes']) && is_array($body['clientes'])) {
        $up = $pdo->prepare(
            'INSERT INTO pa_clientes (atendimento_id, cliente_id, nome, telefones,
                robust_atendente, broker_id, stage, ghl_contact_id, ghl_conv_id,
                last_msg_at, last_analise_at, resumo, atualizado_em)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE cliente_id=VALUES(cliente_id), nome=VALUES(nome),
                telefones=VALUES(telefones), robust_atendente=VALUES(robust_atendente),
                broker_id=VALUES(broker_id), stage=VALUES(stage),
                ghl_contact_id=VALUES(ghl_contact_id), ghl_conv_id=VALUES(ghl_conv_id),
                last_msg_at=VALUES(last_msg_at), last_analise_at=VALUES(last_analise_at),
                resumo=VALUES(resumo), atualizado_em=NOW()'
        );
        foreach ($body['clientes'] as $c) {
            if (empty($c['atendimento_id'])) continue;
            $up->execute([
                (int)$c['atendimento_id'], (int)($c['cliente_id'] ?? 0),
                (string)($c['nome'] ?? ''), (string)($c['telefones'] ?? ''),
                (int)($c['robust_atendente'] ?? 0), ($c['broker_id'] ?? null) ?: null,
                (int)($c['stage'] ?? 0), ($c['ghl_contact_id'] ?? null) ?: null,
                ($c['ghl_conv_id'] ?? null) ?: null,
                isset($c['last_msg_at']) ? (int)$c['last_msg_at'] : null,
                ($c['last_analise_at'] ?? null) ?: null,
                ($c['resumo'] ?? null) ?: null,
            ]);
            $nCli++;
        }
    }

    /* ---- planos do dia (substitui preservando checks) ---- */
    $nPlanos = 0; $nItens = 0;
    $selVelho = $pdo->prepare('SELECT id FROM pa_planos WHERE data = ? AND robust_atendente = ?');
    $selFeito = $pdo->prepare(
        'SELECT i.atendimento_id, i.feito, i.feito_em, i.feito_por, i.feito_auto
           FROM pa_itens i WHERE i.plano_id = ? AND i.feito = 1');
    $delVelho = $pdo->prepare('DELETE FROM pa_planos WHERE id = ?');
    $insPlano = $pdo->prepare(
        'INSERT INTO pa_planos (data, robust_atendente, broker_id, corretor_nome, texto_whatsapp, criado_em)
         VALUES (?,?,?,?,?,NOW())');
    $insItem = $pdo->prepare(
        'INSERT INTO pa_itens (plano_id, atendimento_id, cliente_nome, telefones, stage,
            acao, titulo, justificativa, msg_sugerida, nome_sugerido, score, faixa, origem,
            feito, feito_em, feito_por, feito_auto)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

    foreach (($body['planos'] ?? []) as $p) {
        if (!isset($p['robust_atendente'])) continue;
        $ra = (int)$p['robust_atendente'];

        // preserva checks de um plano anterior do mesmo dia
        $feitos = [];
        $selVelho->execute([$data, $ra]);
        if ($velhoId = $selVelho->fetchColumn()) {
            $selFeito->execute([(int)$velhoId]);
            foreach ($selFeito->fetchAll() as $f) $feitos[(int)$f['atendimento_id']] = $f;
            $delVelho->execute([(int)$velhoId]); // pa_itens cai via ON DELETE CASCADE
        }

        $insPlano->execute([
            $data, $ra, ($p['broker_id'] ?? null) ?: null,
            (string)($p['corretor_nome'] ?? ''), (string)($p['texto_whatsapp'] ?? ''),
        ]);
        $planoId = (int)$pdo->lastInsertId();
        $nPlanos++;

        foreach (($p['itens'] ?? []) as $it) {
            $aid = (int)($it['atendimento_id'] ?? 0);
            $f = $feitos[$aid] ?? null;
            $faixa = in_array(($it['faixa'] ?? ''), ['vermelho','amarelo','azul','branco'], true)
                   ? $it['faixa'] : 'branco';
            $insItem->execute([
                $planoId, $aid,
                (string)($it['cliente_nome'] ?? ''), (string)($it['telefones'] ?? ''),
                (int)($it['stage'] ?? 0), (string)($it['acao'] ?? ''),
                (string)($it['titulo'] ?? ''), ($it['justificativa'] ?? null) ?: null,
                ($it['msg_sugerida'] ?? null) ?: null,
                ($it['nome_sugerido'] ?? null) ?: null,
                max(0, min(100, (int)($it['score'] ?? 0))), $faixa,
                (($it['origem'] ?? '') === 'andamentos') ? 'andamentos' : 'conversa',
                $f ? 1 : 0, $f['feito_em'] ?? null,
                ($f && $f['feito_por'] !== null) ? (int)$f['feito_por'] : null,
                $f ? (int)($f['feito_auto'] ?? 0) : 0,
            ]);
            $nItens++;
        }
    }

    /* ---- checks automáticos: a varredura detectou que a tarefa de um dia
       anterior foi cumprida (ex.: corretor respondeu, visita registrada).
       Marca feito=1/feito_auto=1 sem sobrescrever check manual existente. */
    $nAuto = 0;
    if (!empty($body['auto_checks']) && is_array($body['auto_checks'])) {
        $upAuto = $pdo->prepare(
            'UPDATE pa_itens i JOIN pa_planos p ON p.id = i.plano_id
                SET i.feito = 1, i.feito_auto = 1, i.feito_em = COALESCE(i.feito_em, NOW())
              WHERE p.data = ? AND i.atendimento_id = ? AND i.feito = 0');
        foreach ($body['auto_checks'] as $ac) {
            if (empty($ac['data']) || empty($ac['atendimento_id'])) continue;
            $upAuto->execute([(string)$ac['data'], (int)$ac['atendimento_id']]);
            $nAuto += $upAuto->rowCount();
        }
    }

    $pdo->commit();
    echo json_encode(['ok' => true, 'data' => $data,
        'clientes' => $nCli, 'planos' => $nPlanos, 'itens' => $nItens,
        'auto_checks' => $nAuto]);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => $e->getMessage()]);
}
