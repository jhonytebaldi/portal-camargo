<?php
/* busca-bancos/importar.php — enviar novos .ofx, ver o histórico e ligar a sincronização com o Drive. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('busca-bancos');
$pdo = db();
const BB_PASTA_PADRAO = '1rJqfghetDnTix5VX3W-s0vOGMxbMbQEd';
$resultados = []; $erros = []; $ligadas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = (string)($_POST['acao'] ?? 'enviar');
    if ($acao === 'novo_token') { BbImportador::configSet($pdo, 'token_drive', bin2hex(random_bytes(24))); header('Location: /busca-bancos/importar.php?ok=token#drive'); exit; }
    if ($acao === 'pasta') { if (preg_match('~([A-Za-z0-9_-]{20,})~', (string)($_POST['pasta'] ?? ''), $mm)) BbImportador::configSet($pdo, 'drive_pasta', $mm[1]); header('Location: /busca-bancos/importar.php#drive'); exit; }
    if ($acao === 'omie_cash') {   // Omie Cash direto da API do Omie + liga as contas pelo cadastro do Omie
        require_once __DIR__ . '/lib/Conferencia.php'; require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';
        @set_time_limit(180); $porEmp = [];
        foreach (array_keys(BbImportador::EMPRESAS) as $emp) {
            if (!isset(OmieApi::contas()[$emp])) { $erros[] = 'Omie Cash da ' . h(BbImportador::EMPRESAS[$emp]) . ': a chave da API do Omie desta empresa não está configurada no portal.'; continue; }
            try { $porEmp[$emp] = BbOmie::contasCorrentes($emp); foreach (BbOmie::sincronizarCash($pdo, $emp, $porEmp[$emp], (int)$u['id']) as $r) $resultados[] = $r; }
            catch (Throwable $ex) { $erros[] = 'Omie Cash da ' . h(BbImportador::EMPRESAS[$emp]) . ': ' . h($ex->getMessage()); }
        }
        foreach (BbOmie::vincular($pdo, $porEmp) as $v) $ligadas[] = $v;
        BbImportador::configSet($pdo, 'omie_cash_ultima', date('Y-m-d H:i:s'));
    } elseif ($acao === 'pdf') {   // extratos da Caixa lidos do PDF no navegador
        foreach ((array)json_decode((string)($_POST['dados'] ?? ''), true) as $e) {
            $nome = (string)($e['arquivo'] ?? 'extrato.pdf');
            try { $resultados[] = BbImportador::importarCaixaPdf($pdo, (array)$e, ['nome' => $nome, 'usuario' => (int)$u['id']]) + ['arquivo' => $nome]; }
            catch (Throwable $ex) { $erros[] = h($nome) . ': ' . h($ex instanceof RuntimeException ? $ex->getMessage() : 'não foi possível importar') . '.'; if (!$ex instanceof RuntimeException) error_log('busca-bancos pdf: ' . $ex->getMessage()); }
        }
        if (!$resultados && !$erros) $erros[] = 'Nenhum extrato recebido.';
    } else {
    $ligarOmie = true;
    $drive = json_decode((string)($_POST['drive'] ?? ''), true) ?: [];   // [i => {id, caminho}] opcional, alinhado com arquivos[]
    $fs = $_FILES['arquivos'] ?? null; $empresa = array_key_exists($_POST['empresa'] ?? '', BbImportador::EMPRESAS) ? (string)$_POST['empresa'] : '';
    if (!$fs || !is_array($fs['name']) || ($fs['name'][0] ?? '') === '') $erros[] = 'Escolha pelo menos um arquivo .ofx.';
    else {
    // .zip (ex.: pasta baixada do Drive): abre e trata cada .ofx de dentro como um arquivo enviado, com a pasta de origem
    $entradas = []; $pdfsZip = 0;
    foreach ($fs['name'] as $i => $nome) {
        if ($fs['error'][$i] !== UPLOAD_ERR_OK) { $erros[] = h($nome) . ': o envio falhou (arquivo grande demais ou conexão interrompida).'; continue; }
        if (!preg_match('/\.zip$/i', (string)$nome)) { $entradas[] = ['nome' => (string)$nome, 'tmp' => $fs['tmp_name'][$i], 'drive' => $drive[$i] ?? null]; continue; }
        $z = new ZipArchive();
        if ($z->open($fs['tmp_name'][$i]) !== true) { $erros[] = h($nome) . ': não consegui abrir o .zip.'; continue; }
        $achou = 0;
        for ($k = 0; $k < $z->numFiles && $k < 2000; $k++) {
            $st = $z->statIndex($k); $cam = str_replace('\\', '/', (string)$st['name']);
            if (str_ends_with($cam, '/') || str_contains($cam, '__MACOSX/')) continue;
            if (preg_match('/\.pdf$/i', $cam)) { $pdfsZip++; continue; }
            if (!preg_match('/\.(ofx|qfx)$/i', $cam) || $st['size'] > 8 * 1024 * 1024) continue;
            $tmp = tempnam(sys_get_temp_dir(), 'bbz'); file_put_contents($tmp, (string)$z->getFromIndex($k));
            $nomeZip = mb_convert_encoding($cam, 'UTF-8', mb_check_encoding($cam, 'UTF-8') ? 'UTF-8' : 'CP437');
            $entradas[] = ['nome' => basename($nomeZip), 'tmp' => $tmp, 'zip' => true, 'drive' => ['caminho' => trim(dirname($nomeZip), './')]];
            $achou++;
        }
        $z->close();
        if (!$achou) $erros[] = h($nome) . ': o .zip não tem nenhum arquivo .ofx.';
    }
    if ($pdfsZip) $avisoZip = $pdfsZip . ' PDF(s) dentro do .zip foram ignorados: o extrato da Caixa em PDF entra pelo quadro "Extrato da Caixa em PDF".';
    foreach ($entradas as $i => $ent) { $nome = $ent['nome'];
        if (!preg_match('/\.(ofx|qfx)$/i', (string)$nome)) { $erros[] = h($nome) . ': não é um arquivo .ofx nem .zip. Para o extrato da Caixa em PDF use o quadro "Extrato da Caixa em PDF", logo abaixo.'; continue; }
        // arquivo vindo do Drive pelo navegador: guarda a pasta e o link (a pasta sugere a empresa de conta nova)
        $dm = is_array($ent['drive'] ?? null) ? $ent['drive'] : null;
        $meta = $dm && preg_match('/^[A-Za-z0-9_-]{20,}$/', (string)($dm['id'] ?? ''))
            ? ['origem' => 'drive', 'drive_id' => (string)$dm['id'], 'drive_caminho' => mb_substr(trim((string)($dm['caminho'] ?? '')), 0, 300) ?: null]
            : ['origem' => 'upload', 'drive_caminho' => $dm && trim((string)($dm['caminho'] ?? '')) !== '' ? mb_substr(trim((string)$dm['caminho']), 0, 300) : null];
        try { foreach (BbImportador::importarOfx($pdo, (string)file_get_contents($ent['tmp']), $meta + ['nome' => (string)$nome, 'usuario' => (int)$u['id'], 'empresa' => $empresa]) as $r) $resultados[] = $r + ['arquivo' => (($dm['caminho'] ?? '') !== '' ? $dm['caminho'] . '/' : '') . (string)$nome]; }
        catch (Throwable $e) { $erros[] = h(($dm['caminho'] ?? '') !== '' ? $dm['caminho'] . '/' . $nome : $nome) . ': ' . h($e instanceof RuntimeException ? $e->getMessage() : 'não foi possível ler o arquivo'); if (!$e instanceof RuntimeException) error_log('busca-bancos upload: ' . $e->getMessage()); }
        if (!empty($ent['zip'])) @unlink($ent['tmp']);
    }
    }
    }
}

$token = (string)BbImportador::config($pdo, 'token_drive', '');
if ($token === '') { $token = bin2hex(random_bytes(24)); BbImportador::configSet($pdo, 'token_drive', $token); }
$pasta = (string)BbImportador::config($pdo, 'drive_pasta', BB_PASTA_PADRAO);
$ultima = BbImportador::config($pdo, 'drive_ultima');
$host = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'portal.imobcamargo.com.br');
$envios = $pdo->query('SELECT e.*, c.banco, c.apelido, us.nome AS por FROM bb_envios e LEFT JOIN bb_contas c ON c.id = e.conta_id LEFT JOIN users us ON us.id = e.usuario ORDER BY e.id DESC LIMIT 100')->fetchAll();
$hist = $pdo->query('SELECT a.*, (SELECT 1 FROM bb_arquivo_bruto b WHERE b.arquivo_id = a.id) AS tem_bruto, c.banco, c.apelido, c.empresa, us.nome AS por FROM bb_arquivos a JOIN bb_contas c ON c.id = a.conta_id LEFT JOIN users us ON us.id = a.enviado_por ORDER BY a.id DESC LIMIT 300')->fetchAll();

$script = <<<JS
// Busca Bancos: manda para o portal os .ofx novos (ou alterados) da pasta do Drive.
// Como instalar: script.google.com > Novo projeto > cole tudo > escolha a função "instalar" > Executar > autorize.
const PORTAL = '{$host}/busca-bancos/api-importar.php';
const TOKEN  = '{$token}';
const PASTA  = '{$pasta}';

function instalar() {
  ScriptApp.getProjectTriggers().forEach(t => ScriptApp.deleteTrigger(t));
  ScriptApp.newTrigger('sincronizar').timeBased().everyMinutes(15).create();
  sincronizar();
}

function sincronizar() {
  varrer(DriveApp.getFolderById(PASTA), '', PropertiesService.getScriptProperties(), Date.now());
}

function varrer(pasta, caminho, props, inicio) {
  const arquivos = pasta.getFiles();
  while (arquivos.hasNext()) {
    if (Date.now() - inicio > 4.5 * 60 * 1000) return;            // continua na próxima rodada
    const f = arquivos.next();
    if (!/\\.ofx$/i.test(f.getName())) continue;
    const marca = String(f.getLastUpdated().getTime());
    if (props.getProperty(f.getId()) === marca) continue;           // já enviado
    const r = UrlFetchApp.fetch(PORTAL, {
      method: 'post', contentType: 'application/json', muteHttpExceptions: true,
      headers: {'X-Portal-Token': TOKEN},
      payload: JSON.stringify({nome: f.getName(), drive_id: f.getId(), caminho: caminho,
                               conteudo_b64: Utilities.base64Encode(f.getBlob().getBytes())})
    });
    const cod = r.getResponseCode();
    console.log((caminho + '/' + f.getName()) + ' -> ' + cod + ' ' + r.getContentText().slice(0, 300));
    if (cod === 200 || cod === 422) props.setProperty(f.getId(), marca);   // 422 = arquivo inválido: não insiste
  }
  const subpastas = pasta.getFolders();
  while (subpastas.hasNext()) {
    const s = subpastas.next();
    varrer(s, (caminho ? caminho + '/' : '') + s.getName(), props, inicio);
  }
}
JS;

bb_cabecalho('importar', $u);
?>
<?php foreach ($erros as $e): ?><div class="bb-alerta erro"><?= $e ?></div><?php endforeach; ?>
<?php if (!empty($avisoZip)): ?><div class="bb-alerta info"><?= h($avisoZip) ?></div><?php endif; ?>
<?php if ($resultados): $novos = array_sum(array_column($resultados, 'novos')); $dup = array_sum(array_column($resultados, 'dup')); ?>
  <div class="bb-alerta ok"><b><?= $novos ?> lançamento(s) novo(s)</b> entraram na busca; <?= $dup ?> já existiam e foram ignorados (sem duplicar).</div>
  <div class="bb-tab-wrap" style="margin-bottom:14px"><table class="bb-tab">
    <thead><tr><th>Arquivo</th><th>Conta</th><th>Empresa</th><th>Período do arquivo</th><th class="num">Novos</th><th class="num">Já existiam</th><th>Observações</th></tr></thead><tbody>
    <?php foreach ($resultados as $r): ?>
      <tr><td><?= h($r['arquivo']) ?></td><td><?= bb_banco($r['conta']['banco']) ?> <?= h($r['conta']['apelido']) ?><?= $r['conta_nova'] ? ' <span class="bb-tag amb">conta nova</span>' : '' ?></td><td><?= h(bb_empresa($r['conta']['empresa'])) ?></td>
        <td class="mono"><?= BbTexto::dataBr($r['de']) ?> a <?= BbTexto::dataBr($r['ate']) ?></td><td class="num"><?= $r['novos'] ?></td><td class="num"><?= $r['dup'] ?></td><td><small><?= h(implode(' ', $r['avisos'])) ?></small></td></tr>
    <?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<section class="bb-card">
  <h2>Enviar extratos (.ofx)</h2>
  <p>Pode mandar vários de uma vez, de bancos e meses diferentes: o banco, a conta e o período são lidos de dentro de cada arquivo, e lançamentos repetidos não entram duas vezes. <b>Para trazer a pasta inteira do Drive:</b> no Drive, clique com o botão direito na pasta → <b>Fazer download</b>, e arraste aqui o .zip que baixar (se o Drive dividir em vários .zip, mande todos).</p>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="bb-solta"><b>Arraste os arquivos .ofx (ou o .zip da pasta baixada do Drive) para cá</b> ou escolha:<input type="file" name="arquivos[]" multiple accept=".ofx,.qfx,.zip" required><span data-nomes></span></div>
    <div class="bb-linha-form" style="margin-top:12px">
      <label>Se aparecer uma conta nova, ela é da empresa
        <select name="empresa" class="bb-campo"><option value="">perguntar depois (em Contas)</option><?php foreach (BbImportador::EMPRESAS as $k => $t): ?><option value="<?= $k ?>"><?= h($t) ?></option><?php endforeach; ?></select></label>
      <button class="bb-btn" type="submit">Importar</button>
    </div>
  </form>
</section>

<?php if (!empty($ligarOmie) && array_filter($resultados, fn($r) => $r['conta_nova'])) { require_once __DIR__ . '/lib/Conferencia.php'; try { $ligadas = array_merge($ligadas, BbOmie::vincularTudo($pdo)); } catch (Throwable $e) {} } ?>
<?php if ($ligadas): ?><div class="bb-alerta info"><b>Contas ligadas ao cadastro do Omie:</b><ul><?php foreach ($ligadas as $v): ?><li><?= h($v) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<section class="bb-card" id="bb-omie-cash">
  <h2>Omie Cash (direto do Omie, sem arquivo)</h2>
  <p>O Omie Cash não precisa de extrato: os lançamentos vêm da API do Omie das três empresas. Entram só os <b>conciliados</b> (o que de fato passou pela conta), com nome, CPF/CNPJ e número da transação Pix. Na primeira vez traz todo o histórico; depois, só os últimos dias.
    <?php $uo = BbImportador::config($pdo, 'omie_cash_ultima'); echo $uo ? 'Última atualização: <b>' . h(date('d/m/Y H:i', strtotime((string)$uo))) . '</b>.' : '<b>Ainda não foi atualizado nenhuma vez.</b>'; ?></p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="omie_cash"><button class="bb-btn" type="submit">Atualizar Omie Cash</button></form>
</section>

<section class="bb-card" id="bb-pdf">
  <h2>Extrato da Caixa em PDF</h2>
  <p>Para a Caixa, que só entrega o "Extrato por período" em PDF. O PDF é uma foto da tela, então a leitura é feita aqui no seu navegador (o arquivo não sai do computador) e <b>só é importado se a conta fechar</b>: saldo anterior ± cada lançamento tem que dar o saldo impresso em cada linha. Se um número for lido errado, o arquivo é recusado.</p>
  <div class="bb-solta"><b>Arraste os PDFs da Caixa para cá</b> ou escolha:<input type="file" multiple accept=".pdf,application/pdf"><span data-nomes></span></div>
  <div data-saida style="margin-top:12px"></div>
  <form method="post" data-envio hidden><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="pdf"><input type="hidden" name="dados" value=""></form>
  <p class="bb-nota" style="margin-top:10px">A sincronização automática do Drive só envia arquivos .ofx. Os PDFs da Caixa precisam passar por este quadro. Os outros bancos (Sicoob, Sicredi, Inter, Asaas) têm .ofx: prefira sempre o .ofx.</p>
</section>

<section class="bb-card" id="drive">
  <h2>Atualização automática pela pasta do Drive</h2>
  <p>Um pequeno script do Google confere a pasta a cada 15 minutos e manda para cá os .ofx novos, incluindo os das subpastas. A pasta (IMOBILIARIA / VERTICAL / MZ7) é usada para sugerir a empresa das contas novas.
     <?= $ultima ? 'Última sincronização recebida: <b>' . h(date('d/m/Y H:i', strtotime((string)$ultima))) . '</b>.' : '<b>Ainda não recebemos nenhuma sincronização</b> — instale o script abaixo (uma vez só).' ?></p>
  <?php if (($_GET['ok'] ?? '') === 'token'): ?><div class="bb-alerta">Código novo gerado. O script antigo parou de funcionar: copie o script de novo e substitua no Google.</div><?php endif; ?>
  <ol style="margin:0 0 10px 18px;padding:0;color:#5d6278">
    <li>Entre em <a href="https://script.google.com/home/projects/create" target="_blank" rel="noopener">script.google.com</a> com uma conta que enxergue a pasta e crie um projeto.</li>
    <li>Apague o que estiver lá, cole o script abaixo e salve.</li>
    <li>No topo, escolha a função <b>instalar</b>, clique em <b>Executar</b> e autorize. Pronto: a primeira carga já acontece na hora.</li>
  </ol>
  <button type="button" class="bb-btn sec" data-copiar="#bb-script">Copiar script</button>
  <pre class="bb-codigo" id="bb-script"><?= h($script) ?></pre>
  <div class="bb-linha-form">
    <form method="post" class="bb-linha-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="pasta">
      <label>Pasta do Drive (link ou código) <input class="bb-campo" type="text" name="pasta" value="<?= h($pasta) ?>" size="44"></label><button class="bb-btn sec" type="submit">Trocar pasta</button></form>
    <form method="post" onsubmit="return confirm('Gerar um código novo? O script instalado no Google para de funcionar até você colar o novo.')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="novo_token"><button class="bb-btn perigo" type="submit">Gerar código novo</button></form>
  </div>
</section>

<section class="bb-card">
  <h2>Todas as tentativas de envio</h2>
  <p>Cada vez que um arquivo .ofx é enviado (pelo portal ou pela pasta do Drive), inclusive quando ele já tinha sido importado ou deu erro. Registro a partir de 11/11/2026, 20h.</p>
  <?php if (!$envios): ?><p>Nenhuma ainda.</p><?php else: ?>
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Quando</th><th>Quem</th><th>Arquivo</th><th>Conta</th><th>Resultado</th><th class="num">Novos</th><th class="num">Já existiam</th><th class="num">Tempo</th></tr></thead><tbody>
    <?php foreach ($envios as $e): ?>
      <tr><td class="mono"><?= h(date('d/m/Y H:i:s', strtotime($e['inicio']))) ?></td><td><?= $e['origem'] === 'drive' ? 'Drive' : h($e['por'] ?? '—') ?></td><td><?= h($e['nome']) ?></td>
        <td><?= $e['banco'] ? bb_banco($e['banco']) . ' ' . h($e['apelido']) : '—' ?></td>
        <td><?= ['ok' => '<span class="bb-tag ok">importado</span>', 'repetido' => '<span class="bb-tag">já tinha sido importado</span>', 'erro' => '<span class="bb-tag nao">erro</span>'][$e['status']] ?? h($e['status']) ?><?= $e['erro'] ? ' <small>' . h($e['erro']) . '</small>' : '' ?></td>
        <td class="num"><?= (int)$e['novos'] ?></td><td class="num"><?= (int)$e['dup'] ?></td><td class="num"><?= h(number_format((float)$e['segundos'], 1, ',', '')) ?> s</td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>

<section class="bb-card">
  <h2>Arquivos já importados</h2>
  <?php if (!$hist): ?><p>Nenhum ainda.</p><?php else: ?>
  <div class="bb-tab-wrap"><table class="bb-tab">
    <thead><tr><th>Quando</th><th>Conta</th><th>Arquivo</th><th>Período</th><th class="num">Novos</th><th class="num">Repetidos</th><th>Origem</th></tr></thead><tbody>
    <?php foreach ($hist as $a): ?>
      <tr><td class="mono"><?= h(date('d/m/Y H:i', strtotime($a['enviado_em']))) ?></td><td><?= bb_banco($a['banco']) ?> <?= h($a['apelido']) ?></td>
        <td><?= h(($a['drive_caminho'] ? $a['drive_caminho'] . '/' : '') . $a['nome']) ?><?php if ($a['drive_id']): ?> <a href="https://drive.google.com/file/d/<?= h(rawurlencode($a['drive_id'])) ?>/view" target="_blank" rel="noopener">abrir</a><?php endif; ?><?php if (!empty($a['tem_bruto'])): ?> <a href="/busca-bancos/exportar-ofx.php?original=<?= (int)$a['id'] ?>">baixar</a><?php endif; ?></td>
        <td class="mono"><?php if ($a['periodo_ate'] < $a['periodo_de']): ?>nada fechado ainda<?php else: ?><?= BbTexto::dataBr($a['periodo_de']) ?> a <?= BbTexto::dataBr($a['periodo_ate']) ?><?php endif; ?>
          <?php if (!empty($a['periodo_declarado_ate']) && $a['periodo_declarado_ate'] > $a['periodo_ate']): ?><br><span class="bb-incompleto" title="O banco escreveu no arquivo que ele ia até <?= BbTexto::dataBr($a['periodo_declarado_ate']) ?>, mas ele foi gerado<?= $a['gerado_em'] ? ' em ' . BbTexto::dataBr($a['gerado_em']) : ' antes disso' ?>. Os dias depois disso não estão nele.">⚠ incompleto: dizia até <?= BbTexto::dataBr($a['periodo_declarado_ate']) ?></span><?php endif; ?></td><td class="num"><?= (int)$a['n_novos'] ?></td><td class="num"><?= (int)$a['n_dup'] ?></td>
        <td><?= ['upload' => 'enviado por ' . h($a['por'] ?? '—'), 'drive' => 'Drive', 'pdf' => 'PDF da Caixa', 'omie' => 'Omie (API)'][$a['origem']] ?? h($a['origem']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<script src="/busca-bancos/assets/ocr/tesseract.min.js?v=5.1.1"></script>
<script src="/busca-bancos/assets/pdf-caixa.js?v=<?= @filemtime(__DIR__ . '/assets/pdf-caixa.js') ?: 1 ?>"></script>
<?php bb_rodape();
