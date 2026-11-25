<?php
/* construtor/dados.php — (SÓ ADMIN) carrega/substitui o JSON de dados de
   uma página. Os dados ficam no banco; o arquivo nunca entra no git. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
$u = require_tool('area-construtor');
if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }
$pdo = db(); $msg = ''; $erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_session_start();
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { $erro = 'Sessão expirada. Recarregue.'; }
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
$pags = $pdo->query('SELECT slug,nome,atualizado_em,LENGTH(dados) AS tam FROM co_paginas ORDER BY ordem,nome')->fetchAll();
portal_header('Área do Construtor — dados', $u);
?>
<h1 class="home-titulo">Dados das páginas de construtora</h1>
<?php if ($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>
<div class="card">
<table class="grid"><thead><tr><th>Página</th><th>Slug</th><th>Última carga</th><th>Tamanho</th><th></th></tr></thead><tbody>
<?php foreach ($pags as $p): ?>
  <tr><td><?= h($p['nome']) ?></td><td class="mut"><?= h($p['slug']) ?></td>
  <td><?= $p['atualizado_em'] ? h(date('d/m/Y H:i', strtotime($p['atualizado_em']))) : '<span class="zero">sem dados</span>' ?></td>
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
<?php portal_footer();
