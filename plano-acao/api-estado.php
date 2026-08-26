<?php
/* =====================================================================
   plano-acao/api-estado.php — estado para a tarefa agendada (GET).

   A tarefa começa o dia lendo daqui (o ambiente do Claude zera entre
   execuções; o banco do portal é a memória do módulo). Retorna:
     - brokers: de-para GHL ↔ Robust + status ativo (quem entra no plano)
     - clientes: cache por atendimento (vínculo GHL, última análise, resumo)
     - ultimo_plano: data mais recente já importada
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
header('Content-Type: application/json; charset=utf-8');
pa_exige_token();

$pdo = db();
$brokers = $pdo->query(
    'SELECT id AS broker_id, robust_user_id, nome, ativo FROM brokers'
)->fetchAll();

$clientes = $pdo->query(
    'SELECT atendimento_id, cliente_id, nome, telefones, robust_atendente, broker_id,
            stage, ghl_contact_id, ghl_conv_id, last_msg_at, last_analise_at, resumo
       FROM pa_clientes'
)->fetchAll();

$ultimo = $pdo->query('SELECT MAX(data) FROM pa_planos')->fetchColumn();

/* Itens do plano mais recente (para carry-forward e auto-check da rotina):
   a tarefa reaproveita a análise de quem não teve atividade nova e detecta
   tarefas cumpridas comparando com o que estava pendente. */
$planosDia = []; $itensDia = [];
if ($ultimo) {
    $st = $pdo->prepare('SELECT id, robust_atendente, broker_id, corretor_nome, criado_em
                           FROM pa_planos WHERE data = ?');
    $st->execute([$ultimo]);
    $planosDia = $st->fetchAll();
    $ids = array_map(fn($p) => (int)$p['id'], $planosDia);
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT plano_id, atendimento_id, cliente_nome, telefones, stage,
                                    acao, titulo, justificativa, msg_sugerida, nome_sugerido,
                                    score, faixa, origem, feito, feito_auto
                               FROM pa_itens WHERE plano_id IN ($in)");
        $st->execute($ids);
        $itensDia = $st->fetchAll();
    }
}

echo json_encode([
    'ok' => true,
    'ultimo_plano' => $ultimo ?: null,
    'planos_dia' => $planosDia,
    'itens_dia' => $itensDia,
    'brokers' => $brokers,
    'clientes' => $clientes,
], JSON_UNESCAPED_UNICODE);
