<?php
/* construtor/dados.php — (SÓ ADMIN) carrega/substitui o JSON de dados de
   uma página. Os dados ficam no banco; o arquivo nunca entra no git. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('area-construtor');
if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }
$pdo = db(); $msg = ''; $erro = '';
$chaveNova = ''; $linkNovo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_session_start();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { $erro = 'Sessão expirada. Recarregue.'; }
    elseif (($_POST['acao'] ?? '') === 'link') {
        $slugL = (string)($_POST['slug'] ?? ''); $dias = max(1, min(90, (int)($_POST['dias'] ?? 7)));
        if (!co_slug_ok($slugL)) $erro = 'Página inválida.';
        else {
            $tokL = bin2hex(random_bytes(24));
            $pdo->prepare('INSERT INTO co_links (token_hash,slug,rotulo,expira_em,criado_em,criado_por) VALUES (?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY),NOW(),?)')
                ->execute([hash('sha256', $tokL), $slugL, mb_substr(trim((string)($_POST['rotulo'] ?? '')), 0, 120), $dias, $u['id']]);
            $linkNovo = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'portal.imobcamargo.com.br') . '/construtor/link.php?t=' . $tokL;
            $msg = "Link criado, válido por $dias dia(s). Copie agora: por segurança ele não aparece de novo.";
        }
    }
    elseif (($_POST['acao'] ?? '') === 'revogar') {
        $pdo->prepare('UPDATE co_links SET revogado=1 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
        $msg = 'Link cancelado.';
    }
    elseif (($_POST['acao'] ?? '') === 'chave') {
        $chaveNova = bin2hex(random_bytes(24));
        $pdo->prepare("REPLACE INTO co_config (k,v,atualizado_em) VALUES ('api_token_hash',?,NOW())")->execute([hash('sha256', $chaveNova)]);
        $msg = 'Chave de serviço gerada. Ela aparece só agora: copie e guarde na tarefa agendada. Gerar outra invalida esta.';
    }
    else {
        $slug = (string)($_POST['slug'] ?? '');
        $txt = '';
        if (!empty($_FILES['arq']['tmp_name']) && is_uploaded_file($_FILES['arq']['tmp_name'])) $txt = (string)file_get_contents($_FILES['arq']['tmp_name']);
        elseif (!empty($_POST['json'])) $txt = (string)$_POST['json'];
        $d = json_decode($txt, true);
        if (!co_slug_ok($slug)) $erro = 'Página inválida.';
        elseif (!is_array($d) || !isset($d['C'], $d['M'])) $erro = 'Arquivo inválido: esperado o JSON do painel (com C e M).';
        else {
            $st = $pdo->prepare('UPDATE co_paginas SET dados=?, atualizado_em=NOW(), atualizado_por=? WHERE slug=?');
            $st->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $u['id'], $slug]);
            $msg = $st->rowCount() ? 'Dados carregados: ' . count($d['C']) . ' contratos.' : 'Página não encontrada.';
        }
    }
}
$pags = $pdo->query('SELECT slug,nome,atualizado_em,LENGTH(dados) AS tam,pedido_em FROM co_paginas ORDER BY ordem,nome')->fetchAll();
$temChave = (bool)$pdo->query("SELECT COUNT(*) FROM co_config WHERE k='api_token_hash'")->fetchColumn();
portal_header('Área do Construtor — dados', $u);
?>
<h1 class="home-titulo">Dados das páginas de construtora</h1>
<?php if ($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>
<div class="card">
<table class="grid"><thead><tr><th>Página</th><th>Slug</th><th>Última carga</th><th>Pedido de atualização</th><th>Tamanho</th><th></th></tr></thead><tbody>
<?php foreach ($pags as $p): ?>
  <tr><td><?= h($p['nome']) ?></td><td class="mut"><?= h($p['slug']) ?></td>
  <td><?= $p['atualizado_em'] ? h(date('d/m/Y H:i', strtotime($p['atualizado_em']))) : '<span class="zero">sem dados</span>' ?></td>
  <td><?= $p['pedido_em'] ? h(date('d/m H:i', strtotime($p['pedido_em']))) : '—' ?></td>
  <td class="mut"><?= $p['tam'] ? number_format((int)$p['tam'] / 1024, 0, ',', '.') . ' KB' : '—' ?></td>
  <td><a href="ver.php?p=<?= h($p['slug']) ?>">abrir</a></td></tr>
<?php endforeach; ?>
</tbody></table>
</div>
<div class="card">
  <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0">
    <input type="hidden" name="csrf" value="<?= h(co_csrf()) ?>">
    <label style="font-size:13px">Página<br><select name="slug"><?php foreach ($pags as $p): ?><option value="<?= h($p['slug']) ?>"><?= h($p['nome']) ?></option><?php endforeach; ?></select></label>
    <label style="font-size:13px">Arquivo JSON<br><input type="file" name="arq" id="arq" accept=".json,application/json"></label>
    <button class="btn" type="submit">Carregar (substitui os dados atuais)</button>
  </form>
  <p class="mut" style="margin-top:8px">Atenção: substituir os dados apaga distratos marcados pelo painel que não estejam no arquivo novo.</p>
</div>
<div class="card">
  <h3 style="font-size:14px;margin:0 0 8px">Links de visualização (sem login, só leitura)</h3>
  <?php if ($linkNovo): ?><input readonly id="linkNovo" onclick="this.select()" value="<?= h($linkNovo) ?>" style="width:100%;font:13px monospace;margin-bottom:10px"><?php endif; ?>
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0 0 12px">
    <input type="hidden" name="csrf" value="<?= h(co_csrf()) ?>"><input type="hidden" name="acao" value="link">
    <label style="font-size:13px">Página<br><select name="slug"><?php foreach ($pags as $p): ?><option value="<?= h($p['slug']) ?>"><?= h($p['nome']) ?></option><?php endforeach; ?></select></label>
    <label style="font-size:13px">Para quem (opcional)<br><input name="rotulo" placeholder="ex.: aprovação diretoria" style="min-width:200px"></label>
    <label style="font-size:13px">Validade<br><select name="dias"><option value="1">1 dia</option><option value="3">3 dias</option><option value="7" selected>7 dias</option><option value="15">15 dias</option><option value="30">30 dias</option></select></label>
    <button class="btn" type="submit">Gerar link</button>
  </form>
  <?php $links = $pdo->query('SELECT id,slug,rotulo,expira_em,criado_em,acessos,ultimo_acesso FROM co_links WHERE revogado=0 AND expira_em>NOW() ORDER BY criado_em DESC')->fetchAll(); ?>
  <?php if ($links): ?>
  <table class="grid"><thead><tr><th>Página</th><th>Para quem</th><th>Criado</th><th>Expira</th><th>Acessos</th><th></th></tr></thead><tbody>
  <?php foreach ($links as $l): ?>
    <tr><td><?= h($l['slug']) ?></td><td><?= h($l['rotulo'] ?: '—') ?></td><td class="mut"><?= h(date('d/m H:i', strtotime($l['criado_em']))) ?></td><td><?= h(date('d/m/Y H:i', strtotime($l['expira_em']))) ?></td>
      <td><?= (int)$l['acessos'] ?><?= $l['ultimo_acesso'] ? ' <span class="mut">(último ' . h(date('d/m H:i', strtotime($l['ultimo_acesso']))) . ')</span>' : '' ?></td>
      <td><form method="post" style="margin:0" onsubmit="return confirm('Cancelar este link? Quem tiver o link perde o acesso na hora.')"><input type="hidden" name="csrf" value="<?= h(co_csrf()) ?>"><input type="hidden" name="acao" value="revogar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button class="btn" type="submit" style="background:var(--clay)">Cancelar</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="mut" style="margin:0">Nenhum link ativo.</p><?php endif; ?>
</div>
<div class="card">
  <h3 style="font-size:14px;margin:0 0 8px">Chave de serviço (atualização automática)</h3>
  <?php if ($chaveNova): ?><input readonly id="chaveNova" onclick="this.select()" value="<?= h($chaveNova) ?>" style="width:100%;font:13px monospace;margin-bottom:8px"><?php endif; ?>
  <form method="post" style="margin:0" onsubmit="return <?= $temChave ? "confirm('Gerar uma nova chave invalida a atual. Continuar?')" : 'true' ?>">
    <input type="hidden" name="csrf" value="<?= h(co_csrf()) ?>"><input type="hidden" name="acao" value="chave">
    <button class="btn" type="submit"><?= $temChave ? 'Gerar nova chave' : 'Gerar chave de serviço' ?></button>
    <span class="mut"><?= $temChave ? 'Já existe uma chave ativa.' : 'Nenhuma chave gerada ainda.' ?></span>
  </form>
</div>
<?php portal_footer();
