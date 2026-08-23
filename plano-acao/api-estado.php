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

echo json_encode([
    'ok' => true,
    'ultimo_plano' => $ultimo ?: null,
    'brokers' => $brokers,
    'clientes' => $clientes,
], JSON_UNESCAPED_UNICODE);
