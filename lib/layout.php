<?php
/* lib/layout.php — cabeçalho e rodapé compartilhados do portal. */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function portal_header(string $titulo, ?array $u = null): void {
    $u = $u ?? current_user();
    ?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($titulo) ?> — Camargo</title>
<link rel="stylesheet" href="/assets/portal.css">
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
