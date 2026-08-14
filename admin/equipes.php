<?php
/* admin/equipes.php — equipes + arrastar corretores (N:N), com:
   - equipes flutuantes (sticky) e roláveis;
   - corretores já em equipe vão pro fim, apagados, com contador (mas ainda arrastáveis);
   - desligar/religar corretor (sai/volta da lista de disponíveis; sync do GHL respeita). */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
$pdo = db();

$teams = $pdo->query('SELECT id, nome FROM teams ORDER BY nome')->fetchAll();

// contagem de equipes por corretor
$counts = [];
foreach ($pdo->query('SELECT broker_id, COUNT(*) c FROM team_brokers GROUP BY broker_id') as $r) {
    $counts[$r['broker_id']] = (int)$r['c'];
}
// membros por equipe
$mem = [];
foreach ($pdo->query('SELECT team_id, broker_id FROM team_brokers') as $r) {
    $mem[(int)$r['team_id']][] = $r['broker_id'];
}
// corretores
$ativos = $pdo->query('SELECT id, nome FROM brokers WHERE ativo=1 ORDER BY nome')->fetchAll();
$desligados = $pdo->query('SELECT id, nome FROM brokers WHERE ativo=0 ORDER BY nome')->fetchAll();
$bnAll = [];
foreach ($pdo->query('SELECT id, nome FROM brokers') as $r) $bnAll[$r['id']] = $r['nome'];

// separa ativos em: livres (0 equipes) e já em equipe (>=1)
$livres = []; $emEquipe = [];
foreach ($ativos as $b) {
    if (($counts[$b['id']] ?? 0) > 0) $emEquipe[] = $b; else $livres[] = $b;
}

function chip_pool(array $b, int $c): string {
    $badge = $c > 0 ? '<b class="badge">'.$c.'</b>' : '<b class="badge"></b>';
    return '<span class="chip" draggable="true" data-id="'.h($b['id']).'" data-nome="'.h($b['nome']).'" data-count="'.$c.'">'
         . '<span class="cn">'.h($b['nome']).'</span>'.$badge
         . '<i class="off" title="Desligar corretor">⊘</i></span>';
}

admin_header('equipes', $u);
?>
<h1 class="home-titulo">Equipes</h1>
<p class="home-sub">Arraste os corretores para dentro de cada equipe. As equipes ficam fixas à direita. Um corretor pode estar em várias equipes.</p>

<div class="eq-wrap">
  <!-- POOL -->
  <div class="eq-pool card">
    <input id="busca" class="eq-busca" placeholder="Filtrar corretores...">
    <div class="pool-sec-tit">Disponíveis</div>
    <div id="pool-livre" class="chips">
      <?php foreach ($livres as $b) echo chip_pool($b, 0); ?>
    </div>
    <div class="pool-sec-tit" style="margin-top:14px">Já em equipe(s)</div>
    <div id="pool-eq" class="chips dimzone">
      <?php foreach ($emEquipe as $b) echo chip_pool($b, $counts[$b['id']] ?? 0); ?>
    </div>
    <details class="pool-off-wrap" <?= $desligados ? '' : '' ?>>
      <summary>Desligados (<span id="offCount"><?= count($desligados) ?></span>)</summary>
      <div id="pool-off" class="chips">
        <?php foreach ($desligados as $b): ?>
          <span class="chip offed" data-id="<?= h($b['id']) ?>" data-nome="<?= h($b['nome']) ?>">
            <span class="cn"><?= h($b['nome']) ?></span><i class="on" title="Religar corretor">↺</i></span>
        <?php endforeach; ?>
      </div>
    </details>
  </div>

  <!-- EQUIPES (flutuam) -->
  <div class="eq-teams-col">
    <div class="eq-teams-float">
      <div class="eq-novo">
        <input id="novoNome" placeholder="Nome da nova equipe">
        <button class="btn" id="btnNova">+ Criar</button>
      </div>
      <div id="teams">
        <?php foreach ($teams as $t): $tid=(int)$t['id']; ?>
        <div class="card eq-team" data-team="<?= $tid ?>">
          <div class="eq-team-head">
            <b class="eq-team-nome"><?= h($t['nome']) ?></b>
            <span class="eq-team-acts"><a href="#" class="ren">renomear</a> · <a href="#" class="del">excluir</a></span>
          </div>
          <div class="chips drop">
            <?php foreach (($mem[$tid] ?? []) as $bid): if(!isset($bnAll[$bid])) continue; ?>
              <span class="chip mchip" data-id="<?= h($bid) ?>"><span class="cn"><?= h($bnAll[$bid]) ?></span><i class="x">×</i></span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF=document.querySelector('meta[name=csrf]').content;
async function acao(p){ p.csrf=CSRF; const r=await fetch('/admin/acao.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}); return r.json(); }
function esc(t){const d=document.createElement('div');d.textContent=t;return d.innerHTML;}

let dragId=null, dragNome=null;
document.addEventListener('dragstart', e=>{
  const c=e.target.closest('.chip'); if(!c||c.classList.contains('offed')){ e.preventDefault?.(); return; }
  dragId=c.dataset.id; dragNome=c.dataset.nome; e.dataTransfer.effectAllowed='copy';
});

function wireDrop(zone){
  zone.addEventListener('dragover', e=>{ e.preventDefault(); zone.classList.add('over'); });
  zone.addEventListener('dragleave', ()=> zone.classList.remove('over'));
  zone.addEventListener('drop', async e=>{
    e.preventDefault(); zone.classList.remove('over'); if(!dragId) return;
    const team=zone.closest('.eq-team').dataset.team;
    if(zone.querySelector('.mchip[data-id="'+CSS.escape(dragId)+'"]')) return;
    const r=await acao({action:'tb_add', team_id:team, broker_id:dragId});
    if(r.ok){ zone.appendChild(mkMember(dragId,dragNome)); bumpPool(dragId,+1); }
  });
}
function mkMember(id,nome){ const s=document.createElement('span'); s.className='chip mchip'; s.dataset.id=id;
  s.innerHTML='<span class="cn">'+esc(nome)+'</span><i class="x">×</i>'; return s; }

// Atualiza contador do chip no pool e move entre "Disponíveis" e "Já em equipe"
function bumpPool(id, delta){
  const chip=document.querySelector('#pool-livre .chip[data-id="'+CSS.escape(id)+'"], #pool-eq .chip[data-id="'+CSS.escape(id)+'"]');
  if(!chip) return;
  let c=parseInt(chip.dataset.count||'0')+delta; if(c<0)c=0; chip.dataset.count=c;
  chip.querySelector('.badge').textContent = c>0? c : '';
  if(c>0) document.getElementById('pool-eq').appendChild(chip);
  else    document.getElementById('pool-livre').appendChild(chip);
}

document.addEventListener('click', async e=>{
  // remover corretor da equipe
  if(e.target.classList.contains('x')){
    const chip=e.target.closest('.mchip'); const team=chip.closest('.eq-team').dataset.team;
    const r=await acao({action:'tb_remove', team_id:team, broker_id:chip.dataset.id});
    if(r.ok){ bumpPool(chip.dataset.id,-1); chip.remove(); } return;
  }
  // desligar corretor
  if(e.target.classList.contains('off')){
    const chip=e.target.closest('.chip'); if(!confirm('Desligar '+chip.dataset.nome+'? Ele sai da lista de disponíveis e não volta no sync do GHL (mas fica recuperável em Desligados).')) return;
    const r=await acao({action:'broker_deactivate', broker_id:chip.dataset.id});
    if(r.ok){ moveToOff(chip); } return;
  }
  // religar corretor
  if(e.target.classList.contains('on')){
    const chip=e.target.closest('.chip'); const r=await acao({action:'broker_activate', broker_id:chip.dataset.id});
    if(r.ok){ moveFromOff(chip); } return;
  }
  // renomear / excluir equipe
  if(e.target.classList.contains('ren')){ e.preventDefault();
    const card=e.target.closest('.eq-team'); const nomeEl=card.querySelector('.eq-team-nome');
    const novo=prompt('Novo nome da equipe:', nomeEl.textContent); if(!novo) return;
    const r=await acao({action:'team_rename', team_id:card.dataset.team, nome:novo}); if(r.ok) nomeEl.textContent=novo; return;
  }
  if(e.target.classList.contains('del')){ e.preventDefault();
    const card=e.target.closest('.eq-team'); if(!confirm('Excluir esta equipe? Os corretores não são apagados.')) return;
    const r=await acao({action:'team_delete', team_id:card.dataset.team});
    if(r.ok){ // devolve contagem dos membros ao pool
      card.querySelectorAll('.mchip').forEach(m=> bumpPool(m.dataset.id,-1)); card.remove(); } return;
  }
});

const offCount=document.getElementById('offCount');
function moveToOff(chip){
  const id=chip.dataset.id, nome=chip.dataset.nome;
  chip.remove();
  const s=document.createElement('span'); s.className='chip offed'; s.dataset.id=id; s.dataset.nome=nome;
  s.innerHTML='<span class="cn">'+esc(nome)+'</span><i class="on" title="Religar corretor">↺</i>';
  document.getElementById('pool-off').appendChild(s);
  offCount.textContent = (+offCount.textContent)+1;
}
function moveFromOff(chip){
  const id=chip.dataset.id, nome=chip.dataset.nome;
  // conta em quantas equipes ele ainda está (pelos chips de membro na tela)
  const c=document.querySelectorAll('.mchip[data-id="'+CSS.escape(id)+'"]').length;
  chip.remove();
  const s=document.createElement('span'); s.className='chip'; s.draggable=true; s.dataset.id=id; s.dataset.nome=nome; s.dataset.count=c;
  s.innerHTML='<span class="cn">'+esc(nome)+'</span><b class="badge">'+(c>0?c:'')+'</b><i class="off" title="Desligar corretor">⊘</i>';
  document.getElementById(c>0?'pool-eq':'pool-livre').appendChild(s);
  offCount.textContent = Math.max(0,(+offCount.textContent)-1);
}

document.getElementById('btnNova').addEventListener('click', async ()=>{
  const inp=document.getElementById('novoNome'); const nome=inp.value.trim(); if(!nome) return;
  const r=await acao({action:'team_create', nome}); if(!r.ok){ alert(r.msg||'erro'); return; }
  inp.value='';
  const w=document.createElement('div'); w.className='card eq-team'; w.dataset.team=r.id;
  w.innerHTML='<div class="eq-team-head"><b class="eq-team-nome">'+esc(r.nome)+'</b><span class="eq-team-acts"><a href="#" class="ren">renomear</a> · <a href="#" class="del">excluir</a></span></div><div class="chips drop"></div>';
  document.getElementById('teams').prepend(w); wireDrop(w.querySelector('.drop'));
});

document.getElementById('busca').addEventListener('input', e=>{
  const q=e.target.value.toLowerCase();
  document.querySelectorAll('.eq-pool .chip').forEach(c=> c.style.display = c.dataset.nome.toLowerCase().includes(q)?'':'none');
});

document.querySelectorAll('.drop').forEach(wireDrop);
</script>
<?php portal_footer();
