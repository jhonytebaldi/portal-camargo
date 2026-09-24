<?php
/* =====================================================================
   capa-financeira/pessoas.php — dicionário de pessoas (equipe) e apelidos.
   Importa a planilha "DADOS CORRETORES EQUIPE.xlsx" (NOME, CPF, CHAVE PIX…).
   Só associa automaticamente por igualdade exata (nome ou apelido).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('capa-financeira');
$pdo = db();
$msg = ''; $erro = '';

function cf_so_digitos(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }
function cf_fmt_cnpj(string $d): string { return strlen($d) === 14 ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d) : $d; }
function cf_fmt_cpf(string $d): string { return strlen($d) === 11 ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d) : $d; }
function cf_nome_bonito(string $n): string {
    $n = mb_strtolower(CapaParser::norm($n), 'UTF-8');
    $out = [];
    foreach (explode(' ', $n) as $w) $out[] = in_array($w, ['de', 'da', 'do', 'das', 'dos', 'e'], true) ? $w : mb_convert_case($w, MB_CASE_TITLE, 'UTF-8');
    return implode(' ', $out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $nome = CapaParser::norm((string)($_POST['nome'] ?? ''));
            if ($nome === '') throw new RuntimeException('Nome obrigatório');
            $cnpj = cf_so_digitos((string)($_POST['cnpj'] ?? '')); $cpf = cf_so_digitos((string)($_POST['cpf'] ?? ''));
            $pix = Pix::analisar((string)($_POST['chave_pix'] ?? ''));
            if (!$pix['valido']) throw new RuntimeException('Chave Pix inválida: ' . $pix['erro']);
            $dados = [$nome, CapaParser::key($nome), CapaParser::norm((string)($_POST['razao_social'] ?? '')) ?: null,
                $cnpj ? cf_fmt_cnpj($cnpj) : null, $cpf ? cf_fmt_cpf($cpf) : null, ($_POST['pagar_por'] ?? 'CNPJ') === 'CPF' ? 'CPF' : 'CNPJ',
                CapaParser::norm((string)($_POST['departamento_omie'] ?? '')) ?: null, CapaParser::norm((string)($_POST['conta_vertical'] ?? '')) ?: null,
                CapaParser::norm((string)($_POST['conta_camargo'] ?? '')) ?: null, $pix['valor'],
                !empty($_POST['ativo']) ? 1 : 0, ($_POST['tipo'] ?? 'equipe') === 'terceiro' ? 'terceiro' : 'equipe'];
            if ($id) { $dados[] = $id; $pdo->prepare('UPDATE cf_pessoas SET nome=?, nome_key=?, razao_social=?, cnpj=?, cpf=?, pagar_por=?, departamento_omie=?, conta_vertical=?, conta_camargo=?, chave_pix=?, ativo=?, tipo=? WHERE id=?')->execute($dados); }
            else { $pdo->prepare('INSERT INTO cf_pessoas (nome, nome_key, razao_social, cnpj, cpf, pagar_por, departamento_omie, conta_vertical, conta_camargo, chave_pix, ativo, tipo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($dados); $id = (int)$pdo->lastInsertId(); }
            foreach (preg_split('/[\n;]+/', (string)($_POST['aliases_novos'] ?? '')) ?: [] as $a) if (CapaParser::norm($a) !== '') cf_add_alias($id, $a);
            $msg = 'Pessoa salva.';
        } elseif ($op === 'apelido_remover') {
            $pdo->prepare('DELETE FROM cf_pessoa_aliases WHERE id = ?')->execute([(int)($_POST['alias_id'] ?? 0)]);
            $msg = 'Apelido removido.';
        } elseif ($op === 'importar') {
            $f = $_FILES['planilha'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Envie a planilha .xlsx da equipe');
            $ws = Xlsx::open($f['tmp_name']);
            // cabeçalho: procura NOME / CPF / RAZÃO SOCIAL / CHAVE PIX
            $col = [];
            for ($r = 1; $r <= min(5, $ws->maxRow()); $r++) for ($c = 1; $c <= $ws->maxCol(); $c++) {
                $k = CapaParser::key($ws->cell($r, $c));
                if ($k === 'NOME') $col['nome'] = [$r, $c]; elseif ($k === 'CPF') $col['cpf'] = $c; elseif (str_starts_with($k, 'CHAVE PIX')) $col['pix'] = $c;
                elseif (str_starts_with($k, 'RAZAO SOCIAL')) $col['razao'] = $c;
            }
            if (!isset($col['nome'])) throw new RuntimeException('Não achei a coluna NOME');
            [$hr, $cn] = $col['nome']; $novos = 0; $atual = 0; $avisos = [];
            $todas = cf_pessoas(false);
            $porKey = []; $porCpf = [];
            foreach ($todas as $pid => $p) { $porKey[$p['nome_key']] = $pid; if ($p['cpf']) $porCpf[cf_so_digitos($p['cpf'])] = $pid; foreach ($p['aliases'] as $a) $porKey[$a['alias_key']] = $pid; }
            $ins = $pdo->prepare('INSERT INTO cf_pessoas (nome, nome_key, razao_social, cnpj, cpf, pagar_por, chave_pix) VALUES (?,?,?,?,?,?,?)');
            // preenche o que falta; corrige chave Pix inválida; razão social/CNPJ da planilha vencem quando a pessoa não tem
            $upd = $pdo->prepare('UPDATE cf_pessoas SET razao_social = COALESCE(NULLIF(razao_social, ""), ?), cnpj = COALESCE(NULLIF(cnpj, ""), ?), cpf = COALESCE(NULLIF(cpf, ""), ?), chave_pix = ?, pagar_por = ? WHERE id = ?');
            for ($r = $hr + 1; $r <= $ws->maxRow(); $r++) {
                $nome = CapaParser::norm($ws->cell($r, $cn)); if ($nome === '') continue;
                $cpfCel = isset($col['cpf']) ? $ws->cell($r, $col['cpf']) : null;
                if (is_float($cpfCel) && floor($cpfCel) == $cpfCel) $cpfCel = sprintf('%011.0f', $cpfCel);
                $cpf = cf_so_digitos(CapaParser::norm($cpfCel));
                if ($cpf !== '' && strlen($cpf) !== 11) { $avisos[] = "$nome: CPF com " . strlen($cpf) . ' dígitos (ignorado)'; $cpf = ''; }
                $razao = isset($col['razao']) ? CapaParser::norm($ws->cell($r, $col['razao'])) : '';
                $pixCel = isset($col['pix']) ? $ws->cell($r, $col['pix']) : null;
                if (is_float($pixCel) && floor($pixCel) == $pixCel) $pixCel = sprintf('%.0f', $pixCel);   // célula numérica (telefone/CPF sem pontuação)
                $pixRaw = CapaParser::norm($pixCel);
                $px = Pix::analisar($pixRaw);
                if ($pixRaw !== '' && !$px['valido']) { $avisos[] = "$nome: chave Pix \"$pixRaw\" inválida (" . $px['erro'] . ') — não gravada'; $pixRaw = ''; }
                else $pixRaw = (string)($px['valor'] ?? '');
                $pixD = cf_so_digitos($pixRaw);
                $cnpj = ($px['tipo'] === 'cnpj') ? cf_fmt_cnpj($pixD) : null;
                // razão social preenchida sem CNPJ na Pix (ex.: pessoa que recebe no CPF) — mantém só a razão
                $key = CapaParser::key($nome);
                $id = $porKey[$key] ?? ($cpf !== '' ? ($porCpf[$cpf] ?? null) : null);
                if ($id) {
                    $p = $todas[$id];
                    $pixFinal = $pixRaw !== '' ? $pixRaw : (string)$p['chave_pix'];
                    // Pix antiga inválida no cadastro (ex.: CNPJ com 13 dígitos) é substituída
                    if ($pixFinal !== '' && !Pix::analisar($pixFinal)['valido']) $pixFinal = $pixRaw;
                    $cnpjFinal = $cnpj ?: ($p['cnpj'] ?: null);
                    $pagarPor = $cnpjFinal ? 'CNPJ' : 'CPF';
                    $upd->execute([$razao ?: null, $cnpj, $cpf ? cf_fmt_cpf($cpf) : null, $pixFinal ?: null, $pagarPor, (int)$id]);
                    if ($key !== $p['nome_key'] && !isset($porKey[$key])) cf_add_alias((int)$id, $nome);
                    $atual++;
                } else {
                    $ins->execute([cf_nome_bonito($nome), $key, $razao ?: null, $cnpj, $cpf ? cf_fmt_cpf($cpf) : null, $cnpj ? 'CNPJ' : 'CPF', $pixRaw ?: null]);
                    $pid = (int)$pdo->lastInsertId(); $porKey[$key] = $pid; if ($cpf) $porCpf[$cpf] = $pid;
                    $todas[$pid] = ['nome_key' => $key, 'cpf' => $cpf, 'cnpj' => $cnpj, 'chave_pix' => $pixRaw, 'aliases' => []];
                    $novos++;
                }
            }
            if ($avisos) $erro = 'Avisos: ' . implode(' · ', $avisos);
            $msg = "Importação: $novos pessoa(s) nova(s), $atual atualizada(s). Confira departamento e conta padrão.";
        }
    } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$pessoas = cf_pessoas(false);
$edit = null;
if (!empty($_GET['id'])) $edit = $pessoas[(int)$_GET['id']] ?? null;

portal_header('Pessoas — Capa Financeira', $u);
?>
<style>main.wrap{max-width:1400px}</style>
<script src="/capa-financeira/pix.js?v=<?= @filemtime(__DIR__ . '/pix.js') ?: 1 ?>"></script>
<div class="cf-top">
  <div><h1 class="home-titulo">Pessoas (dicionário)</h1>
  <p class="home-sub">Quem recebe comissão. O nome da capa só é associado sozinho quando bate exatamente com o nome ou um apelido daqui; o resto você escolhe na revisão.</p></div>
  <nav class="cf-nav"><a href="/capa-financeira/">← Capas</a><a href="/capa-financeira/configuracoes.php">Configurações</a></nav>
</div>
<?php if ($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>

<div class="cf-duas">
<section class="card">
  <h2><?= $edit ? 'Editar pessoa' : 'Nova pessoa' ?></h2>
  <form method="post" class="cf-form cf-form-pessoa">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="salvar"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Nome (como vai no recibo)<input name="nome" required value="<?= h($edit['nome'] ?? '') ?>"></label>
    <label>Razão social da empresa (fornecedor no Omie)<input name="razao_social" value="<?= h($edit['razao_social'] ?? '') ?>" placeholder="ex.: TEBALDI PERFORMANCE LTDA"></label>
    <div class="cf-grid2">
      <label>CNPJ<input name="cnpj" value="<?= h($edit['cnpj'] ?? '') ?>"></label>
      <label>CPF<input name="cpf" value="<?= h($edit['cpf'] ?? '') ?>"></label>
    </div>
    <div class="cf-grid2">
      <label>Pagar por padrão<select name="pagar_por"><option <?= ($edit['pagar_por'] ?? 'CNPJ') === 'CNPJ' ? 'selected' : '' ?>>CNPJ</option><option <?= ($edit['pagar_por'] ?? '') === 'CPF' ? 'selected' : '' ?>>CPF</option></select></label>
      <label>Departamento no Omie<input name="departamento_omie" value="<?= h($edit['departamento_omie'] ?? '') ?>"></label>
    </div>
    <div class="cf-grid2">
      <label>Conta corrente padrão (Vertical)<input name="conta_vertical" value="<?= h($edit['conta_vertical'] ?? '') ?>" placeholder="nome exato no Omie"></label>
      <label>Conta corrente padrão (Camargo)<input name="conta_camargo" value="<?= h($edit['conta_camargo'] ?? '') ?>"></label>
    </div>
    <label>Chave PIX (CPF, CNPJ, e-mail, telefone ou aleatória)<input name="chave_pix" class="cf-pix" autocomplete="off" value="<?= h($edit['chave_pix'] ?? '') ?>"></label>
    <label>Apelidos (como aparece nas capas), um por linha<textarea name="aliases_novos" rows="2" placeholder="OSVALDO&#10;FERNANDO FOSSILE"></textarea></label>
    <label>Tipo<select name="tipo"><option value="equipe" <?= ($edit['tipo'] ?? 'equipe') === 'equipe' ? 'selected' : '' ?>>Equipe (corretor, gestor…)</option><option value="terceiro" <?= ($edit['tipo'] ?? '') === 'terceiro' ? 'selected' : '' ?>>Construtora / terceiro (só repasse, sem recibo)</option></select></label>
    <label class="cf-mini"><input type="checkbox" name="ativo" <?= ($edit['ativo'] ?? 1) ? 'checked' : '' ?>> ativo</label>
    <div><button class="btn">Salvar</button> <?php if ($edit): ?><a class="cf-link" href="/capa-financeira/pessoas.php">nova pessoa</a><?php endif; ?></div>
  </form>
  <?php if ($edit && $edit['aliases']): ?><p class="cf-dica">Apelidos cadastrados:</p><div class="chips"><?php foreach ($edit['aliases'] as $a): ?><form method="post" class="cf-inline"><span class="chip mchip"><?= h($a['alias']) ?> <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="apelido_remover"><input type="hidden" name="alias_id" value="<?= (int)$a['id'] ?>"><button class="x cf-x-chip" title="remover apelido">×</button></span></form><?php endforeach; ?></div><?php endif; ?>
</section>
<section class="card">
  <h2>Importar planilha da equipe</h2>
  <p class="cf-dica">Planilha "DADOS CORRETORES EQUIPE.xlsx" (colunas NOME, CPF, RAZÃO SOCIAL, CHAVE PIX). Quem já existe (mesmo nome, apelido ou CPF) ganha razão social/CPF/CNPJ que estiver faltando e a chave Pix da planilha; ninguém é apagado. Chave Pix inválida é avisada e não entra. Data de nascimento e celular não são guardados.</p>
  <form method="post" enctype="multipart/form-data" class="cf-form">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="op" value="importar">
    <input type="file" name="planilha" accept=".xlsx" required>
    <button class="btn">Importar</button>
  </form>
</section>
</div>

<h2 class="cf-h2">Cadastro <small><?= count($pessoas) ?></small></h2>
<div class="cf-tbl-wrap">
<table class="grid cf-lista">
<thead><tr><th>Nome</th><th>Tipo</th><th>Apelidos</th><th>Empresa / CNPJ</th><th>CPF</th><th>Paga por</th><th>Departamento</th><th>Contas (Vertical / Camargo)</th><th>Ativo</th></tr></thead>
<tbody>
<?php foreach ($pessoas as $p): ?>
<tr class="<?= $p['ativo'] ? '' : 'cf-removida' ?>">
  <td><a href="/capa-financeira/pessoas.php?id=<?= (int)$p['id'] ?>"><?= h($p['nome']) ?></a></td>
  <td><?= ($p['tipo'] ?? 'equipe') === 'terceiro' ? '<span class="cf-tag">construtora/terceiro</span>' : 'equipe' ?></td>
  <td><small><?= h(implode(' · ', array_column($p['aliases'], 'alias'))) ?></small></td>
  <td><?= h((string)$p['razao_social']) ?><?= $p['cnpj'] ? '<br><small>' . h($p['cnpj']) . '</small>' : '' ?><?= !$p['razao_social'] && $p['cnpj'] ? ' <span class="cf-tag cf-grave">sem razão social</span>' : '' ?></td>
  <td><?= $p['cpf'] ? '•••.' . h(substr($p['cpf'], 4)) : '—' ?></td>
  <td><?= h($p['pagar_por']) ?></td>
  <td><?= h((string)$p['departamento_omie']) ?></td>
  <td><small><?= h((string)$p['conta_vertical']) ?: '—' ?> / <?= h((string)$p['conta_camargo']) ?: '—' ?></small></td>
  <td><?= $p['ativo'] ? 'sim' : 'não' ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php portal_footer();
