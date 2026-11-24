<?php
/* busca-bancos/boletos.php — créditos de boleto (Sicoob: "CRÉD.LIQUIDAÇÃO COBRANÇA", sem nome) com mais de um título possível
   no Omie: a pessoa escolhe qual título foi pago, e o nome do cliente passa a valer na busca. */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/Conferencia.php';
$u = require_tool('busca-bancos');
$pdo = db();
$msg = ''; $erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $auto = BbOmie::escolherTitulo($pdo, (int)($_POST['lanc'] ?? 0), (int)($_POST['titulo'] ?? 0));
        header('Location: /busca-bancos/boletos.php?ok=' . ((int)($_POST['titulo'] ?? 0) ? 1 : 2) . '&auto=' . $auto . '#lista'); exit;
    } catch (RuntimeException $e) { $erro = $e->getMessage(); }
}
if (isset($_GET['ok'])) $msg = ($_GET['ok'] === '2' ? 'Marcado como "nenhum destes": o crédito saiu da lista.' : 'Crédito ligado ao cliente.')
    . ((int)($_GET['auto'] ?? 0) ? ' Com isso, mais ' . (int)$_GET['auto'] . ' crédito(s) ficaram com uma opção só e foram ligados automaticamente.' : '');
$pend = $pdo->query('SELECT p.*, l.data, l.valor, l.memo, l.checknum, c.banco, c.apelido FROM bb_omie_pend p JOIN bb_lanc l ON l.id = p.lanc_id JOIN bb_contas c ON c.id = l.conta_id ORDER BY l.data DESC, l.valor DESC')->fetchAll();
bb_cabecalho('boletos', $u);
?>
<section class="bb-card" id="lista">
  <h2>Quem pagou estes boletos?</h2>
  <p>O extrato do banco só diz "CRÉD.LIQUIDAÇÃO COBRANÇA", sem nome. Para cada crédito abaixo, o Omie tem <b>mais de um título recebido</b> com o mesmo valor e data parecida, na mesma conta — por isso a ferramenta não ligou sozinha. Escolha qual título é deste crédito (confira no Omie, no histórico do título, ou no comprovante do banco). Quando sobrar uma opção só para outro crédito, ele é ligado automaticamente.</p>
  <?php if ($msg): ?><div class="bb-alerta ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="bb-alerta erro"><?= h($erro) ?></div><?php endif; ?>
  <?php if (!$pend): ?><p><span class="bb-tag ok">Nenhum crédito esperando escolha.</span> Os créditos de boleto são ligados ao clicar em "Atualizar do Omie", em <a href="/busca-bancos/importar.php#bb-omie-cash">Importar extratos</a>.</p>
  <?php else: foreach ($pend as $p): $cands = json_decode((string)$p['candidatos'], true) ?: []; ?>
    <div class="bb-bol">
      <div class="bb-bol-cab"><?= bb_banco($p['banco']) ?> <b><?= h($p['apelido']) ?></b> · <?= BbTexto::dataBr($p['data']) ?> · <b class="ent"><?= BbTexto::brl((float)$p['valor']) ?></b> · <span class="bb-memo"><?= h($p['memo']) ?><?= $p['checknum'] !== '' ? ' · nº ' . h($p['checknum']) : '' ?></span></div>
      <ul><?php foreach ($cands as $c): ?>
        <li><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="lanc" value="<?= (int)$p['lanc_id'] ?>"><input type="hidden" name="titulo" value="<?= (int)$c['titulo'] ?>">
          <button class="bb-btn" type="submit">É este</button> <b><?= h($c['nome'] ?: '(cliente sem nome no Omie)') ?></b><?= $c['doc'] !== '' ? ' · ' . h(BbTexto::docFmt($c['doc'])) : '' ?> <span class="bb-memo"><?= h($c['info']) ?></span></form></li>
      <?php endforeach; ?>
        <li><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="lanc" value="<?= (int)$p['lanc_id'] ?>"><input type="hidden" name="titulo" value="0"><button class="bb-btn sec" type="submit">Nenhum destes</button></form></li></ul>
    </div>
  <?php endforeach; endif; ?>
</section>
<?php bb_rodape();
