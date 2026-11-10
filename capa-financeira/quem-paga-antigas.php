<?php
/* =====================================================================
   capa-financeira/quem-paga-antigas.php — capas já confirmadas (antes da pergunta
   "quem pagou as parcelas?") de construtoras marcadas como "recebe e repassa":
   as contas a receber delas provavelmente estão no nome do cliente e deveriam estar
   no da construtora. Corrige-se caso a caso; o que já está no Omie vai para
   Exportar p/ Omie → Alterações depois da exportação (aplicar pela API).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/_quem_paga.php';

$u = require_tool('capa-financeira');
$pdo = db();
$marc = [];
foreach ($pdo->query('SELECT chave, modo FROM cv_construtoras') as $r) $marc[$r['chave']] = (string)$r['modo'];
$capas = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.tipo = 'R' AND l.status IN ('confirmado','exportado')) nr,
                             (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.tipo = 'R' AND l.status = 'exportado') nexp,
                             (SELECT COALESCE(SUM(l.valor),0) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.tipo = 'R' AND l.status IN ('confirmado','exportado')) total
                      FROM cf_capas c WHERE c.status = 'confirmada' AND c.quem_paga IS NULL HAVING nr > 0 ORDER BY c.construtora, c.data_venda")->fetchAll();
$lista = []; $outras = ['cliente' => 0, 'varia' => 0, '' => 0];
foreach ($capas as $c) {
    $m = $marc[cv_construtora_chave((string)$c['construtora'])] ?? '';
    if ($m === 'construtora') $lista[] = $c; else $outras[$m] = ($outras[$m] ?? 0) + 1;
}
portal_header('Capas antigas — quem pagou', $u);
cf_cabecalho('Capas antigas — contas a receber no nome do cliente', 'Capas confirmadas antes da pergunta "quem pagou as parcelas?", de construtoras marcadas como "recebe e repassa". Confira e corrija uma a uma — nada muda sozinho.',
    [['Capa Financeira', '/capa-financeira/'], ['Capas antigas — quem pagou', null]], 'capas');
?>
<p class="cf-dica">A lista usa a marcação de <a href="/capa-financeira/vendas/construtoras.php">Construtoras — quem paga</a>. Fora dela: <?= (int)$outras[''] ?> capa(s) de construtoras ainda sem marcação, <?= (int)$outras['varia'] ?> de construtoras que variam e <?= (int)$outras['cliente'] ?> em que o cliente paga direto.</p>
<?php if (!$lista): ?><p class="cf-pend ok" style="padding:10px 14px">Nenhuma capa antiga para corrigir.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>Capa</th><th>Cliente</th><th>Construtora</th><th>Venda</th><th>Contas a receber</th><th>Já no Omie</th><th></th></tr></thead><tbody>
<?php foreach ($lista as $c): ?>
<tr><td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= h((string)$c['cod']) ?></a> <small class="cf-raw"><?= h(strtoupper((string)$c['empresa'])) ?></small></td><td><?= h((string)$c['cliente']) ?></td><td><?= h((string)$c['construtora']) ?></td>
<td><?= cf_data_br($c['data_venda']) ?></td><td><?= (int)$c['nr'] ?> · <?= cf_brl($c['total']) ?></td><td><?= (int)$c['nexp'] ?></td>
<td><button type="button" class="btn cf-btn-sec qpa-btn" data-ctx="<?= h(json_encode(cf_qp_contexto($pdo, $c), JSON_UNESCAPED_UNICODE)) ?>">Conferir / corrigir</button> <span class="qpa-res" style="font-size:12.5px"></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="cf-dica">Depois de corrigir: as contas que já estavam no Omie aparecem em <a href="/capa-financeira/exportar.php">Exportar p/ Omie → Alterações depois da exportação</a>, com o botão "Aplicar no Omie" (títulos já baixados ficam para ajuste manual). Se nesta venda foi o cliente que pagou, responda "cliente" e a capa sai da lista sem mudar nada.</p>
<?php endif; ?>
<script>
document.querySelectorAll('.qpa-btn').forEach(b => b.addEventListener('click', async () => {
  const j = await CFQuemPaga.abrir(JSON.parse(b.dataset.ctx), <?= json_encode(csrf_token()) ?>);
  if (!j) return;
  b.disabled = true; b.closest('tr').style.opacity = .6;
  b.nextElementSibling.textContent = '✔ ' + (j.quem_paga.modo === 'construtora' ? j.alteradas + ' conta(s) passaram para a construtora' + (j.pos_exportacao ? ' · ' + j.pos_exportacao + ' para aplicar no Omie' : '') : 'cliente pagou — nada alterado');
}));
</script>
<?php portal_footer();
