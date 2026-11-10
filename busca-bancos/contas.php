<?php
/* busca-bancos/contas.php — contas bancárias (empresa, apelido, conta do Omie), logos dos bancos e nomes próprios. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';
$u = require_tool('busca-bancos');
$pdo = db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = (string)($_POST['acao'] ?? '');
    if ($acao === 'contas') {
        $up = $pdo->prepare('UPDATE bb_contas SET empresa = ?, apelido = ?, banco = ?, omie_cc = ? WHERE id = ?');
        foreach ((array)($_POST['c'] ?? []) as $id => $c) {
            $emp = array_key_exists($c['empresa'] ?? '', BbImportador::EMPRESAS) ? $c['empresa'] : '';
            $banco = array_key_exists($c['banco'] ?? '', BbOfx::BANCOS) ? $c['banco'] : 'outro';
            $cc = preg_replace('/\D+/', '', (string)($c['omie_cc'] ?? ''));
            $up->execute([$emp, mb_substr(trim((string)($c['apelido'] ?? '')), 0, 80) ?: 'Conta ' . (int)$id, $banco, $cc !== '' ? $cc : null, (int)$id]);
        }
        $msg = 'Contas salvas.';
    } elseif ($acao === 'logo') {
        $b = (string)($_POST['banco'] ?? ''); $fl = $_FILES['logo'] ?? null;
        $tipos = ['image/png' => 1, 'image/jpeg' => 1, 'image/webp' => 1, 'image/svg+xml' => 1, 'image/gif' => 1];
        if (!array_key_exists($b, BbOfx::BANCOS)) $msg = 'Banco inválido.';
        elseif (!empty($_POST['remover'])) { $pdo->prepare('DELETE FROM bb_config WHERE chave = ?')->execute(['logo:' . $b]); $msg = 'Logo removida.'; }
        elseif (!$fl || $fl['error'] !== UPLOAD_ERR_OK) $msg = 'Escolha o arquivo da logo.';
        elseif ($fl['size'] > 300 * 1024) $msg = 'A logo precisa ter até 300 KB.';
        else {
            $tipo = (string)(new finfo(FILEINFO_MIME_TYPE))->file($fl['tmp_name']);
            if ($tipo === 'text/plain' || $tipo === 'text/xml' || $tipo === 'text/html') { if (preg_match('/<svg[\s>]/i', (string)file_get_contents($fl['tmp_name'], false, null, 0, 2000))) $tipo = 'image/svg+xml'; }
            if (!isset($tipos[$tipo])) $msg = 'Formato não aceito. Use PNG, JPG, WEBP ou SVG.';
            else { BbImportador::configSet($pdo, 'logo:' . $b, ['tipo' => $tipo, 'dados' => base64_encode((string)file_get_contents($fl['tmp_name']))]); $msg = 'Logo do ' . BbOfx::BANCOS[$b] . ' salva.'; }
        }
    } elseif ($acao === 'proprios') {
        $lista = array_values(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', (string)($_POST['proprios'] ?? '')))));
        BbImportador::configSet($pdo, 'proprios', $lista);
        $n = BbImportador::refazerInternas($pdo);
        $msg = "Lista salva. $n transferência(s) entre contas próprias identificada(s).";
    }
}

$contas = BbCobertura::contas($pdo);
$proprios = (array)BbImportador::config($pdo, 'proprios', BbImportador::PROPRIOS_PADRAO);
$logos = []; foreach ($pdo->query("SELECT chave FROM bb_config WHERE chave LIKE 'logo:%'") as $r) $logos[substr($r['chave'], 5)] = 1;
// contas correntes do Omie (para ligar cada conta ao cruzamento) — só quando pedido, para não gastar chamadas
$omieCcs = [];
if (!empty($_GET['omie']) && OmieApi::disponivel()) foreach (array_keys(OmieApi::contas()) as $emp) {
    try { $r = OmieApi::call($emp, 'geral/contacorrente/', 'ListarContasCorrentes', ['pagina' => 1, 'registros_por_pagina' => 100, 'apenas_importado_api' => 'N']);
        foreach ($r['ListarContasCorrentes'] ?? [] as $cc) $omieCcs[$emp][] = ['id' => (string)$cc['nCodCC'], 'nome' => (string)($cc['descricao'] ?? ''), 'banco' => (string)($cc['codigo_banco'] ?? ''), 'conta' => (string)($cc['numero_conta_corrente'] ?? '')];
    } catch (Throwable $e) { $omieCcs[$emp] = ['erro' => $e->getMessage()]; }
}
bb_cabecalho('contas', $u);
?>
<?php if ($msg): ?><div class="bb-alerta ok"><?= h($msg) ?></div><?php endif; ?>
<section class="bb-card">
  <h2>Contas bancárias</h2>
  <p>As contas aparecem sozinhas quando um extrato é importado. Aqui você diz de qual empresa cada uma é e dá um apelido fácil de reconhecer.</p>
  <?php if (!$contas): ?><p>Nenhuma conta ainda. <a href="/busca-bancos/importar.php">Importe um extrato</a>.</p><?php else: ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="contas">
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Banco</th><th>Agência / conta no extrato</th><th>Empresa</th><th>Apelido</th><th>Conta corrente no Omie (código)</th><th class="num">Lançamentos</th></tr></thead><tbody>
    <?php foreach ($contas as $id => $c): ?>
      <tr<?= $c['empresa'] === '' ? ' style="background:#fff6df"' : '' ?>>
        <td><?= bb_banco($c['banco']) ?> <select name="c[<?= $id ?>][banco]" class="bb-campo" aria-label="Banco"><?php foreach (BbOfx::BANCOS as $k => $t): ?><option value="<?= $k ?>"<?= $c['banco'] === $k ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></td>
        <td class="mono"><?= h(($c['agencia'] !== '' ? $c['agencia'] . ' / ' : '') . $c['conta']) ?></td>
        <td><select name="c[<?= $id ?>][empresa]" class="bb-campo" aria-label="Empresa"><option value="">— escolher —</option><?php foreach (BbImportador::EMPRESAS as $k => $t): ?><option value="<?= $k ?>"<?= $c['empresa'] === $k ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></td>
        <td><input class="bb-campo" type="text" name="c[<?= $id ?>][apelido]" value="<?= h($c['apelido']) ?>" size="30" maxlength="80" aria-label="Apelido"></td>
        <td><?php $lista = $omieCcs[$c['empresa']] ?? null; if (is_array($lista) && !isset($lista['erro'])): ?>
            <select name="c[<?= $id ?>][omie_cc]" class="bb-campo" aria-label="Conta no Omie"><option value="">— não ligar —</option><?php foreach ($lista as $cc): ?><option value="<?= h($cc['id']) ?>"<?= (string)$c['omie_cc'] === $cc['id'] ? ' selected' : '' ?>><?= h($cc['nome'] . ($cc['conta'] !== '' ? ' · ' . $cc['conta'] : '')) ?></option><?php endforeach; ?></select>
          <?php else: ?><input class="bb-campo" type="text" inputmode="numeric" name="c[<?= $id ?>][omie_cc]" value="<?= h((string)$c['omie_cc']) ?>" size="14" aria-label="Código da conta no Omie"><?php if (isset($lista['erro'])): ?><br><small><?= h($lista['erro']) ?></small><?php endif; endif; ?></td>
        <td class="num"><?= (int)$c['n_lanc'] ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <div class="bb-det-acoes"><button class="bb-btn" type="submit">Salvar contas</button>
    <?php if (OmieApi::disponivel() && empty($_GET['omie'])): ?><a class="bb-btn sec" href="?omie=1">Carregar a lista de contas do Omie</a><?php elseif (!OmieApi::disponivel()): ?><small>API do Omie não configurada no portal: o cruzamento com o Omie fica indisponível.</small><?php endif; ?></div>
  </form><?php endif; ?>
</section>

<section class="bb-card">
  <h2>Logos dos bancos</h2>
  <p>Envie o arquivo oficial da logo de cada banco (PNG, JPG, WEBP ou SVG, até 300 KB). Sem logo, aparece um selo com as iniciais.</p>
  <div class="bb-tab-wrap"><table class="bb-tab"><tbody>
  <?php foreach (BbOfx::BANCOS as $k => $t): if ($k === 'outro') continue; ?>
    <tr><td style="width:220px"><?= bb_banco($k, true) ?></td><td>
      <form method="post" enctype="multipart/form-data" class="bb-linha-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="logo"><input type="hidden" name="banco" value="<?= $k ?>">
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" aria-label="Logo do <?= h($t) ?>"><button class="bb-btn sec" type="submit">Enviar</button>
        <?php if (isset($logos[$k])): ?><button class="bb-btn perigo" type="submit" name="remover" value="1" formnovalidate>Remover</button><?php endif; ?></form></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>

<section class="bb-card">
  <h2>Transferências entre contas próprias</h2>
  <p>Quando sai um valor de uma conta e entra o mesmo valor em outra (até 3 dias depois), e a descrição de um dos lados contém um destes trechos, os dois são marcados como transferência interna e saem dos totais. Um trecho por linha; acentos e maiúsculas não importam.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="proprios">
    <textarea name="proprios" rows="6" class="bb-campo" style="width:100%;max-width:520px;display:block" aria-label="Trechos que indicam a própria empresa"><?= h(implode("\n", $proprios)) ?></textarea>
    <div class="bb-det-acoes"><button class="bb-btn" type="submit">Salvar e refazer as marcações</button></div></form>
</section>
<?php bb_rodape();
