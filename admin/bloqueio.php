<?php
/* admin/bloqueio.php — Lista de Bloqueio do portal (só admin).
   Números aqui são ignorados nas análises das ferramentas marcadas. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
require_once __DIR__ . '/../lib/blocklist.php';
$u = admin_guard();
$pdo = db();
$msg = ''; $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'add') {
            $raw = trim($_POST['phone'] ?? '');
            $motivo = trim($_POST['motivo'] ?? '');
            $canon = fone_canon($raw);
            if (strlen($canon) < 8) $erro = 'Número inválido (poucos dígitos).';
            else {
                $pdo->prepare('INSERT INTO blocklist (phone_raw, phone_canon, motivo, criado_por)
                    VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE phone_raw=VALUES(phone_raw), motivo=VALUES(motivo)')
                    ->execute([$raw, $canon, $motivo, (int)$u['id']]);
                $msg = 'Número adicionado à lista.';
            }
        } elseif ($acao === 'del') {
            $pdo->prepare('DELETE FROM blocklist WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $msg = 'Número removido.';
        } elseif ($acao === 'tools') {
            $marcadas = array_map('intval', $_POST['tool'] ?? []);
            $pdo->exec('UPDATE tools SET usa_blocklist=0');
            if ($marcadas) {
                $in = implode(',', array_fill(0, count($marcadas), '?'));
                $pdo->prepare("UPDATE tools SET usa_blocklist=1 WHERE id IN ($in)")->execute($marcadas);
            }
            $msg = 'Ferramentas que respeitam a lista atualizadas.';
        } elseif ($acao === 'semear') {
            require_once __DIR__ . '/../lib/ghl.php';
            $data = ghl_api_get('/users/?locationId=' . urlencode(GHL_LOCATION));
            $ins = $pdo->prepare('INSERT IGNORE INTO blocklist (phone_raw, phone_canon, motivo, criado_por) VALUES (?,?,?,?)');
            $n = 0;
            foreach (($data['users'] ?? []) as $usr) {
                $ph = trim((string)($usr['phone'] ?? ''));
                if ($ph === '') continue;
                $canon = fone_canon($ph);
                if (strlen($canon) < 8) continue;
                $ins->execute([$ph, $canon, 'corretor: ' . (string)($usr['name'] ?? ''), (int)$u['id']]);
                if ($ins->rowCount() > 0) $n++;
            }
            $msg = "Semeados $n telefone(s) de corretores do GHL (os que já existiam foram mantidos).";
        }
    } catch (Throwable $e) { $erro = 'Erro: ' . $e->getMessage(); }
}

$lista = $pdo->query('SELECT id, phone_raw, phone_canon, motivo, criado_em FROM blocklist ORDER BY criado_em DESC, id DESC')->fetchAll();
$tools = $pdo->query('SELECT id, nome, slug, usa_blocklist FROM tools WHERE ativo=1 ORDER BY ordem, nome')->fetchAll();

admin_header('bloqueio', $u);
?>
<h1 class="home-titulo">Lista de Bloqueio</h1>
<p class="home-sub">Números aqui são <b>ignorados nas análises</b> — a conversa inteira daquele contato some das métricas (mensagens, conversas, tempo de resposta, fila). Serve para tirar conversas internas entre corretores, pessoais ou de teste.</p>
<?php if($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>

<div class="card">
  <h3 style="font-size:14px;margin:0 0 8px">Ferramentas que respeitam a lista</h3>
  <form method="post" style="margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="tools">
    <div style="display:flex;gap:16px;flex-wrap:wrap">
      <?php foreach($tools as $t): ?>
        <label class="perm"><input type="checkbox" name="tool[]" value="<?= (int)$t['id'] ?>" <?= $t['usa_blocklist']?'checked':'' ?>> <?= h($t['nome']) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit" style="margin-top:10px">Salvar</button>
  </form>
</div>

<div class="card eq-novo" style="flex-wrap:wrap">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="add">
    <input name="phone" placeholder="Telefone (qualquer formato)" required style="min-width:220px">
    <input name="motivo" placeholder="Motivo (opcional)" style="min-width:220px">
    <button class="btn" type="submit">+ Bloquear número</button>
  </form>
  <form method="post" style="margin:0" onsubmit="return confirm('Puxar os telefones dos corretores do GHL e adicionar à lista?')">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="semear">
    <button class="btn" type="submit" style="background:var(--clay)">Semear corretores (GHL)</button>
  </form>
</div>

<div class="card">
  <table class="grid">
    <thead><tr><th>Telefone</th><th>Canônico</th><th>Motivo</th><th>Adicionado</th><th></th></tr></thead>
    <tbody>
      <?php if(!$lista): ?><tr><td colspan="5" class="mut">Lista vazia.</td></tr><?php endif; ?>
      <?php foreach($lista as $b): ?>
      <tr>
        <td><?= h($b['phone_raw']) ?></td>
        <td class="mut"><?= h($b['phone_canon']) ?></td>
        <td class="mut"><?= h($b['motivo'] ?: '—') ?></td>
        <td class="mut"><?= h(date('d/m/Y', strtotime($b['criado_em']))) ?></td>
        <td><form method="post" style="margin:0" onsubmit="return confirm('Remover este número da lista?')">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="del"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
          <button class="linkbtn" type="submit">remover</button></form></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="home-sub" style="margin-top:8px"><?= count($lista) ?> número(s) na lista. Após mudar a lista, os painéis recalculam na próxima coleta (o Painel dos Corretores atualiza de hora em hora).</p>
</div>
<?php portal_footer();
