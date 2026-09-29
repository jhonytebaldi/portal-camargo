<?php
/* =====================================================================
   listas-3c/index.php: Listas 3C: do Robust para o discador.

   Fluxo pedido pelo Jhony (reunião de 24/09): escolher o modelo e o
   período → ver a prévia tratada (nome limpo, e-mail, telefone, resumo
   curto, canal de origem) → aprovar → subir numa campanha do 3C.
   A montagem roda em passos curtos (ver lib/Montador.php); esta tela só
   dispara os passos e mostra o progresso.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('listas-3c');
$pdo = db();
$modelos = l3c_modelos();
$id = (int)($_GET['id'] ?? 0);
// Máscara para demonstração/gravação: nada de dado pessoal de lead na tela.
$mascara = !empty($_GET['mascara']);
$mk = fn(string $s, string $t) => $mascara ? L3cTratamento::mascarar($s, $t) : $s;

$lista = null; $itens = []; $descartes = []; $campanhas = []; $erro3c = '';
if ($id) {
    $st = $pdo->prepare('SELECT * FROM l3c_listas WHERE id=?'); $st->execute([$id]);
    $lista = $st->fetch() ?: null;
    if ($lista) {
        $st = $pdo->prepare('SELECT descarte, COUNT(*) n FROM l3c_itens WHERE lista_id=? GROUP BY descarte ORDER BY n DESC'); $st->execute([$id]);
        $descartes = $st->fetchAll();
        if (in_array($lista['status'], ['pronta', 'enviando', 'enviada'], true)) {
            $st = $pdo->prepare('SELECT * FROM l3c_itens WHERE lista_id=? AND descarte IS NULL ORDER BY criado_em DESC LIMIT 300'); $st->execute([$id]);
            $itens = $st->fetchAll();
        }
        if ($lista['status'] === 'pronta') {
            try { $campanhas = L3cTresC::doConfig()->campanhas(); } catch (Throwable $e) { $erro3c = $e->getMessage(); }
        }
    }
} else {
    $recentes = $pdo->query('SELECT l.*, (SELECT COUNT(*) FROM l3c_itens i WHERE i.lista_id=l.id AND i.descarte IS NULL) entram
                             FROM l3c_listas l ORDER BY l.id DESC LIMIT 20')->fetchAll();
}

portal_header('Listas 3C', $u);
?>
<style>
.l3-top{display:flex;align-items:baseline;justify-content:space-between;gap:12px;flex-wrap:wrap}
.l3-card{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:18px 20px;margin:0 0 18px}
.l3-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
.l3-form label{font-size:13px;font-weight:600;display:flex;flex-direction:column;gap:6px}
.l3-form select,.l3-form input{font:inherit;padding:9px 10px;border:1px solid var(--line);border-radius:4px;background:#fff}
.l3-btn{font:inherit;font-weight:600;padding:10px 16px;border:none;border-radius:4px;background:var(--moss);color:#fff;cursor:pointer}
.l3-btn:hover{background:var(--moss-d)} .l3-btn[disabled]{opacity:.5;cursor:default}
.l3-btn.sec{background:#fff;color:var(--clay);border:1px solid var(--line)}
.l3-desc{color:var(--mute);font-size:13px;margin:8px 0 0}
.l3-bar{height:12px;background:#e8e4d8;border-radius:6px;overflow:hidden;margin:10px 0 6px}
.l3-bar i{display:block;height:100%;background:var(--moss);width:0;transition:width .4s}
.l3-kpis{display:flex;gap:22px;flex-wrap:wrap;margin:6px 0 0;font-size:14px}
.l3-kpis b{font-size:20px;display:block}
.l3-tbl-wrap{overflow-x:auto}
.l3-tbl{width:100%;border-collapse:collapse;font-size:13px}
.l3-tbl th,.l3-tbl td{text-align:left;padding:7px 8px;border-bottom:1px solid var(--line);vertical-align:top}
.l3-tbl th{font-size:12px;color:var(--mute);font-weight:600}
.l3-res{max-width:420px;color:#33413b}
.l3-pill{display:inline-block;font-size:12px;padding:2px 8px;border-radius:10px;background:#e8e4d8}
.l3-pill.pronta{background:#dcebe2;color:#1f4d3f}.l3-pill.enviada{background:var(--moss);color:#fff}.l3-pill.erro{background:#f3d9cf;color:#8a3317}
.l3-desc-lista{margin:8px 0 0;padding-left:18px;font-size:13px}
@media (max-width:640px){.l3-res{max-width:none}}
</style>

<div class="l3-top">
  <h1 class="home-titulo">Listas 3C</h1>
  <span style="display:flex;gap:18px">
    <a href="modelos.php" style="color:var(--moss);font-weight:600;text-decoration:none">Editar modelos</a>
    <?php if (is_admin($u)): /* chave de API é assunto de admin; o link some para quem só usa */ ?>
      <a href="integracoes.php" style="color:var(--moss);font-weight:600;text-decoration:none">Configurar integrações</a>
    <?php endif; ?>
  </span>
</div>
<p class="home-sub">Monta a lista a partir do Robust, trata os contatos, você aprova e ela sobe na campanha do 3C.</p>

<?php
// Aviso logo na entrada quando falta chave: sem isso a pessoa só descobre
// depois de montar a lista inteira (Robust) ou na hora de aprovar (3C).
$falta = [];
if (!L3cIntegracoes::valor('robust_api_key')['valor']) $falta[] = 'Robust';
if (!L3cIntegracoes::valor('tresc_api_token')['valor']) $falta[] = '3C';
if ($falta): ?>
  <div class="aviso">Falta cadastrar a chave do <?= h(implode(' e do ', $falta)) ?>.
    <?= is_admin($u) ? '<a href="integracoes.php">Configurar integrações</a>' : 'Peça a um administrador do portal.' ?></div>
<?php endif; ?>

<?php if (!$id): ?>
  <div class="l3-card">
    <form class="l3-form" id="l3-nova">
      <label>Modelo
        <select name="modelo" id="l3-modelo">
          <?php foreach ($modelos as $slug => $m): ?>
            <option value="<?= h($slug) ?>" data-desc="<?= h($m['descricao']) ?>"><?= h($m['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span id="l3-rot-de">Cadastro de</span><input type="date" name="de" value="<?= h(date('Y-m-d', strtotime('-14 days'))) ?>" required></label>
      <label>até<input type="date" name="ate" value="<?= h(date('Y-m-d', strtotime('-1 day'))) ?>" required></label>
      <label>Nome da lista (opcional)<input type="text" name="nome" maxlength="160" placeholder="gerado pelo modelo e período"></label>
      <button class="l3-btn" type="submit">Montar lista</button>
    </form>
    <p class="l3-desc" id="l3-modelo-desc"></p>
  </div>

  <div class="l3-card">
    <h2 style="font-size:16px;margin:0 0 8px">Listas recentes</h2>
    <?php if (!$recentes): ?><p class="l3-desc">Nenhuma lista ainda.</p><?php else: ?>
    <div class="l3-tbl-wrap"><table class="l3-tbl">
      <tr><th>Lista</th><th>Modelo</th><th>Período</th><th>Status</th><th>Contatos</th></tr>
      <?php foreach ($recentes as $r): ?>
        <tr>
          <td><a href="?id=<?= (int)$r['id'] ?><?= $mascara ? '&mascara=1' : '' ?>"><?= h($r['nome']) ?></a></td>
          <td><?= h($modelos[$r['modelo']]['nome'] ?? $r['modelo']) ?></td>
          <td><?= h(date('d/m/y', strtotime($r['periodo_de']))) ?> a <?= h(date('d/m/y', strtotime($r['periodo_ate']))) ?></td>
          <td><span class="l3-pill <?= h($r['status']) ?>"><?= h(L3C_STATUS[$r['status']] ?? $r['status']) ?></span></td>
          <td><?= (int)$r['entram'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>

<?php elseif (!$lista): ?>
  <div class="aviso">Lista não encontrada. <a href="./">Voltar</a></div>

<?php else: $par = json_decode($lista['parametros'], true); ?>
  <p><a href="./" style="color:var(--moss)">← Listas</a></p>
  <div class="l3-card" id="l3-lista" data-id="<?= (int)$lista['id'] ?>" data-status="<?= h($lista['status']) ?>">
    <div class="l3-top">
      <h2 style="font-size:18px;margin:0"><?= h($lista['nome']) ?></h2>
      <span class="l3-pill <?= h($lista['status']) ?>" id="l3-status"><?= h(L3C_STATUS[$lista['status']] ?? $lista['status']) ?></span>
    </div>
    <p class="l3-desc"><?= h($par['nome'] ?? $lista['modelo']) ?> · <?= $par['tipo'] === 'agendamento_vencido' ? 'agendamento de' : 'cadastro de' ?>
      <?= h(date('d/m/y', strtotime($lista['periodo_de']))) ?> a <?= h(date('d/m/y', strtotime($lista['periodo_ate']))) ?></p>
    <div class="l3-bar"><i id="l3-bar" style="width:<?= (float)$lista['progresso'] ?>%"></i></div>
    <div id="l3-texto" style="font-size:14px"><?= h($lista['progresso_txt']) ?></div>
    <div class="l3-kpis">
      <span><b id="l3-brutos"><?= array_sum(array_column($descartes, 'n')) ?></b>lidos no Robust</span>
      <span><b id="l3-entram"><?php $e = 0; foreach ($descartes as $d) if ($d['descarte'] === null) $e = (int)$d['n']; echo $e; ?></b>entram na lista</span>
      <span><b id="l3-chamadas"><?= (int)$lista['chamadas_robust'] ?></b>chamadas ao Robust</span>
      <?php if ($lista['status'] === 'enviada'): ?><span><b><?= (int)$lista['importados'] ?></b>importados no 3C</span><?php endif; ?>
    </div>
    <?php if ($lista['erro']): ?><div class="erro" style="margin-top:10px"><?= h($lista['erro']) ?></div><?php endif; ?>
    <?php if (in_array($lista['status'], ['montando', 'pronta', 'erro'], true)): ?>
      <p style="margin:12px 0 0"><button class="l3-btn sec" id="l3-cancelar" type="button">Cancelar lista</button></p>
    <?php endif; ?>
  </div>

  <?php if ($descartes && count($descartes) > 1): ?>
  <div class="l3-card">
    <h2 style="font-size:16px;margin:0">Quem ficou de fora e por quê</h2>
    <ul class="l3-desc-lista">
      <?php foreach ($descartes as $d) if ($d['descarte'] !== null): ?><li><?= (int)$d['n'] ?> · <?= h($d['descarte']) ?></li><?php endif; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php if ($lista['status'] === 'pronta'): ?>
  <div class="l3-card">
    <h2 style="font-size:16px;margin:0 0 10px">Aprovar e subir no 3C</h2>
    <?php if ($erro3c): ?><div class="erro"><?= h($erro3c) ?></div>
    <?php elseif (!$campanhas): ?><div class="aviso">Nenhuma campanha do 3C liberada para este portal.</div>
    <?php else: ?>
    <form class="l3-form" id="l3-aprovar">
      <label>Campanha
        <select name="campanha"><?php foreach ($campanhas as $cid => $cn): ?><option value="<?= (int)$cid ?>"><?= h($cn) ?> (<?= (int)$cid ?>)</option><?php endforeach; ?></select>
      </label>
      <button class="l3-btn" type="submit">Aprovar <?= count($itens) ?> contatos e subir</button>
    </form>
    <p class="l3-desc">Cria uma lista nova com o nome acima dentro da campanha escolhida. As tentativas por status e a reciclagem ficam na configuração da campanha no 3C.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($itens): ?>
  <div class="l3-card">
    <div class="l3-top">
      <h2 style="font-size:16px;margin:0">Prévia tratada<?= count($itens) >= 300 ? ' (primeiros 300)' : '' ?></h2>
      <a href="?id=<?= (int)$lista['id'] ?><?= $mascara ? '' : '&mascara=1' ?>" style="color:var(--moss);font-size:13px"><?= $mascara ? 'Mostrar dados' : 'Ocultar dados pessoais' ?></a>
    </div>
    <div class="l3-tbl-wrap"><table class="l3-tbl">
      <tr><th>Nome</th><th>E-mail</th><th>Telefone</th><th>Canal</th><th>Resumo do atendimento</th></tr>
      <?php foreach ($itens as $it): ?>
        <tr>
          <td><?= $it['nome'] === '' ? '<span style="color:var(--mute)">(sem nome no Robust)</span>' : h($mk($it['nome'], 'nome')) ?></td>
          <td><?= h($mk($it['email'], 'email')) ?></td>
          <td style="white-space:nowrap"><?= h($mascara ? L3cTratamento::mascarar($it['telefone'], 'telefone') : '(' . substr($it['telefone'], 0, 2) . ') ' . substr($it['telefone'], 2)) ?></td>
          <td><?= h($it['canal']) ?></td>
          <td class="l3-res"><?= h($mascara ? preg_replace('/Obs: .*/u', 'Obs: •••', $it['resumo']) : $it['resumo']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<script>
const csrf = <?= json_encode(csrf_token()) ?>;
async function acao(corpo) {
  const r = await fetch('acao.php', {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF': csrf},
                                     body: JSON.stringify(Object.assign({csrf}, corpo))});
  return r.json();
}
const sel = document.getElementById('l3-modelo');
if (sel) {
  const desc = () => {
    const o = sel.selectedOptions[0];
    document.getElementById('l3-modelo-desc').textContent = o.dataset.desc;
    document.getElementById('l3-rot-de').textContent = sel.value === 'agendamento_vencido' ? 'Agendamento de' : 'Cadastro de';
  };
  sel.addEventListener('change', desc); desc();
  document.getElementById('l3-nova').addEventListener('submit', async ev => {
    ev.preventDefault();
    const f = new FormData(ev.target); ev.submitter.disabled = true;
    const r = await acao({acao: 'criar', modelo: f.get('modelo'), de: f.get('de'), ate: f.get('ate'), nome: f.get('nome')});
    // Quem está no modo sem dados pessoais (reunião, gravação) continua nele na lista nova.
    if (r.ok) location.href = '?id=' + r.id + (new URLSearchParams(location.search).has('mascara') ? '&mascara=1' : ''); else { alert(r.erro); ev.submitter.disabled = false; }
  });
}
const card = document.getElementById('l3-lista');
if (card) {
  const id = +card.dataset.id;
  // Pede um passo curto por vez até terminar: cada um trabalha ~8 s no
  // servidor, então nenhuma requisição chega perto do corte de 60 s.
  async function rodar() {
    for (;;) {
      let r;
      try { r = await acao({acao: 'passo', id}); } catch (e) { await new Promise(s => setTimeout(s, 3000)); continue; }
      if (!r.ok) { document.getElementById('l3-texto').textContent = r.erro; return; }
      document.getElementById('l3-bar').style.width = r.progresso + '%';
      document.getElementById('l3-texto').textContent = r.texto;
      document.getElementById('l3-brutos').textContent = r.brutos;
      document.getElementById('l3-entram').textContent = r.entram;
      document.getElementById('l3-chamadas').textContent = r.chamadas;
      if (!['montando', 'enviando'].includes(r.status)) { location.reload(); return; }
      await new Promise(s => setTimeout(s, r.ocupada ? 2000 : 300));
    }
  }
  if (['montando', 'enviando'].includes(card.dataset.status)) rodar();
  const ap = document.getElementById('l3-aprovar');
  if (ap) ap.addEventListener('submit', async ev => {
    ev.preventDefault();
    const b = ev.submitter; b.disabled = true;
    const r = await acao({acao: 'aprovar', id, campanha: +new FormData(ap).get('campanha')});
    if (r.ok) location.reload(); else { alert(r.erro); b.disabled = false; }
  });
  const cc = document.getElementById('l3-cancelar');
  if (cc) cc.addEventListener('click', async () => { if (confirm('Cancelar esta lista?')) { await acao({acao: 'cancelar', id}); location.reload(); } });
}
</script>
<?php
portal_footer();
