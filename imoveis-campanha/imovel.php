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
            // A linha "Parcela até" só aparece quando há mês da última parcela; sem ela o
            // checkbox não vem no POST, e ler "não veio" como "desligado" apagava o
            // limite que ainda não existe (achado no teste contra a produção, 27/10).
            if ((string)($imovel['parcelas_ate'] ?? '') !== '') $obj['parcelas_pode_falar'] = !empty($_POST['parcelas_pode_falar']);
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
      <p class="imc-mini" style="margin:0"><span class="imc-tag clay" style="background:#fff;color:var(--clay);border:1px solid var(--clay)">Código <?= h((string)$imovel['codigo_robust']) ?></span> no texto do anúncio</p>
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
   r41 (29/10, pedido do João depois da reunião com o Jhony): a prévia era
   "pergunta e resposta" e não parecia conversa. Agora ela simula o caminho
   do lead da campanha: ele vê o anúncio, toca em "Enviar mensagem", o
   WhatsApp abre com a frase pronta da Meta já escrita e o cartão do anúncio
   preso à primeira mensagem, e a conversa segue numa tela de celular.
   A resposta continua sendo a da task da prévia (mesma geração do celular
   do corretor, sem envio e sem ferramentas). A frase pronta é a da Meta
   que mais chega nos celulares dos corretores ("Posso ter mais informações
   sobre isso?", 14 dos 30 primeiros contatos medidos em 14/10): é ela que
   decide a primeira fala dela, então o teste começa por ela.
   ===================================================================== */
elseif ($aba === 'previa'):
    $liberados = array_values(array_filter($campos, fn($c) => !empty($c['pode_falar']) && trim((string)$c['valor']) !== ''));
    $pa = (string)($imovel['parcelas_ate'] ?? '');
    $valor = function (string $chave) use ($liberados): string {
        foreach ($liberados as $c) if ($c['chave'] === $chave) return trim((string)$c['valor']);
        return '';
    };
    // O anúncio de exemplo é montado com a ficha; o que liga a conversa ao imóvel
    // de verdade é o código no texto do anúncio (LEIA.md), por isso ele aparece,
    // escrito como a reunião de 29/10 combinou para as campanhas: "Código 1588".
    $tipo = $valor('tipo'); $bairro = $valor('bairro'); $quartos = $valor('quartos');
    $titulo = $tipo !== '' && $bairro !== '' ? "$tipo · $bairro" : (string)$imovel['nome'];
    if ($quartos !== '') $titulo .= " · $quartos " . ($quartos === '1' ? 'quarto' : 'quartos');
    $codigo = trim((string)($imovel['codigo_robust'] ?? ''));
    $foto = trim((string)($imovel['foto_url'] ?? ''));
    $fundoFoto = $foto !== '' ? "background-image:url('" . h($foto) . "')" : '';
?>
<div class="imc-sim">
  <div class="imc-sim-esq">
    <div class="imc-passo"><b>1</b><span>O lead vê o anúncio e toca em <i>Enviar mensagem</i></span></div>
    <div class="imc-ad">
      <div class="imc-ad-top"><span class="imc-av">IC</span><div><b>Imobiliária Camargo</b><small>Patrocinado</small></div></div>
      <div class="imc-ad-txt"><?= h($titulo) ?>. Fale com a gente no WhatsApp!<?php if ($codigo !== ''): ?><br><mark class="imc-ad-cod">Código <?= h($codigo) ?></mark><?php endif; ?></div>
      <div class="imc-ad-foto" style="<?= $fundoFoto ?>"><?= $foto === '' ? '🏠' : '' ?></div>
      <button type="button" class="imc-ad-cta pulsa" id="cta"><span class="imc-wa" aria-hidden="true"></span>Enviar mensagem</button>
    </div>
    <p class="imc-mini" style="margin:8px 2px 0">Anúncio de exemplo, montado com a ficha. Na campanha, o <b>Código <?= h($codigo !== '' ? $codigo : '…') ?></b> vai no texto do anúncio e na mensagem pronta do WhatsApp: é por ele que a Bianca acha este imóvel.</p>
    <div class="imc-passo" style="margin-top:18px"><b>2</b><span>Converse como o lead, no celular</span></div>
    <?php // Voto do João na r42 (29/10): a qualificação é o roteiro da Camargo e fica, mas atrapalha quem só quer
          // conferir as dúvidas do imóvel no teste. Desligar vale só aqui; o lead de verdade sempre é qualificado. ?>
    <div class="imc-qual">
      <label class="imc-qual-sw"><input type="checkbox" id="qual" checked><span class="sw" aria-hidden="true"></span><b>Qualificação</b> <span id="qualEstado">ligada</span></label>
      <p class="imc-mini" id="qualTexto"></p>
    </div>
    <details class="imc-sim-ficha">
      <summary>O que ela sabe deste imóvel <span class="imc-mini">(<?= count($liberados) + ($pa && ($imovel['parcelas_pode_falar'] ?? true) ? 1 : 0) ?> campos)</span></summary>
      <table class="imc-ficha" id="ficha">
        <?php foreach ($liberados as $c): ?>
          <tr data-chave="<?= h((string)$c['chave']) ?>"><td><?= h((string)$c['rotulo']) ?></td><td><?= h(mb_strimwidth((string)$c['valor'], 0, 60, '…')) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($pa && ($imovel['parcelas_pode_falar'] ?? true)): ?><tr data-chave="parcelas_ate"><td>Parcela até</td><td><?= h(ImcFicha::mesCurto($pa)) ?></td></tr><?php endif; ?>
      </table>
      <?php if (count($liberados) < count($campos)): ?><p class="imc-mini" style="margin:8px 0 0"><?= count($campos) - count($liberados) ?> campo(s) limitado(s) ficam fora: se o lead perguntar, ela diz que o corretor confirma.</p><?php endif; ?>
    </details>
  </div>

  <div class="imc-cel" id="cel">
    <div class="imc-cel-top">
      <span class="imc-av">IC</span>
      <div class="quem"><b>Imobiliária Camargo</b><small id="estado">online</small></div>
      <button type="button" id="recomecar" title="Apagar esta conversa e começar como um lead novo">↻ Recomeçar</button>
    </div>
    <div class="imc-cel-zap" id="zap">
      <div class="imc-sis">🔒 Teste: nada é enviado a ninguém</div>
      <div class="imc-sis" id="qualPill" hidden>Qualificação desligada: só as dúvidas do imóvel</div>
      <div class="imc-vazio" id="vazio">Toque em <b>Enviar mensagem</b> no anúncio para chegar aqui como o lead da campanha.</div>
    </div>
    <div class="imc-sug" id="sug" hidden>
      <?php foreach (['Qual o valor?', 'Qual a entrada?', 'Aceita FGTS?', 'Onde fica?', 'Quero visitar'] as $p): ?>
        <button type="button" data-p="<?= h($p) ?>"><?= h($p) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="imc-ref" id="ref" hidden>
      <span class="th" style="<?= $fundoFoto ?>"><?= $foto === '' ? '🏠' : '' ?></span>
      <span class="tx"><small>Anúncio · Imobiliária Camargo</small><b><?= h($titulo) ?></b><?php if ($codigo !== ''): ?><small>Código <?= h($codigo) ?></small><?php endif; ?></span>
    </div>
    <form class="imc-compor" id="perg">
      <input id="q" maxlength="1000" placeholder="Mensagem" autocomplete="off" aria-label="Mensagem do lead">
      <button aria-label="Enviar">➤</button>
    </form>
  </div>
</div>
<script>
(() => {
  const csrf = <?= json_encode(csrf_token()) ?>, id = <?= json_encode((string)$imovel['id']) ?>;
  const rotulos = <?= json_encode(array_column(array_merge($liberados, [['chave' => 'parcelas_ate', 'rotulo' => 'Parcela até']]), 'rotulo', 'chave'), JSON_UNESCAPED_UNICODE) ?>;
  // A frase que o WhatsApp escreve sozinho quando a pessoa toca no anúncio. r43 (29/10, voto do João):
  // nas campanhas dos imóveis a mensagem pronta do anúncio leva o código do Robust, combinado com o
  // Jhony, para a Bianca achar o imóvel mesmo quando o cartão do anúncio não chega. Sem código, a da Meta.
  const CODIGO = <?= json_encode((string)($imovel['codigo_robust'] ?? '')) ?>;
  const FRASE_DO_ANUNCIO = CODIGO ? `Olá! Posso ter mais informações sobre o imóvel Código ${CODIGO}?` : 'Posso ter mais informações sobre isso?';
  const novaSessao = () => crypto.randomUUID ? crypto.randomUUID() : ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
  let sessao = novaSessao(), primeira = true, ocupado = false, rodada = 0;
  const $ = s => document.getElementById(s);
  const zap = $('zap'), q = $('q'), ref = $('ref'), sug = $('sug'), estado = $('estado'), vazio = $('vazio');
  const hora = () => new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
  const esc = t => t.replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  // O WhatsApp mostra *negrito* e _itálico_; a tela mostra igual.
  const comoZap = t => esc(t).replace(/\*([^*\n]+)\*/g, '<b>$1</b>').replace(/(^|\s)_([^_\n]+)_/g, '$1<i>$2</i>');
  const espera = ms => new Promise(ok => setTimeout(ok, ms));
  const rolar = () => { zap.scrollTop = zap.scrollHeight; };
  const sistema = (txt, erro) => { const d = document.createElement('div'); d.className = 'imc-sis' + (erro ? ' erro' : ''); d.textContent = txt; zap.appendChild(d); rolar(); };
  function bolhaLead(txt, comAnuncio) {
    const b = document.createElement('div'); b.className = 'imc-m eu';
    b.innerHTML = (comAnuncio ? ref.outerHTML.replace(' id="ref" hidden', '') : '') + `<span class="t">${esc(txt)}</span><span class="h">${hora()} <i class="vv">✓✓</i></span>`;
    zap.appendChild(b); rolar();
  }
  function bolhaDela(txt, notas) {
    const b = document.createElement('div'); b.className = 'imc-m';
    b.innerHTML = `<span class="t">${comoZap(txt)}</span><span class="h">${hora()}</span>` + (notas ? `<div class="notas">${notas}</div>` : '');
    zap.appendChild(b); rolar();
  }
  function digitando(on) {
    estado.textContent = on ? 'digitando…' : 'online'; estado.classList.toggle('dig', on);
    let d = zap.querySelector('.imc-dig');
    if (on && !d) { d = document.createElement('div'); d.className = 'imc-m imc-dig'; d.innerHTML = '<i></i><i></i><i></i>'; zap.appendChild(d); rolar(); }
    if (!on && d) d.remove();
  }
  const post = d => fetch('acao.php', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({csrf, ...d})})
    .then(r => r.json()).catch(() => ({ok: false, erro: 'sem resposta do portal'}));
  // Tocar no anúncio: como no WhatsApp, a conversa abre com a frase pronta escrita e o anúncio preso a ela.
  function chegarDoAnuncio() {
    $('cta').classList.remove('pulsa');
    if (!primeira) return;
    vazio.hidden = true; ref.hidden = false; q.value = FRASE_DO_ANUNCIO;
    document.getElementById('cel').scrollIntoView({behavior: 'smooth', block: 'nearest'}); q.focus();
  }
  async function enviar(p) {
    p = p.trim(); if (!p || ocupado) return;
    const minha = rodada; ocupado = true; q.value = '';
    const comAnuncio = primeira; primeira = false; vazio.hidden = true; ref.hidden = true;
    bolhaLead(p, comAnuncio);
    await espera(600); if (minha !== rodada) return;
    digitando(true);
    const r = await post({acao: 'previa', id, sessao, pergunta: p, qualificacao: qual.checked});
    const fim = () => { digitando(false); ocupado = false; sug.hidden = false; };
    if (minha !== rodada) return;
    if (!r.ok) { fim(); sistema(r.erro || 'Não consegui pedir a resposta.', true); return; }
    if (!r.chamou) { fim(); sistema('O teste ainda não está ligado do lado da Bianca.', true); return; }
    for (let i = 0; i < 60; i++) {
      await espera(1500); if (minha !== rodada) return;
      const l = await post({acao: 'previa_ler', id, previa: r.id});
      if (!l.ok || l.status === 'pendente') continue;
      if (l.status === 'erro') { fim(); sistema(l.erro || 'Ela não conseguiu responder.', true); return; }
      document.querySelectorAll('#ficha tr').forEach(tr => tr.classList.toggle('usado', l.usados.includes(tr.dataset.chave)));
      const linhas = l.resposta.split(/\n+/).filter(x => x.trim());
      // Uma mensagem por linha, com o "digitando" entre elas, como chega no celular do lead.
      for (let k = 0; k < linhas.length; k++) {
        if (k) { digitando(true); await espera(Math.min(1800, 500 + linhas[k].length * 12)); if (minha !== rodada) return; }
        digitando(false);
        const usados = (l.usados_por_linha[k] || []).map(c => esc(rotulos[c] || c));
        let notas = usados.length ? `<span>usou: ${usados.join(', ')}</span>` : '';
        if (l.passou_por_linha[k]) notas += '<span class="clay">passa ao corretor</span>';
        if (k === linhas.length - 1 && l.numeros_tirados.length) notas += `<span class="clay" title="${esc(l.numeros_tirados.join(', '))}">fora da ficha, tirado: ${esc(l.numeros_tirados.join(', '))}</span>`;
        bolhaDela(linhas[k], notas);
      }
      fim(); return;
    }
    fim(); sistema('Ela demorou mais de 90 s. Tente de novo.', true);
  }
  $('cta').addEventListener('click', chegarDoAnuncio);
  $('perg').addEventListener('submit', e => { e.preventDefault(); if (primeira && !q.value.trim()) { chegarDoAnuncio(); return; } enviar(q.value); });
  sug.querySelectorAll('[data-p]').forEach(b => b.addEventListener('click', () => enviar(b.dataset.p)));
  function recomecar() {
    rodada++; sessao = novaSessao(); primeira = true; ocupado = false; q.value = '';
    zap.querySelectorAll('.imc-m,.imc-sis.erro').forEach(n => n.remove());
    digitando(false); vazio.hidden = false; ref.hidden = true; sug.hidden = true;
    document.querySelectorAll('#ficha tr').forEach(tr => tr.classList.remove('usado'));
  }
  $('recomecar').addEventListener('click', recomecar);
  // Trocar a qualificação recomeça a conversa: o histórico de um jeito confundiria a resposta do outro.
  const qual = $('qual');
  function mostrarQualificacao() {
    $('qualEstado').textContent = qual.checked ? 'ligada' : 'desligada';
    $('qualPill').hidden = qual.checked;
    $('qualTexto').textContent = qual.checked
      ? 'Ligada, ela segue o roteiro da Camargo, como com o lead de verdade: responde e pergunta tipo, bairro, pronto ou planta, entrada. Desligue para só tirar as dúvidas do imóvel.'
      : 'Desligada, ela só responde as dúvidas do imóvel, sem as perguntas do roteiro. Vale só neste teste: com o lead de verdade a qualificação fica sempre ligada.';
  }
  qual.addEventListener('change', () => { recomecar(); mostrarQualificacao(); });
  mostrarQualificacao();
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
