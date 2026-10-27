<?php
/* =====================================================================
   imoveis-campanha/integracao.php: "Ligação com a Bianca" (só admin).

   A ficha de cada imóvel mora no banco da Bianca. Este módulo fala com ele
   por uma chave própria, que a Bianca gera e manda ao admin. Aqui o admin
   cola a chave, o módulo TESTA na hora (lê a lista de imóveis) e só grava
   se abrir. Fica cifrada no banco do portal, com a mesma cifra das chaves
   do Listas 3C (ver listas-3c/lib/Integracoes.php), e nunca volta para o
   navegador. O repositório do portal é público: chave no código, nunca.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('imoveis-campanha');
if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }

$ok = null; $erro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $chave = trim((string)($_POST['chave'] ?? ''));
    if ($chave === '') $erro = 'Cole a chave.';
    else {
        try {
            portal_load_config();
            $n = count((array)(new ImcBianca($chave, defined('IMC_BIANCA_URL') ? (string)IMC_BIANCA_URL : ImcBianca::URL_PADRAO))->rpc('camargo_portal_imoveis'));
            ImcBianca::salvarChave($chave);
            $ok = "Chave testada e salva: $n imóveis na Bianca.";
        } catch (Throwable $e) { $erro = 'Não salvei: ' . imc_erro_legivel($e); }
    }
}
$atual = ImcBianca::chaveSalva();
$funciona = null;
if ($atual) { try { $funciona = count((array)ImcBianca::doConfig()->rpc('camargo_portal_imoveis')); } catch (Throwable $e) { $funciona = imc_erro_legivel($e); } }

portal_header('Ligação com a Bianca · Imóveis da campanha', $u);
imc_estilo();
?>
<p class="imc-bc"><a href="./">Imóveis da campanha</a> / Ligação com a Bianca</p>
<?php if ($ok): ?><div class="imc-ok"><?= h($ok) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="imc-err"><?= h($erro) ?></div><?php endif; ?>
<form method="post" class="imc-box" style="max-width:640px">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <div class="imc-h" style="font-size:17px">Chave da Bianca</div>
  <?php if (is_int($funciona)): ?><span class="imc-tag">Funcionando · <?= $funciona ?> imóveis · <?= h(L3cIntegracoes::mascara($atual)) ?></span>
  <?php elseif ($funciona): ?><span class="imc-tag clay"><?= h((string)$funciona) ?></span>
  <?php else: ?><span class="imc-tag cinza">Ainda sem chave</span><?php endif; ?>
  <label class="imc-f" style="margin-top:14px">Chave<input type="password" name="chave" autocomplete="new-password" placeholder="<?= $atual ? 'deixe vazio para manter a atual' : 'cole a chave que a Bianca mandou' ?>"></label>
  <div class="imc-rodape"><span class="imc-mini" title="Testa lendo a lista de imóveis antes de gravar">Testa antes de salvar</span><button class="imc-btn">Testar e salvar</button></div>
</form>
<?php
portal_footer();
