<?php
/* =====================================================================
   capa-financeira/index.php — lista das capas enviadas + envio de nova capa.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$empresas = cf_config('empresas', []);
$filtro = (string)($_GET['status'] ?? '');

$sql = "SELECT c.*, u.nome AS enviado_nome,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status <> 'removido') AS n_linhas,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status = 'revisao'
              AND ((l.revisado = 0 AND l.flags <> '[]' AND l.flags NOT IN ('[\"PESSOA_NAO_IDENTIFICADA\"]', '[\"REPASSE (favorecido = terceiro; categoria de repasse)\"]')) OR (l.tipo = 'P' AND l.pessoa_id IS NULL))) AS n_pendencias
        FROM cf_capas c LEFT JOIN users u ON u.id = c.enviado_por";
$args = [];
if (in_array($filtro, ['revisao', 'confirmada', 'substituida', 'descartada'], true)) { $sql .= ' WHERE c.status = ?'; $args[] = $filtro; }
$sql .= ' ORDER BY c.id DESC LIMIT 200';
$st = $pdo->prepare($sql); $st->execute($args);
$capas = $st->fetchAll();
$nPessoas = (int)$pdo->query('SELECT COUNT(*) FROM cf_pessoas WHERE ativo = 1')->fetchColumn();
$nExportar = (int)$pdo->query("SELECT COUNT(*) FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id WHERE l.status = 'confirmado' AND c.status = 'confirmada'")->fetchColumn();

portal_header('Capa Financeira', $u);
?>
<style>main.wrap{max-width:1500px}</style>
<?php cf_cabecalho('Capa Financeira → Omie', 'Envie a capa da venda, revise os lançamentos, confirme. Depois gere as planilhas do Omie e os recibos.', [['Capa Financeira', null]], 'capas'); ?>
<?php if (($_GET['ok'] ?? '') === 'confirmada'): ?><div class="cf-sucesso" data-cf-modal="ok" data-cf-titulo="Capa confirmada">✔ Capa #<?= (int)($_GET['id'] ?? 0) ?> confirmada. Os lançamentos já aparecem em <a href="/capa-financeira/exportar.php">Exportar para o Omie</a><?= $nExportar ? ' (' . $nExportar . ' pendentes)' : '' ?> e em <a href="/capa-financeira/recibos.php">Recibos</a>.</div><?php endif; ?>

<?php if ($nPessoas === 0): ?>
<div class="aviso">O dicionário de pessoas está vazio. Cadastre a equipe em <a href="/capa-financeira/pessoas.php">Pessoas</a> (dá pra importar a planilha DADOS CORRETORES EQUIPE) — sem isso todo favorecido vai aparecer como "não identificado".</div>
<?php endif; ?>

<section class="card cf-upload">
  <h2>Enviar nova capa</h2>
  <form method="post" action="/capa-financeira/upload.php" enctype="multipart/form-data" class="cf-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Empresa da importação
      <select name="empresa" required>
        <option value="">— escolha —</option>
        <?php foreach ($empresas as $e): ?>
          <option value="<?= h($e['id']) ?>"><?= h($e['nome']) ?> · <?= h($e['cnpj']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Arquivo da capa (.xlsx)
      <input type="file" name="capas[]" accept=".xlsx" multiple required>
    </label>
    <button class="btn" type="submit">Enviar e revisar</button>
  </form>
  <p class="cf-dica">Reenviar a capa de uma venda já enviada (mesmo COD) cria uma nova versão e mostra o que mudou linha a linha.</p>
  <p class="cf-dica">Dá para selecionar <b>várias capas de uma vez</b> (até 20, da mesma empresa): todas entram em revisão e você resolve os alertas repetidos em <a href="/capa-financeira/lote.php">Resolver em lote</a>. Capa com COD de uma venda do formulário, ou com COD que já é de outro cliente, fica de fora e precisa ser enviada sozinha.</p>
</section>
<section class="card cf-upload" id="drv">
 <details id="drv-det">
  <summary style="cursor:pointer"><h2 style="display:inline">Escolher do Google Drive</h2> <small id="drv-pasta" class="cf-raw" style="font-weight:400"></small> <small id="drv-resumo" class="cf-raw"></small></summary>
  <div style="margin-top:10px">
  <div id="drv-off" class="cf-dica" style="display:none">O acesso ao Drive ainda não foi configurado. Em <a href="/capa-financeira/configuracoes.php">Configurações › Google Drive</a> um administrador envia a chave da conta de serviço e testa o acesso.</div>
  <div id="drv-on" style="display:none">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
      <select id="drv-emp" class="cf-in"><option value="">— empresa da importação —</option><?php foreach ($empresas as $e): ?><option value="<?= h($e['id']) ?>"><?= h($e['nome']) ?></option><?php endforeach; ?></select>
      <input type="text" id="drv-busca" class="cf-in" placeholder="filtrar: nome, construtora, COD, bairro, unidade…" title="A busca ignora acentos e aceita grafias parecidas: Felipe acha Filipe, Ferreira acha Fereira, Matheus acha Mateus, Souza acha Sousa." style="width:330px">
      <label style="font-size:13px"><input type="checkbox" id="drv-ocultar"> ocultar as já importadas, "ok" e modelos</label>
      <button type="button" class="btn" id="drv-importar" disabled>Importar marcadas (<span id="drv-n">0</span>)</button>
      <button type="button" class="cf-x" id="drv-atualizar" title="lê a pasta do Drive de novo agora">↻ atualizar</button>
      <span class="cf-raw" id="drv-quando"></span>
    </div>
    <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:8px;font-size:13px">
      <b>Com contas:</b>
      <span title="filtra as capas em que esta pessoa/empresa aparece numa linha: favorecido nas contas a pagar, cliente ou construtora nas contas a receber. Liga pelo cadastro de Pessoas (nome, apelidos e razão social) ou por parte do nome.">quem: <input type="text" id="drv-fquem" class="cf-in" list="drv-pessoas" placeholder="nome, apelido ou razão social" style="padding:3px 6px;width:230px"></span><datalist id="drv-pessoas"></datalist>
      <select id="drv-ftipo" class="cf-in" style="padding:3px 6px"><option value="">a pagar ou a receber</option><option value="p">só a pagar</option><option value="r">só a receber</option></select>
      <label title="pelo menos um vencimento neste ano"><input type="checkbox" id="drv-fano"> em <?= date('Y') ?></label>
      <label title="pelo menos um vencimento de hoje em diante. A planilha não diz o que já foi pago: aqui 'em aberto' é o que ainda vai vencer."><input type="checkbox" id="drv-faberto"> ainda a vencer (de hoje em diante)</label>
      <span>de <input type="date" id="drv-fde" class="cf-in" style="padding:3px 6px"> até <input type="date" id="drv-fate" class="cf-in" style="padding:3px 6px"> <small class="cf-raw">(só "de" = a partir da data)</small></span>
      <button type="button" class="cf-x" id="drv-flimpar" style="display:none">limpar filtros</button>
      <span class="cf-raw" id="drv-fcont"></span>
    </div>
    <div id="drv-aviso" class="cf-flag cf-leve" style="display:none;margin-bottom:8px"></div>
    <div id="drv-lista" style="max-height:460px;overflow:auto;border:1px solid var(--line);border-radius:8px;padding:6px 10px"></div>
    <p class="cf-dica">Quando há mais de um arquivo da mesma venda (mesmos compradores no nome, em qualquer pasta), só um fica disponível: o <b>aditivo</b> mais recente, se houver; senão o que já está no portal ou o modificado por último. Os outros aparecem travados com o motivo, apontando para o que vale (dá para liberar). A lista é lida do Drive na hora (e de novo a cada 45 s com esta tela aberta): renomear, mover ou subir arquivo na pasta aparece aqui sem fazer mais nada. As marcadas entram em revisão — nada é confirmado nem enviado ao Omie. Marcando uma só, ela pode virar nova versão de uma capa do mesmo cliente; em lote, COD que já existe fica de fora com o motivo. Quando a capa vai toda para o Omie, o arquivo ganha "ok - " no nome sozinho.</p>
  </div>
  <div id="drv-carregando" class="cf-raw">lendo a pasta do Drive…</div>
  </div>
 </details>
</section>
<style>.drv-ano{margin:10px 0;border-top:1px solid var(--line);padding-top:6px}.drv-ano:first-child{border-top:0;margin-top:0}#drv-det>summary h2{margin:0}.drv-ano>summary{cursor:pointer;font-weight:700;font-size:15px;padding:4px 0}.drv-ano .drv-g{margin-left:14px}.drv-g{margin:6px 0}.drv-g>summary{cursor:pointer;font-weight:600;padding:4px 0}.drv-a{display:flex;gap:8px;align-items:center;padding:3px 0 3px 18px;font-size:14px}.drv-a:hover{background:rgba(0,0,0,.03)}.drv-a .cf-flag{font-size:11px;padding:1px 7px;margin:0}.drv-a small{color:#778}</style>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>, $ = id => document.getElementById(id), esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const SIT = {pendente: ['cf-leve', 'pendente'], revisao: ['cf-ok', 'em revisão'], confirmada: ['cf-ok', 'confirmada'], enviada: ['cf-ok', 'no Omie'], alterada: ['cf-grave', 'mudou no Drive'], provavel: ['cf-ok', 'já enviada à mão?'], ok: ['cf-ok', 'ok no nome'], modelo: ['', 'modelo']};
  let arquivos = [], marcados = new Set(), fechados = new Set(), ocupado = false, primeira = true;
  const liberados = new Set(), anoEstado = new Map();   // anoEstado: abrir/fechar que a pessoa fez em cada bloco de ano ('' = ano atual)
  let desenhouEm = 0;   // os blocos recém-desenhados disparam 'toggle' sozinhos: só vale o abrir/fechar feito pela pessoa
  const travado = a => (a.trava || a.nao_subir) && !liberados.has(a.id);
  const TRV = {aditivo: ['substituída pelo aditivo', 'o aditivo substitui esta capa: suba só o aditivo'], copia: ['cópia idêntica', 'é uma cópia exata de outro arquivo desta venda'], mais_recente: ['há versão mais recente', 'existe outro arquivo desta venda modificado depois deste'], copia_importada: ['cópia de arquivo já importado', 'cópia exata de um arquivo desta venda que já está no portal'], ja_importada: ['venda já no portal', 'esta venda já está no portal por outro arquivo; liberar importa este como nova versão']};
  const semAc = t => String(t ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  // texto pesquisável: nome do arquivo + cabeçalho da capa (COD, clientes, construtora, bairro, unidade, data da venda)
  const alvo = a => a._alvo ??= semAc([a.nome, a.subpasta, a.ficha ? ['cod ' + a.ficha.cod, a.ficha.cliente, a.ficha.construtora, a.ficha.bairro, a.ficha.unidade, (a.ficha.venda || '').split('-').reverse().join('/')].join(' ') : ''].join(' '));
  // grafia parecida (a mesma regra do Busca Bancos, BbBusca::chave/mesmoNome): th→t, ph→f, y→i, z→s, k→c, w→v, h mudo,
  // letra dobrada vale uma (Ferreira/Fereira, Matheus/Mateus, Souza/Sousa) e vogal trocada no meio do nome (Felipe/Filipe, Manoel/Manuel).
  // Palavra com número (COD, unidade, data) só vale exata.
  const chaveG = w => /\d/.test(w) ? w : w.replace(/ph|th|ck|ct|y|z|k|w/g, m => ({ph: 'f', th: 't', ck: 'c', ct: 't', y: 'i', z: 's', k: 'c', w: 'v'})[m]).replace(/(^|[^cln])h/g, '$1').replace(/(.)\1+/g, '$1');
  const cacheG = new Map();
  const palavrasG = t => { let r = cacheG.get(t); if (!r) { if (cacheG.size > 6000) cacheG.clear(); r = [...new Set(t.split(/[^a-z0-9]+/).filter(Boolean).map(chaveG))]; cacheG.set(t, r); } return r; };
  const vogalTrocada = (c, w) => {   // c = o digitado; w = palavra do texto (pode ser mais comprida: nome ainda sendo digitado)
    const n = c.length; if (n < 5 || w.length < n || c[0] !== w[0]) return false;
    let dif = -1;
    for (let i = 0; i < n; i++) if (c[i] !== w[i]) { if (dif >= 0) return false; dif = i; }
    return dif > 0 && dif < n - 1 && 'aeiou'.includes(c[dif]) && 'aeiou'.includes(w[dif]);
  };
  const letraAMenos = (c, w) => {   // nome comprido com uma letra sobrando ou faltando (Staranscheck/Staransheck)
    const [m, M] = c.length < w.length ? [c, w] : [w, c];
    if (m.length < 6 || M.length !== m.length + 1 || m[0] !== M[0]) return false;
    let i = 0; while (i < m.length && m[i] === M[i]) i++;
    return m.slice(i) === M.slice(i + 1);
  };
  // a palavra digitada (já sem acento/minúscula) aparece no texto? 1º exata; depois pela grafia parecida
  const temPalavra = (t, p) => {
    if (t.includes(p)) return true;
    if (/\d/.test(p) || p.length < 3) return false;
    const c = chaveG(p), ws = palavrasG(t);
    return ws.some(w => w.includes(c)) || ws.some(w => vogalTrocada(c, w) || letraAMenos(c, w)) || t.split(/[^a-z]+/).some(w => letraAMenos(p, w));
  };
  const bate = (a, busca) => !busca || busca.split(/\s+/).every(p => temPalavra(alvo(a), p));
  // filtros por vencimento das linhas da capa (lidos do conteúdo do arquivo): ano atual, a vencer, e período de/até
  let hoje = <?= json_encode(date('Y-m-d')) ?>;
  const ANO = <?= json_encode(date('Y')) ?>;
  const filtroData = () => ({tipo: $('drv-ftipo').value, ano: $('drv-fano').checked, aberto: $('drv-faberto').checked, de: $('drv-fde').value, ate: $('drv-fate').value, quem: semAc($('drv-fquem').value.trim())});
  const temFiltroData = f => f.ano || f.aberto || f.de || f.ate || f.quem;
  let pessoasTxt = {};   // id da pessoa → texto pesquisável (nome + razão social + apelidos)
  // com "quem" preenchido o filtro é POR LINHA: a mesma linha tem que ser da pessoa, do tipo e do período. Devolve as linhas que bateram.
  function linhasQuem(a, f) {
    const fi = a.ficha; if (!fi || !fi.ln) return null;
    const pal = f.quem.split(/\s+/);
    const qOk = (fi.q || []).map((n, i) => { const t = semAc(n) + ' ' + (pessoasTxt[(fi.qp || [])[i]] || ''); return pal.every(p => temPalavra(t, p)); });
    return fi.ln.filter(l => qOk[l[3]] && (!f.tipo || l[0] === f.tipo) && (!f.ano || l[1].startsWith(ANO + '-')) && (!f.aberto || l[1] >= hoje) && (!f.de || l[1] >= f.de) && (!f.ate || (l[1] && l[1] <= f.ate)));
  }
  function datasOk(a, f) {
    if (!temFiltroData(f)) { a._bate = null; return true; }
    if (!a.ficha) return false;   // conteúdo ainda não lido (ou não é capa): não dá para saber
    if (f.quem) { const ls = linhasQuem(a, f); a._bate = ls; return !!(ls && ls.length); }
    a._bate = null;
    const ds = (f.tipo === 'p' ? [] : (a.ficha.dr || [])).concat(f.tipo === 'r' ? [] : (a.ficha.dp || []));
    if (f.ano && !ds.some(d => d.startsWith(ANO + '-'))) return false;
    if (f.aberto && !ds.some(d => d >= hoje)) return false;
    if ((f.de || f.ate) && !ds.some(d => (!f.de || d >= f.de) && (!f.ate || d <= f.ate))) return false;
    return true;
  }
  const brl = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const mesAno = d => d ? d.slice(5, 7) + '/' + d.slice(0, 4) : '';
  const escolhivel = a => (a.sit === 'pendente' || a.sit === 'alterada') && !travado(a);
  const pendente = a => a.sit === 'pendente' || a.sit === 'alterada';
  function desenhar() {
    const busca = semAc($('drv-busca').value.trim()), todos = !$('drv-ocultar').checked;
    const fd = filtroData(), base = arquivos.filter(a => (todos || pendente(a)) && bate(a, busca));
    const vis = base.filter(a => datasOk(a, fd));
    $('drv-flimpar').style.display = temFiltroData(fd) ? '' : 'none';
    const somaQ = fd.quem ? vis.reduce((t, a) => { (a._bate || []).forEach(l => { t.n++; t.v += l[2]; }); return t; }, {n: 0, v: 0}) : null;
    $('drv-fcont').textContent = temFiltroData(fd) ? vis.length + ' de ' + base.length + ' arquivo(s)' + (somaQ ? ' · ' + somaQ.n + ' conta(s) · ' + brl(somaQ.v) : '') + (fd.quem && !comLinhas ? ' · carregando as linhas das capas…' : '') + (base.some(a => !a.ficha && a.sit !== 'modelo') ? ' · arquivos ainda não lidos ficam de fora' : '') : '';
    // um bloco por subpasta; as pastas de anos anteriores ficam cada uma dentro de um bloco do ano, fechado até você abrir
    // (abre sozinho quando a busca ou um filtro de data está ativo, para os resultados aparecerem)
    const htmlGrupo = (g, its, rotulo) => {
      const esc_g = esc(g), nEsc = its.filter(escolhivel).length;
      return '<details class="drv-g" data-g="' + esc_g + '"' + (fechados.has(g) ? '' : ' open') + '><summary>' + esc(rotulo) + ' <small class="cf-raw">' + its.length + ' arquivo(s)</small>' + (nEsc ? ' <button type="button" class="cf-x drv-mes" data-g="' + esc_g + '">marcar os ' + nEsc + ' pendentes</button>' : '') + '</summary>' +
        its.map(a => { const s = SIT[a.sit] || ['', a.sit]; const d = a.modificado ? new Date(a.modificado).toLocaleDateString('pt-BR') : '';
          return '<label class="drv-a" title="' + esc(a.txt || '') + '"><input type="checkbox" class="drv-c" value="' + esc(a.id) + '"' + (marcados.has(a.id) ? ' checked' : '') + (escolhivel(a) ? '' : ' disabled') + '> <span>' + esc(a.nome) + '</span> <span class="cf-flag ' + s[0] + '">' + s[1] + '</span>' +
            (a.capa ? ' <a href="/capa-financeira/revisar.php?id=' + a.capa + '">capa #' + a.capa + '</a>' : '') +
            (a.aditivo && a.substitui ? ' <span class="cf-flag cf-ok" title="este aditivo é o arquivo que vale para a venda">aditivo — substitui ' + a.substitui.n + ' arquivo(s)' + (a.substitui.capa ? ' e a capa #' + a.substitui.capa : '') + '</span>' : (a.aditivo ? ' <span class="cf-flag cf-ok">aditivo</span>' : '')) +
            (a.trava ? ' <span class="cf-flag cf-grave" title="' + esc(TRV[a.trava.motivo][1]) + '">' + TRV[a.trava.motivo][0] + '</span> <small>vale: ' + esc(a.trava.nome) + (a.trava.subpasta ? ' (' + esc(a.trava.subpasta) + ')' : '') + (a.trava.capa ? ' · <a href="/capa-financeira/revisar.php?id=' + a.trava.capa + '">capa #' + a.trava.capa + '</a>' : '') + '</small>' : '') +
            (a.nao_subir ? ' <span class="cf-flag cf-grave">o nome diz "não subir"</span>' : '') + (a.distrato && !a.nao_subir ? ' <span class="cf-flag cf-leve">distrato</span>' : '') +
            (a.outros && a.outros.length ? ' <span class="cf-flag cf-leve" title="' + esc(a.outros.join(' · ')) + '">há outro arquivo desta venda</span>' : '') +
            (pendente(a) && (a.trava || a.nao_subir) && !liberados.has(a.id) ? ' <button type="button" class="cf-x drv-lib" data-id="' + esc(a.id) + '" title="libera a caixa de seleção deste arquivo mesmo assim">liberar</button>' : '') +
            (a._bate && a._bate.length ? ' <span class="cf-flag cf-ok" title="' + esc(a._bate.map(l => (l[0] === 'p' ? 'pagar ' : 'receber ') + l[1].split('-').reverse().join('/') + ' ' + brl(l[2])).join(' · ')) + '">' + a._bate.length + ' conta(s) · ' + brl(a._bate.reduce((t, l) => t + l[2], 0)) + '</span>' : '') +
            ' <small>' + d + (a.ficha ? ' · ' + esc([a.ficha.cod ? 'COD ' + a.ficha.cod : 'sem COD', a.ficha.construtora, a.ficha.bairro, (() => { const ds = (a.ficha.dr || []).concat(a.ficha.dp || []).sort(); return ds.length ? 'contas de ' + mesAno(ds[0]) + ' a ' + mesAno(ds[ds.length - 1]) : ''; })()].filter(Boolean).join(' · ')) : '') + '</small></label>'; }).join('') + '</details>';
    };
    const porRaiz = {}; vis.forEach(a => ((porRaiz[a.raiz || ''] ??= {})[a.subpasta || '(raiz da pasta)'] ??= []).push(a));
    const filtrando = !!busca || temFiltroData(fd);
    const rolagem = $('drv-lista').scrollTop;
    // um bloco por ano: o atual (pasta principal) primeiro e aberto; os anteriores em ordem decrescente e fechados até você abrir
    const anos = Object.keys(porRaiz).sort((x, y) => x === '' ? -1 : (y === '' ? 1 : y.localeCompare(x, 'pt', {numeric: true})));
    const partes = anos.map(rz => {
      const gs = porRaiz[rz], inner = Object.keys(gs).sort().map(g => htmlGrupo(g, gs[g], rz && g.startsWith(rz) ? (g.slice(rz.length).replace(/^\s*\/\s*/, '') || '(raiz da pasta)') : g)).join('');
      const todosAno = Object.values(gs).flat(), nPend = todosAno.filter(escolhivel).length;
      const aberto = anoEstado.has(rz) ? anoEstado.get(rz) : (rz === '' || filtrando);   // sem escolha da pessoa: ano atual aberto; os outros só abrem quando há busca/filtro
      return '<details class="drv-ano" data-ano="' + esc(rz) + '"' + (aberto ? ' open' : '') + '><summary>📁 ' + esc(rz || ANO) + ' <small class="cf-raw">' + todosAno.length + ' arquivo(s)' + (nPend ? ' · ' + nPend + ' pendente(s) disponíveis' : '') + '</small></summary>' + inner + '</details>';
    });
    $('drv-lista').innerHTML = partes.length ? partes.join('') : '<p class="cf-raw">' + (arquivos.length ? 'Nenhum arquivo com esse filtro.' + ($('drv-ocultar').checked ? ' Desmarque "ocultar as já importadas" para ver todos.' : '') : 'A pasta não tem arquivos .xlsx.') + '</p>';
    const nDisp = arquivos.filter(escolhivel).length;
    $('drv-resumo').textContent = '· ' + nDisp + ' capa(s) pendente(s) disponíveis' + (marcados.size ? ' · ' + marcados.size + ' marcada(s)' : '');
    desenhouEm = Date.now();
    $('drv-lista').scrollTop = rolagem;
    contar();
  }
  function contar() { $('drv-n').textContent = marcados.size; $('drv-importar').disabled = !marcados.size || ocupado; }
  let comLinhas = false; const querLinhas = () => !!$('drv-fquem').value.trim();
  async function ler(manual, cont) {
    if (ocupado) return;
    let j = null;
    try { const r = await fetch('/capa-financeira/drive.php?acao=listar' + (cont ? '&cont=1' : '') + (querLinhas() ? '&linhas=1' : ''), {headers: {'Accept': 'application/json'}}); j = await r.json(); } catch (e) { j = null; }
    $('drv-carregando').style.display = 'none';
    if (!j || !j.ok) {
      const m = j && j.erro ? j.erro : 'Não consegui ler a pasta do Drive agora (sem resposta do portal). Confira sua internet e clique em atualizar.';
      if (primeira || manual) { $('drv-on').style.display = ''; $('drv-aviso').style.display = ''; $('drv-aviso').textContent = m; }
      primeira = false; return;
    }
    primeira = false;
    if (!j.configurado) { $('drv-off').style.display = ''; return; }
    $('drv-on').style.display = ''; $('drv-pasta').textContent = '· ' + j.pasta; $('drv-quando').textContent = 'lido às ' + j.lido_em;
    const avs = [j.aviso].concat(j.avisos || []).filter(Boolean);
    if (j.antigas && (j.antigas.aguardando || j.antigas.fora)) $('drv-quando').textContent += ' · pastas com corte por ano: ' + (j.antigas.aguardando ? j.antigas.aguardando + ' ainda sendo lida(s)' : '') + (j.antigas.fora ? ' ' + j.antigas.fora + ' sem contas no período ficaram de fora' : '');
    $('drv-aviso').style.display = avs.length ? '' : 'none'; $('drv-aviso').textContent = avs.join(' | ');
    if (j.indexar_restam > 0) { $('drv-quando').textContent += ' · lendo o conteúdo das capas para o filtro (faltam ' + j.indexar_restam + ')…'; setTimeout(() => ler(false, true), 1200); }
    if (j.hoje) hoje = j.hoje;
    comLinhas = !!j.com_linhas;
    if (j.pessoas && j.pessoas.length) { pessoasTxt = {}; j.pessoas.forEach(p => pessoasTxt[p.id] = semAc(p.nome + ' ' + p.razao + ' ' + p.apelidos));
      if (!$('drv-pessoas').children.length) $('drv-pessoas').innerHTML = j.pessoas.map(p => '<option value="' + esc(p.nome) + '">' + esc(p.razao || '') + '</option>').join(''); }
    arquivos = j.arquivos; const validos = new Set(arquivos.filter(escolhivel).map(a => a.id));
    marcados = new Set([...marcados].filter(i => validos.has(i)));
    desenhar();
  }
  $('drv-lista').addEventListener('change', e => { if (!e.target.classList.contains('drv-c')) return; e.target.checked ? marcados.add(e.target.value) : marcados.delete(e.target.value); contar(); });
  $('drv-lista').addEventListener('click', async e => { const lb = e.target.closest('.drv-lib');
    if (lb) { e.preventDefault(); const a = arquivos.find(x => x.id === lb.dataset.id); if (!a) return;
      if (!await CFModal.pergunta({tipo: 'parcial', titulo: 'Liberar este arquivo?', html: '<p style="margin:0">' + (a.trava ? esc(TRV[a.trava.motivo][1].charAt(0).toUpperCase() + TRV[a.trava.motivo][1].slice(1)) + ' (<b>' + esc(a.trava.nome) + '</b>). O normal é importar só o arquivo que vale.' : 'O nome do arquivo diz para não subir.') + ' Liberar mesmo assim?</p>', ok: 'Liberar'})) return;
      liberados.add(a.id); desenhar(); return; }
    const b = e.target.closest('.drv-mes'); if (!b) return; e.preventDefault();
    const busca = semAc($('drv-busca').value.trim());
    arquivos.filter(a => (a.subpasta || '(raiz da pasta)') === b.dataset.g && escolhivel(a) && bate(a, busca) && datasOk(a, filtroData())).forEach(a => marcados.add(a.id)); desenhar(); });
  $('drv-lista').addEventListener('toggle', e => { const d = e.target; if (Date.now() - desenhouEm <= 250) return;
    if (d.classList && d.classList.contains('drv-g')) d.open ? fechados.delete(d.dataset.g) : fechados.add(d.dataset.g);
    else if (d.classList && d.classList.contains('drv-ano')) anoEstado.set(d.dataset.ano, d.open); }, true);
  $('drv-busca').addEventListener('input', desenhar); $('drv-ocultar').addEventListener('change', desenhar);
  ['drv-ftipo', 'drv-fano', 'drv-faberto', 'drv-fde', 'drv-fate'].forEach(i => $(i).addEventListener('change', desenhar));
  // "quem": as linhas das capas só vêm do servidor quando o filtro é usado; na primeira vez busca e redesenha
  let tQuem = null;
  $('drv-fquem').addEventListener('input', () => { clearTimeout(tQuem); tQuem = setTimeout(() => { if (querLinhas() && !comLinhas) ler(false, true); else desenhar(); }, 300); });
  $('drv-flimpar').addEventListener('click', () => { $('drv-fano').checked = $('drv-faberto').checked = false; $('drv-fde').value = $('drv-fate').value = $('drv-fquem').value = ''; desenhar(); });
  $('drv-atualizar').addEventListener('click', () => { $('drv-quando').textContent = 'lendo…'; ler(true); });
  $('drv-importar').addEventListener('click', async () => {
    const emp = $('drv-emp').value, ids = [...marcados];
    if (!emp) { await CFModal.aviso({tipo: 'info', titulo: 'Falta a empresa', texto: 'Escolha a empresa da importação (Vertical ou Camargo) ao lado do botão.'}); return; }
    if (ids.length > 1 && !await CFModal.pergunta({titulo: 'Importar ' + ids.length + ' capas do Drive?', html: '<p style="margin:0">Todas entram em <b>revisão</b> na empresa escolhida. Nada é confirmado nem enviado ao Omie. As que tiverem COD já usado em outra capa ficam de fora, com o motivo.</p>', ok: 'Sim, importar'})) return;
    ocupado = true; contar();
    const pg = CFModal.progresso({titulo: 'Importando do Drive', atraso: 200}); let fila = ids.slice(), res = [], erro = null, voltas = 0;
    while (fila.length && voltas++ < 60) {
      pg.pct(Math.round(100 * res.length / ids.length), res.length + ' de ' + ids.length + ' arquivo(s)…');
      let j = null;
      try { const r = await fetch('/capa-financeira/drive.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({acao: 'importar', csrf, empresa: emp, ids: fila, unico: ids.length === 1 ? 1 : 0})}); j = await r.json(); } catch (e) { j = null; }
      if (!j || !j.ok) { erro = j && j.erro ? j.erro : 'O portal não respondeu no meio da importação. O que já foi importado está na lista de capas; atualize a página e importe de novo as que faltaram.'; break; }
      res = res.concat(j.resultados); if (!j.resultados.length && j.restantes.length === fila.length) { erro = 'A importação não avançou (o servidor está lento). Tente de novo com menos arquivos.'; break; }
      fila = j.restantes;
    }
    ocupado = false;
    const ROT = {ok: ['cf-ok', 'importada'], sozinha: ['cf-leve', 'ficou de fora'], ja: ['cf-ok', 'já importada'], erro: ['cf-grave', 'erro']}, nOk = res.filter(r => r.st === 'ok').length;
    const html = (erro ? '<p><b>' + esc(erro) + '</b></p>' : '') + '<div style="text-align:left;max-height:50vh;overflow:auto">' + res.map(r => '<div style="margin:6px 0"><span class="cf-flag ' + ROT[r.st][0] + '">' + ROT[r.st][1] + '</span> <b>' + esc(r.nome) + '</b><br><small>' + (r.id ? '<a href="/capa-financeira/revisar.php?id=' + r.id + '">capa #' + r.id + '</a> · ' : '') + esc(r.msg) + '</small></div>').join('') + '</div>' +
      (nOk > 1 ? '<p style="margin-top:10px"><a class="btn" href="/capa-financeira/lote.php">Resolver alertas repetidos em lote</a></p>' : '');
    await pg.resultado({tipo: erro ? 'erro' : (nOk === res.length ? 'ok' : 'parcial'), titulo: nOk + ' de ' + ids.length + ' capa(s) importadas', html});
    if (nOk === 1 && ids.length === 1) { location.href = '/capa-financeira/revisar.php?id=' + res.find(r => r.st === 'ok').id; return; }
    location.reload();
  });
  ler(false);
  setInterval(() => { if (!document.hidden && $('drv-on').style.display !== 'none') ler(false); }, 45000);
})();
</script>
<p style="margin:10px 0"><a class="btn cf-btn-sec" href="/capa-financeira/lote.php" title="resolve de uma vez os alertas que se repetem nas capas em revisão: data da venda com ano arrastado, grafia parecida e condições fora do dicionário">⚡ Resolver alertas repetidos em lote</a></p>

<div class="admin-tabs">
  <?php foreach (['' => 'Todas', 'revisao' => 'Em revisão', 'confirmada' => 'Confirmadas', 'substituida' => 'Substituídas', 'descartada' => 'Descartadas'] as $k => $n): ?>
    <a class="<?= $k === $filtro ? 'on' : '' ?>" href="/capa-financeira/?status=<?= $k ?>"><?= $n ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$capas): ?>
  <p class="home-sub">Nenhuma capa por aqui ainda.</p>
<?php else: ?>
<div class="cf-tbl-wrap">
<table class="grid cf-lista">
  <thead><tr><th>#</th><th>COD</th><th>Cliente</th><th>Construtora</th><th>Venda</th><th>Empresa</th><th>Linhas</th><th>A pagar</th><th>A receber</th><th>Status</th><th>Enviada</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($capas as $c): $fl = json_decode((string)$c['capa_flags'], true) ?: []; ?>
    <tr>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a><?= (int)$c['versao'] > 1 ? ' <span class="cf-tag">v' . (int)$c['versao'] . '</span>' : '' ?></td>
      <td><?= h((string)$c['cod']) ?: '<span class="cf-tag cf-grave">sem COD</span>' ?></td>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= h($c['cliente']) ?></a></td>
      <td><?= h($c['construtora']) ?></td>
      <td><?= cf_data_br($c['data_venda']) ?></td>
      <td><?= h(strtoupper($c['empresa'])) ?></td>
      <td><?= (int)$c['n_linhas'] ?><?= (int)$c['n_pendencias'] ? ' <span class="cf-tag cf-grave" title="pendências graves">' . (int)$c['n_pendencias'] . ' ⚠</span>' : '' ?></td>
      <td><?= cf_brl($c['total_pagar']) ?></td>
      <td><?= cf_brl($c['total_receber']) ?></td>
      <td><span class="cf-status cf-st-<?= h($c['status']) ?>"><?= h($c['status']) ?></span><?= $fl ? ' <span class="cf-tag" title="' . h(implode(' | ', $fl)) . '">' . count($fl) . ' aviso(s)</span>' : '' ?></td>
      <td><?= h(substr((string)$c['criado_em'], 0, 16)) ?><br><small><?= h((string)$c['enviado_nome']) ?></small></td>
      <td><?php if (in_array($c['status'], ['revisao', 'descartada'], true)): ?><button type="button" class="cf-x cf-excluir" data-id="<?= (int)$c['id'] ?>" data-nome="<?= h($c['cliente']) ?>" title="excluir esta capa de vez (ainda não foi confirmada)">✕</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="cf-dica">✕ exclui de vez uma capa enviada mas ainda não confirmada (arquivo e linhas). Capas confirmadas ficam no histórico.</p>
<script>
(function(){
  const csrf = <?= json_encode(csrf_token()) ?>;
  document.querySelectorAll('.cf-excluir').forEach(b => b.addEventListener('click', async () => {
    if (!confirm('Excluir a capa #' + b.dataset.id + ' (' + b.dataset.nome + ') de vez? Não tem como desfazer — dá pra reenviar a planilha depois.')) return;
    const r = await fetch('/capa-financeira/acao.php', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF':csrf}, body: JSON.stringify({acao:'excluir', capa_id: +b.dataset.id, csrf})});
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!j || !j.ok) { const t = j && j.erro ? j.erro : 'erro ' + r.status; if (window.CFModal) CFModal.aviso({tipo: 'erro', titulo: 'Não excluiu', texto: t}); else alert('Não excluiu: ' + t); return; }
    b.closest('tr').remove();
  }));
})();
</script>
<?php endif; ?>
<?php portal_footer();
