<?php
/* admin/comum.php — base das telas de administração (guarda + CSRF + nav). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';

/** Exige usuário admin. */
function admin_guard(): array {
    $u = require_login();
    if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }
    return $u;
}

function csrf_token(): string {
    portal_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_check(): void {
    portal_session_start();
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', (string)$t)) {
        http_response_code(403); exit('Token de segurança inválido. Recarregue a página.');
    }
}

/** Cabeçalho das telas de admin, com abas. */
function admin_header(string $ativo, array $u): void {
    portal_header('Admin', $u);
    $abas = [
        ''            => ['Início',     '/admin/'],
        'usuarios'    => ['Usuários',   '/admin/usuarios.php'],
        'equipes'     => ['Equipes',    '/admin/equipes.php'],
        'corretores'  => ['Corretores', '/admin/corretores.php'],
    ];
    echo '<div class="admin-tabs">';
    foreach ($abas as $k => $a) {
        $on = $k === $ativo ? ' on' : '';
        echo '<a class="' . $on . '" href="' . h($a[1]) . '">' . h($a[0]) . '</a>';
    }
    echo '</div>';
    echo '<meta name="csrf" content="' . h(csrf_token()) . '">';
}
