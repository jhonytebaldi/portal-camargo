<?php
/* =====================================================================
   plano-acao/index.php — Plano de Ação Diário dos corretores.

   Nível 1: exige a ferramenta 'plano-acao'.
   Nível 2: corretor vê SÓ o próprio plano (users.broker_id →
            brokers.robust_user_id); gestor vê os planos das equipes
            liberadas, separados por corretor; admin vê tudo.
   O conteúdo é importado pela tarefa agendada (api-importar.php).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/../lib/layout.php';

$u = require_tool('plano-acao');
$pdo = db();
$permit = pa_allowed_robust_ids($u);      // null = admin

/* ---- datas com plano (no escopo) ---- */
$where = ''; $args = [];
if ($permit !== null) {
    if (!$permit) { $where = ' WHERE 1=0 '; }
    else {
        $in = implode(',', array_fill(0, count($permit), '?'));
        $where = " WHERE robust_atendente IN ($in) ";
        $args = $permit;
    }
}
$st = $pdo->prepare("SELECT DISTINCT data FROM pa_planos $where ORDER BY data DESC LIMIT 30");
$st->execute($args);
$datas = $st->fetchAll(PDO::FETCH_COLUMN);

$dataSel = (string)($_GET['data'] ?? '');
if (!in_array($dataSel, $datas, true)) $dataSel = $datas[0] ?? '';

/* ---- planos do dia (no escopo) ---- */
$planos = [];
if ($dataSel !== '') {
    $sql = 'SELECT * FROM pa_planos WHERE data = ?';
    $a = [$dataSel];
    if ($permit !== null && $permit) {
        $sql .= ' AND robust_atendente IN (' . implode(',', array_fill(0, count($permit), '?')) . ')';
        $a = array_merge($a, $permit);
    } elseif ($permit !== null) { $sql .= ' AND 1=0'; }
    $sql .= ' ORDER BY corretor_nome';
    $st = $pdo->prepare($sql); $st->execute($a);
    $planos = $st->fetchAll();
}

/* ---- corretor selecionado ---- */
$corSel = (int)($_GET['corretor'] ?? 0);
$idsVisiveis = array_map(fn($p) => (int)$p['robust_atendente'], $planos);
if ($corSel && !in_array($corSel, $idsVisiveis, true)) $corSel = 0;
$planosVer = $corSel ? array_values(array_filter($planos, fn($p) => (int)$p['robust_atendente'] === $corSel)) : $planos;

/* ---- itens dos planos exibidos ---- */
$itensPorPlano = [];
if ($planosVer) {
    $pin = implode(',', array_fill(0, count($planosVer), '?'));
    $st = $pdo->prepare("SELECT * FROM pa_itens WHERE plano_id IN ($pin)
                         ORDER BY FIELD(faixa,'vermelho','amarelo','azul','branco'), score DESC, id");
    $st->execute(array_map(fn($p) => (int)$p['id'], $planosVer));
    foreach ($st->fetchAll() as $it) $itensPorPlano[(int)$it['plano_id']][] = $it;
}

$faixas = pa_faixas();
$stages = pa_stages();
function pa_data_label(string $d): string {
    $ts = strtotime($d . ' 12:00:00');
    $dias = ['Sun'=>'dom','Mon'=>'seg','Tue'=>'ter','Wed'=>'qua','Thu'=>'qui','Fri'=>'sex','Sat'=>'sáb'];
    return ($dias[date('D', $ts)] ?? '') . ' ' . date('d/m', $ts);
}

portal_header('Plano de Ação', $u);
?>
<style>
.pa-topo{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:14px 0 20px}
.pa-topo select{padding:9px 11px;border:2px solid var(--line);border-radius:6px;font:inherit;font-size:14px;background:#fff}
.pa-plano{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin:0 0 22px}
.pa-cab{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:6px}
.pa-cab h2{margin:0;font-size:18px}
.pa-prog{font-size:13px;color:var(--mute)}
.pa-barra{height:6px;background:var(--line);border-radius:4px;overflow:hidden;margin:8px 0 14px}
.pa-barra i{display:block;height:100%;background:var(--moss)}
.pa-faixa{margin:14px 0 4px;font-weight:700;font-size:14px}
.pa-item{display:flex;gap:12px;align-items:flex-start;border-top:1px solid var(--line);padding:12px 2px}
.pa-item input[type=checkbox]{width:20px;height:20px;margin-top:2px;accent-color:var(--moss);cursor:pointer;flex:0 0 auto}
.pa-item.ok .pa-tit,.pa-item.ok .pa-nome{text-decoration:line-through;opacity:.55}
.pa-nome{font-weight:700}
.pa-tel{font-size:13.5px;white-space:nowrap}
.pa-tel a{color:var(--moss);text-decoration:none;font-weight:600}
.pa-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:2px 0 4px}
.pa-badge{font-size:11.5px;padding:2px 8px;border-radius:10px;background:rgba(47,93,79,.12);color:var(--moss);font-weight:600}
.pa-badge.acao{background:rgba(180,81,47,.10);color:var(--clay)}
.pa-badge.origem{background:var(--line);color:var(--mute);font-weight:500}
.pa-tit{margin:2px 0 2px;font-size:14.5px}
.pa-just{font-size:13.5px;color:var(--mute);margin:0}
.pa-msg{margin-top:6px;font-size:13px}
.pa-msg summary{cursor:pointer;color:var(--moss);font-weight:600;list-style:none}
.pa-msg pre{white-space:pre-wrap;background:rgba(47,93,79,.06);border-radius:6px;padding:10px 12px;margin:6px 0 0;font:inherit}
.pa-copiar{margin-left:auto}
.pa-vazio{background:#fff;border:1px dashed var(--line);border-radius:10px;padding:30px;text-align:center;color:var(--mute)}
@media (max-width:560px){.pa-item{gap:9px}.pa-cab h2{font-size:16px}}
</style>

<h1 class="home-titulo">Plano de Ação Diário</h1>
<p class="home-sub">Clientes ativos do seu funil no Robust, priorizados pela conversa real — execute na ordem e dê o check.</p>

<?php if (!$datas): ?>
  <div class="pa-vazio">Nenhum plano importado ainda<?= $permit === [] ? ' — seu usuário não está vinculado a um corretor (fale com o admin)' : '' ?>.</div>
<?php else: ?>
<form class="pa-topo" method="get">
  <select name="data" onchange="this.form.submit()">
    <?php foreach ($datas as $d): ?>
      <option value="<?= h($d) ?>" <?= $d === $dataSel ? 'selected' : '' ?>><?= h(pa_data_label($d)) ?></option>
    <?php endforeach; ?>
  </select>
  <?php if (count($planos) > 1): ?>
  <select name="corretor" onchange="this.form.submit()">
    <option value="0">Todos os corretores (<?= count($planos) ?>)</option>
    <?php foreach ($planos as $p): ?>
      <option value="<?= (int)$p['robust_atendente'] ?>" <?= $corSel === (int)$p['robust_atendente'] ? 'selected' : '' ?>>
        <?= h($p['corretor_nome']) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
</form>

<?php foreach ($planosVer as $p):
    $itens = $itensPorPlano[(int)$p['id']] ?? [];
    $tot = count($itens); $ok = count(array_filter($itens, fn($i) => (int)$i['feito'] === 1));
?>
  <section class="pa-plano">
    <div class="pa-cab">
      <h2><?= h($p['corretor_nome']) ?></h2>
      <span class="pa-prog" data-prog="<?= (int)$p['id'] ?>"><?= $ok ?>/<?= $tot ?> feitas</span>
      <?php if ($p['texto_whatsapp'] !== ''): ?>
        <button class="btn pa-copiar" data-copiar="<?= (int)$p['id'] ?>">Copiar p/ WhatsApp</button>
        <textarea id="wa-<?= (int)$p['id'] ?>" hidden><?= h($p['texto_whatsapp']) ?></textarea>
      <?php endif; ?>
    </div>
    <div class="pa-barra"><i data-barra="<?= (int)$p['id'] ?>" style="width:<?= $tot ? round(100 * $ok / $tot) : 0 ?>%"></i></div>

    <?php $faixaAtual = null; foreach ($itens as $it): ?>
      <?php if ($it['faixa'] !== $faixaAtual): $faixaAtual = $it['faixa']; $f = $faixas[$faixaAtual]; ?>
        <div class="pa-faixa"><?= $f['emoji'] ?> <?= h($f['rotulo']) ?></div>
      <?php endif; ?>
      <div class="pa-item <?= (int)$it['feito'] ? 'ok' : '' ?>" data-item="<?= (int)$it['id'] ?>">
        <input type="checkbox" <?= (int)$it['feito'] ? 'checked' : '' ?>
               data-check="<?= (int)$it['id'] ?>" data-plano="<?= (int)$p['id'] ?>">
        <div>
          <div class="pa-meta">
            <span class="pa-nome"><?= h($it['cliente_nome']) ?></span>
            <?php if ($it['telefones'] !== ''): ?>
              <span class="pa-tel"><?php
                $tels = array_filter(array_map('trim', explode(',', (string)$it['telefones'])));
                $links = [];
                foreach ($tels as $t) $links[] = '<a href="tel:' . h(preg_replace('/[^+\d]/', '', $t)) . '">' . h($t) . '</a>';
                echo implode(' · ', $links);
              ?></span>
            <?php endif; ?>
            <span class="pa-badge"><?= h($stages[(int)$it['stage']] ?? ('stage ' . (int)$it['stage'])) ?></span>
            <span class="pa-badge acao"><?= h($it['acao']) ?></span>
            <?php if ($it['origem'] === 'andamentos'): ?><span class="pa-badge origem">sem conversa GHL — base: andamentos</span><?php endif; ?>
          </div>
          <p class="pa-tit"><?= h($it['titulo']) ?></p>
          <?php if (!empty($it['justificativa'])): ?><p class="pa-just"><?= h($it['justificativa']) ?></p><?php endif; ?>
          <?php if (!empty($it['msg_sugerida'])): ?>
            <details class="pa-msg"><summary>Mensagem sugerida</summary><pre><?= h($it['msg_sugerida']) ?></pre></details>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$itens): ?><p class="pa-just">Sem itens neste plano.</p><?php endif; ?>
  </section>
<?php endforeach; ?>
<?php endif; ?>

<script>
document.addEventListener('change', async (e) => {
  const cb = e.target.closest('[data-check]');
  if (!cb) return;
  const id = cb.dataset.check, feito = cb.checked ? '1' : '0';
  cb.disabled = true;
  try {
    const r = await fetch('acao.php', { method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'item_id=' + encodeURIComponent(id) + '&feito=' + feito });
    const j = await r.json();
    if (!j.ok) throw new Error(j.erro || 'falhou');
    cb.closest('.pa-item').classList.toggle('ok', cb.checked);
    // atualiza progresso do plano
    const pid = cb.dataset.plano;
    const caixa = document.querySelectorAll('[data-plano="' + pid + '"]');
    let tot = caixa.length, ok = 0;
    caixa.forEach(c => { if (c.checked) ok++; });
    const prog = document.querySelector('[data-prog="' + pid + '"]');
    if (prog) prog.textContent = ok + '/' + tot + ' feitas';
    const barra = document.querySelector('[data-barra="' + pid + '"]');
    if (barra) barra.style.width = (tot ? Math.round(100 * ok / tot) : 0) + '%';
  } catch (err) {
    cb.checked = !cb.checked;
    alert('Não consegui salvar o check: ' + err.message);
  } finally { cb.disabled = false; }
});
document.addEventListener('click', async (e) => {
  const b = e.target.closest('[data-copiar]');
  if (!b) return;
  const ta = document.getElementById('wa-' + b.dataset.copiar);
  if (!ta) return;
  try { await navigator.clipboard.writeText(ta.value); }
  catch (_) { ta.hidden = false; ta.select(); document.execCommand('copy'); ta.hidden = true; }
  const antes = b.textContent; b.textContent = 'Copiado ✓';
  setTimeout(() => { b.textContent = antes; }, 1800);
});
</script>
<?php portal_footer();
