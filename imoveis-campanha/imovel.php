<?php
/* =====================================================================
   imoveis-campanha/imovel.php: um imóvel, em quatro abas.

     cadastro   o código do Robust preenche o que o Robust tem; o corretor
                completa o resto (tela "cadastro" aprovada na r23)
     limitar    "O que ela fala": campo a campo, ela pode ou não falar.
                Desligado = ela não recebe o dado e passa ao corretor.
     previa     "Como ela responde": pergunta como o lead e vê a resposta
                que ela daria, sem enviar nada (tela "prévia" da r23)
     respostas  a auditoria: tudo o que ela respondeu sobre o imóvel, com
                a pergunta, a conversa antes dela e o número que a trava tirou

   Cadastro e limitar são formulário comum (POST com CSRF); a prévia e o
   "puxar do Robust" usam acao.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('imoveis-campanha');
$novo = !empty($_GET['novo']);
$id = (string)($_GET['id'] ?? '');
$aba = $novo ? 'cadastro' : (string)($_GET['aba'] ?? 'cadastro');
if (!in_array($aba, ['cadastro', 'limitar', 'previa', 'respostas'], true)) $aba = 'cadastro';
$msg = !empty($_GET['ok']) ? 'Salvo. A Bianca passa a usar a ficha nova em até 1 minuto.' : null;
$erro = null;

try {
    $bianca = ImcBianca::doConfig();
    $imovel = $novo ? null : $bianca->rpc('camargo_portal_imovel', ['p_id' => $id]);
} catch (Throwable $e) {
    portal_header('Imóveis da campanha', $u); imc_estilo();
    echo '<p class="imc-bc"><a href="./">Imóveis da campanha</a></p><div class="imc-err">' . h(imc_erro_legivel($e)) . '</div>';
    portal_footer(); exit;
}
if (!$novo && (!$imovel || !imc_visiveis($u, [$imovel]))) { http_response_code(404); exit('Imóvel não encontrado.'); }
$podeEditar = imc_pode_editar($u, $imovel);
$campos = (array)($imovel['campos'] ?? []);

/* ---------------- gravação ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$podeEditar) { http_response_code(403); exit('Só o corretor do imóvel ou um admin edita.'); }
    $acao = (string)($_POST['acao'] ?? '');
    $base = $imovel ?? ['campos' => [], 'ligado' => false, 'parcelas_pode_falar' => true];
    try {
        if ($acao === 'cadastro') {
            $nome = trim((string)($_POST['nome'] ?? ''));
            $codigo = preg_replace('/\D/', '', (string)($_POST['codigo_robust'] ?? ''));
            if ($nome === '') throw new RuntimeException('Dê um nome ao imóvel (é como ela chama o imóvel na conversa).');
            $novos = [];
            foreach (ImcFicha::ROBUST as $k => $rot) $novos[$k] = [$rot, (string)($_POST['r'][$k] ?? ''), 'robust'];
            foreach (['metragem', 'abertura', 'entrada'] as $k) $novos[$k] = [ImcFicha::CORRETOR[$k], (string)($_POST['c'][$k] ?? ''), 'corretor'];
            $fg = (string)($_POST['c']['fgts'] ?? '');
            $novos['fgts'] = ['FGTS', $fg === 'sim' ? 'aceita FGTS' : ($fg === 'nao' ? 'não aceita FGTS' : ''), 'corretor'];
            // Campos livres: os que o imóvel já tinha (fichas da fase 1) e os novos da tela.
            $usadas = array_map(fn($c) => (string)$c['chave'], $campos);
            foreach ((array)($_POST['x'] ?? []) as $x) {
                $rot = trim((string)($x['rotulo'] ?? '')); $val = (string)($x['valor'] ?? '');
                $k = (string)($x['chave'] ?? '');
                if ($k === '' || isset($novos[$k])) { if ($rot === '' || trim($val) === '') continue; $k = ImcFicha::chaveLivre($rot, array_merge($usadas, array_keys($novos))); }
                $novos[$k] = [$rot, $val, 'corretor'];
            }
            $novos[ImcFicha::DUVIDAS] = ['Outras informações', (string)($_POST['duvidas'] ?? ''), 'corretor'];
            $pa = (string)($_POST['pa_ano'] ?? '') . '-' . (string)($_POST['pa_mes'] ?? '');
            $dono = (string)($_POST['corretor_ghl_id'] ?? '');
            if (!is_admin($u)) $dono = (string)($imovel['corretor_ghl_id'] ?? $u['broker_id']);
            $nomeDono = '';
            foreach (imc_corretores() as $b) if ((string)$b['id'] === $dono) $nomeDono = imc_primeiro_nome((string)$b['nome']);
            if ($dono !== '' && $nomeDono === '') $nomeDono = (string)($imovel['corretor'] ?? '');
            $obj = array_merge($base, [
                'id' => $imovel['id'] ?? ImcFicha::idNovo($nome, $codigo),
                'nome' => $nome, 'codigo_robust' => $codigo,
                'campos' => ImcFicha::juntar($campos, $novos),
                'parcelas_ate' => preg_match('/^\d{4}-\d{2}$/', $pa) ? $pa : null,
                'ligado' => !empty($_POST['ligado']),
                'corretor' => $nomeDono, 'corretor_ghl_id' => $dono,
                'foto_url' => trim((string)($_POST['foto_url'] ?? ($imovel['foto_url'] ?? ''))),
            ]);
            if ($novo) {
                // id já usado por outro imóvel (mesmo código e nome): ganha -2, -3...
                $raiz = $obj['id']; $n = 2;
                while ($bianca->rpc('camargo_portal_imovel', ['p_id' => $obj['id']])) $obj['id'] = substr($raiz, 0, 76) . '-' . $n++;
            }
        } elseif ($acao === 'limitar') {
            $obj = $base;
            $obj['campos'] = ImcFicha::limitar($campos, (array)($_POST['lib'] ?? []));
            $obj['parcelas_pode_falar'] = !empty($_POST['parcelas_pode_falar']);
        } else {
            throw new RuntimeException('Ação desconhecida.');
        }
        $salvo = $bianca->rpc('camargo_portal_imovel_salvar', ['p_imovel' => $obj, 'p_usuario' => (string)$u['nome']]);
        header('Location: imovel.php?id=' . urlencode((string)$salvo['id']) . '&aba=' . ($acao === 'limitar' ? 'limitar' : 'cadastro') . '&ok=1');
        exit;
    } catch (Throwable $e) {
        $erro = imc_erro_legivel($e);
    }
}

$titulo = $imovel['nome'] ?? 'Novo imóvel';
portal_header((string)$titulo . ' · Imóveis da campanha', $u);
imc_estilo();
if ($novo) echo '<p class="imc-bc"><a href="./">Imóveis da campanha</a> / Novo imóvel</p>';
else imc_abas($imovel, $aba);
if ($msg) echo '<div class="imc-ok">' . h($msg) . '</div>';
if ($erro) echo '<div class="imc-err">' . h($erro) . '</div>';
$ro = $podeEditar ? '' : 'disabled';
$v = fn(string $k) => ImcFicha::valor($campos, $k);

/* =====================================================================
   CADASTRO
   ===================================================================== */
if ($aba === 'cadastro'):
    $pa = (string)($imovel['parcelas_ate'] ?? '');
    $faltam = $pa ? ImcFicha::parcelasQueFaltam($pa, new DateTimeImmutable()) : null;
    $fg = $v('fgts'); $fgSel = $fg === '' ? '' : (str_starts_with(mb_strtolower($fg), 'não') || str_starts_with(mb_strtolower($fg), 'nao') ? 'nao' : 'sim');
    $conhecidas = array_merge(array_keys(ImcFicha::ROBUST), array_keys(ImcFicha::CORRETOR), [ImcFicha::DUVIDAS]);
    $livres = array_values(array_filter($campos, fn($c) => !in_array((string)$c['chave'], $conhecidas, true)));
    $resumo = implode(' · ', array_filter([$v('tipo'), $v('bairro'), $v('metragem'), $v('quartos') ? $v('quartos') . ' quartos' : '']));
?>
<form method="post" class="imc-cols" id="cad">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="acao" value="cadastro">
  <input type="hidden" name="foto_url" id="foto_url" value="<?= h((string)($imovel['foto_url'] ?? '')) ?>">
  <div class="imc-box">
    <div class="imc-g" style="grid-template-columns:2fr 1fr auto;align-items:end">
      <label class="imc-f">Nome do imóvel<input name="nome" id="nome" value="<?= h((string)($imovel['nome'] ?? '')) ?>" placeholder="Geminado Vila Nova" <?= $ro ?> required title="É como ela chama o imóvel na conversa"></label>
      <label class="imc-f">Código do Robust<input name="codigo_robust" id="codigo" value="<?= h((string)($imovel['codigo_robust'] ?? '')) ?>" inputmode="numeric" placeholder="1588" <?= $ro ?>></label>
      <?php if ($podeEditar): ?><button type="button" class="imc-btn" id="puxar" title="Preenche com o que está no Robust">Puxar do Robust</button><?php endif; ?>
    </div>
    <div class="imc-mini" id="puxar-st" style="min-height:18px;margin-top:4px"></div>

    <div class="imc-h">Do Robust</div>
    <div class="imc-g">
      <?php foreach (ImcFicha::ROBUST as $k => $rot): ?>
        <label class="imc-f rb"><span class="rbl"><?= h($rot) ?><i>Robust</i></span><input name="r[<?= $k ?>]" data-r="<?= $k ?>" value="<?= h($v($k)) ?>" <?= $ro ?>></label>
      <?php endforeach; ?>
    </div>

    <div class="imc-h">O corretor completa</div>
    <div class="imc-g">
      <label class="imc-f">Metragem<input name="c[metragem]" value="<?= h($v('metragem')) ?>" placeholder="62 m²" <?= $ro ?>></label>
      <label class="imc-f">Abertura<input name="c[abertura]" value="<?= h($v('abertura')) ?>" placeholder="5,5 m" <?= $ro ?>></label>
      <label class="imc-f">Entrada<input name="c[entrada]" value="<?= h($v('entrada')) ?>" placeholder="R$ 32.000" <?= $ro ?>></label>
      <div class="imc-f" title="Mês da última parcela da entrada: quantas faltam é conta do sistema, não dela">Parcela até
        <span style="display:flex;gap:6px">
          <select class="imc-in" name="pa_mes" id="pa_mes" <?= $ro ?>><option value="">mês</option><?php foreach (ImcFicha::MESES_CURTOS as $k => $mn): ?><option value="<?= sprintf('%02d', $k + 1) ?>" <?= $pa && (int)substr($pa, 5, 2) === $k + 1 ? 'selected' : '' ?>><?= $mn ?></option><?php endforeach; ?></select>
          <select class="imc-in" name="pa_ano" id="pa_ano" <?= $ro ?>><option value="">ano</option><?php for ($y = (int)date('Y'); $y <= (int)date('Y') + 6; $y++): ?><option <?= $pa && (int)substr($pa, 0, 4) === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
        </span>
        <span id="faltam"><?php if ($faltam !== null): ?><span class="imc-tag clay">faltam <?= $faltam ?> parcelas</span><?php endif; ?></span></div>
      <div class="imc-f">FGTS
        <span class="imc-seg">
          <input type="radio" name="c[fgts]" id="fg-s" value="sim" <?= $fgSel === 'sim' ? 'checked' : '' ?> <?= $ro ?>><label for="fg-s">Sim</label>
          <input type="radio" name="c[fgts]" id="fg-n" value="nao" <?= $fgSel === 'nao' ? 'checked' : '' ?> <?= $ro ?>><label for="fg-n">Não</label>
        </span>
      </div>
    </div>

    <div class="imc-h">Mais sobre o imóvel</div>
    <div id="livres" style="display:flex;flex-direction:column;gap:8px">
      <?php foreach ($livres as $n => $c): ?>
        <div class="imc-g" style="grid-template-columns:180px 1fr">
          <input type="hidden" name="x[<?= $n ?>][chave]" value="<?= h((string)$c['chave']) ?>">
          <input class="imc-in" name="x[<?= $n ?>][rotulo]" value="<?= h((string)$c['rotulo']) ?>" <?= $ro ?>>
          <input class="imc-in" name="x[<?= $n ?>][valor]" value="<?= h((string)$c['valor']) ?>" <?= $ro ?> title="Apague o texto para tirar o campo">
        </div>
      <?php endforeach; ?>
    </div>
    <?php if ($podeEditar): ?><button type="button" class="imc-btn sec" id="mais" style="margin-top:8px">+ Campo</button><?php endif; ?>

    <div class="imc-h">Dúvidas que o anúncio não responde</div>
    <textarea class="imc-in" name="duvidas" rows="3" placeholder="Pátio nos fundos com 5 m. Aceita carro na entrada." <?= $ro ?>><?= h($v(ImcFicha::DUVIDAS)) ?></textarea>

    <?php if (is_admin($u)): ?>
      <div class="imc-h">Corretor</div>
      <select class="imc-in" name="corretor_ghl_id" style="max-width:340px" title="Quem cuida do imóvel: ela diz que ele confirma o que não está na ficha">
        <option value="">Campanha da casa</option>
        <?php foreach (imc_corretores() as $b): ?>
          <option value="<?= h((string)$b['id']) ?>" <?= (string)($imovel['corretor_ghl_id'] ?? '') === (string)$b['id'] ? 'selected' : '' ?>><?= h((string)$b['nome']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>

    <div class="imc-rodape">
      <label style="display:flex;align-items:center;gap:10px"><span class="imc-sw"><input type="checkbox" name="ligado" value="1" <?= !empty($imovel['ligado']) ? 'checked' : '' ?> <?= $ro ?>><span></span></span> Ela responde sobre este imóvel</label>
      <?php if ($podeEditar): ?><button class="imc-btn" type="submit">Salvar</button><?php endif; ?>
    </div>
  </div>

  <div class="imc-box" style="padding:12px">
    <div class="foto" id="foto" style="aspect-ratio:7/6;border-radius:4px;background:#e8e4d8 center/cover no-repeat;<?= !empty($imovel['foto_url']) ? "background-image:url('" . h((string)$imovel['foto_url']) . "')" : '' ?>"></div>
    <p style="font-weight:700;margin:12px 0 6px" id="resumo"><?= h($resumo ?: (string)($imovel['nome'] ?? '')) ?></p>
    <?php if (!empty($imovel['codigo_robust'])): ?>
      <p class="imc-mini" style="margin:0"><span class="imc-tag clay" style="background:#fff;color:var(--clay);border:1px solid var(--clay)">Cód. <?= h((string)$imovel['codigo_robust']) ?></span> no texto do anúncio</p>
    <?php endif; ?>
    <?php if (!empty($imovel['atualizado_em'])): ?>
      <p class="imc-mini" style="margin:10px 0 0">Alterado por <?= h((string)($imovel['atualizado_por'] ?? '')) ?> · <?= h(date('d/m H:i', strtotime((string)$imovel['atualizado_em']))) ?></p>
    <?php endif; ?>
  </div>
</form>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>;
  // "faltam N parcelas" na hora em que o mês muda (mesma conta do servidor e da Bianca).
  const faltam = document.getElementById('faltam');
  const conta = () => {
    const m = /^(\d{4})-(\d{2})$/.exec(document.getElementById('pa_ano').value + '-' + document.getElementById('pa_mes').value); if (!m) { faltam.innerHTML = ''; return; }
    const hoje = new Date(new Date().toLocaleString('en-US', {timeZone: 'America/Sao_Paulo'}));
    const n = Math.max(0, (+m[1]) * 12 + (+m[2]) - (hoje.getFullYear() * 12 + hoje.getMonth() + 1));
    faltam.innerHTML = '<span class="imc-tag clay">faltam ' + n + ' parcelas</span>';
  };
  ['pa_mes', 'pa_ano'].forEach(i => document.getElementById(i)?.addEventListener('change', conta));
  let n = <?= count($livres) ?>;
  document.getElementById('mais')?.addEventListener('click', () => {
    const d = document.createElement('div'); d.className = 'imc-g'; d.style.gridTemplateColumns = '180px 1fr';
    d.innerHTML = `<input type="hidden" name="x[${n}][chave]" value=""><input class="imc-in" name="x[${n}][rotulo]" placeholder="Condomínio"><input class="imc-in" name="x[${n}][valor]" placeholder="R$ 280">`;
    document.getElementById('livres').appendChild(d); n++; d.querySelector('input.imc-in').focus();
  });
  const st = document.getElementById('puxar-st');
  document.getElementById('puxar')?.addEventListener('click', async () => {
    const codigo = document.getElementById('codigo').value.replace(/\D/g, '');
    if (!codigo) { st.textContent = 'Digite o código do Robust.'; return; }
    st.textContent = 'Lendo o Robust…';
    const r = await fetch('acao.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf, acao: 'robust', codigo})})
      .then(r => r.json()).catch(() => ({ok: false, erro: 'sem resposta'}));
    if (!r.ok) { st.textContent = r.erro || 'Não leu.'; return; }
    for (const [k, val] of Object.entries(r.campos)) { const i = document.querySelector(`[data-r="${k}"]`); if (i && val) i.value = val; }
    const nome = document.getElementById('nome'); if (!nome.value && r.titulo) nome.value = r.titulo;
    if (r.foto) { document.getElementById('foto_url').value = r.foto; document.getElementById('foto').style.backgroundImage = `url('${r.foto}')`; }
    st.innerHTML = '<span class="imc-tag">✓ puxado do Robust</span>';
  });
})();
</script>
<?php

/* =====================================================================
   LIMITAR: o que ela pode falar, campo a campo
   ===================================================================== */
elseif ($aba === 'limitar'):
    $pa = (string)($imovel['parcelas_ate'] ?? '');
    $total = count($campos) + ($pa ? 1 : 0);
    $lib = count(array_filter($campos, fn($c) => !empty($c['pode_falar']))) + ($pa && ($imovel['parcelas_pode_falar'] ?? true) ? 1 : 0);
?>
<form method="post" class="imc-box">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="acao" value="limitar">
  <div style="display:flex;justify-content:space-between;align-items:baseline;margin:0 0 6px">
    <div class="imc-h" style="margin:0;font-size:17px">O que ela pode falar</div>
    <span class="imc-mini" id="cont"><?= $lib ?> de <?= $total ?> liberados</span>
  </div>
  <table class="imc-tab">
    <thead><tr><th>Campo</th><th>Valor</th><th style="text-align:right">Ela pode falar</th></tr></thead>
    <tbody>
    <?php foreach ($campos as $c): $on = !empty($c['pode_falar']); ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <td style="font-weight:600;white-space:nowrap"><?= h((string)$c['rotulo']) ?></td>
        <td class="v"><?= h((string)$c['valor']) ?></td>
        <td class="s"><?php if (!$on): ?><span class="imc-tag clay" style="margin-right:10px" title="Se o lead perguntar, ela diz que o corretor confirma">passa ao corretor</span><?php endif; ?>
          <label class="imc-sw"><input type="checkbox" name="lib[<?= h((string)$c['chave']) ?>]" value="1" <?= $on ? 'checked' : '' ?> <?= $ro ?>><span></span></label></td>
      </tr>
    <?php endforeach; ?>
    <?php if ($pa): $on = (bool)($imovel['parcelas_pode_falar'] ?? true); ?>
      <tr class="<?= $on ? '' : 'off' ?>">
        <td style="font-weight:600">Parcela até</td>
        <td class="v"><?= h(ImcFicha::mesCurto($pa)) ?> · faltam <?= ImcFicha::parcelasQueFaltam($pa, new DateTimeImmutable()) ?> <span class="imc-mini" title="A conta é do sistema, refeita todo mês">(conta automática)</span></td>
        <td class="s"><?php if (!$on): ?><span class="imc-tag clay" style="margin-right:10px">passa ao corretor</span><?php endif; ?>
          <label class="imc-sw"><input type="checkbox" name="parcelas_pode_falar" value="1" <?= $on ? 'checked' : '' ?> <?= $ro ?>><span></span></label></td>
      </tr>
    <?php endif; ?>
    </tbody>
  </table>
  <?php if (!$campos): ?><p class="imc-mini">A ficha está vazia: preencha o cadastro primeiro.</p><?php endif; ?>
  <div class="imc-rodape">
    <span class="imc-mini"><?php if (!empty($imovel['atualizado_em'])): ?>Alterado por <?= h((string)($imovel['atualizado_por'] ?? '')) ?> · <?= h(date('d/m H:i', strtotime((string)$imovel['atualizado_em']))) ?><?php endif; ?></span>
    <?php if ($podeEditar): ?><button class="imc-btn" type="submit">Salvar</button><?php endif; ?>
  </div>
</form>
<script>
document.querySelectorAll('.imc-tab input[type=checkbox]').forEach(cb => cb.addEventListener('change', () => {
  cb.closest('tr').classList.toggle('off', !cb.checked);
  const t = document.querySelectorAll('.imc-tab input[type=checkbox]');
  document.getElementById('cont').textContent = [...t].filter(x => x.checked).length + ' de ' + t.length + ' liberados';
}));
</script>
<?php

/* =====================================================================
   PRÉVIA: como ela responde (nada é enviado)
   ===================================================================== */
elseif ($aba === 'previa'):
    $liberados = array_values(array_filter($campos, fn($c) => !empty($c['pode_falar']) && trim((string)$c['valor']) !== ''));
    $pa = (string)($imovel['parcelas_ate'] ?? '');
?>
<div class="imc-cols" style="grid-template-columns:380px minmax(0,1fr)">
  <div class="imc-box">
    <div class="imc-h" style="font-size:17px">Pergunte como o lead</div>
    <form id="perg" style="display:flex;flex-direction:column;gap:8px">
      <input class="imc-in" id="q" maxlength="1000" placeholder="Qual a entrada? Parcela em quantas vezes?" autocomplete="off">
      <button class="imc-btn" style="justify-content:center">Perguntar</button>
    </form>
    <div class="imc-chips" style="margin:10px 0 4px">
      <?php foreach (['Valor' => 'Qual o valor?', 'Entrada' => 'Qual a entrada?', 'Metragem' => 'Qual a metragem?', 'Localização' => 'Onde fica?'] as $t => $p): ?>
        <a href="#" class="imc-chip" data-p="<?= h($p) ?>" style="font-size:13px;padding:4px 12px"><?= h($t) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="imc-h" style="border-top:1px solid var(--line);padding-top:14px">Ficha</div>
    <table class="imc-ficha" id="ficha">
      <?php foreach ($liberados as $c): ?>
        <tr data-chave="<?= h((string)$c['chave']) ?>"><td><?= h((string)$c['rotulo']) ?></td><td><?= h(mb_strimwidth((string)$c['valor'], 0, 60, '…')) ?></td></tr>
      <?php endforeach; ?>
      <?php if ($pa && ($imovel['parcelas_pode_falar'] ?? true)): ?><tr data-chave="parcelas_ate"><td>Parcela até</td><td><?= h(ImcFicha::mesCurto($pa)) ?></td></tr><?php endif; ?>
    </table>
    <?php if (count($liberados) < count($campos)): ?><p class="imc-mini" style="margin:8px 0 0"><?= count($campos) - count($liberados) ?> campo(s) limitado(s) ficam fora da ficha dela.</p><?php endif; ?>
  </div>
  <div class="imc-box" style="padding:0;overflow:hidden">
    <div style="display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid var(--line);color:var(--mute)">🔒 Prévia · nada é enviado</div>
    <div class="imc-zap" id="zap" style="border-radius:0"></div>
  </div>
</div>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>, id = <?= json_encode((string)$imovel['id']) ?>;
  const rotulos = <?= json_encode(array_column(array_merge($liberados, [['chave' => 'parcelas_ate', 'rotulo' => 'Parcela até']]), 'rotulo', 'chave'), JSON_UNESCAPED_UNICODE) ?>;
  const sessao = crypto.randomUUID ? crypto.randomUUID() : ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
  const zap = document.getElementById('zap'), q = document.getElementById('q');
  const hora = () => new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
  const esc = t => t.replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  const bolha = (txt, ela, tags = '') => {
    const b = document.createElement('div'); b.className = 'imc-b' + (ela ? ' ela' : '');
    b.innerHTML = '<span class="t">' + esc(txt) + '</span>' + (tags ? `<div class="tags">${tags}</div>` : '') + `<span class="h">${hora()}${ela ? ' ✓✓' : ''}</span>`;
    zap.appendChild(b); zap.scrollTop = zap.scrollHeight; return b;
  };
  const post = d => fetch('acao.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf, ...d})})
    .then(r => r.json()).catch(() => ({ok: false, erro: 'sem resposta do portal'}));
  async function perguntar(p) {
    p = p.trim(); if (!p) return; q.value = ''; bolha(p, false);
    const esperando = bolha('…', true);
    const r = await post({acao: 'previa', id, sessao, pergunta: p});
    if (!r.ok) { esperando.remove(); bolha(r.erro || 'Não pediu.', true, '<span class="imc-tag clay">erro</span>'); return; }
    if (!r.chamou) { esperando.remove(); bolha('A prévia ainda não está ligada do lado da Bianca.', true, '<span class="imc-tag clay">sem prévia</span>'); return; }
    for (let i = 0; i < 60; i++) {
      await new Promise(ok => setTimeout(ok, 1500));
      const l = await post({acao: 'previa_ler', id, previa: r.id});
      if (!l.ok || l.status === 'pendente') continue;
      esperando.remove();
      if (l.status === 'erro') { bolha(l.erro || 'Ela não conseguiu responder.', true, '<span class="imc-tag clay">erro</span>'); return; }
      document.querySelectorAll('#ficha tr').forEach(tr => tr.classList.toggle('usado', l.usados.includes(tr.dataset.chave)));
      const linhas = l.resposta.split(/\n+/).filter(x => x.trim());
      linhas.forEach((t, k) => {
        const usadosAqui = l.usados_por_linha[k] || [];
        let tags = usadosAqui.map(c => `<span class="imc-tag">${esc(rotulos[c] || c)}</span>`).join('');
        if (l.passou_por_linha[k]) tags += '<span class="imc-tag clay">passa ao corretor</span>';
        if (k === linhas.length - 1 && l.numeros_tirados.length) tags += `<span class="imc-tag clay" title="${esc(l.numeros_tirados.join(', '))}">${l.numeros_tirados.length} número(s) tirado(s)</span>`;
        bolha(t, true, tags);
      });
      return;
    }
    esperando.remove(); bolha('Ela demorou mais de 90 s. Tente de novo.', true, '<span class="imc-tag clay">sem resposta</span>');
  }
  document.getElementById('perg').addEventListener('submit', e => { e.preventDefault(); perguntar(q.value); });
  document.querySelectorAll('[data-p]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); perguntar(a.dataset.p); }));
})();
</script>
<?php

/* =====================================================================
   RESPOSTAS: a auditoria
   ===================================================================== */
else:
    $origem = ($_GET['origem'] ?? '') === 'previa' ? 'previa' : 'conversa';
    $respostas = [];
    try { $respostas = (array)$bianca->rpc('camargo_portal_imovel_respostas', ['p_id' => (string)$imovel['id'], 'p_origem' => $origem, 'p_limite' => 200]); }
    catch (Throwable $e) { echo '<div class="imc-err">' . h(imc_erro_legivel($e)) . '</div>'; }
    $todos = [];
    try { $todos = imc_visiveis($u, (array)$bianca->rpc('camargo_portal_imoveis')); } catch (Throwable $e) {}
    $conversas = count(array_unique(array_filter(array_column($respostas, 'contact_id'))));
    $tirados = array_sum(array_map(fn($r) => count((array)($r['numeros_tirados'] ?? [])), $respostas));
    $rot = array_column(imc_campos_para_marcar($imovel), 'rotulo', 'chave');
    $base = 'imovel.php?id=' . urlencode((string)$imovel['id']) . '&aba=respostas';
?>
<div class="imc-top" style="margin-top:0">
  <div class="imc-chips" style="margin:0">
    <span class="imc-chip"><b><?= count($respostas) ?></b> respostas</span>
    <?php if ($origem === 'conversa'): ?><span class="imc-chip"><b><?= $conversas ?></b> conversas</span><?php endif; ?>
    <span class="imc-chip" title="Número que ela escreveu sem estar na ficha: a trava tira antes de enviar"><?php if ($tirados): ?><i class="dot"></i><?php endif; ?><b><?= $tirados ?></b> <?= $tirados === 1 ? 'número tirado' : 'números tirados' ?></span>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <select class="imc-in" style="width:auto" onchange="location='imovel.php?aba=respostas&origem=<?= $origem ?>&id='+encodeURIComponent(this.value)" title="Imóvel">
      <?php foreach ($todos as $t): ?><option value="<?= h((string)$t['id']) ?>" <?= $t['id'] === $imovel['id'] ? 'selected' : '' ?>><?= h((string)$t['nome']) ?></option><?php endforeach; ?>
    </select>
    <span class="imc-seg">
      <a href="<?= h($base) ?>&origem=conversa" style="padding:8px 14px;text-decoration:none;<?= $origem === 'conversa' ? 'background:var(--moss);color:#fff' : 'color:var(--mute);background:#fff' ?>">Conversas</a>
      <a href="<?= h($base) ?>&origem=previa" style="padding:8px 14px;text-decoration:none;<?= $origem === 'previa' ? 'background:var(--moss);color:#fff' : 'color:var(--mute);background:#fff' ?>">Prévias</a>
    </span>
  </div>
</div>
<?php if (!$respostas): ?>
  <div class="aviso"><?= $origem === 'conversa' ? (empty($imovel['ligado']) ? 'Nenhuma resposta: o imóvel está desligado.' : 'Ela ainda não respondeu nenhum lead sobre este imóvel.') : 'Nenhuma prévia ainda.' ?></div>
<?php endif; ?>
<?php foreach ($respostas as $n => $r):
    $usados = ImcFicha::usados((string)$r['resposta'], imc_campos_para_marcar($imovel), (string)$imovel['nome']);
    $nt = (array)($r['numeros_tirados'] ?? []);
    $ctx = (array)($r['contexto'] ?? []);
?>
  <div class="imc-resp">
    <div class="imc-mini"><?= h(date('d/m H:i', strtotime((string)$r['criado_em']))) ?> · <?= h($origem === 'previa' ? 'prévia no portal' : ((string)($imovel['corretor'] ?? '') ?: 'Campanha da casa')) ?></div>
    <div class="imc-b" style="max-width:70%"><span class="t"><?= h((string)$r['pergunta']) ?></span></div>
    <div class="imc-b ela" style="max-width:70%"><span class="t"><?= h((string)$r['resposta']) ?></span><div class="tags"><?php /* sem espaço entre as tags e o texto */ ?>
        <?php foreach ($usados as $c): ?><span class="imc-tag"><?= h((string)($rot[$c] ?? $c)) ?></span><?php endforeach; ?>
        <?php if (ImcFicha::passouAoCorretor((string)$r['resposta'])): ?><span class="imc-tag clay">passa ao corretor</span><?php endif; ?>
        <?php if ($nt): ?><span class="imc-tag clay" title="<?= h(implode(', ', $nt)) ?>"><?= count($nt) ?> número<?= count($nt) > 1 ? 's' : '' ?> tirado<?= count($nt) > 1 ? 's' : '' ?></span><?php endif; ?>
      </div>
    </div>
    <?php if ($ctx): ?>
      <a href="#" class="imc-mini" style="align-self:flex-end;color:var(--moss);font-weight:600" onclick="this.nextElementSibling.classList.toggle('on');return false">Ver conversa</a>
      <div class="imc-ctx"><b style="font-size:12px">Conversa antes da pergunta</b>
        <?php foreach ($ctx as $m): ?><p><b><?= ($m['quem'] ?? '') === 'lead' ? 'Lead' : 'Bianca' ?>:</b> <?= h(mb_strimwidth((string)($m['texto'] ?? ''), 0, 300, '…')) ?></p><?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach;
endif;
portal_footer();
