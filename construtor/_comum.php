<?php
/* construtor/_comum.php — regras de acesso da Área do Construtor.
   Nível 1: ferramenta 'area-construtor' (user_tools).
   Nível 2: quais páginas/construtoras o usuário vê (co_user_paginas).
   Admin vê todas e é o único que edita (distratos, carga de dados). */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';

/** Páginas ativas que o usuário pode abrir (admin = todas). */
function co_paginas_visiveis(array $u): array {
    if (is_admin($u)) {
        return db()->query('SELECT slug,nome,descricao,atualizado_em FROM co_paginas WHERE ativo=1 ORDER BY ordem,nome')->fetchAll();
    }
    $st = db()->prepare('SELECT p.slug,p.nome,p.descricao,p.atualizado_em FROM co_paginas p
        JOIN co_user_paginas up ON up.slug=p.slug WHERE up.user_id=? AND p.ativo=1 ORDER BY p.ordem,p.nome');
    $st->execute([$u['id']]);
    return $st->fetchAll();
}

/** Exige a ferramenta + permissão na página $slug. Retorna [usuario, pagina]. */
function co_guard(string $slug): array {
    $u = require_tool('area-construtor');
    foreach (co_paginas_visiveis($u) as $p) {
        if ($p['slug'] === $slug) return [$u, $p];
    }
    http_response_code(403);
    exit('Você não tem acesso a esta página.');
}

function co_slug_ok(string $s): bool { return (bool)preg_match('/^[a-z0-9-]{1,40}$/', $s); }

function co_csrf(): string {
    portal_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

/** Chave de serviço da carga automática: config.php (CONSTRUTOR_TOKEN) ou gerada no admin (co_config). */
function co_token_valido(string $tok): bool {
    if ($tok === '') return false;
    if (defined('CONSTRUTOR_TOKEN') && CONSTRUTOR_TOKEN !== '' && hash_equals(CONSTRUTOR_TOKEN, $tok)) return true;
    try {
        $st = db()->prepare("SELECT v FROM co_config WHERE k='api_token_hash'"); $st->execute();
        $hash = (string)$st->fetchColumn();
        return $hash !== '' && hash_equals($hash, hash('sha256', $tok));
    } catch (Throwable $e) { return false; }
}
