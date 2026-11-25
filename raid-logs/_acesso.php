<?php
/* raid-logs/_acesso.php — quem pode abrir o WCLogs.
   Ferramenta pessoal do Jhony: não usa o controle de ferramentas do portal
   (nem admin entra por ser admin). Para liberar outros logins, defina no
   config.php:  define('RL_LOGINS', ['login1', 'login2']);
   Sem RL_LOGINS, vale o usuário cujo login ou nome começa com "jhony";
   se não existir nenhum assim, cai para os administradores. */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/auth.php';

function rl_pode(?array $u): bool {
    if (!$u) return false;
    if (defined('RL_LOGINS')) return in_array(strtolower((string)$u['login']), array_map('strtolower', (array)RL_LOGINS), true);
    $eh = static fn(array $x): bool => (bool)preg_match('/^jhony/i', (string)$x['login']) || (bool)preg_match('/^jhony/i', (string)$x['nome']);
    if ($eh($u)) return true;
    static $existe = null;
    if ($existe === null) $existe = (int)db()->query("SELECT COUNT(*) FROM users WHERE ativo = 1 AND (login LIKE 'jhony%' OR nome LIKE 'jhony%')")->fetchColumn() > 0;
    return !$existe && is_admin($u);
}
/** Guarda das páginas do módulo: login + rl_pode (senão 403). */
function rl_guard(): array {
    $u = require_login();
    if (!rl_pode($u)) { http_response_code(403); exit('Acesso negado.'); }
    return $u;
}
/** Tira o botão grande da home e da lista de ferramentas do admin (idempotente). */
function rl_esconder_botao(): void {
    try { db()->exec("UPDATE tools SET ativo = 0, nome = 'WCLogs', descricao = '' WHERE slug = 'raid-logs' AND (ativo = 1 OR nome <> 'WCLogs')"); } catch (Throwable $e) {}
}
