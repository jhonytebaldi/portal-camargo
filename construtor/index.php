<?php
/* construtor/index.php — lista as construtoras que o usuário pode ver.
   Com uma só, abre direto. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('area-construtor');
$pags = co_paginas_visiveis($u);
if (count($pags) === 1 && !is_admin($u)) { header('Location: ver.php?p=' . urlencode($pags[0]['slug'])); exit; }
portal_header('Área do Construtor', $u);
?>
<h1 class="home-titulo">Área do Construtor</h1>
<p class="home-sub">Escolha a construtora.</p>
<?php if (!$pags): ?>
  <div class="aviso">Nenhuma construtora liberada para o seu acesso. Fale com a Imobiliária Camargo.</div>
<?php else: ?>
<div class="grade">
  <?php foreach ($pags as $p): ?>
    <a class="card-ferramenta" href="ver.php?p=<?= h($p['slug']) ?>">
      <span class="ic">🏗️</span>
      <span class="nm"><?= h($p['nome']) ?></span>
      <span class="ds"><?= h($p['descricao']) ?><?= $p['atualizado_em'] ? ' · dados de ' . h(date('d/m/Y', strtotime($p['atualizado_em']))) : ' · sem dados carregados' ?></span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php if (is_admin($u)): ?>
  <p class="home-sub" style="margin-top:24px">Admin: <a href="dados.php">carregar/atualizar dados das páginas</a> · libere o acesso de cada usuário em <a href="/admin/usuarios.php">Usuários</a>.</p>
<?php endif; ?>
<?php portal_footer();
