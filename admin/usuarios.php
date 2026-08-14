<?php
/* admin/usuarios.php — usuários + vínculo com corretor + ativação por link. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
$pdo = db();
$msg = ''; $erro = ''; $linkGerado = '';

function novo_token(): string { return bin2hex(random_bytes(24)); }
function link_ativacao(string $token): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'portal.imobcamargo.com.br';
    return 'https://' . $host . '/ativar.php?token=' . $token;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    $broker = trim($_POST['broker_id'] ?? '');
    $broker = $broker !== '' ? $broker : null;
    try {
        if ($acao === 'criar') {
            $nome=trim($_POST['nome']??''); $login=trim($_POST['login']??''); $papel=$_POST['papel']??'viewer';
            if ($nome===''||$login==='') $erro='Preencha nome e login.';
            elseif (!in_array($papel,['admin','gestor','viewer'],true)) $erro='Papel inválido.';
            else {
                $tok=novo_token();
                $pdo->prepare('INSERT INTO users (nome,login,senha_hash,papel,ativo,broker_id,ativacao_token) VALUES (?,?,?,?,1,?,?)')
                    ->execute([$nome,$login,'',$papel,$broker,$tok]);
                $msg='Usuário criado. Envie o link de ativação abaixo para ele definir a senha.';
                $linkGerado=link_ativacao($tok);
            }
        } elseif ($acao === 'salvar') {
            $id=(int)$_POST['id']; $nome=trim($_POST['nome']??''); $papel=$_POST['papel']??'viewer'; $ativo=isset($_POST['ativo'])?1:0;
            if ($id===(int)$u['id'] && ($papel!=='admin' || !$ativo)) $erro='Você não pode remover o próprio acesso de admin.';
            else { $pdo->prepare('UPDATE users SET nome=?, papel=?, ativo=?, broker_id=? WHERE id=?')->execute([$nome,$papel,$ativo,$broker,$id]); $msg='Usuário atualizado.'; }
        } elseif ($acao === 'genlink') {
            $id=(int)$_POST['id']; $tok=novo_token();
            $pdo->prepare('UPDATE users SET ativacao_token=? WHERE id=?')->execute([$tok,$id]);
            $msg='Link de definição de senha gerado. Envie ao usuário.'; $linkGerado=link_ativacao($tok);
        }
    } catch (Throwable $e) { $erro='Erro: '.$e->getMessage().' (login já usado?)'; }
}

$users = $pdo->query('SELECT id,nome,login,papel,ativo,broker_id,ativacao_token,senha_hash FROM users ORDER BY nome')->fetchAll();
$tools = $pdo->query('SELECT id,nome,slug FROM tools WHERE ativo=1 ORDER BY ordem,nome')->fetchAll();
$teams = $pdo->query('SELECT id,nome FROM teams ORDER BY nome')->fetchAll();
$brokers = $pdo->query('SELECT id,nome,email FROM brokers WHERE ativo=1 ORDER BY nome')->fetchAll();
$semUser = $pdo->query('SELECT id,nome,email FROM brokers WHERE ativo=1 AND id NOT IN (SELECT broker_id FROM users WHERE broker_id IS NOT NULL) ORDER BY nome')->fetchAll();

$edit=null; $uTools=[]; $uTeams=[];
if (isset($_GET['id'])) {
    $st=$pdo->prepare('SELECT * FROM users WHERE id=?'); $st->execute([(int)$_GET['id']]); $edit=$st->fetch();
    if ($edit){
        $st=$pdo->prepare('SELECT tool_id FROM user_tools WHERE user_id=?'); $st->execute([$edit['id']]); $uTools=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
        $st=$pdo->prepare('SELECT team_id FROM user_teams WHERE user_id=?'); $st->execute([$edit['id']]); $uTeams=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
    }
}
admin_header('usuarios', $u);
?>
<h1 class="home-titulo">Usuários</h1>
<?php if($msg): ?><div class="ok-box"><?= h($msg) ?></div><?php endif; ?>
<?php if($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>
<?php if($linkGerado): ?>
  <div class="ok-box">Link de ativação (copie e envie por WhatsApp):
    <input readonly onclick="this.select()" value="<?= h($linkGerado) ?>" style="width:100%;margin-top:6px;font:12px monospace"></div>
<?php endif; ?>

<div class="card eq-novo" style="flex-wrap:wrap">
  <form method="post" id="formCriar" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="criar">
    <input type="hidden" name="broker_id" id="ci_broker" value="">
    <input name="nome" id="ci_nome" placeholder="Nome" required>
    <input name="login" id="ci_login" placeholder="Login (e-mail)" required style="min-width:220px">
    <select name="papel"><option value="viewer">viewer</option><option value="gestor">gestor</option><option value="admin">admin</option></select>
    <button class="btn" type="submit">+ Criar (gera link)</button>
    <span class="tag" id="ci_vinc"></span>
  </form>
</div>

<?php if($semUser): ?>
<div class="card">
  <div class="pool-sec-tit">Corretores ativos sem usuário — clique para preencher</div>
  <div class="chips">
    <?php foreach($semUser as $b): ?>
      <span class="chip sug" data-id="<?= h($b['id']) ?>" data-nome="<?= h($b['nome']) ?>" data-email="<?= h($b['email']) ?>"><?= h($b['nome']) ?> +</span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
<table class="grid">
  <thead><tr><th>Nome</th><th>Login</th><th>Papel</th><th>Corretor</th><th>Status</th><th></th></tr></thead>
  <tbody>
    <?php $bn=[]; foreach($brokers as $b)$bn[$b['id']]=$b['nome']; foreach($users as $usr): ?>
    <tr>
      <td><?= h($usr['nome']) ?></td><td class="mut"><?= h($usr['login']) ?></td><td><?= h($usr['papel']) ?></td>
      <td class="mut"><?= h($usr['broker_id'] ? ($bn[$usr['broker_id']] ?? $usr['broker_id']) : '—') ?></td>
      <td><?php if(!$usr['ativo']) echo '<span class=zero>inativo</span>'; elseif($usr['senha_hash']==='') echo '<span style="color:#c9a253">aguardando 1º acesso</span>'; else echo 'ativo'; ?></td>
      <td><a href="/admin/usuarios.php?id=<?= (int)$usr['id'] ?>">editar</a></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if($edit): ?>
<h2 style="font-size:15px;margin-top:26px">Editar: <?= h($edit['nome']) ?></h2>
<div class="card">
  <form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin:0 0 8px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="salvar"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <label style="font-size:13px">Nome<br><input name="nome" value="<?= h($edit['nome']) ?>"></label>
    <label style="font-size:13px">Papel<br><select name="papel"><?php foreach(['viewer','gestor','admin'] as $p): ?><option value="<?= $p ?>" <?= $edit['papel']===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?></select></label>
    <label style="font-size:13px">Corretor vinculado<br>
      <select name="broker_id"><option value="">(nenhum)</option>
        <?php foreach($brokers as $b): ?><option value="<?= h($b['id']) ?>" <?= $edit['broker_id']===$b['id']?'selected':'' ?>><?= h($b['nome']) ?></option><?php endforeach; ?>
      </select></label>
    <label style="font-size:13px"><input type="checkbox" name="ativo" <?= $edit['ativo']?'checked':'' ?>> ativo</label>
    <button class="btn" type="submit">Salvar</button>
  </form>
  <form method="post" style="margin:0 0 8px">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="genlink"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <button class="btn" type="submit" style="background:var(--clay)">Gerar link de senha (1º acesso / redefinir)</button>
    <span class="tag"><?= $edit['senha_hash']==='' ? 'Ainda não definiu a senha.' : 'Senha já definida — use só para redefinir.' ?></span>
  </form>

  <div class="perm-cols">
    <div><h3 style="font-size:13px;margin:8px 0">Ferramentas que pode abrir</h3>
      <?php foreach($tools as $t): ?><label class="perm"><input type="checkbox" class="ck" data-kind="tool" data-id="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'],$uTools,true)?'checked':'' ?>> <?= h($t['nome']) ?></label><?php endforeach; ?>
    </div>
    <div><h3 style="font-size:13px;margin:8px 0">Equipes que pode ver (Painel)</h3>
      <?php if(!$teams): ?><span class="mut">Nenhuma equipe.</span><?php endif; ?>
      <?php foreach($teams as $t): ?><label class="perm"><input type="checkbox" class="ck" data-kind="team" data-id="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'],$uTeams,true)?'checked':'' ?>> <?= h($t['nome']) ?></label><?php endforeach; ?>
    </div>
  </div>
  <?php if($edit['broker_id']): ?><p class="mut" style="margin-top:8px">Este usuário vê os próprios dados no Painel automaticamente (além das equipes marcadas).</p><?php endif; ?>
  <?php if($edit['papel']==='admin'): ?><p class="mut" style="margin-top:4px">Obs.: admin já enxerga todas as ferramentas e equipes.</p><?php endif; ?>
</div>
<script>
const CSRF=document.querySelector('meta[name=csrf]').content; const UID=<?= (int)$edit['id'] ?>;
document.querySelectorAll('.ck').forEach(ck=> ck.addEventListener('change', async ()=>{
  const kind=ck.dataset.kind, on=ck.checked?'1':'0'; const action= kind==='tool'?'user_tool':'user_team';
  const body={action, user_id:UID, on, csrf:CSRF}; body[kind==='tool'?'tool_id':'team_id']=ck.dataset.id;
  const r=await fetch('/admin/acao.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)});
  const j=await r.json(); if(!j.ok){ alert(j.msg||'erro'); ck.checked=!ck.checked; }
}));
</script>
<?php endif; ?>

<script>
document.querySelectorAll('.chip.sug').forEach(c=> c.addEventListener('click', ()=>{
  document.getElementById('ci_nome').value=c.dataset.nome;
  document.getElementById('ci_login').value=c.dataset.email||'';
  document.getElementById('ci_broker').value=c.dataset.id;
  document.getElementById('ci_vinc').textContent='vinculado a: '+c.dataset.nome;
  document.getElementById('formCriar').scrollIntoView({behavior:'smooth',block:'center'});
  document.getElementById('ci_login').focus();
}));
</script>
<?php portal_footer();
