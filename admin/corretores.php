<?php
/* admin/corretores.php — lista de corretores + sincronização do GHL. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();

$brokers = db()->query('SELECT id, nome, email, ativo FROM brokers ORDER BY nome')->fetchAll();

admin_header('corretores', $u);
?>
<h1 class="home-titulo">Corretores</h1>
<p class="home-sub">Lista usada nas equipes. Sincronize para trazer os corretores do GHL.</p>

<div style="margin:12px 0">
  <button class="btn" id="btnSync">🔄 Sincronizar do GHL</button>
  <span id="syncMsg" class="tag"></span>
</div>

<div class="card">
<table class="grid">
  <thead><tr><th>Nome</th><th>E-mail</th><th>ID (GHL)</th></tr></thead>
  <tbody id="tb">
    <?php foreach ($brokers as $b): ?>
      <tr><td><?= h($b['nome']) ?></td><td class="mut"><?= h($b['email']) ?></td><td class="mut" style="font:12px monospace"><?= h($b['id']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$brokers): ?><tr><td colspan="3" class="mut">Nenhum corretor ainda. Clique em “Sincronizar do GHL”.</td></tr><?php endif; ?>
  </tbody>
</table>
</div>

<script>
const CSRF = document.querySelector('meta[name=csrf]').content;
async function acao(params){
  params.csrf = CSRF;
  const r = await fetch('/admin/acao.php', {method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(params)});
  return r.json();
}
document.getElementById('btnSync').onclick = async () => {
  const msg = document.getElementById('syncMsg');
  msg.textContent = 'Sincronizando...';
  const r = await acao({action:'ghl_sync'});
  if (r.ok){ msg.textContent = r.n + ' corretor(es) sincronizados. Recarregando...'; setTimeout(()=>location.reload(), 800); }
  else { msg.textContent = 'Erro: ' + (r.msg||'falhou'); }
};
</script>
<?php portal_footer();
