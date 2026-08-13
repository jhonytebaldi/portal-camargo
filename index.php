<?php
/* index.php — porta de entrada do portal.
   Sem login → login.php. Com login → home com os botões das ferramentas
   que o usuário pode abrir. */
declare(strict_types=1);
require_once __DIR__ . '/lib/layout.php';

$u = require_login();
$tools = user_tools($u);

portal_header('Início', $u);
?>
<h1 class="home-titulo">Ferramentas</h1>
<p class="home-sub">Escolha uma ferramenta abaixo.</p>

<?php if (!$tools): ?>
  <div class="aviso">Você ainda não tem nenhuma ferramenta liberada. Fale com o administrador.</div>
<?php else: ?>
  <div class="grade">
    <?php foreach ($tools as $t): ?>
      <a class="card-ferramenta" href="<?= h($t['caminho']) ?>">
        <span class="ic"><?= h($t['icone'] ?: '▦') ?></span>
        <span class="nm"><?= h($t['nome']) ?></span>
        <span class="ds"><?= h($t['descricao']) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
portal_footer();
