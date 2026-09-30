<?php
/* =====================================================================
   listas-3c/modelos.php: editar os modelos de lista.

   O Jhony descreveu os critérios com "depende" (do período do mês, de a
   lista ser recente ou antiga). Então motivos, etapas e a janela de lead
   de corretor ficam editáveis aqui, sem mexer em código. Grava em
   l3c_config('modelos'); listas já montadas guardam a foto do modelo que
   usaram (l3c_listas.parametros) e não mudam.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('listas-3c');
$modelos = l3c_modelos();
$catalogo = l3c_catalogo();
sort($catalogo, SORT_LOCALE_STRING);
$salvo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $novo = [];
    $nunca = array_values(array_intersect($catalogo, (array)($_POST['nunca'] ?? [])));
    foreach ($modelos as $slug => $m) {
        $novo[$slug] = [
            'etapas' => array_map('intval', (array)($_POST['etapas'][$slug] ?? [])),
            'motivos' => array_values(array_intersect($catalogo, (array)($_POST['motivos'][$slug] ?? []))),
            'dias_origem_corretor' => max(0, (int)($_POST['dias'][$slug] ?? 0)),
            'motivos_nunca' => $nunca,
        ];
    }
    l3c_config_set('modelos', $novo);
    $modelos = l3c_modelos();
    $salvo = true;
}

portal_header('Modelos · Listas 3C', $u);
$nuncaAtual = array_map([L3cTratamento::class, 'chaveMotivo'], (array)(reset($modelos)['motivos_nunca'] ?? []));
$marcado = fn(array $lista, string $mot) => in_array(L3cTratamento::chaveMotivo($mot), array_map([L3cTratamento::class, 'chaveMotivo'], $lista), true);
?>
<style>
.l3-card{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:18px 20px;margin:0 0 18px}
.l3-mot{columns:2 260px;font-size:13px;margin:8px 0 0}
.l3-mot label{display:block;break-inside:avoid;padding:2px 0}
.l3-etapas label{margin-right:14px;font-size:14px}
.l3-btn{font:inherit;font-weight:600;padding:10px 16px;border:none;border-radius:4px;background:var(--moss);color:#fff;cursor:pointer}
.l3-desc{color:var(--mute);font-size:13px;margin:4px 0 0}
</style>
<p><a href="./" style="color:var(--moss)">← Listas 3C</a></p>
<h1 class="home-titulo">Modelos de lista</h1>
<p class="home-sub">Marque o que entra em cada modelo. Vale para as próximas listas.</p>
<?php if ($salvo): ?><div class="ok-box">Modelos salvos.</div><?php endif; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <?php foreach ($modelos as $slug => $m): ?>
  <div class="l3-card">
    <h2 style="font-size:16px;margin:0"><?= h($m['nome']) ?></h2>
    <p class="l3-desc"><?= h($m['descricao']) ?></p>
    <p class="l3-etapas" style="margin:10px 0 0"><b style="font-size:13px">Etapas:</b>
      <?php foreach ([0, 1, 2, 3] as $e): ?>
        <label><input type="checkbox" name="etapas[<?= h($slug) ?>][]" value="<?= $e ?>" <?= in_array($e, $m['etapas'], true) ? 'checked' : '' ?>> <?= h(L3cTratamento::ETAPAS[$e]) ?></label>
      <?php endforeach; ?>
      <span class="l3-desc">(Proposta e Negociado nunca entram)</span>
    </p>
    <p style="margin:8px 0 0;font-size:14px"><label>Deixar de fora lead de origem do corretor com menos de
      <input type="number" min="0" max="365" name="dias[<?= h($slug) ?>]" value="<?= (int)$m['dias_origem_corretor'] ?>" style="width:70px"> dias</label></p>
    <?php if ($m['tipo'] === 'encerrados'): ?>
      <p style="margin:10px 0 0;font-size:13px"><b>Motivos de encerramento que entram</b></p>
      <div class="l3-mot">
        <?php foreach ($catalogo as $mot): if (in_array(L3cTratamento::chaveMotivo($mot), $nuncaAtual, true)) continue; ?>
          <label><input type="checkbox" name="motivos[<?= h($slug) ?>][]" value="<?= h($mot) ?>" <?= $marcado($m['motivos'], $mot) ? 'checked' : '' ?>> <?= h($mot) ?></label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

  <div class="l3-card">
    <h2 style="font-size:16px;margin:0">Nunca entram (em nenhum modelo)</h2>
    <p class="l3-desc">Vence a marcação dos modelos acima.</p>
    <div class="l3-mot">
      <?php foreach ($catalogo as $mot): ?>
        <label><input type="checkbox" name="nunca[]" value="<?= h($mot) ?>" <?= in_array(L3cTratamento::chaveMotivo($mot), $nuncaAtual, true) ? 'checked' : '' ?>> <?= h($mot) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <button class="l3-btn" type="submit">Salvar modelos</button>
</form>
<?php
portal_footer();
