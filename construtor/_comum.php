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

/** Monta o HTML do painel. $modo: 'usuario' (logado) ou 'link' (link público só leitura). */
function co_render(string $slug, array $row, string $modo, ?array $u = null, string $rotulo = '', string $expira = ''): void {
    $tpl = __DIR__ . '/paginas/' . $slug . '.html';
    if (!is_file($tpl)) { http_response_code(404); exit('Painel ainda não publicado.'); }
    $dados = (string)($row['dados'] ?? '');
    if ($dados === '') { http_response_code(404); exit('Os dados desta página ainda não foram carregados.'); }
    $admin = $modo === 'usuario' && $u && is_admin($u);
    $json = str_replace('</', '<\\/', $dados);
    $cfg = '<script>window.CO_EDIT=' . ($admin ? 'true' : 'false')
         . ';window.CO_API=' . ($admin ? json_encode('acao.php?p=' . $slug) : 'null')
         . ';window.CO_CSRF=' . json_encode($modo === 'usuario' ? co_csrf() : '') . ';</script>';
    $quando = '<span style="margin-left:auto">Dados de ' . h(!empty($row['atualizado_em']) ? date('d/m/Y H:i', strtotime($row['atualizado_em'])) : '—') . '</span>';
    $estilo = 'background:#1d3270;color:#d3ddf7;font:13px Ubuntu,system-ui,sans-serif;padding:8px max(5vw,16px);display:flex;gap:16px;align-items:center;flex-wrap:wrap';
    if ($modo === 'link') {
        $barra = '<div style="' . $estilo . '"><b style="color:#fff">Imobiliária Camargo · visualização compartilhada</b>'
               . ($rotulo !== '' ? '<span>' . h($rotulo) . '</span>' : '')
               . $quando . '<span>Link válido até ' . h(date('d/m/Y H:i', strtotime($expira))) . '</span></div>';
    } else {
        $barra = '<div style="' . $estilo . '">'
           . '<a href="/" style="color:#fff;text-decoration:none;font-weight:700">← Portal Camargo</a>'
           . (count(co_paginas_visiveis($u)) > 1 ? '<a href="/construtor/" style="color:#d3ddf7">Outras construtoras</a>' : '')
           . $quando
           . '<button id="coPedir" type="button" style="font:600 13px Ubuntu,system-ui,sans-serif;min-height:34px;padding:0 14px;border:0;border-radius:8px;background:#f2b705;color:#121a3a;cursor:pointer"'
           . (!empty($row['pedido_em']) ? ' disabled>Atualização solicitada às ' . h(date('H:i', strtotime($row['pedido_em']))) . ' · em até 1 hora' : '>Solicitar atualização') . '</button>'
           . ($admin ? '<a href="/construtor/dados.php" style="color:#d3ddf7">Compartilhar link</a>' : '')
           . '<span>' . h($u['nome']) . '</span><a href="/logout.php" style="color:#d3ddf7">Sair</a></div>'
           . '<script>document.getElementById("coPedir").onclick=async function(){const b=this;b.disabled=true;b.textContent="Enviando…";try{const r=await fetch("pedir.php?p=' . h($slug) . '",{method:"POST",headers:{"X-CSRF":window.CO_CSRF}});const j=await r.json();if(!j.ok)throw new Error(j.msg);b.textContent="Atualização solicitada · os dados serão refeitos em até 1 hora"}catch(e){b.disabled=false;b.textContent="Tentar de novo";alert("Não foi possível solicitar: "+e.message)}};</script>';
    }
    $html = file_get_contents($tpl);
    $html = str_replace('__DATA__', $json, $html);
    $html = preg_replace('/<body>/', '<body>' . $barra, $html, 1);
    $html = str_replace('</head>', '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">' . $cfg . '</head>', $html);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    echo $html;
}
