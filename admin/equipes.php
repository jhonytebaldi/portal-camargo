<?php
/* admin/equipes.php — equipes + arrastar corretores (N:N). */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
$pdo = db();

$brokers = $pdo->query('SELECT id, nome FROM brokers WHERE ativo=1 ORDER BY nome')->fetchAll();
$teams   = $pdo->query('SELECT id, nome FROM teams ORDER BY nome')->fetchAll();
$mem = [];
foreach ($pdo->query('SELECT team_id, broker_id FROM team_brokers') as $r) {
    $mem[(int)$r['team_id']][] = $r['broker_id'];
}
$bn = [];
foreach ($brokers as $b) $bn[$b['id']] = $b['nome'];

admin_header('equipes', $u);
?>
<h1 class="home-titulo">Equipes</h1>
<p class="home-sub">Arraste os corretores da esquerda para dentro de cada equipe. Um corretor pode estar em várias equipes.</p>

<?php if (!$brokers): ?>
  <div class="aviso">Nenhum corretor cadastrado ainda. Vá em <a href="/admin/corretores.php">Corretores</a> e clique em “Sincronizar do GHL”.</div>
<?php else: ?>
<div class="eq-wrap">
  <div class="eq-pool card">
    <h2 style="font-size:14px;margin:0 0 8px">Corretores</h2>
    <input id="busca" class="eq-busca" placeholder="Filtrar...">
    <div id="pool" class="chips">
      <?php foreach ($brokers as $b): ?>
        <span class="chip" draggable="true" data-id="<?= h($b['id']) ?>" data-nome="<?= h($b['nome']) ?>"><?= h($b['nome']) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="eq-teams">
    <div class="eq-novo card">
      <input id="novoNome" placeholder="Nome da nova equipe">
      <button class="btn" id="btnNova">+ Criar equipe</button>
    </div>
    <div id="teams">
      <?php foreach ($teams as $t): $tid=(int)$t['id']; ?>
      <div class="card eq-team" data-team="<?= $tid ?>">
        <div class="eq-team-head">
          <b class="eq-team-nome"><?= h($t['nome']) ?></b>
          <span class="eq-team-acts">
            <a href="#" class="ren">renomear</a> · <a href="#" class="del">excluir</a>
          </span>
        </div>
        <div class="chips drop">
          <?php foreach (($mem[$tid] ?? []) as $bid): if(!isset($bn[$bid])) continue; ?>
            <span class="chip mchip" data-id="<?= h($bid) ?>"><?= h($bn[$bid]) ?><i class="x">×</i></span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
const CSRF = document.querySelector('meta[name=csrf]').content;
async function acao(p){ p.csrf=CSRF; const r=await fetch('/admin/acao.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}); return r.json(); }

let dragId=null, dragNome=null;
document.addEventListener('dragstart', e=>{
  if(e.target.classList.contains('chip')){ dragId=e.target.dataset.id; dragNome=e.target.dataset.nome||e.target.textContent.replace('×','').trim(); e.dataTransfer.effectAllowed='copy'; }
});
function wireDrop(zone){
  zone.addEventListener('dragover', e=>{ e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragleave', ()=> zone.classList.remove('over'));
  zone.addEventListener('drop', async e=>{
    e.preventDefault(); zone.classList.remove('over');
    if(!dragId) return;
    const team = zone.closest('.eq-team').dataset.team;
    if(zone.querySelector('.mchip[data-id="'+CSS.escape(dragId)+'"]')) return; // já está
    const r = await acao({action:'tb_add', team_id:team, broker_id:dragId});
    if(r.ok){ zone.appendChild(mkChip(dragId, dragNome)); }
  });
}
function mkChip(id,nome){
  const s=document.createElement('span'); s.className='chip mchip'; s.dataset.id=id;
  s.innerHTML = escapeHtml(nome)+'<i class="x">×</i>'; return s;
}
function escapeHtml(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

document.addEventListener('click', async e=>{
  if(e.target.classList.contains('x')){
    const chip=e.target.closest('.mchip'); const team=chip.closest('.eq-team').dataset.team;
    const r=await acao({action:'tb_remove', team_id:team, broker_id:chip.dataset.id});
    if(r.ok) chip.remove();
  }
  if(e.target.classList.contains('ren')){ e.preventDefault();
    const card=e.target.closest('.eq-team'); const nomeEl=card.querySelector('.eq-team-nome');
    const novo=prompt('Novo nome da equipe:', nomeEl.textContent); if(!novo) return;
    const r=await acao({action:'team_rename', team_id:card.dataset.team, nome:novo}); if(r.ok) nomeEl.textContent=novo;
  }
  if(e.target.classList.contains('del')){ e.preventDefault();
    const card=e.target.closest('.eq-team');
    if(!confirm('Excluir esta equipe? Os corretores não são apagados, só a equipe.')) return;
    const r=await acao({action:'team_delete', team_id:card.dataset.team}); if(r.ok) card.remove();
  }
});

document.getElementById('btnNova')?.addEventListener('click', async ()=>{
  const inp=document.getElementById('novoNome'); const nome=inp.value.trim(); if(!nome) return;
  const r=await acao({action:'team_create', nome}); if(!r.ok){ alert(r.msg||'erro'); return; }
  inp.value='';
  const wrap=document.createElement('div'); wrap.className='card eq-team'; wrap.dataset.team=r.id;
  wrap.innerHTML='<div class="eq-team-head"><b class="eq-team-nome">'+escapeHtml(r.nome)+'</b><span class="eq-team-acts"><a href="#" class="ren">renomear</a> · <a href="#" class="del">excluir</a></span></div><div class="chips drop"></div>';
  document.getElementById('teams').prepend(wrap); wireDrop(wrap.querySelector('.drop'));
});

document.getElementById('busca')?.addEventListener('input', e=>{
  const q=e.target.value.toLowerCase();
  document.querySelectorAll('#pool .chip').forEach(c=> c.style.display = c.textContent.toLowerCase().includes(q)?'':'none');
});

document.querySelectorAll('.drop').forEach(wireDrop);
</script>
<?php portal_footer();
