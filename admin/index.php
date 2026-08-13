<?php
/* admin/index.php — painel de administração (visão geral).
   O CRUD completo (criar usuários, arrastar corretores para equipes,
   atribuir ferramentas/equipes) entra no próximo incremento. Esta tela já
   exige papel admin e mostra o estado atual. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';

$u = require_login();
if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }

$nUsers   = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$nTeams   = (int) db()->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$nBrokers = (int) db()->query('SELECT COUNT(*) FROM brokers')->fetchColumn();
$tools    = db()->query('SELECT * FROM tools ORDER BY ordem, nome')->fetchAll();
$users    = db()->query('SELECT id, nome, login, papel, ativo FROM users ORDER BY nome')->fetchAll();

portal_header('Admin', $u);
?>
<h1 class="home-titulo">Administração</h1>
<p class="home-sub"><?= $nUsers ?> usuário(s) · <?= $nTeams ?> equipe(s) · <?= $nBrokers ?> corretor(es).
  A gestão completa (usuários, arrastar corretores para equipes, permissões) entra no próximo incremento.</p>

<h2 style="font-size:15px;margin-top:24px">Ferramentas registradas</h2>
<table class="grid">
  <thead><tr><th>Nome</th><th>Slug</th><th>Caminho</th><th>Ativo</th></tr></thead>
  <tbody>
    <?php foreach ($tools as $t): ?>
    <tr><td><?= h($t['icone']) ?> <?= h($t['nome']) ?></td><td><?= h($t['slug']) ?></td>
        <td><?= h($t['caminho']) ?></td><td><?= $t['ativo'] ? 'sim' : 'não' ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2 style="font-size:15px;margin-top:24px">Usuários</h2>
<table class="grid">
  <thead><tr><th>Nome</th><th>Login</th><th>Papel</th><th>Ativo</th></tr></thead>
  <tbody>
    <?php foreach ($users as $usr): ?>
    <tr><td><?= h($usr['nome']) ?></td><td><?= h($usr['login']) ?></td>
        <td><?= h($usr['papel']) ?></td><td><?= $usr['ativo'] ? 'sim' : 'não' ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php
portal_footer();
