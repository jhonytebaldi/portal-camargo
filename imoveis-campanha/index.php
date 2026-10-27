<?php
/* =====================================================================
   imoveis-campanha/index.php: a lista dos imóveis da campanha (tela
   "lista" aprovada na r23 de 23/10/2026): cartão por imóvel, filtro
   ligados/desligados, o interruptor que liga a Bianca naquele imóvel e
   quantas conversas ela já respondeu sobre ele.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('imoveis-campanha');
$erro = null; $todos = [];
try { $todos = (array)ImcBianca::doConfig()->rpc('camargo_portal_imoveis'); }
catch (Throwable $e) { $erro = imc_erro_legivel($e); }
$meus = imc_visiveis($u, $todos);
$f = (string)($_GET['f'] ?? 'todos');
$lig = array_filter($meus, fn($i) => !empty($i['ligado']));
$mostrar = $f === 'ligados' ? $lig : ($f === 'desligados' ? array_filter($meus, fn($i) => empty($i['ligado'])) : $meus);

portal_header('Imóveis da campanha', $u);
imc_estilo();
?>
<div class="imc-top">
  <h1>Imóveis da campanha</h1>
  <div style="display:flex;gap:8px">
    <?php if (is_admin($u)): ?><a class="imc-btn sec" href="integracao.php" title="A chave que liga este módulo à Bianca">Ligação com a Bianca</a><?php endif; ?>
    <?php if (!$erro && imc_pode_editar($u, null)): ?><a class="imc-btn" href="imovel.php?novo=1">+ Novo imóvel</a><?php endif; ?>
  </div>
</div>
<?php if ($erro): ?>
  <div class="imc-err"><?= h($erro) ?></div>
<?php else: ?>
<div class="imc-chips">
  <a class="imc-chip <?= $f === 'todos' ? 'on' : '' ?>" href="?f=todos">Todos <b><?= count($meus) ?></b></a>
  <a class="imc-chip <?= $f === 'ligados' ? 'on' : '' ?>" href="?f=ligados">Ligados <b><?= count($lig) ?></b></a>
  <a class="imc-chip <?= $f === 'desligados' ? 'on' : '' ?>" href="?f=desligados">Desligados <b><?= count($meus) - count($lig) ?></b></a>
</div>
<?php if (!$mostrar): ?>
  <div class="aviso"><?= $meus ? 'Nenhum imóvel neste filtro.' : 'Nenhum imóvel ainda. Clique em Novo imóvel.' ?></div>
<?php endif; ?>
<div class="imc-grade">
<?php foreach ($mostrar as $i):
    $c = (array)($i['campos'] ?? []);
    $quartos = ImcFicha::valor($c, 'quartos'); $vagas = ImcFicha::valor($c, 'vagas'); $m2 = ImcFicha::valor($c, 'metragem');
    $preco = ImcFicha::valor($c, 'valor');
    $dono = (string)($i['corretor'] ?? '') ?: 'Campanha da casa';
    $link = 'imovel.php?id=' . urlencode((string)$i['id']);
?>
  <div class="imc-card <?= empty($i['ligado']) ? 'off' : '' ?>">
    <a href="<?= h($link) ?>" class="foto" style="<?= !empty($i['foto_url']) ? "background-image:url('" . h((string)$i['foto_url']) . "')" : '' ?>"><?= empty($i['foto_url']) ? '🏠' : '' ?></a>
    <a href="<?= h($link) ?>" class="nm" style="color:inherit;text-decoration:none"><?= h((string)$i['nome']) ?></a>
    <?php if (!empty($i['codigo_robust'])): ?><span class="imc-cod" title="Código no Robust e no texto do anúncio">Cód. <?= h((string)$i['codigo_robust']) ?></span><?php endif; ?>
    <div class="imc-ic">
      <?php if ($quartos !== ''): ?><span title="Quartos">🛏 <?= h($quartos) ?></span><?php endif; ?>
      <?php if ($vagas !== ''): ?><span title="Vagas">🚗 <?= h(preg_replace('/\D.*$/', '', $vagas) ?: $vagas) ?></span><?php endif; ?>
      <?php if ($m2 !== ''): ?><span title="Metragem">📐 <?= h(mb_strimwidth($m2, 0, 14, '…')) ?></span><?php endif; ?>
    </div>
    <div class="imc-preco" title="<?= h($preco) ?>"><?= h(mb_strimwidth($preco, 0, 30, '…')) ?></div>
    <div class="imc-dono"><span class="imc-av"><?= h(imc_iniciais($dono)) ?></span><?= h($dono) ?></div>
    <div class="imc-pe">
      <label class="imc-sw" title="<?= empty($i['ligado']) ? 'Desligado: ela não fala deste imóvel' : 'Ligado: ela responde sobre este imóvel' ?>">
        <input type="checkbox" data-ligar="<?= h((string)$i['id']) ?>" <?= !empty($i['ligado']) ? 'checked' : '' ?> <?= imc_pode_editar($u, $i) ? '' : 'disabled' ?>><span></span>
      </label>
      <a href="<?= h($link) ?>&aba=respostas" style="color:inherit;text-decoration:none" title="Conversas em que ela respondeu sobre este imóvel"><?= (int)($i['conversas'] ?? 0) ?> conversas</a>
      <span>·</span>
      <a href="<?= h($link) ?>&aba=respostas" style="color:inherit;text-decoration:none"><?= (int)($i['respostas'] ?? 0) ?> respostas</a>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<script>
// O interruptor do cartão liga/desliga a Bianca neste imóvel na hora (salva a ficha inteira com ligado trocado).
document.querySelectorAll('[data-ligar]').forEach(cb => cb.addEventListener('change', async () => {
  cb.disabled = true;
  const r = await fetch('acao.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({csrf: <?= json_encode(csrf_token()) ?>, acao: 'ligar', id: cb.dataset.ligar, ligado: cb.checked})})
    .then(r => r.json()).catch(() => ({ok: false, erro: 'sem resposta'}));
  if (!r.ok) { alert(r.erro || 'Não salvou.'); cb.checked = !cb.checked; }
  cb.closest('.imc-card').classList.toggle('off', !cb.checked);
  cb.disabled = false;
}));
</script>
<?php
portal_footer();
