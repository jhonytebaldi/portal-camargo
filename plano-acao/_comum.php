<?php
/* =====================================================================
   plano-acao/_comum.php — helpers do módulo Plano de Ação Diário.

   O plano é gerado FORA do portal (tarefa agendada no Claude, seg–sex de
   manhã) e entra pelos endpoints api-importar.php / api-estado.php com o
   token de serviço PLANO_ACAO_TOKEN (config.php, fora da web).
   Aqui dentro o portal só exibe, escopa por permissão e registra os checks.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';

/** Valida o token de serviço dos endpoints de API (tarefa agendada). */
function pa_exige_token(): void {
    portal_load_config();
    if (!defined('PLANO_ACAO_TOKEN') || PLANO_ACAO_TOKEN === '') {
        http_response_code(500);
        exit(json_encode(['ok' => false, 'erro' => 'PLANO_ACAO_TOKEN não configurado no config.php']));
    }
    $t = $_SERVER['HTTP_X_PORTAL_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!is_string($t) || !hash_equals(PLANO_ACAO_TOKEN, $t)) {
        http_response_code(401);
        exit(json_encode(['ok' => false, 'erro' => 'token inválido']));
    }
}

/**
 * IDs de atendente do ROBUST que o usuário pode ver.
 * Tradução do escopo padrão do portal (GHL broker ids, via equipes +
 * corretor vinculado) para o id do Robust, usando brokers.robust_user_id.
 * Admin retorna null = sem restrição.
 */
function pa_allowed_robust_ids(?array $u = null): ?array {
    $u = $u ?? current_user();
    if (is_admin($u)) return null;
    $ghl = allowed_broker_ids($u);
    if (!$ghl) return [];
    $in = implode(',', array_fill(0, count($ghl), '?'));
    $st = db()->prepare("SELECT robust_user_id FROM brokers
                         WHERE id IN ($in) AND robust_user_id IS NOT NULL");
    $st->execute($ghl);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Rótulos compartilhados (mesma taxonomia do gerador do plano). */
function pa_faixas(): array {
    return [
        'vermelho' => ['emoji' => '🔴', 'rotulo' => 'Agora cedo'],
        'amarelo'  => ['emoji' => '🟡', 'rotulo' => 'Ainda hoje'],
        'azul'     => ['emoji' => '🔵', 'rotulo' => 'Esta semana'],
        'branco'   => ['emoji' => '⚪', 'rotulo' => 'Manter / avaliar encerrar'],
    ];
}

function pa_stages(): array {
    return [0 => 'Lead', 1 => 'Atendimento', 2 => 'Agendamento', 3 => 'Visita', 4 => 'Proposta', 5 => 'Negociado'];
}
