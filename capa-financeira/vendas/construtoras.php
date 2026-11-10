<?php
/* =====================================================================
   capa-financeira/vendas/construtoras.php — quem paga as parcelas, por construtora.
   Marcação inicial feita à mão (cliente paga direto / construtora recebe e repassa /
   varia) + cadastro do Omie usado por empresa. Depois, cada decisão confirmada no
   formulário (etapa 3) e na conferência das capas reforça ou corrige a marcação.
   Nada é deduzido do que já está no Omie (lá há contas no nome errado).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
$empresas = cf_config('empresas', []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? '';
    csrf_check();
    $nome = trim((string)($in['nome'] ?? ''));
    $modo = in_array($in['modo'] ?? '', ['cliente', 'construtora', 'varia'], true) ? $in['modo'] : null;
    $sinal = in_array($in['sinal'] ?? '', ['cliente', 'construtora'], true) ? $in['sinal'] : 'cliente';
    if ($nome === '' || !$modo) { echo json_encode(['ok' => false, 'erro' => 'escolha uma opção']); exit; }
    $emp = (string)($in['empresa'] ?? '');
    $cad = is_array($in['cadastro'] ?? null) ? $in['cadastro'] : null;
    if ($emp !== '' && !in_array($emp, array_column($empresas, 'id'), true)) { echo json_encode(['ok' => false, 'erro' => 'empresa inválida']); exit; }
    cv_construtora_gravar($nome, $modo, $sinal, $emp ?: null, $cad, $u, false);
    echo json_encode(['ok' => true, 'linha' => cv_construtora($nome)], JSON_UNESCAPED_UNICODE);
    exit;
}

// construtoras que aparecem nas capas e nas vendas do formulário (agrupadas pela grafia normalizada)
$lista = [];
$add = function (string $nome, string $empresa, string $fonte) use (&$lista) {
    $k = cv_construtora_chave($nome); if ($k === '') return;
    $lista[$k] ??= ['nome' => $nome, 'capas' => 0, 'vendas' => 0, 'empresas' => []];
    $lista[$k][$fonte]++;
    if ($empresa !== '') $lista[$k]['empresas'][$empresa] = true;
};
foreach (db()->query("SELECT construtora, empresa FROM cf_capas WHERE construtora IS NOT NULL AND construtora <> '' AND status <> 'descartada'") as $r) $add((string)$r['construtora'], (string)$r['empresa'], 'capas');
foreach (db()->query("SELECT construtora, empresa FROM cv_vendas WHERE construtora <> '' AND status <> 'cancelada'") as $r) $add((string)$r['construtora'], (string)$r['empresa'], 'vendas');
$marc = [];
foreach (db()->query('SELECT * FROM cv_construtoras') as $r) { $r['omie'] = json_decode((string)$r['omie'], true) ?: []; $marc[$r['chave']] = $r; if (!isset($lista[$r['chave']])) $lista[$r['chave']] = ['nome' => $r['nome'], 'capas' => 0, 'vendas' => 0, 'empresas' => []]; }
uasort($lista, fn($a, $b) => [($b['capas'] + $b['vendas'])] <=> [($a['capas'] + $a['vendas'])] ?: strcmp($a['nome'], $b['nome']));
$semMarca = count(array_filter(array_keys($lista), fn($k) => empty($marc[$k]['modo'])));

portal_header('Construtoras — quem paga', $u);
?>
<style>
main.wrap{max-width:1300px}
.cvc-tab{width:100%;border-collapse:collapse;font-size:13.5px}.cvc-tab th{font-size:11.5px;text-transform:uppercase;color:var(--mute);text-align:left;padding:6px;border-bottom:1px solid var(--line)}
.cvc-tab td{padding:8px 6px;border-bottom:1px solid #ece8dc;vertical-align:top}
.cvc-op{display:flex;flex-direction:column;gap:4px}.cvc-op label{display:flex;gap:6px;align-items:center;font-size:13.5px}
.cvc-cad{font-size:12.5px;margin-bottom:4px}.cvc-cad b{font-weight:600}
.cvc-st{font-size:12px;color:var(--mute)}.cvc-nao{background:#fff8e1}
</style>
<?php cf_cabecalho('Construtoras — quem paga as parcelas', 'Marque uma vez como cada construtora costuma receber: é a sugestão inicial na etapa 3 das vendas e na conferência das capas. Cada decisão confirmada depois reforça ou corrige a marcação.',
    [['Capa Financeira', '/capa-financeira/'], ['Vendas', '/capa-financeira/vendas/'], ['Construtoras', null]], 'vendas'); ?>
<?php if ($semMarca): ?><div class="aviso" style="margin-bottom:10px"><?= $semMarca ?> construtora(s) ainda sem marcação (destacadas).</div><?php endif; ?>
<section class="card">
<table class="cvc-tab">
<thead><tr><th>Construtora</th><th>Aparece em</th><th>Quem paga as parcelas</th><th>Sinal (arras)</th><th>Cadastro no Omie (quem paga, quando é a construtora)</th><th>Histórico</th></tr></thead>
<tbody>
<?php foreach ($lista as $k => $c): $m = $marc[$k] ?? null; $modo = $m['modo'] ?? ''; ?>
<tr data-nome="<?= h($m['nome'] ?? $c['nome']) ?>" class="<?= $modo ? '' : 'cvc-nao' ?>">
  <td><b><?= h($m['nome'] ?? $c['nome']) ?></b></td>
  <td class="cvc-st"><?= $c['capas'] ?> capa(s) · <?= $c['vendas'] ?> venda(s)<br><?= h(implode(', ', array_map('strtoupper', array_keys($c['empresas'])))) ?></td>
  <td><div class="cvc-op">
    <?php foreach (['cliente' => 'Cliente paga direto', 'construtora' => 'Construtora recebe e repassa', 'varia' => 'Varia / não sei (pergunta sempre)'] as $v => $t): ?>
      <label><input type="radio" name="m<?= md5($k) ?>" value="<?= $v ?>"<?= $modo === $v ? ' checked' : '' ?>> <?= $t ?></label>
    <?php endforeach; ?></div></td>
  <td><select data-sinal<?= $modo === 'construtora' ? '' : ' disabled' ?>><option value="cliente"<?= ($m['sinal'] ?? 'cliente') !== 'construtora' ? ' selected' : '' ?>>veio do cliente</option><option value="construtora"<?= ($m['sinal'] ?? '') === 'construtora' ? ' selected' : '' ?>>também pela construtora</option></select></td>
  <td><?php foreach ($empresas as $e): $cad = $m['omie'][$e['id']] ?? null; ?>
    <div class="cvc-cad"><?= h(strtoupper($e['id'])) ?>: <?= $cad ? '<b>' . h($cad['nome']) . '</b> ' . h(cf_fmt_doc((string)$cad['doc'])) : '<i class="cvc-st">—</i>' ?>
      <button type="button" class="cf-x" data-cad="<?= h($e['id']) ?>" data-empnome="<?= h($e['nome']) ?>">🔍 escolher</button></div>
  <?php endforeach; ?></td>
  <td class="cvc-st" data-hist><?= $m ? (int)$m['n_construtora'] . ' com repasse · ' . (int)$m['n_cliente'] . ' cliente direto' . ($m['atualizado_em'] ? '<br>atualizado ' . h(date('d/m/Y', strtotime((string)$m['atualizado_em']))) : '') : 'sem marcação' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php if (!$lista): ?><p class="cf-dica">Nenhuma construtora nas capas ou vendas ainda.</p><?php endif; ?>
</section>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>;
  async function gravar(tr, extra) {
    const modo = (tr.querySelector('input[type=radio]:checked') || {}).value;
    if (!modo) { CFModal.aviso({tipo: 'parcial', titulo: 'Escolha quem paga', texto: 'Marque primeiro quem paga as parcelas desta construtora.'}); return null; }
    const r = await fetch(location.pathname, {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf, nome: tr.dataset.nome, modo, sinal: tr.querySelector('[data-sinal]').value, ...extra})});
    const j = await r.json().catch(() => ({ok: false, erro: 'erro ' + r.status}));
    if (!j.ok) { CFModal.aviso({tipo: 'erro', texto: j.erro}); return null; }
    tr.classList.remove('cvc-nao');
    tr.querySelector('[data-hist]').innerHTML = j.linha.n_construtora + ' com repasse · ' + j.linha.n_cliente + ' cliente direto<br>atualizado agora';
    return j;
  }
  document.querySelectorAll('tr[data-nome]').forEach(tr => {
    tr.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => { tr.querySelector('[data-sinal]').disabled = r.value !== 'construtora'; gravar(tr, {}); }));
    tr.querySelector('[data-sinal]').addEventListener('change', () => gravar(tr, {}));
    tr.querySelectorAll('[data-cad]').forEach(b => b.addEventListener('click', async () => {
      const c = await CFOmie.escolher({empresa: b.dataset.cad, empresaNome: b.dataset.empnome, termo: tr.dataset.nome, titulo: 'Cadastro da construtora no Omie — ' + b.dataset.cad.toUpperCase(), csrf});
      if (!c) return;
      const j = await gravar(tr, {empresa: b.dataset.cad, cadastro: c});
      if (j) b.parentElement.innerHTML = b.dataset.cad.toUpperCase() + ': <b>' + CVesc(c.nome) + '</b> ' + CVesc(c.doc) + ' <span class="cvc-st">(salvo)</span>';
    }));
  });
  function CVesc(s) { return String(s ?? '').replace(/</g, '&lt;'); }
})();
</script>
<?php portal_footer();
