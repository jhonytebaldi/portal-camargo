<?php
/* =====================================================================
   imoveis-campanha/_comum.php: base do módulo "Imóveis da campanha".

   Pedido do Jhony (reunião de 22/10/2026), condição dele para ligar a
   Bianca no imóvel do anúncio: as condições mudam todo mês, então a
   Camargo tem de poder ALTERAR a ficha, LIMITAR o que ela fala de cada
   imóvel (campo a campo) e AUDITAR tudo o que ela respondeu.

   Nível 1: ferramenta 'imoveis-campanha'. Nível 2: corretor (usuário com
   broker_id) vê e edita só os imóveis em que é o corretor; admin vê todos.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../admin/comum.php';      // csrf_token() / csrf_check()
require_once __DIR__ . '/../listas-3c/lib/Apis.php';  // L3cRobust (mesma chave da Busca / Listas 3C)
require_once __DIR__ . '/lib/Bianca.php';
require_once __DIR__ . '/lib/Ficha.php';
require_once __DIR__ . '/schema.php';

imc_instalar_se_preciso();
date_default_timezone_set('America/Sao_Paulo');

/** Imóveis que este usuário enxerga (admin: todos; corretor: os dele). */
function imc_visiveis(array $u, array $todos): array
{
    if (is_admin($u)) return $todos;
    $meu = (string)($u['broker_id'] ?? '');
    return array_values(array_filter($todos, fn($i) => $meu !== '' && (string)($i['corretor_ghl_id'] ?? '') === $meu));
}

function imc_pode_editar(array $u, ?array $imovel): bool
{
    if (is_admin($u)) return true;
    if ($imovel === null) return !empty($u['broker_id']);   // imóvel novo: nasce dele
    return (string)($imovel['corretor_ghl_id'] ?? '') !== '' && (string)$imovel['corretor_ghl_id'] === (string)($u['broker_id'] ?? '');
}

/** Corretores ativos do portal (espelho do GHL), para o admin escolher o dono. */
function imc_corretores(): array
{
    return db()->query('SELECT id, nome FROM brokers WHERE ativo = 1 ORDER BY nome')->fetchAll();
}

function imc_primeiro_nome(string $nome): string
{
    return explode(' ', trim($nome))[0] ?? '';
}

/** "RM" */
function imc_iniciais(string $nome): string
{
    $p = preg_split('/\s+/', trim($nome)) ?: [];
    $i = mb_substr($p[0] ?? '', 0, 1) . (count($p) > 1 ? mb_substr($p[count($p) - 1], 0, 1) : '');
    return mb_strtoupper($i ?: 'C');
}

function imc_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * A ficha para marcar o que a resposta usou: os campos e, se liberada, a
 * "Parcela até" (que não é campo: o mês fica em parcelas_ate e a conta de
 * quantas faltam é do sistema). Só serve para marcar; nada decide com isto.
 */
function imc_campos_para_marcar(array $imovel): array
{
    $campos = (array)($imovel['campos'] ?? []);
    if (!empty($imovel['parcelas_ate']) && ($imovel['parcelas_pode_falar'] ?? true)) {
        $campos[] = ['chave' => 'parcelas_ate', 'rotulo' => 'Parcela até', 'pode_falar' => true, 'valor' => ImcFicha::mesLongo((string)$imovel['parcelas_ate'])];
    }
    return $campos;
}

/** Mensagem do banco da Bianca em português de gente. */
function imc_erro_legivel(Throwable $e): string
{
    $m = $e->getMessage();
    return match (true) {
        str_contains($m, 'chave_invalida') => 'A chave da ligação com a Bianca não abriu. Um admin confere em Ligação com a Bianca.',
        str_contains($m, 'previa_no_limite') => 'Limite de 60 prévias por hora. Tente de novo daqui a pouco.',
        str_contains($m, 'imovel_inexistente') => 'Este imóvel não existe mais.',
        default => $m,
    };
}

/** CSS do módulo (no molde das telas aprovadas na r23: cartões com borda musgo em cima). */
function imc_estilo(): void
{ ?>
<style>
.wrap{max-width:1180px}
.imc-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:4px 0 14px;flex-wrap:wrap}
.imc-top h1{font-size:26px;margin:0}
.imc-btn{font:inherit;font-weight:600;padding:9px 16px;border:none;border-radius:6px;background:var(--moss);color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.imc-btn:hover{background:var(--moss-d)}
.imc-btn.sec{background:#fff;color:var(--moss);border:1px solid var(--line)}
.imc-chips{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 18px}
.imc-chip{display:inline-flex;gap:8px;align-items:center;padding:6px 14px;border:1px solid var(--line);border-radius:18px;background:var(--card);color:var(--ink);text-decoration:none;font-size:14px}
.imc-chip.on{background:var(--moss);border-color:var(--moss);color:#fff}
.imc-chip .dot{width:7px;height:7px;border-radius:50%;background:var(--clay)}
.imc-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px}
.imc-card{background:var(--card);border:1px solid var(--line);border-top:4px solid var(--moss);border-radius:8px;padding:10px;display:flex;flex-direction:column;gap:8px;color:var(--ink);text-decoration:none}
.imc-card.off{opacity:.55}
.imc-card .foto{aspect-ratio:7/5;border-radius:4px;background:#e8e4d8 center/cover no-repeat;display:flex;align-items:center;justify-content:center;color:var(--mute);font-size:28px}
.imc-card .nm{font-weight:700;font-size:17px;padding:0 4px}
.imc-cod{display:inline-block;font-size:12px;padding:2px 9px;border-radius:10px;background:#e3ede8;color:#1f4d3f;width:max-content;margin:0 4px}
.imc-ic{display:flex;gap:16px;color:var(--mute);font-size:14px;padding:0 4px}
.imc-preco{font-weight:700;font-size:19px;padding:0 4px}
.imc-dono{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--mute);padding:0 4px}
.imc-av{width:30px;height:30px;border-radius:50%;background:#dfe6e1;color:var(--moss);font-weight:700;font-size:12px;display:inline-flex;align-items:center;justify-content:center}
.imc-pe{display:flex;align-items:center;gap:12px;border-top:1px solid var(--line);padding:10px 4px 2px;font-size:13px;color:var(--mute)}
.imc-sw{position:relative;width:44px;height:24px;flex:none;display:inline-block}
.imc-sw input{opacity:0;width:0;height:0}
.imc-sw span{position:absolute;inset:0;background:#cfcabd;border-radius:12px;cursor:pointer;transition:.15s}
.imc-sw span:before{content:"";position:absolute;width:18px;height:18px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.15s}
.imc-sw input:checked+span{background:var(--moss)}
.imc-sw input:checked+span:before{transform:translateX(20px)}
.imc-sw input:disabled+span{cursor:default}
.imc-bc{color:var(--mute);font-size:15px;margin:0 0 10px}.imc-bc a{color:var(--moss);text-decoration:none}
.imc-abas{display:flex;gap:4px;border-bottom:1px solid var(--line);margin:0 0 18px;flex-wrap:wrap}
.imc-abas a{padding:8px 14px;color:var(--mute);text-decoration:none;font-weight:600;border-bottom:3px solid transparent;margin-bottom:-1px}
.imc-abas a.on{color:var(--moss);border-color:var(--moss)}
.imc-box{background:var(--card);border:1px solid var(--line);border-top:4px solid var(--moss);border-radius:8px;padding:18px 20px}
.imc-cols{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start}
.imc-h{font-size:15px;font-weight:700;margin:14px 0 8px}.imc-h:first-child{margin-top:0}
.imc-g{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}
.imc-f{display:flex;flex-direction:column;gap:5px;font-size:13px;color:var(--mute)}
.imc-f input,.imc-f select,.imc-f textarea,.imc-in{font:inherit;font-size:14px;color:var(--ink);padding:9px 10px;border:1px solid var(--line);border-radius:6px;background:#fff;width:100%}
.imc-f.rb input{background:#f0ede4}
.imc-f .rbl{display:flex;justify-content:space-between}.imc-f .rbl i{font-style:normal;font-size:11px;background:#e8e4d8;border-radius:4px;padding:0 6px}
.imc-tag{display:inline-block;font-size:11.5px;padding:2px 8px;border-radius:9px;background:var(--moss);color:#fff}
.imc-tag.clay{background:var(--clay)}.imc-tag.cinza{background:#e8e4d8;color:var(--ink)}
.imc-seg{display:inline-flex;width:max-content;border:1px solid var(--line);border-radius:6px;overflow:hidden}
.imc-seg label{padding:8px 16px;cursor:pointer;background:#fff;color:var(--mute)}
.imc-seg input{display:none}.imc-seg input:checked+label{background:var(--moss);color:#fff}
.imc-rodape{display:flex;justify-content:space-between;align-items:center;gap:12px;border-top:1px solid var(--line);margin-top:18px;padding-top:14px;flex-wrap:wrap}
.imc-ok{background:#dcebe2;color:#1f4d3f;border-radius:6px;padding:10px 14px;margin:0 0 14px}
.imc-err{background:#f3d9cf;color:#8a3317;border-radius:6px;padding:10px 14px;margin:0 0 14px}
.imc-tab{width:100%;border-collapse:collapse}
.imc-tab th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mute);text-align:left;padding:8px 10px;border-bottom:1px solid var(--line)}
.imc-tab td{padding:11px 10px;border-bottom:1px solid #ebe7dc;vertical-align:middle}
.imc-tab tr.off td.v{color:#a8aea9}
.imc-tab td.s{text-align:right;white-space:nowrap}
.imc-mini{font-size:12.5px;color:var(--mute)}
.imc-zap{background:#efeae0;border-radius:6px;padding:16px;display:flex;flex-direction:column;gap:8px;min-height:380px;max-height:560px;overflow:auto}
.imc-b{max-width:78%;width:fit-content;align-self:flex-start;padding:9px 12px 6px;border-radius:8px;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.06);font-size:14.5px}
.imc-b .t{white-space:pre-wrap}
.imc-b.ela{align-self:flex-end;background:#e3ede8}
.imc-b .h{display:block;text-align:right;font-size:11px;color:var(--mute);margin-top:2px}
.imc-b .tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}
.imc-ficha{width:100%;border-collapse:collapse;font-size:14px}
.imc-ficha td{padding:7px 8px;border-bottom:1px solid #ebe7dc}.imc-ficha td+td{text-align:right}
.imc-ficha tr.usado td{background:#e3ede8}.imc-ficha tr.usado td:first-child:before{content:"● ";color:var(--moss)}
.imc-resp{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:14px 16px;display:flex;flex-direction:column;gap:8px;margin:0 0 12px}
.imc-ctx{background:#f0ede4;border-radius:6px;padding:10px 12px;font-size:13px;color:#4b5550;display:none}
.imc-ctx.on{display:block}.imc-ctx p{margin:2px 0}
@media (max-width:820px){.imc-cols{grid-template-columns:1fr}}
/* r41: o teste como o lead da campanha. Tela de celular com as cores do WhatsApp (o lead à direita, ela à esquerda). */
.imc-sim [hidden]{display:none!important} /* .imc-ref e .imc-sug têm display próprio, que venceria o hidden */
.imc-sim{display:grid;grid-template-columns:minmax(0,340px) minmax(0,420px);gap:28px;align-items:start;justify-content:center}
.imc-passo{display:flex;align-items:center;gap:10px;font-weight:600;margin:0 0 10px}
.imc-passo b{width:26px;height:26px;border-radius:50%;background:var(--moss);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:13px;flex:none}
.imc-ad{background:#fff;border:1px solid var(--line);border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.06)}
.imc-ad-top{display:flex;align-items:center;gap:10px;padding:10px 12px}.imc-ad-top div{display:flex;flex-direction:column;line-height:1.2}
.imc-ad-top small,.imc-cel-top small{font-size:12px;color:var(--mute)}
.imc-ad-txt{padding:0 12px 10px;font-size:14px}
.imc-ad-foto{aspect-ratio:1/1;background:#e8e4d8 center/cover no-repeat;display:flex;align-items:center;justify-content:center;font-size:44px}
.imc-ad-cta{width:100%;border:none;border-top:1px solid var(--line);background:#f4f6f5;padding:12px;font:inherit;font-weight:700;color:#1f2b26;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer}
.imc-ad-cta:hover{background:#e6efe9}
.imc-wa{width:18px;height:18px;border-radius:50%;background:#25d366;display:inline-block;box-shadow:inset 0 0 0 4px #25d366,inset 0 0 0 6px #fff}
.imc-sim-ficha{margin-top:6px;background:var(--card);border:1px solid var(--line);border-radius:8px;padding:10px 12px}
.imc-sim-ficha summary{cursor:pointer;font-weight:600}
.imc-cel{border:10px solid #1f2b26;border-radius:34px;overflow:hidden;background:#efeae2;display:flex;flex-direction:column;height:680px;box-shadow:0 10px 30px rgba(0,0,0,.18)}
.imc-cel-top{display:flex;align-items:center;gap:10px;background:#075e54;color:#fff;padding:12px 12px}
.imc-cel-top .quem{display:flex;flex-direction:column;line-height:1.2;flex:1;min-width:0}
.imc-cel-top small{color:#cfe6e0}.imc-cel-top small.dig{color:#fff;font-style:italic}
.imc-cel-top button{border:1px solid rgba(255,255,255,.45);background:transparent;color:#fff;border-radius:14px;padding:4px 10px;font:inherit;font-size:12.5px;cursor:pointer}
.imc-cel .imc-av{background:#fff;color:#075e54}
.imc-cel-zap{flex:1;overflow:auto;padding:12px 12px 6px;display:flex;flex-direction:column;gap:6px}
.imc-sis{align-self:center;background:#fdf4c5;color:#54524a;font-size:12px;padding:4px 10px;border-radius:7px;text-align:center;max-width:90%}
.imc-sis.erro{background:#f6e0d6;color:#7a3a1d}
.imc-vazio{margin:auto 18px;text-align:center;color:#54605a;font-size:14px;background:rgba(255,255,255,.7);padding:14px;border-radius:10px}
.imc-m{max-width:82%;width:fit-content;align-self:flex-start;background:#fff;border-radius:8px;padding:6px 9px 4px;box-shadow:0 1px .5px rgba(0,0,0,.13);font-size:14.5px;line-height:1.35}
.imc-m.eu{align-self:flex-end;background:#d9fdd3}
.imc-m .t{white-space:pre-wrap}.imc-m .h{float:right;font-size:11px;color:#667781;margin:6px 0 0 10px}
.imc-m .vv{font-style:normal;color:#53bdeb}
.imc-m .notas{clear:both;display:flex;flex-wrap:wrap;gap:4px 10px;border-top:1px dashed #d5dcd8;margin-top:5px;padding-top:3px;font-size:11.5px;color:var(--moss)}
.imc-m .notas .clay{color:var(--clay)}
.imc-m .imc-ref{margin:0 0 6px;border-radius:6px}
.imc-dig{display:flex;gap:4px;padding:10px 12px}.imc-dig i{width:7px;height:7px;border-radius:50%;background:#9aa5a0;animation:imc-dig 1s infinite}
.imc-dig i:nth-child(2){animation-delay:.15s}.imc-dig i:nth-child(3){animation-delay:.3s}
@keyframes imc-dig{0%,60%,100%{opacity:.35;transform:translateY(0)}30%{opacity:1;transform:translateY(-3px)}}
.imc-ref{display:flex;gap:8px;align-items:center;background:rgba(0,0,0,.05);border-left:4px solid #06cf9c;padding:6px;margin:0 8px 6px;border-radius:8px}
.imc-ref .th{width:46px;height:46px;border-radius:5px;background:#e8e4d8 center/cover no-repeat;flex:none;display:flex;align-items:center;justify-content:center}
.imc-ref .tx{display:flex;flex-direction:column;line-height:1.25;min-width:0;font-size:13px}.imc-ref small{font-size:11.5px;color:#667781}
.imc-sug{display:flex;gap:6px;overflow-x:auto;padding:4px 8px 6px}
.imc-sug button{flex:none;border:1px solid #c9d3ce;background:#fff;color:#075e54;border-radius:16px;padding:5px 11px;font:inherit;font-size:13px;cursor:pointer}
.imc-compor{display:flex;gap:6px;padding:6px 8px 10px;background:#efeae2}
.imc-compor input{flex:1;border:none;border-radius:20px;padding:10px 14px;font:inherit;font-size:14.5px;background:#fff;min-width:0}
.imc-compor button{width:42px;height:42px;border:none;border-radius:50%;background:#00a884;color:#fff;font-size:17px;cursor:pointer;flex:none}
@media (max-width:820px){.imc-sim{grid-template-columns:1fr;gap:18px}.imc-cel{height:600px;border-width:6px;border-radius:22px}}
</style>
<?php }

/** Cabeçalho das telas de um imóvel: migalha e as quatro abas. */
function imc_abas(array $imovel, string $ativa): void
{
    $id = urlencode((string)$imovel['id']);
    $abas = ['cadastro' => 'Cadastro', 'limitar' => 'O que ela fala', 'previa' => 'Como ela responde', 'respostas' => 'Respostas'];
    echo '<p class="imc-bc"><a href="./">Imóveis da campanha</a> / ' . h((string)$imovel['nome']) . '</p><nav class="imc-abas">';
    foreach ($abas as $k => $t) echo '<a class="' . ($k === $ativa ? 'on' : '') . '" href="imovel.php?id=' . $id . '&aba=' . $k . '">' . h($t) . '</a>';
    echo '</nav>';
}
