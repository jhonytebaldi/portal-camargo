<?php
/* =====================================================================
   capa-financeira/upload.php — recebe a capa (.xlsx), extrai os
   lançamentos, cruza com o que já existe (duplicatas/alterações) e grava
   tudo em "revisão". Depois manda para revisar.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /capa-financeira/'); exit; }
csrf_check();
$pdo = db();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
/* ---- 2º passo: a pessoa viu os avisos (mesmo COD de uma venda do formulário) e decidiu ---- */
$tok = (string)($_POST['pendente'] ?? '');
if ($tok !== '') {
    $p = $_SESSION['cf_upload_pend'][$tok] ?? null;
    unset($_SESSION['cf_upload_pend'][$tok]);
    if (!$p || !is_file($p['destino'])) { header('Location: /capa-financeira/'); exit; }
    if (empty($_POST['forcar']) || empty($_POST['entendi'])) { @unlink($p['destino']); header('Location: /capa-financeira/'); exit; }   // desistiu
    require_once __DIR__ . '/_importar.php';
    try { $capaId = cf_importar_capa($p['destino'], $p['empresa'], $p['nome'], $u); }
    catch (Throwable $e) { @unlink($p['destino']); portal_header('Capa Financeira', $u); echo '<div class="erro">' . h($e->getMessage()) . '</div>'; portal_footer(); exit; }
    header('Location: /capa-financeira/revisar.php?id=' . $capaId); exit;
}

$empresa = (string)($_POST['empresa'] ?? '');
$empresas = array_column(cf_config('empresas', []), null, 'id');
if (!isset($empresas[$empresa])) { portal_header('Capa Financeira', $u); echo '<div class="erro">Escolha a empresa (Vertical ou Camargo).</div>'; portal_footer(); exit; }

$f = $_FILES['capa'] ?? null;
if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) { portal_header('Capa Financeira', $u); echo '<div class="erro">Envio falhou. Selecione um arquivo .xlsx.</div>'; portal_footer(); exit; }
$nomeOrig = (string)$f['name'];
if (!preg_match('/\.xlsx$/i', $nomeOrig)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Só arquivos .xlsx (a capa exportada do Excel/Google Sheets).</div>'; portal_footer(); exit; }

/* ---- guarda o arquivo fora da web ---- */
$dir = cf_data_dir() . '/capas/' . date('Y/m');
if (!is_dir($dir)) @mkdir($dir, 0750, true);
$seguro = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $nomeOrig) ?: 'capa.xlsx';
$destino = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '-' . $seguro;
if (!move_uploaded_file($f['tmp_name'], $destino)) { portal_header('Capa Financeira', $u); echo '<div class="erro">Não consegui gravar o arquivo no servidor.</div>'; portal_footer(); exit; }

/* ---- mesmo COD de uma venda feita pelo formulário? avisa (várias vezes) antes de importar ---- */
$vendasCod = [];
try {
    $codUp = cf_parser()->parse($destino)['capa']['cod'] ?? null;
    if ($codUp !== null && $codUp !== '') {
        $q = $pdo->prepare("SELECT v.id, v.construtora, v.capa_id, v.atualizado_em, (SELECT GROUP_CONCAT(c.nome ORDER BY c.ordem SEPARATOR ' E ') FROM cv_compradores c WHERE c.venda_id = v.id) AS comp
                            FROM cv_vendas v WHERE v.cod_imovel = ? AND v.status <> 'cancelada'");
        $q->execute([(string)$codUp]); $vendasCod = $q->fetchAll();
    }
} catch (Throwable $e) { $vendasCod = []; }   // planilha ilegível: o erro aparece na importação, logo abaixo
if ($vendasCod) {
    $tok = bin2hex(random_bytes(8));
    $_SESSION['cf_upload_pend'][$tok] = ['destino' => $destino, 'empresa' => $empresa, 'nome' => $nomeOrig];
    portal_header('Capa com o mesmo COD de uma venda', $u);
    cf_cabecalho('⚠ Esta capa tem o mesmo COD de uma venda do formulário', '', [['Capa Financeira', '/capa-financeira/'], ['Enviar capa', null]], 'capas');
    ?>
    <div class="erro" style="font-size:15px"><b>ATENÇÃO — a capa "<?= h($nomeOrig) ?>" (COD <?= h((string)$codUp) ?>) NÃO foi importada ainda.</b><br>
      Já existe venda cadastrada pelo <b>formulário</b> com este COD:
      <ul><?php foreach ($vendasCod as $vc): ?><li><a href="/capa-financeira/vendas/editar.php?id=<?= (int)$vc['id'] ?>" target="_blank">Venda #<?= (int)$vc['id'] ?> — <?= h((string)$vc['comp']) ?> × <?= h((string)$vc['construtora']) ?></a> (última edição <?= h(substr((string)$vc['atualizado_em'], 0, 16)) ?>)</li><?php endforeach; ?></ul>
      Se você enviar mesmo assim, esta planilha vira uma <b>nova versão da capa dessa venda</b>:
      <ul><li>o que está na planilha passa a valer no lugar do que foi preenchido no formulário;</li>
          <li>contas que já foram ao Omie e estiverem diferentes entram em "Alterações depois da exportação";</li>
          <li>na próxima vez que alguém salvar a venda no formulário, a capa volta a ser a do formulário.</li></ul>
      <b>O caminho certo quase sempre é alterar a venda no formulário, não subir a planilha.</b></div>
    <form method="post" action="/capa-financeira/upload.php" id="up-pend" class="card" style="padding:16px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="pendente" value="<?= h($tok) ?>"><input type="hidden" name="forcar" value="">
      <p><button type="submit" class="btn" style="font-size:16px;padding:12px 22px">Não enviar (voltar)</button>
         <a class="btn cf-btn-sec" href="/capa-financeira/vendas/editar.php?id=<?= (int)$vendasCod[0]['id'] ?>" target="_blank">Abrir a venda no formulário</a></p>
      <hr style="margin:16px 0">
      <label style="display:flex;gap:8px;font-size:14px"><input type="checkbox" name="entendi" value="1" id="up-entendi"> Li os avisos: sei que esta planilha vai substituir a capa da venda do formulário.</label>
      <div id="up-passo2" style="display:none;margin-top:12px" class="erro"><b>Tem certeza?</b> A venda #<?= (int)$vendasCod[0]['id'] ?> foi feita pelo formulário. Subir a planilha por cima costuma gerar divergência entre o formulário e o Omie.
        <p style="margin:10px 0 0"><button type="button" class="btn cf-btn-sec cf-btn-perigo" id="up-final">Ignorar e mandar mesmo assim</button></p></div>
      <p style="margin-top:12px"><button type="button" class="cf-x" id="up-quero" disabled>quero enviar mesmo assim…</button></p>
    </form>
    <script>
    (() => {
      const f = document.getElementById('up-pend'), chk = document.getElementById('up-entendi'), q = document.getElementById('up-quero');
      chk.onchange = () => { q.disabled = !chk.checked; if (!chk.checked) document.getElementById('up-passo2').style.display = 'none'; };
      q.onclick = () => document.getElementById('up-passo2').style.display = '';
      document.getElementById('up-final').onclick = () => {
        if (!confirm('ÚLTIMO AVISO: esta planilha vai virar a nova versão da capa de uma venda feita pelo formulário.\n\nIgnorar o aviso e importar mesmo assim?')) return;
        f.forcar.value = '1'; f.submit();
      };
    })();
    </script>
    <?php
    portal_footer(); exit;
}

/* ---- parse + gravação (mesma rotina do formulário de vendas) ---- */
require_once __DIR__ . '/_importar.php';
try { $capaId = cf_importar_capa($destino, $empresa, $nomeOrig, $u); }
catch (Throwable $e) {
    @unlink($destino);
    portal_header('Capa Financeira', $u);
    echo '<div class="erro">' . h($e->getMessage()) . '</div>';
    portal_footer(); exit;
}
header('Location: /capa-financeira/revisar.php?id=' . $capaId);
