<?php
/* =====================================================================
   capa-financeira/configuracoes.php — dicionários do módulo:
   categorias (função → categoria Omie), condições (→ código ≤20 na
   Nota Fiscal), empresas (Vertical/Camargo) e opções.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$msg = ''; $erro = '';

/** "chave = valor" por linha → array. */
function cf_pares(string $txt): array
{
    $out = [];
    foreach (preg_split('/\r?\n/', $txt) ?: [] as $ln) {
        if (!str_contains($ln, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $ln, 2));
        if ($k !== '' && $v !== '') $out[CapaParser::key($k)] = CapaParser::norm($v);
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $op = (string)($_POST['op'] ?? '');
        if ($op === 'categorias') {
            $m = cf_pares((string)($_POST['texto'] ?? ''));
            foreach (['CORRETOR', 'BONUS', 'RECEBER_NOVO', 'RECEBER_USADO', 'RECEBER_BONUS'] as $ob) if (!isset($m[$ob])) throw new RuntimeException("Faltou a chave $ob");
            cf_config_set('categorias', $m); $msg = 'Categorias salvas.';
        } elseif ($op === 'nf_dict') {
            $m = cf_pares((string)($_POST['texto'] ?? ''));
            foreach ($m as $k => $v) if (mb_strlen($v, 'UTF-8') > 20) throw new RuntimeException("\"$v\" passa de 20 caracteres");
            cf_config_set('nf_dict', $m); $msg = 'Condições salvas.';
        } elseif ($op === 'empresas') {
            $emp = cf_config('empresas', []);
            foreach ($emp as &$e) {
                $id = $e['id'];
                $e['nome'] = CapaParser::norm((string)($_POST["nome_$id"] ?? $e['nome']));
                $e['razao'] = CapaParser::norm((string)($_POST["razao_$id"] ?? $e['razao']));
                $e['cnpj'] = CapaParser::norm((string)($_POST["cnpj_$id"] ?? $e['cnpj']));
                $e['conta_padrao'] = CapaParser::norm((string)($_POST["conta_$id"] ?? ''));
                $e['conta_padrao_cp'] = CapaParser::norm((string)($_POST["contacp_$id"] ?? ''));
                $e['projeto_omie'] = !empty($_POST["projeto_$id"]) ? 1 : 0;
                $e['logo_propria'] = !empty($_POST["logo_$id"]) ? 1 : 0;
                $e['endereco'] = CapaParser::norm((string)($_POST["end_$id"] ?? ($e['endereco'] ?? '')));
            }
            unset($e);
            cf_config_set('empresas', $emp);
            cf_config_set('corretor_baixa_recibo', !empty($_POST['corretor_baixa_recibo']) ? '1' : '0');
            cf_config_set('cidade_recibo', CapaParser::norm((string)($_POST['cidade_recibo'] ?? 'Joinville')));
            $msg = 'Empresas salvas.';
        }
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$cat = cf_config('categorias', []); $nf = cf_config('nf_dict', []); $emp = cf_config('empresas', []);
$paraTexto = fn(array $m) => implode("\n", array_map(fn($k, $v) => "$k = $v", array_keys($m), $m));

portal_header('Configurações — Capa Financeira', $u);
?>
<div class="cf-top">
  <div><h1 class="home-titulo">Configurações</h1><p class="home-sub">Dicionários usados na extração e na exportação. Os nomes de categoria e conta corrente têm que ser exatamente como estão no Omie.</p></div>
  <nav class="cf-nav"><a href="/capa-financeira/">← Capas</a><a href="/capa-financeira/pessoas.php">Pessoas</a></nav>
</div>
<?php if ($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>

<div class="cf-duas">
<section class="card">
  <h2>Função → categoria do Omie</h2>
  <p class="cf-dica">Uma por linha: <code>FUNÇÃO = Categoria no Omie</code>. Chaves especiais: <code>BONUS</code> (natureza bônus), <code>RECEBER_NOVO</code>, <code>RECEBER_USADO</code>, <code>RECEBER_BONUS</code>.</p>
  <form method="post" class="cf-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="categorias">
    <textarea name="texto" rows="14"><?= h($paraTexto($cat)) ?></textarea>
    <button class="btn">Salvar categorias</button></form>
</section>
<section class="card">
  <h2>Condições → código na Nota Fiscal (≤ 20)</h2>
  <p class="cf-dica">Texto da coluna G / prefixo do histórico → código curto. Condição fora da lista entra como está (cortada em 20) e gera aviso.</p>
  <form method="post" class="cf-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="nf_dict">
    <textarea name="texto" rows="14"><?= h($paraTexto($nf)) ?></textarea>
    <button class="btn">Salvar condições</button></form>
</section>
</div>

<section class="card">
  <h2>Empresas e recibo</h2>
  <form method="post" class="cf-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="empresas">
  <div class="cf-duas">
  <?php foreach ($emp as $e): $id = $e['id']; ?>
    <fieldset class="cf-fieldset"><legend><?= h(strtoupper($id)) ?></legend>
      <label>Nome<input name="nome_<?= $id ?>" value="<?= h($e['nome']) ?>"></label>
      <label>Razão social (no recibo)<input name="razao_<?= $id ?>" value="<?= h($e['razao'] ?? '') ?>"></label>
      <label>CNPJ<input name="cnpj_<?= $id ?>" value="<?= h($e['cnpj']) ?>"></label>
      <label>Conta corrente padrão das contas a receber (nome exato no Omie)<input name="conta_<?= $id ?>" value="<?= h($e['conta_padrao'] ?? '') ?>"></label>
      <label>Conta corrente padrão das contas a pagar (nome exato no Omie; a conta da pessoa, se preenchida, vence)<input name="contacp_<?= $id ?>" value="<?= h($e['conta_padrao_cp'] ?? '') ?>"></label>
      <label class="cf-mini"><input type="checkbox" name="projeto_<?= $id ?>" <?= !empty($e['projeto_omie']) ? 'checked' : '' ?>> preencher a coluna Projeto do Omie com a construtora (só marque se os projetos existem no Omie com esse nome)</label>
      <label>Endereço (rodapé do recibo, opcional)<input name="end_<?= $id ?>" value="<?= h($e['endereco'] ?? '') ?>"></label>
      <label class="cf-mini"><input type="checkbox" name="logo_<?= $id ?>" <?= !empty($e['logo_propria']) ? 'checked' : '' ?>> usar logo/dados próprios no recibo (senão usa a marca Camargo)</label>
    </fieldset>
  <?php endforeach; ?>
  </div>
  <div class="cf-grid2">
    <label>Cidade no recibo<input name="cidade_recibo" value="<?= h((string)cf_config('cidade_recibo', 'Joinville')) ?>"></label>
    <label class="cf-mini"><input type="checkbox" name="corretor_baixa_recibo" <?= cf_config('corretor_baixa_recibo', '1') === '1' ? 'checked' : '' ?>> corretor pode baixar o próprio recibo</label>
  </div>
  <button class="btn">Salvar empresas</button>
  </form>
</section>
<?php portal_footer();
