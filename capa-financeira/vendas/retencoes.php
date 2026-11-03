<?php
/* =====================================================================
   capa-financeira/vendas/retencoes.php — quanto as Retenções para
   Imobiliária (integração, pré-venda...) geraram de receita. Só no portal:
   não há lançamento no Omie (decisão de 03/11/2026 — categorias a definir).
   Período pela data da venda; valores recalculados com as regras atuais.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
$de = Venda::data($_GET['de'] ?? '') ?: date('Y-01-01');
$ate = Venda::data($_GET['ate'] ?? '') ?: date('Y-m-d');
$emp = in_array($_GET['empresa'] ?? '', ['vertical', 'camargo'], true) ? $_GET['empresa'] : '';
$soConcluidas = !isset($_GET['todas']);

$sql = "SELECT v.*, (SELECT GROUP_CONCAT(c.nome ORDER BY c.ordem SEPARATOR ' E ') FROM cv_compradores c WHERE c.venda_id = v.id) AS compradores_txt
        FROM cv_vendas v WHERE v.status <> 'cancelada' AND v.e2_dados IS NOT NULL AND v.data_venda BETWEEN ? AND ?"
     . ($emp ? ' AND v.empresa = ?' : '') . ($soConcluidas ? " AND v.e2_status <> 'rascunho'" : '') . ' ORDER BY v.data_venda, v.id';
$st = db()->prepare($sql);
$st->execute(array_merge([$de, $ate], $emp ? [$emp] : []));
$linhas = []; $porObs = []; $porMes = []; $total = 0.0;
foreach ($st->fetchAll() as $v) {
    foreach (['valor_contrato', 'valor_bonus', 'documentacao', 'descontos', 'pct_corretores', 'pct_fundo', 'pct_nf', 'pct_nf_bonus', 'brinde', 'cesta'] as $k) $v[$k] = $v[$k] === null ? null : (float)$v[$k];
    $d = json_decode((string)$v['e2_dados'], true);
    if (!$d) continue;
    $calc = Distribuicao::calc($d, Venda::calc($v));
    foreach ($calc['linhas'] as $l) {
        if (!Distribuicao::retencao($l['bloco'])) continue;
        $obs = ($l['obs'] ?? '') !== '' ? $l['obs'] : ($l['nome'] ?: $l['cargo']);
        $chave = Venda::texto($obs) . ' · ' . $l['cargo'];
        $mes = substr((string)$v['data_venda'], 0, 7);
        $val = round($l['valor'], 2);
        $linhas[] = ['v' => $v, 'l' => $l, 'obs' => $obs, 'valor' => $val];
        $porObs[$chave] = ($porObs[$chave] ?? 0) + $val;
        $porMes[$mes][$chave] = ($porMes[$mes][$chave] ?? 0) + $val;
        $total += $val;
    }
}
arsort($porObs);
$chaves = array_keys($porObs);
$brl = fn($x) => 'R$ ' . number_format((float)$x, 2, ',', '.');

portal_header('Retenções para Imobiliária', $u);
?>
<style>main.wrap{max-width:1300px}.cv-filtro{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap}.cv-filtro label{font-size:12.5px;font-weight:600}
.cv-filtro input,.cv-filtro select{display:block;margin-top:3px;padding:7px 9px;font:inherit;border:2px solid var(--line);border-radius:6px}</style>
<?php cf_cabecalho('Retenções para Imobiliária', 'Quanto a integração, a pré-venda e as demais retenções geraram de receita. Só no portal — nada é lançado no Omie.',
    [['Capa Financeira', '/capa-financeira/'], ['Vendas', '/capa-financeira/vendas/'], ['Retenções', null]], 'vendas'); ?>

<form class="cf-barra cv-filtro" method="get">
  <label>Venda de<input type="date" name="de" value="<?= h($de) ?>"></label>
  <label>até<input type="date" name="ate" value="<?= h($ate) ?>"></label>
  <label>Empresa<select name="empresa"><option value="">as duas</option><option value="vertical"<?= $emp === 'vertical' ? ' selected' : '' ?>>Vertical</option><option value="camargo"<?= $emp === 'camargo' ? ' selected' : '' ?>>Camargo</option></select></label>
  <label style="display:flex;gap:6px;align-items:center;font-weight:500"><input type="checkbox" name="todas" value="1"<?= $soConcluidas ? '' : ' checked' ?> style="margin:0"> incluir distribuições em rascunho</label>
  <button class="btn" type="submit">Filtrar</button>
</form>

<div class="cf-resumo">
  <div class="cf-kpi"><span>Total no período</span><b><?= $brl($total) ?></b></div>
  <?php foreach (array_slice($porObs, 0, 5, true) as $k => $x): ?><div class="cf-kpi"><span><?= h($k) ?></span><b><?= $brl($x) ?></b></div><?php endforeach; ?>
</div>

<?php if (!$linhas): ?>
  <p class="home-sub">Nenhuma retenção no período.</p>
<?php else: ?>
<h2 class="cf-h2">Por mês (data da venda)</h2>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
  <thead><tr><th>Mês</th><?php foreach ($chaves as $k): ?><th class="cf-num"><?= h($k) ?></th><?php endforeach; ?><th class="cf-num">Total</th></tr></thead>
  <tbody><?php foreach ($porMes as $m => $vals): ?><tr><td><?= h(substr($m, 5, 2) . '/' . substr($m, 0, 4)) ?></td>
    <?php foreach ($chaves as $k): ?><td class="cf-num"><?= isset($vals[$k]) ? $brl($vals[$k]) : '—' ?></td><?php endforeach; ?><td class="cf-num"><b><?= $brl(array_sum($vals)) ?></b></td></tr><?php endforeach; ?></tbody>
  <tfoot><tr><td>Total</td><?php foreach ($chaves as $k): ?><td class="cf-num"><?= $brl($porObs[$k]) ?></td><?php endforeach; ?><td class="cf-num"><?= $brl($total) ?></td></tr></tfoot>
</table></div>

<h2 class="cf-h2">Por venda</h2>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
  <thead><tr><th>Venda</th><th>Data</th><th>Compradores</th><th>Construtora</th><th>Empresa</th><th>Retenção</th><th>Cargo</th><th class="cf-num">%</th><th class="cf-num">Valor</th><th>Etapa 2</th></tr></thead>
  <tbody><?php foreach ($linhas as $x): $v = $x['v']; $l = $x['l']; ?><tr>
    <td><a href="/capa-financeira/vendas/editar.php?id=<?= (int)$v['id'] ?>&etapa=2">#<?= (int)$v['id'] ?></a></td><td><?= cf_data_br($v['data_venda']) ?></td>
    <td><?= h((string)$v['compradores_txt']) ?></td><td><?= h($v['construtora']) ?></td><td><?= h(strtoupper($v['empresa'])) ?></td>
    <td><?= h($x['obs']) ?></td><td><?= h($l['cargo']) ?><?= $l['bloco'] === 'adm_bonus' ? ' (bônus)' : '' ?></td><td class="cf-num"><?= h(Distribuicao::pctTxt((float)$l['pct'])) ?></td>
    <td class="cf-num"><?= $brl($x['valor']) ?></td><td><?= cv_etapa_badge($v['e2_status']) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php portal_footer();
