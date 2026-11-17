<?php
/* =====================================================================
   alterador-omie/index.php — "Alterador Omie": altera em massa campos de
   contas a pagar/receber no Omie pela API, com prévia, conferência de
   cada título, histórico e desfazer. A tela é toda em JS (assets/ao.js);
   os dados vêm de api.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_login();
ao_instalar_se_preciso();   // admin: cria tabelas e registra a ferramenta antes da checagem de acesso (1ª visita)
$u = require_tool('alterador-omie');
$empresas = ao_empresas();
portal_header('Alterador Omie', $u);
?>
<link rel="stylesheet" href="<?= h(ao_asset('ao.css')) ?>">
<div class="ao" id="ao" data-csrf="<?= h(csrf_token()) ?>" data-empresas='<?= h(json_encode($empresas, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="ao-top">
    <div>
      <h1 class="home-titulo" style="margin-bottom:2px">🛠️ Alterador Omie</h1>
      <p class="cf-dica" style="margin:0">Altera em massa campos de contas a pagar e a receber no Omie — com prévia, conferência título a título e histórico com desfazer.</p>
    </div>
    <nav class="ao-abas"><button type="button" data-aba="titulos" class="on">Títulos</button><button type="button" data-aba="historico">Histórico</button><button type="button" data-aba="ajuda">Como funciona</button></nav>
  </div>
<?php if (!$empresas): ?>
  <div class="erro">Nenhuma empresa com a API do Omie configurada (OMIE_CONTAS no config.php do servidor).</div>
<?php else: ?>
  <div class="ao-barra card">
    <label>Empresa <select id="ao-emp"><?php foreach ($empresas as $id => $nome): ?><option value="<?= h($id) ?>"<?= $id === 'vertical' ? ' selected' : '' ?>><?= h($nome) ?></option><?php endforeach; ?></select></label>
    <div class="ao-tipos" role="group" aria-label="Tipo"><button type="button" data-tipo="P" class="on">A pagar</button><button type="button" data-tipo="R">A receber</button><button type="button" data-tipo="PR">Ambos</button></div>
    <div class="ao-atual">
      <button type="button" class="btn" id="ao-atualizar">↻ Atualizar títulos</button>
      <span id="ao-quando" class="cf-dica"></span>
      <button type="button" class="ao-link" id="ao-completa" title="relê todos os títulos do Omie (necessário para sumir com títulos excluídos por lá); pode levar ~1 min">leitura completa</button>
    </div>
  </div>
  <div id="ao-msg"></div>

  <section data-painel="titulos">
    <div class="ao-acoes">
      <span id="ao-cont" class="ao-cont"></span>
      <button type="button" class="ao-link" id="ao-sel-filtrados">☑ selecionar todos os filtrados</button>
      <button type="button" class="ao-link" id="ao-sel-limpar">☐ limpar seleção</button>
      <button type="button" class="ao-link" id="ao-filtros-limpar">✖ limpar filtros</button>
    </div>
    <div class="ao-tbl-wrap"><table class="ao-tbl" id="ao-tbl"><thead></thead><tbody></tbody></table></div>
    <p class="ao-mais" id="ao-mais"></p>

    <div class="ao-dock" id="ao-dock" hidden>
      <div class="ao-dock-in">
        <b id="ao-dock-n"></b>
        <button type="button" class="btn" id="ao-abrir-alt">Alterar selecionados →</button>
      </div>
    </div>

    <div class="card ao-alt" id="ao-alt" hidden>
      <div class="ao-alt-top"><h2>Alterar <span id="ao-alt-n"></span></h2>
        <div class="ao-rec"><select id="ao-rec-sel"><option value="">usar uma receita salva…</option></select><button type="button" class="ao-link" id="ao-rec-salvar">salvar como receita</button><button type="button" class="ao-link" id="ao-rec-excluir" hidden>excluir receita</button></div>
      </div>
      <div id="ao-ops"></div>
      <div class="ao-add"><select id="ao-add-campo"><option value="">+ adicionar campo para alterar…</option></select></div>
      <div id="ao-seq" class="ao-seq" hidden></div>
      <div class="ao-alt-bot"><button type="button" class="btn" id="ao-previa">Ver prévia</button><button type="button" class="ao-link" id="ao-fechar-alt">cancelar</button></div>
      <div id="ao-prev"></div>
    </div>
  </section>

  <section data-painel="rodada" hidden><div id="ao-rodada"></div></section>
  <section data-painel="historico" hidden><div id="ao-hist"></div></section>
  <section data-painel="ajuda" hidden>
    <div class="card ao-ajuda">
      <h2>Como funciona</h2>
      <ol>
        <li><b>Títulos em memória.</b> A lista vem de uma leitura geral do Omie guardada no portal. <b>↻ Atualizar títulos</b> traz só o que foi incluído ou alterado desde a última leitura (segundos; no máximo 1 vez por minuto). A <b>leitura completa</b> relê tudo (1 vez a cada 10 min) e é o único jeito de sumir com títulos excluídos no Omie.</li>
        <li><b>Filtre e selecione</b> como numa planilha: cada coluna tem um filtro (datas aceitam <code>05/11</code>, <code>01/11-30/11</code>, <code>11/2026</code>; valores aceitam <code>&gt;100</code>, <code>100-500</code>; clique no título da coluna para ordenar). Shift+clique seleciona um intervalo.</li>
        <li><b>Escolha os campos</b> e a regra de cada um. Textos aceitam marcadores: <code>{seq}</code> 001, 002… · <code>{n}</code> 1, 2… · <code>{total}</code> · <code>{data}</code> sequência de datas · <code>{venc}</code> <code>{prev}</code> <code>{emis}</code> (com formato, ex. <code>{venc:mm/aaaa}</code>) · <code>{forn}</code> <code>{doc}</code> <code>{nf}</code> <code>{parc}</code> <code>{valor}</code> <code>{atual}</code>.</li>
        <li><b>Prévia</b> mostra antes → depois de cada título, com o que não pode ser alterado e por quê. Nada vai ao Omie antes de você confirmar.</li>
        <li><b>Envio e conferência.</b> Cada título é relido do Omie na hora (se mudou desde a prévia, não é enviado), recebe só os campos que mudam e é conferido de volta em até 3 tentativas, cada uma com uma consulta diferente (o Omie bloqueia consulta repetida em menos de 1 min). Só a última, ~70 s depois, pode concluir "o Omie não aplicou".</li>
        <li><b>Histórico</b> guarda o antes/depois de cada título: dá para <b>desfazer</b> uma rodada, reconferir e exportar.</li>
      </ol>
      <h2>Limites do Omie (testados em 17/11/2026)</h2>
      <ul>
        <li><b>Observação:</b> a API só <b>acrescenta uma linha nova</b> no fim. Para substituir ou inserir no início, use "Alterar Contas Selecionadas" na própria tela do Omie (lá existe essa opção).</li>
        <li><b>Texto vazio é ignorado:</b> não dá para limpar Nº do documento, NF ou observação pela API.</li>
        <li><b>Código de integração não muda</b> pela API (o Omie usa esse código para localizar o título). Para numerar títulos, use o Nº do documento.</li>
        <li><b>Título pago/recebido:</b> vencimento, previsão e valor o Omie aceita e ignora — por isso ficam bloqueados. Nº doc, NF, parcela, emissão, categoria, departamentos e observação funcionam.</li>
        <li><b>Cancelado</b>, <b>bloqueado</b> (integração/CNAB) ou com <b>boleto gerado</b>: o Omie trava a alteração (no boleto, só os campos financeiros).</li>
        <li><b>Tamanhos:</b> Nº do documento e NF até 20 caracteres; parcela até 7 (ex. 001/012).</li>
        <li><b>Velocidade:</b> ~2 a 4 s por título; a aba precisa ficar aberta. Se fechar, continue pelo Histórico.</li>
        <li>Não altera: baixa já feita (data/valor pago), retenções de impostos, rateio entre várias categorias, chave Pix existente (só substitui pela do cadastro).</li>
      </ul>
    </div>
  </section>
<?php endif; ?>
</div>
<script src="<?= h(ao_asset('ao.js')) ?>"></script>
<?php portal_footer();
