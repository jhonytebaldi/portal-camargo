<?php
/* =====================================================================
   capa-financeira/vendas/editar.php — formulário da capa de venda.
   ?id=N&etapa=1..4 — cada etapa num arquivo (etapa1.inc.php … etapa4.inc.php);
   aqui ficam o cabeçalho, as abas das etapas, os helpers de JS comuns e o
   painel lateral comum (datas, cancelar/descancelar, histórico).
   Cálculos e validação vêm sempre do servidor (api.php → previa/previa2).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
$id = (int)($_GET['id'] ?? 0);
$venda = $id ? cv_carregar($id) : null;
if ($id && !$venda) { http_response_code(404); portal_header('Venda', $u); echo '<p class="aviso">Venda não encontrada.</p>'; portal_footer(); exit; }
$etapa = $venda ? max(1, min(4, (int)($_GET['etapa'] ?? 1))) : 1;

$v = $venda ?? (['id' => 0, 'empresa' => '', 'construtora' => '', 'endereco' => '', 'bairro' => '', 'cep' => '', 'unidade' => '', 'cod_imovel' => '', 'status_imovel' => null, 'data_venda' => null,
                 'valor_contrato' => null, 'valor_bonus' => null, 'documentacao' => null, 'descontos' => null, 'obs' => null, 'e1_status' => 'rascunho',
                 'e2_status' => 'rascunho', 'e3_status' => 'rascunho', 'e4_status' => 'rascunho', 'status' => 'andamento', 'rev' => 0, 'compradores' => [], 'e2' => null, 'e3' => null, 'capa_id' => null] + Venda::PADROES);
$cancelada = $v['status'] === 'cancelada';
$hist = $venda ? cv_historico($id, 400) : [];

/** painel lateral comum (chamado de dentro do <aside> de cada etapa) */
function cv_aside_comum(?array $venda, array $hist, bool $cancelada): void
{
    if (!$venda) return;
    $id = (int)$venda['id'];
    echo '<section class="card"><p class="cf-dica">Criada em ' . h(substr((string)$venda['criado_em'], 0, 16)) . ' por ' . h((string)$venda['criado_nome'])
       . ' · última edição ' . h(substr((string)$venda['atualizado_em'], 0, 16)) . ' por ' . h((string)$venda['atualizado_nome']) . '</p>';
    echo '<p><a class="btn cf-btn-sec" href="/capa-financeira/vendas/xlsx.php?id=' . $id . '">⬇ Baixar .xlsx</a></p>';
    echo $cancelada ? '<p><button type="button" class="btn" id="cv-descancelar">Descancelar esta venda</button></p>'
                    : '<p><button type="button" class="cf-x" id="cv-cancelar">cancelar esta venda</button></p>';
    echo '</section>';
    if (!$hist) return;
    echo '<section class="card"><h2>Histórico de alterações</h2><ul class="cv-hist">';
    foreach (array_slice($hist, 0, 60) as $hh) {
        $q = '<span class="q">' . h(date('d/m H:i', strtotime($hh['quando']))) . ' · ' . h($hh['user_nome']) . ($hh['etapa'] ? ' · etapa ' . (int)$hh['etapa'] : '') . '</span> — ';
        $t = match ($hh['acao']) {
            'criar' => 'criou a venda',
            'concluir' => (int)$hh['etapa'] ? '<b>concluiu a etapa ' . (int)$hh['etapa'] . '</b>' . ((int)$hh['etapa'] === 4 ? ' (todos os pagamentos liberados)' : '') : '<b>venda concluída</b> (todos os pagamentos no Omie)',
            'reabrir' => '<b>' . ((int)$hh['etapa'] ? 'etapa ' . (int)$hh['etapa'] . ' voltou a rascunho' : 'venda voltou a “em andamento”') . '</b> (' . h((string)$hh['depois']) . ')',
            'cancelar' => '<b>cancelou a venda</b> (' . h((string)$hh['depois']) . ')',
            'descancelar' => '<b>descancelou a venda</b> (voltou a ' . h((string)$hh['depois']) . ')',
            'omie_cadastro', 'omie_vinculo', 'bairro_novo' => h((string)$hh['campo']) . ': ' . h((string)$hh['depois']),
            'xlsx' => 'baixou a planilha',
            'contas_receber' => '<b>' . h((string)$hh['depois']) . '</b>',
            'liberar' => '<b>' . h((string)$hh['campo']) . '</b> — ' . h((string)$hh['depois']) . ((string)$hh['antes'] !== 'aguardando' ? ' <small>(antes: ' . h((string)$hh['antes']) . ')</small>' : ''),
            'desliberar' => '<b>desfez a liberação</b> de ' . h((string)$hh['campo']) . ' (' . h((string)$hh['antes']) . ')',
            default => h((string)$hh['campo']) . ': ' . ((string)$hh['antes'] !== '' ? '<s>' . h((string)$hh['antes']) . '</s> → ' : '') . ((string)$hh['depois'] !== '' ? h((string)$hh['depois']) : '<i>(vazio)</i>'),
        };
        echo '<li>' . $q . $t . '</li>';
    }
    echo '</ul>' . (count($hist) > 60 ? '<p class="cf-dica">Mostrando as 60 mais recentes de ' . count($hist) . '.</p>' : '') . '</section>';
}

/** barra de ações grande no fim da tela (fica visível embaixo enquanto rola): etapa anterior · salvar rascunho · concluir · próxima etapa */
function cv_barra_acoes(int $etapa, ?array $venda, bool $concluida, bool $cancelada, string $idSalvar, string $idConcluir, string $txtConcluir, string $info = ''): void
{
    $id = $venda ? (int)$venda['id'] : 0;
    $prontas = 4;   // etapas já construídas
    echo '<div class="cv-barra-fim cv-botoes"><div class="cv-nav">';
    if ($id && $etapa > 1) echo '<a class="btn cf-btn-sec" href="/capa-financeira/vendas/editar.php?id=' . $id . '&etapa=' . ($etapa - 1) . '">← Etapa ' . ($etapa - 1) . '</a>';
    if ($info !== '') echo '<span class="cv-status-txt">' . $info . '</span>';
    echo '</div><div class="cv-acoes">';
    if (!$cancelada) {
        if (!$concluida) echo '<button type="button" class="btn cf-btn-sec" id="' . h($idSalvar) . '">Salvar rascunho</button>';
        echo '<button type="button" class="btn" id="' . h($idConcluir) . '">' . h($concluida ? 'Salvar alterações' : $txtConcluir) . '</button>';
    }
    if ($id && $etapa < $prontas) echo '<a class="btn' . ($concluida ? '' : ' cf-btn-sec') . '" href="/capa-financeira/vendas/editar.php?id=' . $id . '&etapa=' . ($etapa + 1) . '">Etapa ' . ($etapa + 1) . ' →</a>';
    echo '</div></div>';
}

// título: compradores × construtora (o número da venda vai pequeno ao lado)
$nomesC = array_values(array_filter(array_map(fn($c) => trim((string)($c['nome'] ?? '')), $v['compradores'])));
$eC = $nomesC && mb_strtoupper(implode('', $nomesC)) === implode('', $nomesC) ? ' E ' : ' e ';   // nomes em maiúsculas → "E"
$compTxt = count($nomesC) <= 2 ? implode($eC, $nomesC) : implode(', ', array_slice($nomesC, 0, -1)) . $eC . end($nomesC);
$tituloVenda = trim($compTxt . ($compTxt !== '' && trim((string)$v['construtora']) !== '' ? ' × ' : '') . trim((string)$v['construtora']));
portal_header($venda ? ($tituloVenda !== '' ? $tituloVenda . ' · #' . $id : 'Venda #' . $id) : 'Nova venda', $u);
?>
<style>
main.wrap{max-width:1400px}
.cv-num-venda{font-size:14px;font-weight:500;color:var(--mute);margin-left:8px;white-space:nowrap}
.cv-steps{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 4px}
.cv-step{flex:1;min-width:200px;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:8px 12px;font-size:13px;color:var(--mute);text-decoration:none}
.cv-step b{display:block;color:var(--ink);font-size:14px}.cv-step.on{border:2px solid var(--moss);background:#fff}.cv-step.off{opacity:.55}
a.cv-step:hover{border-color:var(--moss)}
.cv-cols{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:18px;align-items:start}
@media(max-width:1100px){.cv-cols{grid-template-columns:1fr}}
.cv-lado{min-width:0}
.cv-g{display:grid;gap:10px}.cv-g2{grid-template-columns:1fr 1fr}.cv-g3{grid-template-columns:2fr 1fr 1fr}.cv-g4{grid-template-columns:repeat(4,1fr)}.cv-gend{grid-template-columns:130px 2fr 1.2fr 1fr}
@media(max-width:700px){.cv-g2,.cv-g3,.cv-g4,.cv-gend{grid-template-columns:1fr}}
.cv-f label{display:block;font-size:12.5px;font-weight:600}
.cv-f input,.cv-f select,.cv-f textarea{display:block;width:100%;margin-top:3px;padding:7px 9px;font:inherit;font-size:14px;border:2px solid var(--line);border-radius:6px;background:#fff;box-sizing:border-box}
.cv-f input.cv-num{text-align:right;font-variant-numeric:tabular-nums}
.cv-f input:focus,.cv-f select:focus{border-color:var(--moss);outline:none}
.cv-f input.cv-erro{border-color:var(--clay);background:#fff6f2}
.cv-sug{display:block;font-style:italic;font-size:11.5px;color:var(--mute);font-weight:400;margin-top:2px}
.cv-sug button,.cv-mini-btn{font:inherit;font-size:11.5px;font-style:normal;font-weight:600;padding:1px 7px;border-radius:5px;border:1px solid var(--moss);background:#fff;color:var(--moss);cursor:pointer;margin-left:4px}
.cv-comp{border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin:8px 0;background:#fff}
.cv-comp-top{display:flex;justify-content:space-between;align-items:center;font-size:13px;font-weight:700;margin-bottom:6px}
.cv-chip{display:inline-block;font-size:11.5px;font-weight:600;border-radius:10px;padding:1px 8px;margin-top:3px}
.cv-chip.ok{background:#e3ede8;color:var(--moss)}.cv-chip.novo{background:#fff1cc;color:#7a5a00}.cv-chip.mal{background:rgba(180,81,47,.12);color:var(--clay)}.cv-chip.esp{background:#eee;color:var(--mute)}
.cv-radio{display:flex;gap:14px;margin-top:6px}.cv-radio label{font-weight:500;font-size:14px;display:flex;gap:5px;align-items:center}
.cv-radio input{width:auto;margin:0}
.cv-calc{width:100%;border-collapse:collapse;font-size:13px}.cv-calc td{padding:3px 4px;border-bottom:1px solid #ece8dc}
.cv-calc td:last-child{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.cv-calc tr.t td{font-weight:700;background:#efece2}
.cv-calc tr.sub td{color:var(--mute);font-size:12px}
.cv-desc{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;background:#f7f7f4;border:1px solid var(--line);border-radius:6px;padding:8px;word-break:break-word}
.cv-pend ul{margin:4px 0 0 16px;padding:0;font-size:12.5px}.cv-pend .e{color:#7a2f16}.cv-pend .a{color:#5b4a0e}
.cv-botoes{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0}
.cv-barra-fim{position:sticky;bottom:0;z-index:15;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0 0;padding:12px 16px;background:#fff;border:1px solid var(--line);border-top:3px solid var(--moss);border-radius:10px 10px 0 0;box-shadow:0 -6px 18px rgba(0,0,0,.08)}
.cv-barra-fim .btn{font-size:16px;padding:12px 22px;border-radius:8px}
.cv-barra-fim .cv-nav{display:flex;gap:10px;align-items:center}
.cv-barra-fim .cv-acoes{display:flex;gap:10px;flex-wrap:wrap}
.cv-barra-fim .cv-status-txt{font-size:13px;color:var(--mute)}
.cv-hist{font-size:12px;padding-left:16px}.cv-hist li{margin:3px 0}.cv-hist .q{color:var(--mute)}
.cv-modal{position:fixed;inset:0;background:rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center;z-index:30}
.cv-modal>div{background:#fff;border-radius:10px;max-width:640px;width:92%;padding:18px 20px;box-shadow:0 10px 40px rgba(0,0,0,.25);max-height:90vh;overflow:auto}
.cv-pend-omie{border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:8px 0;font-size:13px}
details.cv-par summary{cursor:pointer;font-size:13px;font-weight:600;color:var(--moss)}
.cv-msg{padding:10px 14px;border-radius:6px;margin:10px 0;font-size:14px}.cv-msg.ok{background:#e6f4ea;color:#1e5b34}.cv-msg.erro{background:rgba(180,81,47,.12);color:#7a2f16}
.cv-tab{width:100%;border-collapse:collapse;font-size:13px}.cv-tab th{font-size:11.5px;text-transform:uppercase;color:var(--mute);text-align:left;padding:4px 5px;border-bottom:1px solid var(--line)}
.cv-tab td{padding:4px 5px;border-bottom:1px solid #ece8dc;vertical-align:top}.cv-tab td.n{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.cv-tab input,.cv-tab select{margin-top:0!important;padding:5px 7px!important;font-size:13px!important}
.cv-tab tfoot td{font-weight:700;background:#efece2}
.cv-bloco-h{display:flex;justify-content:space-between;align-items:baseline;gap:10px;flex-wrap:wrap}.cv-bloco-h small{color:var(--mute);font-weight:500}
.cv-desc-linha{display:block;font-size:11.5px;color:var(--clay)}
</style>
<?php cf_cabecalho($venda ? ($tituloVenda !== '' ? h($tituloVenda) . ' <small class="cv-num-venda">venda #' . $id . '</small>' : 'Venda #' . $id) . ($cancelada ? ' <span class="cf-status cf-st-descartada">cancelada</span>' : '') : 'Nova venda',
    'Preencha por partes: dá pra salvar rascunho a qualquer momento e continuar depois. Cada alteração fica registrada no histórico com o seu nome.',
    [['Capa Financeira', '/capa-financeira/'], ['Vendas', '/capa-financeira/vendas/'], [$venda ? '#' . $id : 'Nova', null]], 'vendas'); ?>

<?php if (!empty($_GET['ok'])): ?><div class="cv-msg ok" data-cf-modal="<?= str_contains((string)$_GET['ok'], 'Atenção:') ? 'parcial' : 'ok' ?>" data-cf-titulo="<?= h(cv_titulo_ok((string)$_GET['ok'])) ?>">✔ <?= h((string)$_GET['ok']) ?></div>
<script>try { const u = new URL(location.href); u.searchParams.delete('ok'); history.replaceState(null, '', u); } catch (e) {}</script><?php endif; ?>
<?php if ($cancelada): ?><div class="cv-msg erro">Esta venda está <b>cancelada</b> — só leitura. Use “Descancelar” no painel ao lado para voltar ao estado anterior.</div><?php endif; ?>
<div id="cv-msg"></div>

<div class="cv-steps">
  <?php
  $steps = [1 => 'Dados da venda', 2 => 'Distribuição das comissões', 3 => 'Fluxo do cliente e recebidos', 4 => 'Pagamentos aos corretores'];
  foreach ($steps as $n => $t) {
      $pronta = $n <= 4;
      $cls = 'cv-step' . ($n === $etapa ? ' on' : '') . (!$pronta ? ' off' : '');
      $badge = $pronta ? cv_etapa_badge($v["e{$n}_status"]) : 'em construção';
      if ($pronta && $venda && $n !== $etapa) echo '<a class="' . $cls . '" href="/capa-financeira/vendas/editar.php?id=' . $id . '&etapa=' . $n . '"><b>' . $n . ' · ' . $t . '</b>' . $badge . '</a>';
      else echo '<div class="' . $cls . '"' . ($pronta && !$venda && $n > 1 ? ' title="salve a etapa 1 primeiro"' : '') . '><b>' . $n . ' · ' . $t . '</b>' . $badge . '</div>';
  }
  ?>
</div>

<script>
window.CV = {
  csrf: <?= json_encode(csrf_token()) ?>, id: <?= (int)$id ?>, rev: <?= (int)$v['rev'] ?>, etapa: <?= $etapa ?>,
  somenteLeitura: <?= $cancelada ? 'true' : 'false' ?>, sujo: false,
  // ações demoradas (conferência no Omie, geração/importação da capa): modal com a porcentagem
  PASSOS: {
    concluir: ['Etapa 1 — dados da venda', ['Validando os dados', 'Conferindo os compradores no Omie', 'Gravando']],
    salvar: ['Gravando a etapa 1', ['Validando os dados', 'Conferindo no Omie', 'Gravando']],
    salvar2: ['Gravando a distribuição', ['Validando', 'Conferindo quem recebe no Omie', 'Gravando']],
    concluir2: ['Etapa 2 — distribuição', ['Validando', 'Conferindo quem recebe no Omie', 'Gravando']],
    salvar3: ['Gravando o fluxo do cliente', ['Validando o fluxo', 'Conferindo quem paga no Omie', 'Gerando a planilha da capa', 'Importando na Capa Financeira']],
    concluir3: ['Etapa 3 — contas a receber', ['Validando o fluxo', 'Conferindo quem paga no Omie', 'Gerando a planilha da capa', 'Importando na Capa Financeira']],
    salvar4: ['Gravando os pagamentos', ['Validando', 'Gravando']],
    liberar4: ['Liberando os pagamentos', ['Conferindo os pagamentos', 'Gerando a planilha da capa', 'Importando na Capa Financeira (contas a pagar)']],
    desfazer4: ['Desfazendo a liberação', ['Conferindo', 'Gerando a planilha da capa', 'Importando na Capa Financeira']],
    cancelar: ['Cancelando a venda', ['Conferindo os títulos no Omie', 'Excluindo os títulos sem baixa', 'Cancelando a venda']],
    descancelar: ['Descancelando a venda', ['Gravando']],
  },
  async api(dados) {
    const ps = this.PASSOS[dados.acao];
    const prog = ps && window.CFModal ? CFModal.progresso({titulo: ps[0], etapas: ps[1], atraso: 250}).faixa(0, 92) : null;
    let j;
    try {
      const r = await fetch('/capa-financeira/vendas/api.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':this.csrf}, body: JSON.stringify({...dados, csrf: this.csrf, id: this.id, rev: this.rev})});
      try { j = await r.json(); } catch (e) { j = {ok:false, erros:['Resposta inválida do servidor (HTTP ' + r.status + ')']}; }
    } catch (e) { j = {ok:false, erros:['Sem conexão com o portal — confira a internet e tente de novo.']}; }
    if (prog) { if (j.ok) await prog.fim(); else prog.fechar(); }
    return j;
  },
  esc: s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'),
  brl: v => (v < 0 ? '−' : '') + 'R$ ' + Math.abs(+v || 0).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.'),
  pc: v => String(+((+v || 0) * 100).toFixed(3)).replace('.', ',') + '%',
  lista: a => '<ul>' + a.map(x => '<li>' + CV.esc(x) + '</li>').join('') + '</ul>',
  alerta(t) { return window.CFModal ? CFModal.aviso({tipo: 'parcial', titulo: 'Atenção', texto: t}) : alert(t); },
  msg(cls, html) {
    const m = document.getElementById('cv-msg'); m.innerHTML = '<div class="cv-msg ' + cls + '">' + html + '</div>'; m.scrollIntoView({block:'center'});
    if (window.CFModal) CFModal.aviso({tipo: cls === 'ok' ? 'ok' : 'erro', html});
  },
  vai(texto) { this.sujo = false; location.href = '/capa-financeira/vendas/editar.php?id=' + this.id + '&etapa=' + this.etapa + '&ok=' + encodeURIComponent(texto || 'Salvo.'); },
  numBlur(i, pct) {
    let s = i.value.replace(/[R$\s%]/g, ''); if (s === '') return;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.'); else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    const n = Number(s); if (!isFinite(n)) { i.classList.add('cv-erro'); return; } i.classList.remove('cv-erro');
    i.value = pct ? String(+n.toFixed(3)).replace('.', ',') : n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  },
};
window.addEventListener('beforeunload', e => { if (CV.sujo) { e.preventDefault(); e.returnValue = ''; } });
document.addEventListener('click', async ev => {
  if (ev.target.id === 'cv-cancelar') {
    const p = await CV.api({acao:'cancelar_previa'});
    if (!p.ok) { CV.msg('erro', CV.lista(p.erros || [])); return; }
    const enviados = p.titulos.filter(t => t.acao !== 'nada');
    if (!enviados.length) {
      const motivo = prompt('Cancelar esta venda? Ela sai da lista (o histórico fica guardado e dá para descancelar depois).'
        + (p.capas.length ? '\n\nAs contas geradas na Capa Financeira (ainda não enviadas ao Omie) serão descartadas — ao descancelar, conclua a etapa 3 de novo.' : '') + '\n\nInforme o motivo:');
      if (!motivo) return;
      const j = await CV.api({acao:'cancelar', motivo});
      if (j.ok) { CV.sujo = false; await CFModal.aviso({tipo: 'ok', titulo: 'Venda cancelada', texto: 'Ela saiu da lista; o histórico fica guardado e dá para descancelar depois.'}); location.href = '/capa-financeira/vendas/'; } else CV.msg('erro', CV.lista(j.erros || []));
      return;
    }
    const bloq = enviados.filter(t => t.acao === 'bloqueado');
    const m = document.createElement('div'); m.className = 'cv-modal';
    m.innerHTML = '<div><h2 style="margin-top:0">Cancelar a venda — títulos no Omie</h2>'
      + '<p>Esta venda já tem ' + enviados.length + ' título(s) enviado(s) ao Omie. Ao cancelar:</p>'
      + '<table class="cv-tab"><thead><tr><th>Título</th><th>Quem</th><th>Vencimento</th><th class="n">Valor</th><th>No Omie</th><th>O que acontece</th></tr></thead><tbody>'
      + enviados.map(t => '<tr><td>' + CV.esc(t.codigo) + ' (' + (t.tipo === 'R' ? 'receber' : 'pagar') + ')</td><td>' + CV.esc(t.quem) + '</td><td>' + CV.esc((t.vencimento || '').split('-').reverse().join('/')) + '</td><td class="n">' + CV.brl(t.valor) + '</td><td>' + CV.esc(t.status_omie) + '</td><td>'
          + (t.acao === 'bloqueado' ? '<b style="color:var(--clay)">NÃO pode ser excluído — já tem baixa (pago/recebido). Fica no Omie; trate manualmente.</b>' : 'será <b>excluído</b> do Omie') + '</td></tr>').join('')
      + '</tbody></table>'
      + (bloq.length ? '<p class="cv-msg erro">' + bloq.length + ' título(s) já tiveram baixa e continuarão no Omie. A capa desta venda não será descartada.</p>' : '<p class="cf-dica">As capas desta venda serão descartadas; ao descancelar, conclua a etapa 3 de novo para gerar as contas.</p>')
      + '<label class="cv-f" style="display:block;margin-top:10px">Motivo do cancelamento<textarea data-motivo rows="2"></textarea></label>'
      + '<label style="display:flex;gap:6px;margin-top:8px;font-size:13px"><input type="checkbox" data-entendi> Entendi — excluir no Omie os títulos sem baixa e cancelar a venda</label>'
      + '<div class="cv-botoes" style="margin-top:12px"><button type="button" class="btn cf-btn-sec cf-btn-perigo" data-ok>Cancelar venda</button><button type="button" class="btn cf-btn-sec" data-x>Voltar</button></div></div>';
    document.body.appendChild(m);
    m.querySelector('[data-x]').onclick = () => m.remove();
    m.querySelector('[data-ok]').onclick = async () => {
      const motivo = m.querySelector('[data-motivo]').value.trim();
      if (!motivo) { CV.alerta('Informe o motivo.'); return; }
      if (!m.querySelector('[data-entendi]').checked) { CV.alerta('Marque que entendeu o que vai acontecer no Omie.'); return; }
      m.querySelectorAll('button').forEach(b => b.disabled = true);
      const j = await CV.api({acao:'cancelar', motivo, confirmado: true});
      m.remove();
      if (j.ok) { CV.sujo = false; await CFModal.aviso({tipo: 'ok', titulo: 'Venda cancelada', html: j.resultado && j.resultado.length ? '<b>Omie:</b>' + CV.lista(j.resultado) : ''}); location.href = '/capa-financeira/vendas/'; }
      else CV.msg('erro', CV.lista(j.erros || []));
    };
  }
  if (ev.target.id === 'cv-descancelar') {
    if (!confirm('Descancelar esta venda? Ela volta ao estado em que estava antes do cancelamento.')) return;
    const j = await CV.api({acao:'descancelar'});
    if (j.ok) { CV.sujo = false; location.href = '/capa-financeira/vendas/editar.php?id=' + CV.id + '&ok=' + encodeURIComponent('Venda descancelada.'); } else CV.msg('erro', CV.lista(j.erros || []));
  }
});
</script>

<?php require __DIR__ . ['', '/etapa1.inc.php', '/etapa2.inc.php', '/etapa3.inc.php', '/etapa4.inc.php'][$etapa]; ?>
<?php portal_footer();
