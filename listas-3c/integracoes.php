<?php
/* =====================================================================
   listas-3c/integracoes.php: "Configurar integrações" (só admin).

   Por que existe: tirar do Jhony o passo técnico de editar o config.php no
   servidor. Aqui um admin do portal cola a chave, o módulo TESTA na hora
   contra a API de verdade e só grava se ela responder. Chave nunca volta
   para o navegador: a tela mostra só os 4 últimos caracteres.
   Onde fica e como é cifrada: ver lib/Integracoes.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('listas-3c');
// Chave de API é decisão de quem administra o portal: quem só usa a
// ferramenta (o Guilherme, por exemplo) monta e aprova listas, mas não
// troca credencial.
if (!is_admin($u)) { http_response_code(403); exit('Apenas administradores.'); }

$I = L3cIntegracoes::class;
$msgOk = []; $msgErro = [];

/** Testa o Robust com uma leitura mínima (1 atendimento). */
$testaRobust = function (string $nick, string $chave): ?string {
    try { (new L3cRobust($nick, $chave))->get('/atendimentos', ['per_page' => 1]); return null; }
    catch (Throwable $e) { return $e->getMessage(); }
};
/** Testa o 3C listando as campanhas; devolve [erro, campanhas]. */
$testa3c = function (string $base, string $tok): array {
    try { return [null, (new L3cTresC(rtrim($base, '/'), $tok))->todasCampanhas()]; }
    catch (Throwable $e) { return [$e->getMessage(), []]; }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $acao = (string)($_POST['acao'] ?? 'salvar');
    $novos = []; $limpar = [];

    if ($acao === 'salvar') {
        // ---- Robust: só testa/grava se algo foi digitado ----
        $nick = trim((string)($_POST['robust_nickname'] ?? ''));
        $chave = trim((string)($_POST['robust_api_key'] ?? ''));
        $nickAtual = (string)($I::valor('robust_nickname')['valor'] ?? '');
        if ($chave !== '' || ($nick !== '' && $nick !== $nickAtual)) {
            $nickUsar = $nick !== '' ? $nick : $nickAtual;
            $chaveUsar = $chave !== '' ? $chave : (string)($I::valor('robust_api_key')['valor'] ?? '');
            $erro = ($nickUsar === '' || $chaveUsar === '') ? 'Preencha o apelido e a chave do Robust.' : $testaRobust($nickUsar, $chaveUsar);
            if ($erro) { $msgErro[] = "Robust: não salvei, a chave não funcionou ($erro)."; }
            else {
                $novos['robust_nickname'] = $nickUsar;
                if ($chave !== '') $novos['robust_api_key'] = $chave;
                $msgOk[] = 'Robust: chave testada e salva.';
            }
        }
        if (!empty($_POST['limpar_robust'])) { $limpar[] = 'robust_api_key'; $limpar[] = 'robust_nickname'; $msgOk[] = 'Robust: voltou a usar a chave da Busca / config.php.'; }

        // ---- 3C ----
        $base = trim((string)($_POST['tresc_base_url'] ?? ''));
        $tok = trim((string)($_POST['tresc_api_token'] ?? ''));
        $baseAtual = (string)$I::valor('tresc_base_url')['valor'];
        if ($tok !== '' || ($base !== '' && $base !== $baseAtual)) {
            $baseUsar = $base !== '' ? $base : $baseAtual;
            $tokUsar = $tok !== '' ? $tok : (string)($I::valor('tresc_api_token')['valor'] ?? '');
            if (!preg_match('~^https://[a-z0-9.-]+\.3c\.(plus|fluxo\.com\.br)/api/v1/?$~i', $baseUsar)) {
                // Endereço fixo do 3C: evita mandar o token para um domínio digitado errado.
                $msgErro[] = '3C: o endereço precisa ser do tipo https://SUAEMPRESA.3c.plus/api/v1';
            } elseif ($tokUsar === '') {
                $msgErro[] = '3C: cole o token.';
            } else {
                [$erro, $camps] = $testa3c($baseUsar, $tokUsar);
                if ($erro) { $msgErro[] = "3C: não salvei, o token não funcionou ($erro)."; }
                else {
                    $novos['tresc_base_url'] = rtrim($baseUsar, '/');
                    if ($tok !== '') $novos['tresc_api_token'] = $tok;
                    $msgOk[] = '3C: token testado e salvo (' . count($camps) . ' campanhas visíveis).';
                }
            }
        }
        if (!empty($_POST['limpar_3c'])) { $limpar[] = 'tresc_api_token'; $limpar[] = 'tresc_base_url'; $msgOk[] = '3C: voltou a usar o config.php.'; }

        // ---- Trava de campanhas: só quando a lista foi mostrada ----
        if (isset($_POST['tem_campanhas'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['campanhas'] ?? [])))));
            if ($ids) { $novos['campanhas_permitidas'] = $ids; $msgOk[] = 'Campanhas liberadas: ' . count($ids) . '.'; }
            else { $limpar[] = 'campanhas_permitidas'; }
        }

        if ($novos || $limpar) $I::salvar($novos, $limpar);
    }
}

// ---- Estado atual (depois de salvar) ----
$rNick = $I::valor('robust_nickname'); $rKey = $I::valor('robust_api_key');
$tBase = $I::valor('tresc_base_url'); $tTok = $I::valor('tresc_api_token');
$perm = L3cTresC::permitidas();
$origemTxt = ['tela' => 'salva nesta tela', 'config' => 'do config.php do servidor', 'busca' => 'a mesma da Busca de Imóveis'];

// Testa o que está valendo agora, para a tela dizer "funcionando" com prova.
$okRobust = null; $okTresc = null; $todas = [];
if ($rKey['valor'] && $rNick['valor']) $okRobust = $testaRobust((string)$rNick['valor'], (string)$rKey['valor']) ?? true;
if ($tTok['valor']) { [$e, $todas] = $testa3c((string)$tBase['valor'], (string)$tTok['valor']); $okTresc = $e ?? true; }

portal_header('Integrações · Listas 3C', $u);
$estado = function ($ok, array $v, string $falta) use ($origemTxt): string {
    if ($v['problema'] && !$v['valor']) return '<span class="l3-pill erro">' . h($v['problema']) . '</span>';
    if ($ok === null) return '<span class="l3-pill erro">' . h($falta) . '</span>';
    if ($ok !== true) return '<span class="l3-pill erro">Não respondeu: ' . h((string)$ok) . '</span>';
    return '<span class="l3-pill pronta">Funcionando · ' . h($origemTxt[$v['origem']] ?? '') . ' · ' . h(L3cIntegracoes::mascara((string)$v['valor'])) . '</span>';
};
?>
<style>
.l3-card{background:var(--card);border:1px solid var(--line);border-radius:6px;padding:18px 20px;margin:0 0 18px}
.l3-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;margin-top:12px}
.l3-form label{font-size:13px;font-weight:600;display:flex;flex-direction:column;gap:6px}
.l3-form input[type=text],.l3-form input[type=password]{font:inherit;padding:9px 10px;border:1px solid var(--line);border-radius:4px;background:#fff}
.l3-btn{font:inherit;font-weight:600;padding:10px 16px;border:none;border-radius:4px;background:var(--moss);color:#fff;cursor:pointer}
.l3-desc{color:var(--mute);font-size:13px;margin:6px 0 0}
.l3-pill{display:inline-block;font-size:12px;padding:3px 9px;border-radius:10px;background:#e8e4d8}
.l3-pill.pronta{background:#dcebe2;color:#1f4d3f}.l3-pill.erro{background:#f3d9cf;color:#8a3317}
.l3-camps{columns:2 260px;font-size:13px;margin:8px 0 0}.l3-camps label{display:block;break-inside:avoid;padding:2px 0}
.l3-chk{font-size:13px;margin-top:8px;display:block}
</style>
<p><a href="./" style="color:var(--moss)">← Listas 3C</a></p>
<h1 class="home-titulo">Configurar integrações</h1>
<p class="home-sub">As chaves que o módulo usa para ler o Robust e subir no 3C. Cada chave é testada antes de salvar e fica guardada cifrada no banco do portal.</p>
<?php foreach ($msgOk as $m): ?><div class="ok-box"><?= h($m) ?></div><?php endforeach; ?>
<?php foreach ($msgErro as $m): ?><div class="erro"><?= h($m) ?></div><?php endforeach; ?>

<form method="post">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="acao" value="salvar">

  <div class="l3-card">
    <h2 style="font-size:16px;margin:0 0 6px">Robust (leitura dos atendimentos)</h2>
    <?= $estado($okRobust, $rKey, 'Ainda sem chave') ?>
    <?php if ($rKey['origem'] === 'busca' && $okRobust === true): ?>
      <p class="l3-desc">Nada a fazer: o módulo usa a mesma chave que a Busca de Imóveis já usa.</p>
    <?php endif; ?>
    <div class="l3-form">
      <label>Apelido (nickname)<input type="text" name="robust_nickname" value="<?= h((string)($rNick['valor'] ?? 'imobcamargo')) ?>" autocomplete="off"></label>
      <label>Chave da API<input type="password" name="robust_api_key" placeholder="<?= $rKey['valor'] ? 'deixe vazio para manter a atual' : 'cole a chave aqui' ?>" autocomplete="new-password"></label>
    </div>
    <p class="l3-desc">No Robust: Painel de Controle → Configurações → Dados Administrativos.</p>
    <?php if ($rKey['origem'] === 'tela'): ?><label class="l3-chk"><input type="checkbox" name="limpar_robust" value="1"> Apagar a chave salva aqui e voltar a usar a da Busca / config.php</label><?php endif; ?>
  </div>

  <div class="l3-card">
    <h2 style="font-size:16px;margin:0 0 6px">3C Plus (subir a lista na campanha)</h2>
    <?= $estado($okTresc, $tTok, 'Ainda sem token') ?>
    <div class="l3-form">
      <label>Endereço da API<input type="text" name="tresc_base_url" value="<?= h((string)$tBase['valor']) ?>" autocomplete="off"></label>
      <label>Token da API<input type="password" name="tresc_api_token" placeholder="<?= $tTok['valor'] ? 'deixe vazio para manter o atual' : 'cole o token aqui' ?>" autocomplete="new-password"></label>
    </div>
    <p class="l3-desc">É o token de API de um usuário gestor do 3C (o mesmo que a Bianca já usa com a Camargo).</p>
    <?php if ($tTok['origem'] === 'tela'): ?><label class="l3-chk"><input type="checkbox" name="limpar_3c" value="1"> Apagar o token salvo aqui e voltar a usar o config.php</label><?php endif; ?>

    <?php if ($todas): ?>
      <input type="hidden" name="tem_campanhas" value="1">
      <p style="margin:14px 0 0;font-size:13px"><b>Em quais campanhas o módulo pode subir lista</b></p>
      <p class="l3-desc">Nenhuma marcada = todas aparecem na hora de aprovar.</p>
      <div class="l3-camps">
        <?php foreach ($todas as $cid => $cn): ?>
          <label><input type="checkbox" name="campanhas[]" value="<?= (int)$cid ?>" <?= $perm !== null && in_array((int)$cid, $perm, true) ? 'checked' : '' ?>> <?= h($cn) ?> (<?= (int)$cid ?>)</label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <button class="l3-btn" type="submit">Testar e salvar</button>
</form>
<?php
portal_footer();
