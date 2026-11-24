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
        } elseif ($op === 'drive_chave') {
            if (!is_admin($u)) throw new RuntimeException('Só administradores podem trocar a chave de acesso ao Google Drive.');
            $f = $_FILES['chave'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione o arquivo .json da chave (o que o Google Cloud baixou para o seu computador) e envie de novo.');
            if ((int)$f['size'] > 20000) throw new RuntimeException('Arquivo grande demais para ser a chave da conta de serviço (ela tem uns 2 KB). Confira se escolheu o .json certo.');
            $email = CfDrive::gravarChave((string)file_get_contents($f['tmp_name']));
            $msg = 'Chave gravada. Conta do portal no Drive: ' . $email . '. Compartilhe a pasta das capas com esse e-mail como Editor e clique em "Testar acesso".';
        } elseif ($op === 'drive_pasta') {
            $v = trim((string)($_POST['pasta'] ?? ''));
            if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $v, $mm)) $v = $mm[1];
            if ($v !== '' && !preg_match('/^[A-Za-z0-9_-]{10,80}$/', $v)) throw new RuntimeException('Não reconheci a pasta. Cole o link da pasta do Drive (https://drive.google.com/drive/folders/…) ou só o código dela.');
            cf_config_set('drive_pasta', $v); $msg = 'Pasta do Drive salva.';
        } elseif ($op === 'drive_extras') {
            $ex = [];
            foreach (preg_split('/\r?\n/', (string)($_POST['extras'] ?? '')) ?: [] as $ln) {
                $ln = trim($ln); if ($ln === '') continue;
                [$pst, $ano] = array_pad(array_map('trim', explode(';', $ln, 2)), 2, '');
                if (preg_match('~/folders/([A-Za-z0-9_-]{10,})~', $pst, $mm)) $pst = $mm[1];
                if (!preg_match('/^[A-Za-z0-9_-]{10,80}$/', $pst)) throw new RuntimeException('Não reconheci a pasta "' . $ln . '". Use uma linha por pasta: o link (ou o código) e, se quiser, "; 2026" para trazer só as capas com contas a partir desse ano.');
                if ($ano !== '' && !preg_match('/^20\d\d$/', $ano)) throw new RuntimeException('Ano inválido em "' . $ln . '". Depois do ponto e vírgula vai só o ano, ex.: "; 2026".');
                $ex[] = ['id' => $pst, 'desde' => $ano !== '' ? $ano . '-01-01' : null];
            }
            cf_config_set('drive_extras', $ex); $msg = count($ex) . ' pasta(s) extra(s) salva(s). Lembre de compartilhar cada uma com a conta do portal.';
        } elseif ($op === 'drive_teste') {
            $l = CfDrive::listar();
            $nEx = count(array_filter($l['arquivos'], fn($a) => !empty($a['extra'])));
            $msg = 'Acesso ao Drive funcionando: pasta "' . $l['pasta'] . '" com ' . (count($l['arquivos']) - $nEx) . ' arquivo(s) .xlsx' . ($nEx ? ' e ' . $nEx . ' nas pastas de anos anteriores' : '') . '.' . (!empty($l['avisos']) ? ' ATENÇÃO: ' . implode(' | ', $l['avisos']) : '');
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
            cf_config_set('omie_pix_modo', in_array($_POST['omie_pix_modo'] ?? '', ['TRA', 'PIX', 'NENHUM'], true) ? $_POST['omie_pix_modo'] : 'TRA');
            $msg = 'Empresas salvas.';
        } elseif ($op === 'vendas_pag') {
            if (!is_admin($u)) throw new RuntimeException('Só administradores alteram quem libera pagamentos e os feriados.');
            $fer = [];
            foreach (preg_split('/\r?\n/', (string)($_POST['feriados'] ?? '')) ?: [] as $ln) {
                $ln = trim($ln); if ($ln === '') continue;
                if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})\s*(?:[=\-–]\s*(.*))?$#u', $ln, $m) || !checkdate((int)$m[2], (int)$m[1], (int)$m[3])) throw new RuntimeException("Feriado inválido: \"$ln\" (use dd/mm/aaaa = nome)");
                $fer["$m[3]-$m[2]-$m[1]"] = trim((string)($m[4] ?? '')) ?: 'feriado';
            }
            ksort($fer);
            cf_config_set('feriados', $fer);
            $lib = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtolower(trim($x)), preg_split('/[\s,;]+/', (string)($_POST['liberadores'] ?? '')) ?: []))));
            cf_config_set('cv_liberadores', $lib);
            $msg = 'Feriados e liberadores salvos.';
        } elseif ($op === 'vendas_feriados_padrao') {
            if (!is_admin($u)) throw new RuntimeException('Só administradores alteram os feriados.');
            require_once __DIR__ . '/vendas/lib/Pagamentos.php';
            $fer = (array)cf_config('feriados', []);
            $fer += Pagamentos::feriadosPadrao((int)date('Y'), (int)date('Y') + 4);
            ksort($fer);
            cf_config_set('feriados', $fer);
            $msg = 'Feriados nacionais e de Joinville dos próximos anos incluídos.';
        }
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$cat = cf_config('categorias', []); $nf = cf_config('nf_dict', []); $emp = cf_config('empresas', []);
$paraTexto = fn(array $m) => implode("\n", array_map(fn($k, $v) => "$k = $v", array_keys($m), $m));

portal_header('Configurações — Capa Financeira', $u);
?>
<?php cf_cabecalho('Configurações', 'Dicionários usados na extração e na exportação. Os nomes de categoria e conta corrente têm que ser exatamente como estão no Omie.', [['Capa Financeira', '/capa-financeira/'], ['Configurações', null]], 'config'); ?>
<?php if ($msg): ?><div class="ok-box" data-cf-modal="ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="erro" data-cf-modal="erro"><?= h($erro) ?></div><?php endif; ?>

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
  <h2>Google Drive — pasta das capas</h2>
  <p class="cf-dica">Permite escolher as capas direto da pasta do Drive na tela de Capas. O portal entra com uma conta própria (conta de serviço), que só enxerga a pasta compartilhada com ela.</p>
  <?php $drvEmail = CfDrive::email(); $drvPasta = (string)cf_config('drive_pasta', ''); ?>
  <p><b>Situação:</b> <?= $drvEmail ? '<span class="cf-flag cf-ok">chave configurada</span> conta <code>' . h($drvEmail) . '</code>' : '<span class="cf-flag cf-leve">sem chave</span> envie abaixo o .json da conta de serviço' ?></p>
  <?php if (is_admin($u)): ?>
  <form method="post" enctype="multipart/form-data" class="cf-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="drive_chave">
    <label>Chave da conta de serviço (.json baixado do Google Cloud) <input type="file" name="chave" accept=".json,application/json" required></label>
    <button class="btn cf-btn-sec"><?= $drvEmail ? 'Trocar a chave' : 'Enviar a chave' ?></button>
    <span class="cf-dica">A chave fica só no servidor, fora do GitHub. Depois de enviar, pode apagar o arquivo do seu computador.</span></form>
  <?php else: ?><p class="cf-dica">Só administradores trocam a chave.</p><?php endif; ?>
  <form method="post" class="cf-form" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="drive_pasta">
    <label>Pasta das capas (link ou código) <input type="text" name="pasta" value="<?= h($drvPasta) ?>" placeholder="<?= h(CfDrive::PASTA_PADRAO) ?> (padrão: CAPAS CORRIGIDAS PRA SUBIR NO SISTEMA)" style="width:520px;max-width:100%"></label>
    <button class="btn cf-btn-sec">Salvar pasta</button></form>
  <?php $drvEx = cf_config('drive_extras'); if (!is_array($drvEx)) $drvEx = CfDrive::EXTRAS_PADRAO; ?>
  <form method="post" class="cf-form" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="drive_extras">
    <label>Pastas de anos anteriores (uma por linha: link ou código; opcional "; ano" para trazer só as capas com contas a pagar/receber a partir desse ano)
      <textarea name="extras" rows="3" style="width:100%"><?= h(implode("\n", array_map(fn($e) => $e['id'] . (!empty($e['desde']) ? ' ; ' . substr((string)$e['desde'], 0, 4) : ''), $drvEx))) ?></textarea></label>
    <button class="btn cf-btn-sec">Salvar pastas extras</button>
    <span class="cf-dica">Cada pasta precisa estar compartilhada com a conta do portal (leitor basta; editor se quiser o "ok - " automático).</span></form>
  <?php if ($drvEmail): ?><form method="post" class="cf-form" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="drive_teste"><button class="btn">Testar acesso</button></form><?php endif; ?>
</section>

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
    <label>Chave Pix no envio pela API (contas a pagar)
      <?php $pm = (string)cf_config('omie_pix_modo', 'TRA'); ?>
      <select name="omie_pix_modo">
        <option value="TRA" <?= $pm === 'TRA' ? 'selected' : '' ?>>Transferência bancária → finalidade "Transferência por chave PIX" (como no lançamento manual)</option>
        <option value="PIX" <?= $pm === 'PIX' ? 'selected' : '' ?>>Pagamento PIX QR-Code com a chave no campo do QR Code (como a importação por planilha faz)</option>
        <option value="NENHUM" <?= $pm === 'NENHUM' ? 'selected' : '' ?>>Não preencher forma de pagamento (a chave vai só na observação)</option>
      </select></label>
  </div>
  <button class="btn">Salvar empresas</button>
  </form>
</section>

<section class="card" id="vendas">
  <h2>Vendas — pagamentos aos corretores</h2>
  <?php $adm = is_admin($u); $ferCfg = (array)cf_config('feriados', []); $libCfg = (array)cf_config('cv_liberadores', []); ?>
  <p class="cf-dica">Feriados: se a quinta-feira de pagamento cair num feriado, o pagamento vai para a quarta. Quem libera pagamentos: administradores + os logins abaixo (financeiro).</p>
  <form method="post" class="cf-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="vendas_pag">
  <div class="cf-duas">
    <label>Feriados (um por linha: <code>dd/mm/aaaa = nome</code>)
      <textarea name="feriados" rows="14" <?= $adm ? '' : 'readonly' ?>><?= h(implode("\n", array_map(fn($d, $n) => implode('/', array_reverse(explode('-', $d))) . ' = ' . $n, array_keys($ferCfg), $ferCfg))) ?></textarea></label>
    <label>Logins que liberam pagamentos (um por linha ou separados por vírgula)
      <textarea name="liberadores" rows="6" <?= $adm ? '' : 'readonly' ?>><?= h(implode("\n", $libCfg)) ?></textarea></label>
  </div>
  <?php if ($adm): ?><button class="btn">Salvar feriados e liberadores</button><?php else: ?><p class="cf-dica">Só administradores alteram esta parte.</p><?php endif; ?>
  </form>
  <?php if ($adm): ?><form method="post" style="margin-top:6px"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="vendas_feriados_padrao">
    <button class="btn cf-btn-sec">Incluir feriados nacionais + Joinville dos próximos 5 anos</button></form><?php endif; ?>
</section>
<?php portal_footer();
