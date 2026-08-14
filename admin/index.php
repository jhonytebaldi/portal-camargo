<?php
/* admin/index.php — início da administração (hub + números). */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();

$nUsers   = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$nTeams   = (int) db()->query('SELECT COUNT(*) FROM teams')->fetchColumn();
$nBrokers = (int) db()->query('SELECT COUNT(*) FROM brokers')->fetchColumn();

admin_header('', $u);
?>
<h1 class="home-titulo">Administração</h1>
<p class="home-sub"><?= $nUsers ?> usuário(s) · <?= $nTeams ?> equipe(s) · <?= $nBrokers ?> corretor(es).</p>

<div class="grade">
  <a class="card-ferramenta" href="/admin/usuarios.php">
    <span class="ic">👤</span><span class="nm">Usuários</span>
    <span class="ds">Criar/editar usuários, papéis e o que cada um acessa e vê.</span></a>
  <a class="card-ferramenta" href="/admin/equipes.php">
    <span class="ic">👥</span><span class="nm">Equipes</span>
    <span class="ds">Criar equipes e arrastar corretores para dentro (um corretor pode estar em várias).</span></a>
  <a class="card-ferramenta" href="/admin/corretores.php">
    <span class="ic">🧑‍💼</span><span class="nm">Corretores</span>
    <span class="ds">Lista de corretores, sincronizada do GHL.</span></a>
</div>
<?php portal_footer();
