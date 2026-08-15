<?php
/* =====================================================================
   _auth.php — "porteiro" adaptável da Busca.

   Se a Busca está instalada como MÓDULO do portal (existe ../lib/auth.php
   um nível acima), usa o LOGIN DO PORTAL + a ferramenta 'busca'
   (controle de acesso centralizado, um login só para tudo).

   Se está rodando SOZINHA (instalação antiga, sem o portal), cai no
   login próprio da Busca (senha em credenciais.php). Assim o MESMO código
   funciona nos dois cenários — e continua válido depois que a Busca se
   auto-atualiza pelo repositório dela.
   ===================================================================== */

/** Caminho do auth do portal, se a Busca estiver sob o portal; senão null. */
function busca_portal_auth(): ?string {
    $p = dirname(__DIR__) . '/lib/auth.php';
    return is_file($p) ? $p : null;
}

/** Páginas HTML: exige poder ABRIR a Busca (login + ferramenta). */
function busca_exige_acesso(): void {
    if ($p = busca_portal_auth()) {
        require_once $p;
        require_tool('busca');                 // portal: login + nível 1
    } else {
        require_once __DIR__ . '/config.php';  // standalone: senha própria
        session_start();
        if (empty($_SESSION['autenticado'])) { header('Location: login.php'); exit; }
    }
}

/** Endpoints JSON: igual, mas responde 401 em vez de redirecionar.
 *  Retorna o usuário (com 'papel') — no modo standalone devolve papel=admin,
 *  pois lá quem tem a senha tem acesso total, como sempre foi. */
function busca_exige_acesso_api(): array {
    if ($p = busca_portal_auth()) {
        require_once $p;
        $u = current_user();
        if (!$u || !user_has_tool('busca', $u)) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['erro' => 'não autenticado']);
            exit;
        }
        return $u;
    }
    require_once __DIR__ . '/config.php';
    session_start();
    if (empty($_SESSION['autenticado'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['erro' => 'não autenticado']);
        exit;
    }
    return ['papel' => 'admin', '_standalone' => true];
}

/** Ferramentas de administração (atualizar): exige admin do portal
 *  (ou, no modo standalone, apenas estar logado na Busca). */
function busca_exige_admin(): void {
    if ($p = busca_portal_auth()) {
        require_once $p;
        $u = require_login();
        if (($u['papel'] ?? '') !== 'admin') { http_response_code(403); exit('Apenas administrador.'); }
    } else {
        require_once __DIR__ . '/config.php';
        session_start();
        if (empty($_SESSION['autenticado'])) { header('Location: login.php'); exit; }
    }
}
