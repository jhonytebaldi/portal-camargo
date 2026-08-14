<?php
/* lib/layout.php — cabeçalho e rodapé compartilhados do portal. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

/** URL do CSS com "cache-busting" pela data de modificação do arquivo. */
function css_href(): string {
    $f = dirname(__DIR__) . '/assets/portal.css';
    $v = @filemtime($f) ?: 1;
    return '/assets/portal.css?v=' . $v;
}

function portal_header(string $titulo, ?array $u = null): void {
    $u = $u ?? current_user();
    ?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($titulo) ?> — Camargo</title>
<link rel="stylesheet" href="<?= css_href() ?>">
</head><body>
<header class="topbar">
  <a class="brand" href="/">Camargo · <span>dados</span></a>
  <?php if ($u): ?>
  <nav>
    <?php if (is_admin($u)): ?><a href="/admin/">Admin</a><?php endif; ?>
    <span class="who"><?= h($u['nome']) ?></span>
    <a class="sair" href="/logout.php">Sair</a>
  </nav>
  <?php endif; ?>
</header>
<main class="wrap"><?php
}

function portal_footer(): void {
    ?></main>
<footer class="rodape">Portal interno — Imobiliária Camargo</footer>
</body></html><?php
}
