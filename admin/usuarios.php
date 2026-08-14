<?php
/* admin/usuarios.php — gestão de usuários + permissões (nível 1 e 2). */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
$pdo = db();
$msg = ''; $erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'criar') {
            $nome=trim($_POST['nome']??''); $login=trim($_POST['login']??'');
            $senha=(string)($_POST['senha']??''); $papel=$_POST['papel']??'viewer';
            if ($nome===''||$login===''||strlen($senha)<6) $erro='Preencha nome, login e senha (mín. 6).';
            elseif (!in_array($papel,['admin','gestor','viewer'],true)) $erro='Papel inválido.';
            else {
                $pdo->prepare('INSERT INTO users (nome,login,senha_hash,papel,ativo) VALUES (?,?,?,?,1)')
                    ->execute([$nome,$login,password_hash($senha,PASSWORD_DEFAULT),$papel]);
                $msg='Usuário criado.';
            }
        } elseif ($acao === 'salvar') {
            $id=(int)$_POST['id']; $nome=trim($_POST['nome']??''); $papel=$_POST['papel']??'viewer';
            $ativo=isset($_POST['ativo'])?1:0; $nova=(string)($_POST['nova_senha']??'');
            if ($id===(int)$u['id'] && ($papel!=='admin' || !$ativo)) $erro='Você não pode remover o próprio acesso de admin.';
            else {
                $pdo->prepare('UPDATE users SET nome=?, papel=?, ativo=? WHERE id=?')->execute([$nome,$papel,$ativo,$id]);
                if ($nova!==''){ if(strlen($nova)<6) $erro='A nova senha precisa de 6+ caracteres.'; else $pdo->prepare('UPDATE users SET senha_hash=? WHERE id=?')->execute([password_hash($nova,PASSWORD_DEFAULT),$id]); }
                if(!$erro) $msg='Usuário atualizado.';
            }
        }
    } catch (Throwable $e) { $erro='Erro: '.$e->getMessage().' (login duplicado?)'; }
}

$users = $pdo->query('SELECT id,nome,login,papel,ativo FROM users ORDER BY nome')->fetchAll();
$tools = $pdo->query('SELECT id,nome,slug FROM tools WHERE ativo=1 ORDER BY ordem,nome')->fetchAll();
$teams = $pdo->query('SELECT id,nome FROM teams ORDER BY nome')->fetchAll();

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

<div class="card eq-novo">
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="acao" value="criar">
    <input name="nome" placeholder="Nome" required>
    <input name="login" placeholder="Login" required>
    <input name="senha" type="password" placeholder="Senha (mín. 6)" required>
    <select name="papel"><option value="viewer">viewer</option><option value="gestor">gestor</option><option value="admin">admin</option></select>
    <button class="btn" type="submit">+ Criar usuário</button>
  </form>
</div>

<div class="card">
<table class="grid">
  <thead><tr><th>Nome</th><th>Login</th><th>Papel</th><th>Ativo</th><th></th></tr></thead>
  <tbody>
    <?php foreach($users as $usr): ?>
    <tr><td><?= h($usr['nome']) ?></td><td class="mut"><?= h($usr['login']) ?></td><td><?= h($usr['papel']) ?></td>
      <td><?= $usr['ativo']?'sim':'<span class=zero>não</span>' ?></td>
      <td><a href="/admin/usuarios.php?id=<?= (int)$usr['id'] ?>">editar</a></td></tr>
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
    <label style="font-size:13px">Papel<br><select name="papel">
      <?php foreach(['viewer','gestor','admin'] as $p): ?><option value="<?= $p ?>" <?= $edit['papel']===$p?'selected':'' ?>><?= $p ?></option><?php endforeach; ?>
    </select></label>
    <label style="font-size:13px">Nova senha (opcional)<br><input name="nova_senha" type="password" placeholder="deixe em branco p/ manter"></label>
    <label style="font-size:13px"><input type="checkbox" name="ativo" <?= $edit['ativo']?'checked':'' ?>> ativo</label>
    <button class="btn" type="submit">Salvar</button>
  </form>

  <div class="perm-cols">
    <div>
      <h3 style="font-size:13px;margin:8px 0">Ferramentas que pode abrir</h3>
      <?php foreach($tools as $t): ?>
        <label class="perm"><input type="checkbox" class="ck" data-kind="tool" data-id="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'],$uTools,true)?'checked':'' ?>> <?= h($t['nome']) ?></label>
      <?php endforeach; ?>
    </div>
    <div>
      <h3 style="font-size:13px;margin:8px 0">Equipes que pode ver (Painel)</h3>
      <?php if(!$teams): ?><span class="mut">Nenhuma equipe criada.</span><?php endif; ?>
      <?php foreach($teams as $t): ?>
        <label class="perm"><input type="checkbox" class="ck" data-kind="team" data-id="<?= (int)$t['id'] ?>" <?= in_array((int)$t['id'],$uTeams,true)?'checked':'' ?>> <?= h($t['nome']) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if($edit['papel']==='admin'): ?><p class="mut" style="margin-top:8px">Obs.: admin já enxerga todas as ferramentas e equipes automaticamente.</p><?php endif; ?>
</div>
<script>
const CSRF=document.querySelector('meta[name=csrf]').content; const UID=<?= (int)$edit['id'] ?>;
document.querySelectorAll('.ck').forEach(ck=> ck.addEventListener('change', async ()=>{
  const kind=ck.dataset.kind, id=ck.dataset.id, on=ck.checked?'1':'0';
  const action= kind==='tool'?'user_tool':'user_team';
  const body={action, user_id:UID, on, csrf:CSRF}; body[kind==='tool'?'tool_id':'team_id']=id;
  const r=await fetch('/admin/acao.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)});
  const j=await r.json(); if(!j.ok){ alert(j.msg||'erro'); ck.checked=!ck.checked; }
}));
</script>
<?php endif; ?>
<?php portal_footer();
