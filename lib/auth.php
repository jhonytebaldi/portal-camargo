<?php
/* =====================================================================
   lib/auth.php — autenticação e permissões compartilhadas do portal.

   Usada pela home, pelo admin e por TODOS os módulos (busca, painel, ...).
   Controle em 2 níveis:
     Nível 1  — quais ferramentas o usuário abre  (user_tools / tools)
     Nível 2  — o que ele vê dentro de cada uma    (ex.: user_teams no painel)
   O papel 'admin' enxerga tudo, sem depender das tabelas de permissão.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Inicia a sessão compartilhada (mesmo cookie em todo o subdomínio). */
function portal_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    portal_load_config();
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name(defined('PORTAL_SESSION_NAME') ? PORTAL_SESSION_NAME : 'camargo_portal');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',            // vale para /busca, /painel-corretores, etc.
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Usuário logado (linha da tabela users) ou null. */
function current_user(): ?array {
    portal_session_start();
    if (empty($_SESSION['uid'])) return null;
    static $cache = null;
    if ($cache !== null) return $cache;
    $st = db()->prepare('SELECT id, nome, login, papel, ativo, broker_id FROM users WHERE id = ? AND ativo = 1');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    return $cache = ($u ?: null);
}

function is_admin(?array $u = null): bool {
    $u = $u ?? current_user();
    return $u && $u['papel'] === 'admin';
}

/** Exige login; redireciona para /login.php guardando o destino. */
function require_login(): array {
    $u = current_user();
    if (!$u) {
        $dest = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: /login.php?next=' . urlencode($dest));
        exit;
    }
    return $u;
}

/** Lista de ferramentas ativas que o usuário pode abrir (linhas de tools). */
function user_tools(?array $u = null): array {
    $u = $u ?? current_user();
    if (!$u) return [];
    if (is_admin($u)) {
        return db()->query('SELECT * FROM tools WHERE ativo = 1 ORDER BY ordem, nome')->fetchAll();
    }
    $st = db()->prepare(
        'SELECT t.* FROM tools t
         JOIN user_tools ut ON ut.tool_id = t.id
         WHERE ut.user_id = ? AND t.ativo = 1
         ORDER BY t.ordem, t.nome'
    );
    $st->execute([$u['id']]);
    return $st->fetchAll();
}

function user_has_tool(string $slug, ?array $u = null): bool {
    foreach (user_tools($u) as $t) if ($t['slug'] === $slug) return true;
    return false;
}

/** Guarda de módulo: exige login E acesso à ferramenta $slug (senão 403). */
function require_tool(string $slug): array {
    $u = require_login();
    if (!user_has_tool($slug, $u)) {
        http_response_code(403);
        exit('Acesso negado a esta ferramenta.');
    }
    return $u;
}

/* ---- Nível 2: escopo do Painel dos Corretores (equipes/corretores) ---- */

/** IDs das equipes que o usuário pode ver (admin = todas). */
function allowed_team_ids(?array $u = null): array {
    $u = $u ?? current_user();
    if (!$u) return [];
    if (is_admin($u)) {
        return array_map('intval', db()->query('SELECT id FROM teams')->fetchAll(PDO::FETCH_COLUMN));
    }
    $st = db()->prepare('SELECT team_id FROM user_teams WHERE user_id = ?');
    $st->execute([$u['id']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * IDs dos corretores que o usuário pode ver no painel (união das equipes
 * permitidas). Admin = todos os corretores ativos.
 * Retorna null como "sem restrição" (todos) apenas para admin.
 */
function allowed_broker_ids(?array $u = null): array {
    $u = $u ?? current_user();
    if (!$u) return [];
    if (is_admin($u)) {
        return db()->query('SELECT id FROM brokers WHERE ativo = 1')->fetchAll(PDO::FETCH_COLUMN);
    }
    $ids = [];
    $teams = allowed_team_ids($u);
    if ($teams) {
        $in = implode(',', array_fill(0, count($teams), '?'));
        $st = db()->prepare("SELECT DISTINCT broker_id FROM team_brokers WHERE team_id IN ($in)");
        $st->execute($teams);
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    // "Ver os próprios dados": inclui o corretor vinculado ao usuário.
    if (!empty($u['broker_id'])) $ids[] = $u['broker_id'];
    return array_values(array_unique($ids));
}

/** Escapa texto para HTML. */
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
