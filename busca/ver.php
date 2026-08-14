<?php
/* =====================================================================
   ver.php — página PÚBLICA da seleção que o corretor manda ao cliente.
   Não exige login: o link é o segredo (token aleatório de 32 caracteres).

   Mostra apenas o que é material de divulgação. Ficam DE FORA, de propósito:
     - nome do proprietário / construtora
     - avisos de inconsistência de cadastro
     - datas de atualização e alteração
     - pendências, peso de auditoria, telefone de quem quer que seja
   ===================================================================== */

require_once __DIR__ . '/config.php';

$token = $_GET['s'] ?? '';
if (!preg_match('/^[a-zA-Z0-9]{16,64}$/', $token)) { http_response_code(404); exit('Link inválido.'); }

$arq = DATA_DIR . '/sel_' . $token . '.json';
if (!is_readable($arq)) { http_response_code(404); exit('Este link não existe mais.'); }

$sel = json_decode(file_get_contents($arq), true);
if (!$sel || empty($sel['imoveis'])) { http_response_code(404); exit('Seleção vazia.'); }

// Expira em 90 dias para o link não circular para sempre.
if (!empty($sel['criado_em']) && strtotime($sel['criado_em']) < strtotime('-90 days')) {
    http_response_code(410); exit('Este link expirou.');
}

$brl = function ($v) { return $v ? 'R$ ' . number_format($v, 0, ',', '.') : 'Sob consulta'; };
// Remove os caracteres de ícone que o CRM insere nos títulos (área privada
// do Unicode, E000-F8FF). No navegador do cliente eles viram quadradinho.
$limpa = function ($v) {
    $v = preg_replace('/[\x{E000}-\x{F8FF}\x{FE00}-\x{FE0F}\x{1F000}-\x{1FAFF}]/u', '', (string)$v);
    return trim(preg_replace('/\s{2,}/u', ' ', $v));
};
$esc = function ($v) use ($limpa) { return htmlspecialchars($limpa($v), ENT_QUOTES, 'UTF-8'); };

// Muita foto vem com o nome do arquivo no lugar da legenda ("WhatsApp Image
// 2026-08-06 at 14.30.43", "IMG_2287"). Isso não diz nada ao cliente e passa
// impressão de descuido, então some. Legenda de verdade ("Fachada", "Living")
// continua aparecendo.
$legenda = function ($v) use ($limpa) {
    $v = $limpa($v);
    if ($v === '') return '';
    $lixo = [
        '/^whatsapp\s*(image|video)/i',
        '/^(img|dsc|dscn|foto|image|imagem|photo|pxl|scan)[\s_-]*\d+/i',
        '/^\d{4}[-_]?\d{2}[-_]?\d{2}/',
        '/^\d{6,}$/',
        '/\.(jpe?g|png|webp|heic|gif)$/i',
        '/^sem\s*(titulo|nome)$/i',
    ];
    foreach ($lixo as $re) if (preg_match($re, $v)) return '';
    return $v;
};
?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Imóveis selecionados — Imobiliária Camargo</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#12211c;--paper:#f2efe6;--card:#fff;--moss:#2f5d4f;--line:#d8d3c4;--mute:#6b7770}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,system-ui,sans-serif;line-height:1.55}
.wrap{max-width:860px;margin:0 auto;padding:30px 18px 60px}
header{border-bottom:2px solid var(--ink);padding-bottom:16px;margin-bottom:26px}
h1{font-family:Fraunces,Georgia,serif;font-size:clamp(24px,5vw,34px);margin:0 0 4px}
.sub{color:var(--mute);font-size:14px}
.im{background:var(--card);border:1px solid var(--line);border-radius:4px;overflow:hidden;margin-bottom:22px}
.fotos{display:grid;grid-template-columns:2fr 1fr 1fr;gap:3px;background:var(--line)}
.fotos a{display:block;line-height:0;position:relative;cursor:pointer}
.maisfotos{position:absolute;inset:0;background:rgba(18,33,28,.62);color:#fff;
  display:flex;align-items:center;justify-content:center;font-family:Fraunces,serif;
  font-size:26px;font-weight:700;line-height:1}
.vertodas{display:block;width:100%;padding:10px;background:var(--card);border:none;
  border-top:1px solid var(--line);font:inherit;font-size:13.5px;color:var(--moss);
  cursor:pointer;font-weight:600}
.vertodas:hover{background:#f7f5ee}
.fotos img{width:100%;height:100%;object-fit:cover;aspect-ratio:4/3}
.fotos a:first-child img{aspect-ratio:16/11}
.corpo{padding:18px 20px}
h2{font-family:Fraunces,serif;font-size:20px;margin:0 0 6px;line-height:1.25}
.local{color:var(--mute);font-size:13.5px;margin-bottom:12px}
.preco{font-family:Fraunces,serif;font-size:25px;font-weight:700;margin-bottom:12px}
.specs{display:flex;gap:20px;flex-wrap:wrap;font-size:13.5px;color:var(--mute);
  border-top:1px solid var(--line);border-bottom:1px solid var(--line);padding:11px 0;margin-bottom:12px}
.specs b{color:var(--ink)}
.desc{font-size:14px;color:#3d4a44;white-space:pre-line}
.perto{margin-top:14px;padding-top:13px;border-top:1px solid var(--line)}
.perto b{font-size:12.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--moss)}
.perto ul{list-style:none;margin:8px 0 0;padding:0;display:grid;
  grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:5px 18px}
.perto li{font-size:13.5px;color:#3d4a44;display:flex;justify-content:space-between;
  gap:10px;border-bottom:1px dotted var(--line);padding-bottom:3px}
.perto li span{color:var(--mute);font-size:12.5px;white-space:nowrap}
.tags{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.tag{font-size:11.5px;padding:3px 9px;background:rgba(47,93,79,.09);color:var(--moss);border-radius:2px}
footer{margin-top:34px;padding-top:20px;border-top:1px solid var(--line);color:var(--mute);font-size:13px}
.print{margin:0 0 22px;padding:11px 20px;background:var(--moss);color:#fff;border:none;
  border-radius:3px;font:inherit;font-weight:600;cursor:pointer}
@media print{
  .print{display:none} body{background:#fff}
  .im{break-inside:avoid;page-break-inside:avoid;border:1px solid #ccc}
  .wrap{max-width:none;padding:0}
}
@media(max-width:600px){.fotos{grid-template-columns:1fr 1fr}}
</style></head><body>
<div class="wrap">
<header>
  <h1>Imóveis selecionados para você</h1>
  <div class="sub">Imobiliária Camargo · CRECI 4996 PJ · <?= count($sel['imoveis']) ?> opç<?= count($sel['imoveis'])>1?'ões':'ão' ?></div>
</header>

<button class="print" onclick="window.print()">Salvar como PDF / Imprimir</button>

<?php foreach ($sel['imoveis'] as $x): ?>
<div class="im">
  <?php if (!empty($x['fotos'])):
    $fotos = array_values($x['fotos']);
    $total = count($fotos);
    $capa  = array_slice($fotos, 0, 3);
    // Todas as fotos vão no atributo data-*; a galeria lê daí, sem nova
    // requisição ao servidor. A grade mostra 3 só por causa do layout.
    $payload = [];
    foreach ($fotos as $ft) {
      $payload[] = ['g' => $ft['g'] ?? $ft['p'], 'p' => $ft['p'], 'l' => $legenda($ft['leg'] ?? '')];
    }
  ?>
  <div class="fotos" data-fotos='<?= $esc(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
       data-titulo="<?= $esc($x['ti'] ?? 'Imóvel') ?>">
    <?php foreach ($capa as $i => $ft): ?>
      <a href="<?= $esc($ft['g'] ?? $ft['p']) ?>" data-i="<?= $i ?>">
        <img src="<?= $esc($ft['p']) ?>" alt="<?= $esc($legenda($ft['leg'] ?? '')) ?>" loading="lazy">
        <?php if ($i === 2 && $total > 3): ?>
          <span class="maisfotos">+<?= $total - 3 ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($total > 1): ?>
    <button class="vertodas" data-abrir="0"><?= $total ?> fotos — ver todas</button>
  <?php endif; ?>
  <?php endif; ?>
  <div class="corpo">
    <h2><?= $esc($x['ti'] ?? 'Imóvel') ?></h2>
    <div class="local"><?= $esc(trim(($x['b'] ?? '') . ', ' . ($x['ci'] ?? ''), ', ')) ?><?= !empty($x['r']) ? ' · zona ' . $esc(strtolower($x['r'])) : '' ?></div>
    <div class="preco"><?= $brl($x['p'] ?? null) ?></div>
    <div class="specs">
      <?php if (!empty($x['q'])): ?><span><b><?= (int)$x['q'] ?></b> quartos</span><?php endif; ?>
      <?php if (!empty($x['su'])): ?><span><b><?= (int)$x['su'] ?></b> suíte<?= $x['su']>1?'s':'' ?></span><?php endif; ?>
      <?php if (!empty($x['ba'])): ?><span><b><?= (int)$x['ba'] ?></b> banheiros</span><?php endif; ?>
      <?php if (!empty($x['v'])):  ?><span><b><?= (int)$x['v'] ?></b> vagas</span><?php endif; ?>
      <?php if (!empty($x['a'])):  ?><span><b><?= number_format($x['a'], 2, ',', '.') ?></b> m²</span><?php endif; ?>
      <?php if (!empty($x['ea'])): ?><span>Entrega <b><?= (!empty($x['em']) ? str_pad($x['em'],2,'0',STR_PAD_LEFT).'/' : '') . (int)$x['ea'] ?></b></span><?php endif; ?>
    </div>
    <?php if (!empty($x['d'])): ?><div class="desc"><?= $esc($x['d']) ?></div><?php endif; ?>
    <?php if (!empty($x['perto'])): ?>
      <div class="perto">
        <b>O que tem por perto</b>
        <ul>
        <?php foreach ($x['perto'] as $p): ?>
          <li><?= $esc($p['n']) ?><?php
            if ($p['km'] !== null) {
              $pre = !empty($x['aprox']) ? '~' : '';
              echo '<span>' . $pre . ($p['km'] < 1
                    ? round($p['km'] * 1000) . ' m'
                    : number_format($p['km'], 1, ',', '.') . ' km') . '</span>';
            } ?></li>
        <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php if (!empty($x['am'])): ?>
      <div class="tags"><?php foreach (array_slice($x['am'], 0, 10) as $a): ?><span class="tag"><?= $esc($a) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<footer>
  Imobiliária Camargo Joinville Ltda · CRECI 4996 PJ<br>
  (47) 3278-8371 · (47) 99995-4045 · comercial@imobcamargo.com.br<br>
  <span style="font-size:12px">Valores e disponibilidade sujeitos a alteração sem aviso prévio.</span>
</footer>
</div>

<!-- Galeria em tela cheia -->
<div id="galeria" class="gal" hidden>
  <button class="gal-x" id="galX" aria-label="Fechar">&times;</button>
  <button class="gal-nav gal-ant" id="galAnt" aria-label="Anterior">&#8249;</button>
  <button class="gal-nav gal-prox" id="galProx" aria-label="Próxima">&#8250;</button>
  <figure class="gal-fig">
    <img id="galImg" alt="">
    <figcaption id="galCap"></figcaption>
  </figure>
  <div class="gal-tiras" id="galTiras"></div>
</div>

<style>
.gal{position:fixed;inset:0;background:rgba(12,20,17,.96);z-index:100;display:flex;
  flex-direction:column;align-items:center;justify-content:center}
.gal[hidden]{display:none}
.gal-fig{margin:0;max-width:94vw;max-height:72vh;display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:10px}
.gal-fig img{max-width:94vw;max-height:66vh;object-fit:contain;border-radius:3px;
  background:#1a2a24}
.gal-fig figcaption{color:#e8e4d8;font-size:14px;text-align:center;min-height:20px}
.gal-fig figcaption span{color:#9aa8a1;font-size:12.5px;display:block;margin-top:3px}
.gal-x{position:absolute;top:14px;right:18px;background:none;border:none;color:#fff;
  font-size:38px;line-height:1;cursor:pointer;padding:4px 12px;opacity:.85}
.gal-x:hover{opacity:1}
.gal-nav{position:absolute;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.35);
  border:none;color:#fff;font-size:44px;line-height:1;cursor:pointer;padding:14px 18px;
  border-radius:4px;opacity:.8}
.gal-nav:hover{opacity:1;background:rgba(0,0,0,.55)}
.gal-ant{left:10px} .gal-prox{right:10px}
.gal-tiras{display:flex;gap:6px;overflow-x:auto;max-width:94vw;padding:14px 4px 4px}
.gal-tiras img{height:56px;width:76px;object-fit:cover;border-radius:2px;cursor:pointer;
  opacity:.45;border:2px solid transparent;flex-shrink:0}
.gal-tiras img.on{opacity:1;border-color:#5c8a78}
@media(max-width:600px){
  .gal-nav{font-size:32px;padding:10px 12px}
  .gal-fig img{max-height:56vh}
  .gal-tiras img{height:44px;width:60px}
}
@media print{.gal{display:none !important}}
</style>

<script>
(function(){
  var fotos = [], i = 0;
  var g    = document.getElementById('galeria');
  var img  = document.getElementById('galImg');
  var cap  = document.getElementById('galCap');
  var tiras= document.getElementById('galTiras');

  function mostrar(n){
    if(!fotos.length) return;
    i = (n + fotos.length) % fotos.length;
    var f = fotos[i];
    img.src = f.g || f.p;
    img.alt = f.l || '';
    cap.innerHTML = (f.l ? f.l : '') + '<span>' + (i+1) + ' de ' + fotos.length + '</span>';
    Array.prototype.forEach.call(tiras.children, function(t, k){
      t.classList.toggle('on', k === i);
    });
    var atual = tiras.children[i];
    if(atual && atual.scrollIntoView){
      try{ atual.scrollIntoView({block:'nearest', inline:'center', behavior:'smooth'}); }
      catch(e){ atual.scrollIntoView(false); }   // navegadores antigos
    }
    // pré-carrega vizinhas para a navegação não piscar
    [i+1, i-1].forEach(function(k){
      var v = fotos[(k + fotos.length) % fotos.length];
      if(v){ var im = new Image(); im.src = v.g || v.p; }
    });
  }

  function abrir(lista, n){
    fotos = lista;
    tiras.innerHTML = '';
    fotos.forEach(function(f, k){
      var t = document.createElement('img');
      t.src = f.p; t.loading = 'lazy'; t.alt = f.l || '';
      t.onclick = function(){ mostrar(k); };
      tiras.appendChild(t);
    });
    g.hidden = false;
    document.body.style.overflow = 'hidden';
    mostrar(n);
  }

  function fechar(){
    g.hidden = true;
    document.body.style.overflow = '';
    img.src = '';
  }

  // Abre pelas miniaturas e pelo botão "ver todas".
  document.querySelectorAll('.fotos').forEach(function(bloco){
    var lista;
    try{ lista = JSON.parse(bloco.dataset.fotos || '[]'); }catch(e){ lista = []; }
    bloco.querySelectorAll('a').forEach(function(a){
      a.addEventListener('click', function(ev){
        ev.preventDefault();          // não abre o arquivo em outra aba
        abrir(lista, parseInt(a.dataset.i, 10) || 0);
      });
    });
    var bt = bloco.parentNode.querySelector('.vertodas');
    if(bt) bt.addEventListener('click', function(){ abrir(lista, 0); });
  });

  document.getElementById('galX').onclick    = fechar;
  document.getElementById('galAnt').onclick  = function(){ mostrar(i-1); };
  document.getElementById('galProx').onclick = function(){ mostrar(i+1); };
  g.addEventListener('click', function(e){ if(e.target === g) fechar(); });

  document.addEventListener('keydown', function(e){
    if(g.hidden) return;
    if(e.key === 'Escape') fechar();
    else if(e.key === 'ArrowRight') mostrar(i+1);
    else if(e.key === 'ArrowLeft')  mostrar(i-1);
  });

  // Arrastar o dedo no celular.
  var x0 = null;
  g.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, {passive:true});
  g.addEventListener('touchend', function(e){
    if(x0 === null) return;
    var d = e.changedTouches[0].clientX - x0;
    if(Math.abs(d) > 45) mostrar(d < 0 ? i+1 : i-1);
    x0 = null;
  }, {passive:true});
})();
</script>
</body></html>
