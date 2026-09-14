<?php
/* =====================================================================
   capa-financeira/index.php — lista das capas enviadas + envio de nova capa.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$empresas = cf_config('empresas', []);
$filtro = (string)($_GET['status'] ?? '');

$sql = "SELECT c.*, u.nome AS enviado_nome,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status <> 'removido') AS n_linhas,
          (SELECT COUNT(*) FROM cf_lancamentos l WHERE l.capa_id = c.id AND l.status = 'revisao' AND l.revisado = 0
              AND (l.flags LIKE '%GRAVE:%' OR l.flags LIKE '%PESSOA_NAO_IDENTIFICADA%')) AS n_pendencias
        FROM cf_capas c LEFT JOIN users u ON u.id = c.enviado_por";
$args = [];
if (in_array($filtro, ['revisao', 'confirmada', 'substituida', 'descartada'], true)) { $sql .= ' WHERE c.status = ?'; $args[] = $filtro; }
$sql .= ' ORDER BY c.id DESC LIMIT 200';
$st = $pdo->prepare($sql); $st->execute($args);
$capas = $st->fetchAll();
$nPessoas = (int)$pdo->query('SELECT COUNT(*) FROM cf_pessoas WHERE ativo = 1')->fetchColumn();

portal_header('Capa Financeira', $u);
?>
<style>main.wrap{max-width:1400px}</style>
<div class="cf-top">
  <div>
    <h1 class="home-titulo">Capa Financeira → Omie</h1>
    <p class="home-sub">Envie a capa da venda, revise os lançamentos, confirme. Depois gere as planilhas do Omie e os recibos.</p>
  </div>
  <nav class="cf-nav">
    <a href="/capa-financeira/pessoas.php">Pessoas (dicionário)</a>
    <a href="/capa-financeira/configuracoes.php">Configurações</a>
  </nav>
</div>

<?php if ($nPessoas === 0): ?>
<div class="aviso">O dicionário de pessoas está vazio. Cadastre a equipe em <a href="/capa-financeira/pessoas.php">Pessoas</a> (dá pra importar a planilha DADOS CORRETORES EQUIPE) — sem isso todo favorecido vai aparecer como "não identificado".</div>
<?php endif; ?>

<section class="card cf-upload">
  <h2>Enviar nova capa</h2>
  <form method="post" action="/capa-financeira/upload.php" enctype="multipart/form-data" class="cf-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <label>Empresa da importação
      <select name="empresa" required>
        <option value="">— escolha —</option>
        <?php foreach ($empresas as $e): ?>
          <option value="<?= h($e['id']) ?>"><?= h($e['nome']) ?> · <?= h($e['cnpj']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Arquivo da capa (.xlsx)
      <input type="file" name="capa" accept=".xlsx" required>
    </label>
    <button class="btn" type="submit">Enviar e revisar</button>
  </form>
  <p class="cf-dica">Reenviar a capa de uma venda já enviada (mesmo COD) cria uma nova versão e mostra o que mudou linha a linha.</p>
</section>

<div class="admin-tabs">
  <?php foreach (['' => 'Todas', 'revisao' => 'Em revisão', 'confirmada' => 'Confirmadas', 'substituida' => 'Substituídas', 'descartada' => 'Descartadas'] as $k => $n): ?>
    <a class="<?= $k === $filtro ? 'on' : '' ?>" href="/capa-financeira/?status=<?= $k ?>"><?= $n ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$capas): ?>
  <p class="home-sub">Nenhuma capa por aqui ainda.</p>
<?php else: ?>
<div class="cf-tbl-wrap">
<table class="grid cf-lista">
  <thead><tr><th>#</th><th>COD</th><th>Cliente</th><th>Construtora</th><th>Venda</th><th>Empresa</th><th>Linhas</th><th>A pagar</th><th>A receber</th><th>Status</th><th>Enviada</th></tr></thead>
  <tbody>
  <?php foreach ($capas as $c): $fl = json_decode((string)$c['capa_flags'], true) ?: []; ?>
    <tr>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['id'] ?></a><?= (int)$c['versao'] > 1 ? ' <span class="cf-tag">v' . (int)$c['versao'] . '</span>' : '' ?></td>
      <td><?= h((string)$c['cod']) ?: '<span class="cf-tag cf-grave">sem COD</span>' ?></td>
      <td><a href="/capa-financeira/revisar.php?id=<?= (int)$c['id'] ?>"><?= h($c['cliente']) ?></a></td>
      <td><?= h($c['construtora']) ?></td>
      <td><?= cf_data_br($c['data_venda']) ?></td>
      <td><?= h(strtoupper($c['empresa'])) ?></td>
      <td><?= (int)$c['n_linhas'] ?><?= (int)$c['n_pendencias'] ? ' <span class="cf-tag cf-grave" title="pendências graves">' . (int)$c['n_pendencias'] . ' ⚠</span>' : '' ?></td>
      <td><?= cf_brl($c['total_pagar']) ?></td>
      <td><?= cf_brl($c['total_receber']) ?></td>
      <td><span class="cf-status cf-st-<?= h($c['status']) ?>"><?= h($c['status']) ?></span><?= $fl ? ' <span class="cf-tag" title="' . h(implode(' | ', $fl)) . '">' . count($fl) . ' aviso(s)</span>' : '' ?></td>
      <td><?= h(substr((string)$c['criado_em'], 0, 16)) ?><br><small><?= h((string)$c['enviado_nome']) ?></small></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php portal_footer();
