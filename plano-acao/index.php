<?php
/* =====================================================================
   plano-acao/index.php — Plano de Ação Diário dos corretores.

   Nível 1: exige a ferramenta 'plano-acao'.
   Nível 2: corretor vê SÓ o próprio plano (users.broker_id →
            brokers.robust_user_id); gestor vê os planos das equipes
            liberadas, separados por corretor; admin vê tudo.
   Filtros: data, corretor, equipe, ação e busca livre (client-side).
   Seleção de itens + "Copiar cód. atendimentos" (códigos do Robust).
   Resumo da equipe (gestor/admin) com progresso e média de 7 dias.
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

/* ---- equipes dos corretores visíveis (para o filtro de equipe) ---- */
$brokerIds = array_values(array_filter(array_map(fn($p) => $p['broker_id'], $planos)));
$teamByBroker = []; $teamsSel = [];
if ($brokerIds) {
    $tin = implode(',', array_fill(0, count($brokerIds), '?'));
    $tq = $pdo->prepare("SELECT tb.broker_id, tb.team_id, t.nome
                           FROM team_brokers tb JOIN teams t ON t.id = tb.team_id
                          WHERE tb.broker_id IN ($tin)");
    $tq->execute($brokerIds);
    $permitTeams = is_admin($u) ? null : array_flip(allowed_team_ids($u));
    foreach ($tq->fetchAll() as $r) {
        if ($permitTeams !== null && !isset($permitTeams[(int)$r['team_id']])) continue;
        $teamByBroker[$r['broker_id']][] = (int)$r['team_id'];
        $teamsSel[(int)$r['team_id']] = $r['nome'];
    }
    asort($teamsSel);
}

/* ---- corretor selecionado (server-side) ---- */
$corSel = (int)($_GET['corretor'] ?? 0);
$idsVisiveis = array_map(fn($p) => (int)$p['robust_atendente'], $planos);
if ($corSel && !in_array($corSel, $idsVisiveis, true)) $corSel = 0;
$planosVer = $corSel ? array_values(array_filter($planos, fn($p) => (int)$p['robust_atendente'] === $corSel)) : $planos;

/* ---- itens dos planos exibidos ---- */
$itensPorPlano = []; $acoesDistintas = [];
if ($planosVer) {
    $pin = implode(',', array_fill(0, count($planosVer), '?'));
    $st = $pdo->prepare("SELECT * FROM pa_itens WHERE plano_id IN ($pin)
                         ORDER BY FIELD(faixa,'vermelho','amarelo','azul','branco'), score DESC, id");
    $st->execute(array_map(fn($p) => (int)$p['id'], $planosVer));
    foreach ($st->fetchAll() as $it) {
        $itensPorPlano[(int)$it['plano_id']][] = $it;
        $acoesDistintas[$it['acao']] = true;
    }
}
ksort($acoesDistintas);

/* ---- resumo por corretor (gestor/admin, quando vê mais de um) ---- */
$resumo = [];
if (count($planos) > 1 && $dataSel !== '') {
    $rin = implode(',', array_fill(0, count($idsVisiveis), '?'));
    // dia selecionado
    $rq = $pdo->prepare("SELECT p.robust_atendente, p.corretor_nome,
              COUNT(*) tot, SUM(i.feito) feitas, SUM(i.feito_auto) autos,
              SUM(i.faixa='vermelho' AND i.feito=0) verm_pend,
              SUM(i.faixa='amarelo' AND i.feito=0) ama_pend,
              SUM(i.acao='encerrar' AND i.feito=0) enc_pend
         FROM pa_planos p JOIN pa_itens i ON i.plano_id = p.id
        WHERE p.data = ? AND p.robust_atendente IN ($rin)
        GROUP BY p.robust_atendente, p.corretor_nome");
    $rq->execute(array_merge([$dataSel], $idsVisiveis));
    foreach ($rq->fetchAll() as $r) $resumo[(int)$r['robust_atendente']] = $r + ['media7' => null];
    // média de conclusão dos últimos 7 dias (até a data selecionada)
    $rq7 = $pdo->prepare("SELECT p.robust_atendente,
              SUM(i.feito)/COUNT(*) pct
         FROM pa_planos p JOIN pa_itens i ON i.plano_id = p.id
        WHERE p.data BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ?
          AND p.robust_atendente IN ($rin)
        GROUP BY p.robust_atendente");
    $rq7->execute(array_merge([$dataSel, $dataSel], $idsVisiveis));
    foreach ($rq7->fetchAll() as $r) {
        $rid = (int)$r['robust_atendente'];
        if (isset($resumo[$rid])) $resumo[$rid]['media7'] = (float)$r['pct'];
    }
}

/* última atualização (importação) do dia selecionado */
$ultimaAtu = null;
if ($dataSel !== '') {
    $st = $pdo->prepare('SELECT MAX(criado_em) FROM pa_planos WHERE data = ?');
    $st->execute([$dataSel]);
    $ultimaAtu = $st->fetchColumn() ?: null;
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
.pa-topo{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:14px 0 8px}
.pa-topo select,.pa-topo input[type=search]{padding:9px 11px;border:2px solid var(--line);border-radius:6px;font:inherit;font-size:14px;background:#fff}
.pa-topo input[type=search]{min-width:220px;flex:1}
.pa-acoes-topo{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 18px}
.btn2{background:#fff;color:var(--moss);border:2px solid var(--moss);border-radius:6px;padding:7px 12px;font:inherit;font-weight:600;font-size:13.5px;cursor:pointer}
.btn2:hover{background:rgba(47,93,79,.08)}
.pa-selinfo{font-size:13px;color:var(--mute)}
.pa-resumo{background:#fff;border:1px solid var(--line);border-radius:10px;padding:14px 18px;margin:0 0 20px;overflow-x:auto}
.pa-resumo h2{margin:0 0 10px;font-size:16px}
.pa-resumo table{border-collapse:collapse;width:100%;font-size:14px;min-width:640px}
.pa-resumo th{text-align:left;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute);border-bottom:2px solid var(--line);padding:6px 10px 6px 0}
.pa-resumo td{border-bottom:1px solid var(--line);padding:7px 10px 7px 0;white-space:nowrap}
.pa-resumo tr.clicavel{cursor:pointer}
.pa-resumo tr.clicavel:hover td{background:rgba(47,93,79,.05)}
.pa-mini-barra{display:inline-block;width:90px;height:7px;background:var(--line);border-radius:4px;overflow:hidden;vertical-align:middle;margin-right:6px}
.pa-mini-barra i{display:block;height:100%;background:var(--moss)}
.pa-plano{background:#fff;border:1px solid var(--line);border-radius:10px;padding:18px 20px;margin:0 0 22px}
.pa-cab{display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between;margin-bottom:6px}
.pa-cab h2{margin:0;font-size:18px}
.pa-prog{font-size:13px;color:var(--mute)}
.pa-barra{height:6px;background:var(--line);border-radius:4px;overflow:hidden;margin:8px 0 14px}
.pa-barra i{display:block;height:100%;background:var(--moss)}
.pa-faixa{margin:14px 0 4px;font-weight:700;font-size:14px}
.pa-item{display:flex;gap:12px;align-items:flex-start;border-top:1px solid var(--line);padding:12px 2px}
.pa-item input.pa-check{width:20px;height:20px;margin-top:2px;accent-color:var(--moss);cursor:pointer;flex:0 0 auto}
.pa-item.ok .pa-tit,.pa-item.ok .pa-nome{text-decoration:line-through;opacity:.55}
.pa-corpo{flex:1;min-width:0}
.pa-nome{font-weight:700}
.pa-tel{font-size:13.5px;white-space:nowrap}
.pa-tel a{color:var(--moss);text-decoration:none;font-weight:600}
.pa-meta{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:2px 0 4px}
.pa-badge{font-size:11.5px;padding:2px 8px;border-radius:10px;background:rgba(47,93,79,.12);color:var(--moss);font-weight:600}
.pa-badge.acao{background:rgba(180,81,47,.10);color:var(--clay)}
.pa-badge.origem{background:var(--line);color:var(--mute);font-weight:500}
.pa-badge.auto{background:rgba(46,92,138,.12);color:#2E5C8A}
.pa-cod{font-family:ui-monospace,Consolas,monospace;font-size:11.5px;color:var(--mute);background:var(--line);padding:2px 7px;border-radius:6px}
.pa-tit{margin:2px 0 2px;font-size:14.5px}
.pa-just{font-size:13.5px;color:var(--mute);margin:0}
.pa-hint{font-size:13px;color:#8a6d1c;background:rgba(212,160,23,.12);border-radius:6px;padding:6px 10px;margin:6px 0 0}
.pa-msg{margin-top:6px;font-size:13px}
.pa-msg summary{cursor:pointer;color:var(--moss);font-weight:600;list-style:none}
.pa-msg pre{white-space:pre-wrap;background:rgba(47,93,79,.06);border-radius:6px;padding:10px 12px;margin:6px 0 0;font:inherit}
.pa-sel{width:16px;height:16px;margin-top:5px;accent-color:#2E5C8A;cursor:pointer;flex:0 0 auto}
.pa-copiar{margin-left:auto}
.pa-vazio{background:#fff;border:1px dashed var(--line);border-radius:10px;padding:30px;text-align:center;color:var(--mute)}
.pa-oculto{display:none!important}
@media (max-width:560px){.pa-item{gap:8px}.pa-cab h2{font-size:16px}.pa-topo input[type=search]{min-width:140px}}
</style>

<h1 class="home-titulo">Plano de Ação Diário</h1>
<p class="home-sub">Clientes ativos do seu funil no Robust, priorizados pela conversa real — execute na ordem e dê o check.<?php
if ($ultimaAtu) {
    $ts = strtotime($ultimaAtu);
    $dias = ['Sun'=>'dom','Mon'=>'seg','Tue'=>'ter','Wed'=>'qua','Thu'=>'qui','Fri'=>'sex','Sat'=>'sáb'];
    echo ' <b>Última atualização: ' . ($dias[date('D',$ts)] ?? '') . ' ' . date('d/m', $ts) . ' às ' . date('H\hi', $ts) . '</b>.';
} ?></p>

<?php if (!$datas): ?>
  <div class="pa-vazio">Nenhum plano importado ainda<?= $permit === [] ? ' — seu usuário não está vinculado a um corretor (fale com o admin)' : '' ?>.</div>
<?php else: ?>
<form class="pa-topo" method="get" id="pa-form">
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
  <?php if (count($teamsSel) > 1): ?>
  <select id="f-equipe">
    <option value="">Todas as equipes</option>
    <?php foreach ($teamsSel as $tid => $tnome): ?>
      <option value="<?= (int)$tid ?>"><?= h($tnome) ?></option>
    <?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select id="f-acao">
    <option value="">Todas as ações</option>
    <?php foreach (array_keys($acoesDistintas) as $ac): ?>
      <option value="<?= h($ac) ?>"><?= h($ac) ?></option>
    <?php endforeach; ?>
  </select>
  <select id="f-status">
    <option value="">Feitas e pendentes</option>
    <option value="pend">Só pendentes</option>
    <option value="ok">Só feitas</option>
  </select>
  <input type="search" id="f-busca" placeholder="Buscar em tudo: nome, telefone, código, texto…">
</form>
<div class="pa-acoes-topo">
  <button type="button" class="btn2" id="b-sel-todos">Selecionar listados</button>
  <button type="button" class="btn2" id="b-sel-nada">Limpar seleção</button>
  <button type="button" class="btn" id="b-copiar-cod">Copiar cód. atendimentos</button>
  <span class="pa-selinfo" id="sel-info"></span>
</div>

<?php if ($resumo && !$corSel): ?>
<section class="pa-resumo">
  <h2>Resumo da equipe — <?= h(pa_data_label($dataSel)) ?></h2>
  <table>
    <tr><th>Corretor</th><th>Progresso</th><th>Feitas</th><th>🔴 pend.</th><th>🟡 pend.</th><th>Encerrar sug.</th><th>Média 7 dias</th></tr>
    <?php foreach ($planos as $p): $r = $resumo[(int)$p['robust_atendente']] ?? null; if (!$r) continue;
        $tot=(int)$r['tot']; $ft=(int)$r['feitas']; $pct=$tot?round(100*$ft/$tot):0; ?>
    <tr class="clicavel" data-corretor="<?= (int)$p['robust_atendente'] ?>">
      <td><?= h($r['corretor_nome']) ?></td>
      <td><span class="pa-mini-barra"><i style="width:<?= $pct ?>%"></i></span><?= $pct ?>%</td>
      <td><?= $ft ?>/<?= $tot ?><?= (int)$r['autos'] ? ' <span class="pa-badge auto">' . (int)$r['autos'] . ' auto</span>' : '' ?></td>
      <td><?= (int)$r['verm_pend'] ?: '—' ?></td>
      <td><?= (int)$r['ama_pend'] ?: '—' ?></td>
      <td><?= (int)$r['enc_pend'] ?: '—' ?></td>
      <td><?= $r['media7'] !== null ? round(100*(float)$r['media7']) . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</section>
<?php endif; ?>

<?php foreach ($planosVer as $p):
    $itens = $itensPorPlano[(int)$p['id']] ?? [];
    $tot = count($itens); $ok = count(array_filter($itens, fn($i) => (int)$i['feito'] === 1));
    $teams = implode(',', $teamByBroker[$p['broker_id']] ?? []);
?>
  <section class="pa-plano" data-teams="<?= h($teams) ?>">
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
      <div class="pa-item <?= (int)$it['feito'] ? 'ok' : '' ?>"
           data-item="<?= (int)$it['id'] ?>" data-aid="<?= (int)$it['atendimento_id'] ?>"
           data-acao="<?= h($it['acao']) ?>" data-feito="<?= (int)$it['feito'] ?>">
        <input type="checkbox" class="pa-sel" title="Selecionar p/ copiar códigos">
        <input type="checkbox" class="pa-check" <?= (int)$it['feito'] ? 'checked' : '' ?>
               data-check="<?= (int)$it['id'] ?>" data-plano="<?= (int)$p['id'] ?>">
        <div class="pa-corpo">
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
            <span class="pa-cod">Cód. <?= (int)$it['atendimento_id'] ?></span>
            <span class="pa-badge"><?= h($stages[(int)$it['stage']] ?? ('stage ' . (int)$it['stage'])) ?></span>
            <span class="pa-badge acao"><?= h($it['acao']) ?></span>
            <?php if ($it['origem'] === 'andamentos'): ?><span class="pa-badge origem">sem conversa GHL — base: andamentos</span><?php endif; ?>
            <?php if ((int)$it['feito'] && !empty($it['feito_auto'])): ?><span class="pa-badge auto">feita — detectada na conversa</span><?php endif; ?>
          </div>
          <p class="pa-tit"><?= h($it['titulo']) ?></p>
          <?php if (!empty($it['justificativa'])): ?><p class="pa-just"><?= h($it['justificativa']) ?></p><?php endif; ?>
          <?php if (!empty($it['nome_sugerido'])): ?>
            <p class="pa-hint">💡 O cliente se identificou como <b><?= h($it['nome_sugerido']) ?></b> na conversa — atualize o nome do cadastro no Robust/GHL.</p>
          <?php endif; ?>
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
/* ---------- filtros client-side (equipe, ação, status, busca) ---------- */
function aplicaFiltros(){
  const eq = document.getElementById('f-equipe')?.value || '';
  const ac = document.getElementById('f-acao')?.value || '';
  const stq = document.getElementById('f-status')?.value || '';
  const q  = (document.getElementById('f-busca')?.value || '').toLowerCase().trim();
  document.querySelectorAll('.pa-plano').forEach(sec => {
    let secVisivel = true;
    if (eq) {
      const teams = (sec.dataset.teams || '').split(',').filter(Boolean);
      secVisivel = teams.includes(eq);
    }
    sec.classList.toggle('pa-oculto', !secVisivel);
    if (!secVisivel) return;
    sec.querySelectorAll('.pa-item').forEach(it => {
      let v = true;
      if (ac && it.dataset.acao !== ac) v = false;
      if (v && stq === 'pend' && it.querySelector('.pa-check').checked) v = false;
      if (v && stq === 'ok' && !it.querySelector('.pa-check').checked) v = false;
      if (v && q && !it.textContent.toLowerCase().includes(q)) v = false;
      it.classList.toggle('pa-oculto', !v);
    });
    // esconde cabeçalhos de faixa sem itens visíveis logo abaixo
    sec.querySelectorAll('.pa-faixa').forEach(fx => {
      let el = fx.nextElementSibling, tem = false;
      while (el && !el.classList.contains('pa-faixa')) {
        if (el.classList.contains('pa-item') && !el.classList.contains('pa-oculto')) { tem = true; break; }
        el = el.nextElementSibling;
      }
      fx.classList.toggle('pa-oculto', !tem);
    });
  });
  atualizaSelInfo();
}
['f-equipe','f-acao','f-status'].forEach(id => document.getElementById(id)?.addEventListener('change', aplicaFiltros));
document.getElementById('f-busca')?.addEventListener('input', () => { clearTimeout(window._paT); window._paT = setTimeout(aplicaFiltros, 180); });

/* ---------- seleção + copiar códigos ---------- */
function itensListados(){
  return [...document.querySelectorAll('.pa-item')].filter(i =>
    !i.classList.contains('pa-oculto') && !i.closest('.pa-plano').classList.contains('pa-oculto'));
}
function atualizaSelInfo(){
  const sel = document.querySelectorAll('.pa-sel:checked').length;
  const lis = itensListados().length;
  document.getElementById('sel-info').textContent =
    sel ? sel + ' selecionado(s)' : lis + ' listado(s)';
}
document.addEventListener('change', e => { if (e.target.classList.contains('pa-sel')) atualizaSelInfo(); });
document.getElementById('b-sel-todos')?.addEventListener('click', () => {
  itensListados().forEach(i => i.querySelector('.pa-sel').checked = true); atualizaSelInfo();
});
document.getElementById('b-sel-nada')?.addEventListener('click', () => {
  document.querySelectorAll('.pa-sel:checked').forEach(c => c.checked = false); atualizaSelInfo();
});
document.getElementById('b-copiar-cod')?.addEventListener('click', async () => {
  const marcados = [...document.querySelectorAll('.pa-sel:checked')].map(c => c.closest('.pa-item'));
  const alvo = marcados.length ? marcados : itensListados();
  const cods = [...new Set(alvo.map(i => i.dataset.aid))];
  const texto = cods.join(', ');
  const b = document.getElementById('b-copiar-cod');
  try { await navigator.clipboard.writeText(texto); }
  catch (_) {
    const ta = document.createElement('textarea'); ta.value = texto;
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
  }
  const antes = b.textContent;
  b.textContent = 'Copiados ' + cods.length + ' cód. ✓';
  setTimeout(() => { b.textContent = antes; }, 2200);
});

/* ---------- resumo: clique filtra o corretor ---------- */
document.querySelectorAll('.pa-resumo tr.clicavel').forEach(tr => {
  tr.addEventListener('click', () => {
    const f = document.getElementById('pa-form');
    const sel = f.querySelector('select[name=corretor]');
    if (sel) { sel.value = tr.dataset.corretor; f.submit(); }
  });
});

/* ---------- check de tarefa ---------- */
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

/* ---------- copiar texto WhatsApp ---------- */
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

atualizaSelInfo();
</script>
<?php portal_footer();
