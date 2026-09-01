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
// Lista de Bloqueio: números bloqueados somem do plano na hora (além de já
// não entrarem na próxima importação).
require_once __DIR__ . '/../lib/blocklist.php';
$BLOCK_PA = blocklist_ativa('plano-acao') ? blocklist_set() : [];
$paBloq = function ($tels) use ($BLOCK_PA): bool {
    if (!$BLOCK_PA) return false;
    foreach (explode(',', (string)$tels) as $t) if (fone_bloqueado(trim($t), $BLOCK_PA)) return true;
    return false;
};
$itensPorPlano = []; $acoesDistintas = [];
if ($planosVer) {
    $pin = implode(',', array_fill(0, count($planosVer), '?'));
    $st = $pdo->prepare("SELECT * FROM pa_itens WHERE plano_id IN ($pin)
                         ORDER BY FIELD(faixa,'vermelho','amarelo','azul','branco'), score DESC, id");
    $st->execute(array_map(fn($p) => (int)$p['id'], $planosVer));
    foreach ($st->fetchAll() as $it) {
        if ($paBloq($it['telefones'] ?? '')) continue;   // número na lista de bloqueio
        $itensPorPlano[(int)$it['plano_id']][] = $it;
        $acoesDistintas[$it['acao']] = true;
    }
}
ksort($acoesDistintas);

/* =====================================================================
   Dashboard de desempenho por corretor — dia selecionado ou período.
   KPIs por corretor (todos derivados de pa_planos/pa_itens):
     execucao   = média diária de (feitas / total)             — disciplina
     prio       = média diária de (🔴🟡 feitas / 🔴🟡 total)    — foco no que importa
     concluidas = atendimentos com check no período (manual | auto)
     resp_pend  = clientes esperando resposta ainda pendentes (último dia) — risco
     enc_feitos = encerramentos executados (limpeza de carteira)
     carteira / frio% = tamanho e % ⚪ no último dia do período   — saúde
     horas_check = mediana de horas entre o plano e o check manual — agilidade
     dias_ativos = dias com ≥1 check manual                       — uso do sistema
   ===================================================================== */
$periodoSel = (string)($_GET['periodo'] ?? 'dia');
if (!in_array($periodoSel, ['dia','7','30','mes'], true)) $periodoSel = 'dia';
$dash = []; $dashDias = []; $dashIni = $dataSel; $dashFim = $dataSel;
if ($dataSel !== '' && $idsVisiveis) {
    if ($periodoSel === '7')   $dashIni = date('Y-m-d', strtotime($dataSel . ' -6 days'));
    if ($periodoSel === '30')  $dashIni = date('Y-m-d', strtotime($dataSel . ' -29 days'));
    if ($periodoSel === 'mes') $dashIni = substr($dataSel, 0, 8) . '01';
    $rin = implode(',', array_fill(0, count($idsVisiveis), '?'));
    $st = $pdo->prepare("SELECT p.data, p.criado_em, p.robust_atendente, p.corretor_nome,
                                i.atendimento_id, i.faixa, i.acao, i.feito, i.feito_auto, i.feito_em
                           FROM pa_planos p JOIN pa_itens i ON i.plano_id = p.id
                          WHERE p.data BETWEEN ? AND ? AND p.robust_atendente IN ($rin)");
    $st->execute(array_merge([$dashIni, $dashFim], $idsVisiveis));
    $porDia = [];   // [rid][data] => agregados do dia
    $conc = [];     // [rid] => atendimento_id => auto?
    $encf = [];     // [rid] => set de atendimentos encerrados
    $horas = [];    // [rid] => lista de horas até check manual
    $diasAtivos = [];
    $nomes = [];
    foreach ($st->fetchAll() as $r) {
        $rid = (int)$r['robust_atendente']; $d = $r['data'];
        $nomes[$rid] = $r['corretor_nome'];
        $dashDias[$d] = true;
        $a = &$porDia[$rid][$d];
        if (!$a) $a = ['tot'=>0,'feitas'=>0,'ptot'=>0,'pfeitas'=>0,'resp_pend'=>0,'branco'=>0,'criado'=>$r['criado_em']];
        $a['tot']++; if ((int)$r['feito']) $a['feitas']++;
        if (in_array($r['faixa'], ['vermelho','amarelo'], true)) { $a['ptot']++; if ((int)$r['feito']) $a['pfeitas']++; }
        if ($r['acao'] === 'responder cliente' && !(int)$r['feito']) $a['resp_pend']++;
        if ($r['faixa'] === 'branco') $a['branco']++;
        unset($a);
        if ((int)$r['feito']) {
            $aid = (int)$r['atendimento_id'];
            if (!isset($conc[$rid][$aid]) || !(int)$r['feito_auto']) $conc[$rid][$aid] = (int)$r['feito_auto'];
            if ($r['acao'] === 'encerrar') $encf[$rid][$aid] = true;
            if (!(int)$r['feito_auto'] && $r['feito_em'] && $r['criado_em']) {
                $h = (strtotime($r['feito_em']) - strtotime($r['criado_em'])) / 3600;
                if ($h >= 0 && $h < 24*14) { $horas[$rid][] = $h; $diasAtivos[$rid][substr($r['feito_em'],0,10)] = true; }
            }
        }
    }
    foreach ($porDia as $rid => $dias) {
        ksort($dias);
        $ex = []; $pr = []; $ult = end($dias);
        foreach ($dias as $a) {
            if ($a['tot']) $ex[] = $a['feitas'] / $a['tot'];
            if ($a['ptot']) $pr[] = $a['pfeitas'] / $a['ptot'];
        }
        $manual = 0; $auto = 0;
        foreach ($conc[$rid] ?? [] as $isAuto) { if ($isAuto) $auto++; else $manual++; }
        $hs = $horas[$rid] ?? []; sort($hs);
        $dash[$rid] = [
            'nome' => $nomes[$rid], 'dias' => count($dias),
            'execucao' => $ex ? array_sum($ex)/count($ex) : null,
            'prio' => $pr ? array_sum($pr)/count($pr) : null,
            'manual' => $manual, 'auto' => $auto,
            'resp_pend' => $ult['resp_pend'], 'enc_feitos' => count($encf[$rid] ?? []),
            'carteira' => $ult['tot'], 'frio' => $ult['tot'] ? $ult['branco']/$ult['tot'] : 0,
            'horas_check' => $hs ? $hs[intdiv(count($hs), 2)] : null,
            'dias_ativos' => count($diasAtivos[$rid] ?? []),
        ];
    }
    uasort($dash, fn($a, $b) => ($b['execucao'] ?? -1) <=> ($a['execucao'] ?? -1));
}
$dashAtivos = array_filter($dash, fn($k) => ($k['manual'] + $k['auto']) > 0);
$dashInativos = array_filter($dash, fn($k) => ($k['manual'] + $k['auto']) === 0);
$eqEx = []; $eqPr = []; $eqConc = 0; $eqResp = 0;
foreach ($dash as $k) {
    if ($k['execucao'] !== null) $eqEx[] = $k['execucao'];
    if ($k['prio'] !== null) $eqPr[] = $k['prio'];
    $eqConc += $k['manual'] + $k['auto']; $eqResp += $k['resp_pend'];
}
function pa_periodo_label(string $p, string $ini, string $fim): string {
    $f = fn($d) => date('d/m', strtotime($d));
    return $p === 'dia' ? pa_data_label($fim) : $f($ini) . ' a ' . $f($fim);
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
.pa-dash{background:#fff;border:1px solid var(--line);border-radius:10px;padding:16px 20px;margin:0 0 20px}
.pa-dash-cab{display:flex;flex-wrap:wrap;gap:6px 14px;align-items:baseline;margin-bottom:12px}
.pa-dash h2{margin:0;font-size:17px}
.pa-dash-sub{font-size:13px;color:var(--mute);margin:8px 0 0}
.pa-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px}
.pa-tile{background:var(--paper);border:1px solid var(--line);border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:2px}
.pa-tile.alerta{border-left:4px solid var(--clay)}
.pa-tile-l{font-size:12px;color:var(--mute)}
.pa-tile b{font-size:24px;font-weight:700;font-variant-numeric:tabular-nums}
.pa-tile b.pa-hero{font-size:34px;color:var(--moss)}
.pa-tile-s{font-size:11.5px;color:var(--mute)}
.pa-legenda{display:flex;gap:16px;font-size:12.5px;color:var(--mute);margin:4px 0 4px}
.pa-legenda i{display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-2px}
.pa-chart-wrap{overflow-x:auto}
.pa-chart{width:100%;max-width:760px;height:auto;display:block;font-family:inherit}
.pa-chart .pa-ax{font-size:10.5px;fill:var(--mute)}
.pa-chart .pa-lbl{font-size:12px;fill:var(--ink)}
.pa-chart .pa-val{font-size:11px;fill:var(--ink);font-variant-numeric:tabular-nums}
.pa-chart .pa-row{cursor:pointer}
.pa-chart .pa-row:hover rect{opacity:.8}
.pa-dash-tbl-wrap{overflow-x:auto;margin-top:12px}
.pa-dash-tbl{border-collapse:collapse;width:100%;font-size:13.5px;min-width:820px}
.pa-dash-tbl th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:var(--mute);border-bottom:2px solid var(--line);padding:6px 10px 6px 0;white-space:nowrap}
.pa-dash-tbl td{border-bottom:1px solid var(--line);padding:7px 10px 7px 0;white-space:nowrap;font-variant-numeric:tabular-nums}
.pa-dash-tbl tr.clicavel{cursor:pointer}
.pa-dash-tbl tr.clicavel:hover td{background:rgba(47,93,79,.05)}
.pa-dash-tbl tr.sem-atividade td{color:var(--mute)}
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
  <select name="periodo" onchange="this.form.submit()">
    <option value="dia" <?= $periodoSel==='dia'?'selected':'' ?>>Desempenho: dia</option>
    <option value="7" <?= $periodoSel==='7'?'selected':'' ?>>Desempenho: 7 dias</option>
    <option value="30" <?= $periodoSel==='30'?'selected':'' ?>>Desempenho: 30 dias</option>
    <option value="mes" <?= $periodoSel==='mes'?'selected':'' ?>>Desempenho: mês</option>
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

<?php if ($dash): ?>
<section class="pa-dash">
  <div class="pa-dash-cab">
    <h2>Desempenho — <?= h(pa_periodo_label($periodoSel, $dashIni, $dashFim)) ?></h2>
    <span class="pa-dash-sub"><?= count($dashDias) ?> dia(s) com plano · <?= count($dashAtivos) ?> corretor(es) com atividade</span>
  </div>
  <div class="pa-tiles">
    <div class="pa-tile"><span class="pa-tile-l">Execução média da equipe</span><b class="pa-hero"><?= $eqEx ? round(100*array_sum($eqEx)/count($eqEx)) : 0 ?>%</b><span class="pa-tile-s">tarefas do plano concluídas</span></div>
    <div class="pa-tile"><span class="pa-tile-l">Prioridades atendidas</span><b><?= $eqPr ? round(100*array_sum($eqPr)/count($eqPr)) : 0 ?>%</b><span class="pa-tile-s">🔴🟡 concluídas</span></div>
    <div class="pa-tile"><span class="pa-tile-l">Tarefas concluídas</span><b><?= $eqConc ?></b><span class="pa-tile-s">clientes trabalhados no período</span></div>
    <div class="pa-tile <?= $eqResp ? 'alerta' : '' ?>"><span class="pa-tile-l">Esperando resposta</span><b><?= $eqResp ?></b><span class="pa-tile-s">clientes ainda sem retorno</span></div>
  </div>

  <?php if ($dashAtivos):
    $n = count($dashAtivos); $rowH = 34; $left = 170; $w = 760; $plotW = $w - $left - 70; $hgt = 30 + $n * $rowH + 8; ?>
  <div class="pa-legenda"><span><i style="background:#159463"></i>Execução geral</span><span><i style="background:#c86a2c"></i>Prioridades 🔴🟡</span></div>
  <div class="pa-chart-wrap">
  <svg class="pa-chart" viewBox="0 0 <?= $w ?> <?= $hgt ?>" role="img" aria-label="Execução por corretor">
    <?php for ($g = 0; $g <= 100; $g += 25): $x = $left + $plotW * $g / 100; ?>
      <line x1="<?= $x ?>" y1="22" x2="<?= $x ?>" y2="<?= $hgt - 8 ?>" stroke="var(--line)" stroke-width="1"/>
      <text x="<?= $x ?>" y="14" text-anchor="middle" class="pa-ax"><?= $g ?>%</text>
    <?php endfor; ?>
    <?php $y = 30; foreach ($dashAtivos as $rid => $k):
        $ex = round(100 * ($k['execucao'] ?? 0)); $pr = $k['prio'] === null ? null : round(100 * $k['prio']);
        $bx = max(4, $plotW * $ex / 100); $bp = $pr === null ? 0 : max(4, $plotW * $pr / 100);
        $nome = mb_strimwidth($k['nome'], 0, 22, '…'); ?>
      <g class="pa-row" data-corretor="<?= (int)$rid ?>">
        <title><?= h($k['nome']) ?> — execução <?= $ex ?>% · prioridades <?= $pr === null ? '—' : $pr . '%' ?> · <?= $k['manual'] + $k['auto'] ?> concluídas</title>
        <text x="<?= $left - 10 ?>" y="<?= $y + 15 ?>" text-anchor="end" class="pa-lbl"><?= h($nome) ?></text>
        <rect x="<?= $left ?>" y="<?= $y ?>" width="<?= $bx ?>" height="12" rx="0" fill="#159463"/>
        <rect x="<?= $left + $bx - 4 ?>" y="<?= $y ?>" width="4" height="12" rx="3" fill="#159463"/>
        <text x="<?= $left + $bx + 6 ?>" y="<?= $y + 10 ?>" class="pa-val"><?= $ex ?>%</text>
        <?php if ($pr !== null): ?>
        <rect x="<?= $left ?>" y="<?= $y + 14 ?>" width="<?= $bp ?>" height="12" fill="#c86a2c"/>
        <rect x="<?= $left + $bp - 4 ?>" y="<?= $y + 14 ?>" width="4" height="12" rx="3" fill="#c86a2c"/>
        <?php endif; ?>
      </g>
    <?php $y += $rowH; endforeach; ?>
  </svg>
  </div>
  <?php endif; ?>

  <div class="pa-dash-tbl-wrap"><table class="pa-dash-tbl">
    <tr><th>Corretor</th><th>Execução</th><th>Prioridades</th><th>Concluídas</th><th>Esperando resp.</th><th>Encerrados</th><th>Carteira</th><th>% frio</th><th>Horas até o check</th><th>Dias ativos</th></tr>
    <?php foreach ($dash as $rid => $k): ?>
    <tr class="clicavel <?= ($k['manual']+$k['auto']) ? '' : 'sem-atividade' ?>" data-corretor="<?= (int)$rid ?>">
      <td><?= h($k['nome']) ?></td>
      <td><?= $k['execucao'] === null ? '—' : round(100*$k['execucao']) . '%' ?></td>
      <td><?= $k['prio'] === null ? '—' : round(100*$k['prio']) . '%' ?></td>
      <td><?= $k['manual'] + $k['auto'] ?><?= $k['auto'] ? ' <span class="pa-badge auto">' . $k['auto'] . ' auto</span>' : '' ?></td>
      <td><?= $k['resp_pend'] ?: '—' ?></td>
      <td><?= $k['enc_feitos'] ?: '—' ?></td>
      <td><?= $k['carteira'] ?></td>
      <td><?= round(100*$k['frio']) ?>%</td>
      <td><?= $k['horas_check'] === null ? '—' : ($k['horas_check'] < 1 ? '<1h' : round($k['horas_check']) . 'h') ?></td>
      <td><?= $k['dias_ativos'] ?>/<?= $k['dias'] ?></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php if ($dashInativos): ?><p class="pa-dash-sub">Sem atividade no período: <?= h(implode(', ', array_map(fn($k) => explode(' ', $k['nome'])[0], $dashInativos))) ?>.</p><?php endif; ?>
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
document.querySelectorAll('.pa-dash-tbl tr.clicavel, .pa-chart .pa-row').forEach(tr => {
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
