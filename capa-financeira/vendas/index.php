<?php
/* =====================================================================
   capa-financeira/vendas/index.php — lista das vendas preenchidas pelo
   formulário (em etapas), com busca. "Nova venda" abre o formulário.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
$pdo = db();
$q = trim((string)($_GET['q'] ?? ''));
$st = (string)($_GET['st'] ?? '');

$where = []; $args = [];
if ($st === 'cancelada') $where[] = "v.status = 'cancelada'";
elseif ($st === 'concluida') $where[] = "v.status = 'concluida'";
elseif ($st === 'todas') { /* tudo */ }
else $where[] = "v.status <> 'cancelada'";
if ($q !== '') {
    $doc = Venda::docLimpo($q);
    $like = '%' . $q . '%';
    $w = "(v.construtora LIKE ? OR v.endereco LIKE ? OR v.bairro LIKE ? OR v.unidade LIKE ? OR v.cod_imovel = ? OR v.id = ? OR CONVERT(v.e2_dados USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ?
           OR EXISTS (SELECT 1 FROM cv_compradores c WHERE c.venda_id = v.id AND (c.nome LIKE ? OR c.email LIKE ?";
    array_push($args, $like, $like, $like, $like, preg_replace('/\D+/', '', $q), (int)preg_replace('/\D+/', '', $q), $like, $like, $like);
    if (strlen($doc) >= 5 && preg_match('/\d/', $doc)) { $w .= ' OR c.documento LIKE ?'; $args[] = '%' . $doc . '%'; }
    $w .= ')))';
    $where[] = $w;
}
$sql = "SELECT v.*, ua.nome AS atualizado_nome,
          (SELECT GROUP_CONCAT(c.nome ORDER BY c.ordem SEPARATOR ' E ') FROM cv_compradores c WHERE c.venda_id = v.id) AS compradores
        FROM cv_vendas v LEFT JOIN users ua ON ua.id = v.atualizado_por"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY v.atualizado_em DESC, v.id DESC LIMIT 300';
$s = $pdo->prepare($sql); $s->execute($args);
$vendas = $s->fetchAll();

portal_header('Vendas — capa em etapas', $u);
?>
<style>main.wrap{max-width:1400px}.cv-etapas{display:flex;gap:4px;flex-wrap:wrap}.cv-etapas .cf-status{font-size:10.5px;padding:1px 6px}
.cv-busca{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.cv-busca input[type=search]{flex:1;min-width:260px;padding:8px 10px;font:inherit;border:2px solid var(--line);border-radius:6px}
.cv-busca select{padding:8px;border:2px solid var(--line);border-radius:6px;font:inherit}</style>
<?php cf_cabecalho('Vendas (formulário da capa)', 'Preencha a capa da venda em etapas — dados da venda, distribuição, fluxo do cliente e pagamentos. Tudo fica salvo e com histórico.', [['Capa Financeira', '/capa-financeira/'], ['Vendas', null]], 'vendas'); ?>

<div class="cf-barra">
  <form class="cv-busca" method="get">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar por comprador, CPF/CNPJ, e-mail, corretor, construtora, endereço, unidade, COD ou nº da venda" autofocus>
    <select name="st">
      <?php foreach (['' => 'Em andamento + concluídas', 'concluida' => 'Concluídas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'] as $k => $n): ?>
        <option value="<?= $k ?>"<?= $k === $st ? ' selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Buscar</button>
  </form>
  <div class="cf-acoes"><a class="btn" href="/capa-financeira/vendas/editar.php">+ Nova venda</a></div>
</div>

<?php if (!$vendas): ?>
  <p class="home-sub"><?= $q !== '' ? 'Nada encontrado para essa busca.' : 'Nenhuma venda preenchida pelo formulário ainda.' ?></p>
<?php else: ?>
<div class="cf-tbl-wrap">
<table class="grid cf-lista">
  <thead><tr><th>#</th><th>Compradores</th><th>Construtora</th><th>Imóvel</th><th>Venda</th><th>Empresa</th><th>Etapas</th><th>Última edição</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($vendas as $v): $link = '/capa-financeira/vendas/editar.php?id=' . (int)$v['id']; ?>
    <tr>
      <td><a href="<?= $link ?>"><?= (int)$v['id'] ?></a></td>
      <td><a href="<?= $link ?>"><?= h($v['compradores'] ?: '(sem comprador)') ?></a><?= $v['status'] === 'cancelada' ? ' <span class="cf-status cf-st-descartada">cancelada</span>' : '' ?></td>
      <td><?= h($v['construtora']) ?></td>
      <td><?= h(trim($v['endereco'] . ($v['unidade'] !== '' ? ' — ' . Venda::unidadeFormatada($v['unidade']) : ''))) ?><?= $v['cod_imovel'] !== '' ? '<br><small>COD ' . h($v['cod_imovel']) . ($v['status_imovel'] ? ' · ' . h($v['status_imovel']) : '') . '</small>' : '' ?></td>
      <td><?= cf_data_br($v['data_venda']) ?></td>
      <td><?= h(strtoupper($v['empresa'])) ?></td>
      <td><div class="cv-etapas"><?php for ($i = 1; $i <= 4; $i++) echo '<span title="Etapa ' . $i . '">' . $i . ' ' . ($i > 1 && $v["e{$i}_status"] === 'rascunho' ? '<span class="cf-status cf-st-substituida">—</span>' : cv_etapa_badge($v["e{$i}_status"])) . '</span>'; ?></div></td>
      <td><?= h(substr((string)$v['atualizado_em'], 0, 16)) ?><br><small><?= h((string)$v['atualizado_nome']) ?></small></td>
      <td><a class="cf-x" href="/capa-financeira/vendas/xlsx.php?id=<?= (int)$v['id'] ?>" title="baixar a planilha (.xlsx)">⬇ xlsx</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php portal_footer();
