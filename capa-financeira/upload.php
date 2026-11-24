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
    require_once __DIR__ . '/_importar.php';
    if (($p['tipo'] ?? '') === 'cod_cliente') {
        // COD igual ao de uma capa de OUTRO cliente: a pessoa escolhe se é a mesma venda (nova versão) ou outra venda (importa separada)
        $dec = (string)($_POST['decisao'] ?? '');
        if (!in_array($dec, ['versao', 'separada'], true)) { @unlink($p['destino']); header('Location: /capa-financeira/'); exit; }   // desistiu
        try { $capaId = $dec === 'versao' ? cf_importar_capa($p['destino'], $p['empresa'], $p['nome'], $u) : cf_importar_capa($p['destino'], $p['empresa'], $p['nome'], $u, null, false, [$p['flag']]); }
        catch (Throwable $e) { @unlink($p['destino']); portal_header('Capa Financeira', $u); echo '<div class="erro">' . h($e->getMessage()) . '</div>'; portal_footer(); exit; }
        header('Location: /capa-financeira/revisar.php?id=' . $capaId); exit;
    }
    if (empty($_POST['forcar']) || empty($_POST['entendi'])) { @unlink($p['destino']); header('Location: /capa-financeira/'); exit; }   // desistiu
    try { $capaId = cf_importar_capa($p['destino'], $p['empresa'], $p['nome'], $u); }
    catch (Throwable $e) { @unlink($p['destino']); portal_header('Capa Financeira', $u); echo '<div class="erro">' . h($e->getMessage()) . '</div>'; portal_footer(); exit; }
    header('Location: /capa-financeira/revisar.php?id=' . $capaId); exit;
}

$empresa = (string)($_POST['empresa'] ?? '');
$empresas = array_column(cf_config('empresas', []), null, 'id');
if (!isset($empresas[$empresa])) { portal_header('Capa Financeira', $u); echo '<div class="erro">Escolha a empresa (Vertical ou Camargo).</div>'; portal_footer(); exit; }

/* ---- vários arquivos de uma vez: importa os que não têm aviso e lista o resultado ---- */
$varios = [];
if (isset($_FILES['capas']) && is_array($_FILES['capas']['name'] ?? null)) {
    foreach ($_FILES['capas']['name'] as $i => $n) {
        if (($_FILES['capas']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $varios[] = ['name' => (string)$n, 'tmp_name' => (string)$_FILES['capas']['tmp_name'][$i], 'error' => (int)$_FILES['capas']['error'][$i]];
    }
}
if (count($varios) > 1) {
    require_once __DIR__ . '/_importar.php';
    @set_time_limit(110);
    $t0 = microtime(true); $res = [];
    $dir = cf_data_dir() . '/capas/' . date('Y/m');
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $temVenda = $pdo->prepare("SELECT COUNT(*) FROM cv_vendas WHERE cod_imovel = ? AND status <> 'cancelada'");
    $jaTem = $pdo->prepare("SELECT id, cliente FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada') ORDER BY id DESC LIMIT 1");
    foreach ($varios as $v) {
        $nome = $v['name'];
        if ((microtime(true) - $t0) > 25) { $res[] = ['nome' => $nome, 'st' => 'pulada', 'msg' => 'Não deu tempo de processar neste envio (o servidor limita a duração). Envie de novo só as que faltaram.']; continue; }
        if ($v['error'] !== UPLOAD_ERR_OK) { $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => 'O envio do arquivo falhou (código ' . $v['error'] . '). Tente enviar este arquivo de novo.']; continue; }
        if (!preg_match('/\.xlsx$/i', $nome)) { $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => 'Não é .xlsx. Exporte a capa do Excel/Google Sheets como .xlsx e envie de novo.']; continue; }
        $seguro = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $nome) ?: 'capa.xlsx';
        $destino = $dir . '/' . date('Ymd-His') . '-' . substr(bin2hex(random_bytes(3)), 0, 6) . '-' . $seguro;
        if (!move_uploaded_file($v['tmp_name'], $destino)) { $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => 'Não consegui gravar o arquivo no servidor. Tente de novo; se persistir, me avise.']; continue; }
        try {
            $capaUp = cf_parser()->parse($destino)['capa'];
            $codUp = $capaUp['cod'] ?? null;
            if ($codUp !== null && $codUp !== '') {
                $temVenda->execute([(string)$codUp]);
                if ((int)$temVenda->fetchColumn() > 0) { @unlink($destino); $res[] = ['nome' => $nome, 'st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' é de uma venda feita pelo formulário. Envie este arquivo sozinho para ver o aviso e decidir.']; continue; }
                $jaTem->execute([(string)$codUp]);
                if ($ex = $jaTem->fetch()) {
                    // já existe capa com este COD: no envio em lote a ordem dos arquivos é arbitrária (capa × aditivo), então a versão nova é decidida uma a uma
                    @unlink($destino);
                    $res[] = ['nome' => $nome, 'st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' já está na capa #' . (int)$ex['id'] . ' (' . $ex['cliente'] . '). Se for aditivo/nova versão, envie este arquivo sozinho, depois da capa original; se o COD estiver errado, corrija na planilha.'];
                    continue;
                }
                if ($conf = cf_cod_outro_cliente($pdo, $capaUp)) { @unlink($destino); $res[] = ['nome' => $nome, 'st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' já está na capa #' . (int)$conf['id'] . ' de outro cliente (' . $conf['cliente'] . '). Confira o COD na planilha ou envie este arquivo sozinho para decidir.']; continue; }
            }
            $id = cf_importar_capa($destino, $empresa, $nome, $u);
            $q = $pdo->prepare('SELECT versao, cliente, cod FROM cf_capas WHERE id = ?'); $q->execute([$id]); $c = $q->fetch();
            $res[] = ['nome' => $nome, 'st' => 'ok', 'id' => $id, 'msg' => ($c['cod'] ? 'COD ' . $c['cod'] : 'sem COD') . ' · ' . $c['cliente'] . ((int)$c['versao'] > 1 ? ' · versão ' . (int)$c['versao'] . ' (já havia capa com este COD)' : '')];
        } catch (Throwable $e) { @unlink($destino); $res[] = ['nome' => $nome, 'st' => 'erro', 'msg' => $e->getMessage() . ' Confira se o arquivo é uma capa no modelo e envie de novo.']; }
    }
    $nOk = count(array_filter($res, fn($r) => $r['st'] === 'ok'));
    portal_header('Capas enviadas', $u);
    cf_cabecalho('Envio de várias capas', $nOk . ' de ' . count($res) . ' importadas e em revisão. Nada foi confirmado nem enviado ao Omie.', [['Capa Financeira', '/capa-financeira/'], ['Enviar capas', null]], 'capas');
    $rot = ['ok' => ['cf-ok', 'importada'], 'sozinha' => ['cf-leve', 'enviar sozinha'], 'pulada' => ['cf-leve', 'não processada'], 'erro' => ['cf-grave', 'erro']];
    echo '<div class="cf-tbl-wrap"><table class="grid cf-tbl"><thead><tr><th>Arquivo</th><th>Situação</th><th>Detalhe</th></tr></thead><tbody>';
    foreach ($res as $r) echo '<tr><td>' . h($r['nome']) . '</td><td><span class="cf-flag ' . $rot[$r['st']][0] . '">' . $rot[$r['st']][1] . '</span></td><td>' . (isset($r['id']) ? '<a href="/capa-financeira/revisar.php?id=' . (int)$r['id'] . '">Capa #' . (int)$r['id'] . '</a> · ' : '') . h($r['msg']) . '</td></tr>';
    echo '</tbody></table></div><p style="margin-top:14px"><a class="btn" href="/capa-financeira/lote.php">Resolver alertas repetidos em lote</a> <a class="btn cf-btn-sec" href="/capa-financeira/?status=revisao">Ver capas em revisão</a></p>';
    portal_footer(); exit;
}

$f = $varios[0] ?? ($_FILES['capa'] ?? null);
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

/* ---- mesmo COD de uma capa de OUTRO cliente? (COD copiado de outra planilha) pergunta antes de virar "nova versão" ---- */
require_once __DIR__ . '/_importar.php';
$conf = null;
try { $capaUp = cf_parser()->parse($destino)['capa']; $conf = cf_cod_outro_cliente($pdo, $capaUp); } catch (Throwable $e) { $conf = null; }
if ($conf) {
    $tok = bin2hex(random_bytes(8));
    $_SESSION['cf_upload_pend'][$tok] = ['tipo' => 'cod_cliente', 'destino' => $destino, 'empresa' => $empresa, 'nome' => $nomeOrig,
        'flag' => 'GRAVE:COD_REPETIDO_OUTRO_CLIENTE (COD ' . $capaUp['cod'] . ' também está na capa #' . (int)$conf['id'] . ' — ' . $conf['cliente'] . ')'];
    portal_header('COD repetido em outra venda', $u);
    cf_cabecalho('⚠ Este COD já está em uma capa de outro cliente', '', [['Capa Financeira', '/capa-financeira/'], ['Enviar capa', null]], 'capas');
    ?>
    <div class="erro" style="font-size:15px"><b>A capa "<?= h($nomeOrig) ?>" NÃO foi importada ainda.</b><br>
      Ela está com o COD <b><?= h((string)$capaUp['cod']) ?></b>, que já é o da <a href="/capa-financeira/revisar.php?id=<?= (int)$conf['id'] ?>" target="_blank">capa #<?= (int)$conf['id'] ?></a> (<?= h((string)$conf['status']) ?>, enviada em <?= h(substr((string)$conf['criado_em'], 0, 10)) ?>):
      <ul><li>Capa que já existe: <b><?= h((string)$conf['cliente']) ?></b> × <?= h((string)$conf['construtora']) ?></li>
          <li>Capa que você está enviando: <b><?= h((string)$capaUp['cliente']) ?></b> × <?= h((string)$capaUp['construtora']) ?></li></ul>
      <b>Causa mais comum:</b> o COD ficou copiado de outra planilha. Nesse caso o certo é voltar, corrigir o COD no arquivo e enviar de novo.</div>
    <form method="post" action="/capa-financeira/upload.php" class="card" style="padding:16px">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="pendente" value="<?= h($tok) ?>">
      <p><button type="submit" name="decisao" value="" class="btn" style="font-size:16px;padding:12px 22px">Não enviar (vou corrigir o COD)</button></p>
      <hr style="margin:16px 0">
      <p><button type="submit" name="decisao" value="separada" class="btn cf-btn-sec">É outra venda — importar separada</button>
         <span class="cf-dica">Entra como capa nova, sem substituir a #<?= (int)$conf['id'] ?>, com um aviso grave pedindo para corrigir o COD na revisão.</span></p>
      <p><button type="submit" name="decisao" value="versao" class="btn cf-btn-sec cf-btn-perigo" onclick="return confirm('Importar como NOVA VERSÃO da capa #<?= (int)$conf['id'] ?>? As linhas que não estiverem nesta planilha saem, e o que já foi ao Omie entra em alterações depois da exportação.')">É a mesma venda — importar como nova versão</button>
         <span class="cf-dica">Use só quando o comprador mudou de verdade (ex.: aditivo trocando o titular). Substitui a capa #<?= (int)$conf['id'] ?>.</span></p>
    </form>
    <?php
    portal_footer(); exit;
}

/* ---- parse + gravação (mesma rotina do formulário de vendas) ---- */
try { $capaId = cf_importar_capa($destino, $empresa, $nomeOrig, $u); }
catch (Throwable $e) {
    @unlink($destino);
    portal_header('Capa Financeira', $u);
    echo '<div class="erro">' . h($e->getMessage()) . '</div>';
    portal_footer(); exit;
}
header('Location: /capa-financeira/revisar.php?id=' . $capaId);
