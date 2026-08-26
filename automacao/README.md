# Rotina do Plano de Ação — runbook da tarefa agendada

Roda 2x/dia (seg–sex). Cada execução ATUALIZA o plano do dia corrente:
a rodada da manhã cria o plano; a da tarde o refresca (checks preservados,
tarefas cumpridas detectadas viram check automático).

## Passos (sessão agendada do Claude)

1. Clonar o repositório e entrar em `automacao/`:
   `git clone https://github.com/jhonytebaldi/portal-camargo && cd portal-camargo/automacao`
2. Exportar credenciais (valores nas skills `portal-camargo`, `robust-crm`, `ghl-api`):
   `PA_TOKEN`, `ROBUST_KEY`, `ROBUST_NICK`, `GHL_TOKEN`, `GHL_LOCATION`.
3. `python3 rotina.py preparar` — coleta incremental, triagem, pré-score.
   Saída: `trabalho/contexto.json` + `trabalho/lotes/lote_NN.json`.
   Só entra em re-análise quem tem atividade nova (mensagem, andamento,
   mudança de estágio) ou nunca foi analisado; o resto reaproveita o plano
   anterior (carry-forward) sem custo de IA.
4. Analisar cada `trabalho/lotes/lote_NN.json` com a `rubrica_analise.md`
   (subagentes em paralelo; a data do plano está em `contexto.json`).
   Gravar cada resultado em `trabalho/analise/out_NN.json` (mesma ordem).
5. `python3 rotina.py publicar` — merge, regras de titularidade
   (encerrar-transferido / alinhar), auto-checks e importação no portal.
   Imprime um resumo JSON (também em `trabalho/resumo_publicacao.json`).
6. Reportar ao Jhony em 3-5 linhas: nº de clientes, re-analisados,
   auto-checks, divergências e qualquer falha de API. Sem perguntas —
   se algo falhar parcialmente, publicar o que der e relatar.

## Regras que o código já aplica (não reimplementar)

- Robust `/pessoas?ids=` e `/leads?ids=` SEMPRE com `per_page=100`
  (o padrão de 30 corta resultados em silêncio).
- Dono divergente Robust×WeSales: stage 0/1 → "encerrar (transferido)";
  stage 2+ → "alinhar titularidade" (lista manual do gestor). NUNCA
  transferir automaticamente.
- Mensagem de `workflow` não conta como resposta do corretor.
- Reimportar o mesmo dia preserva checks manuais e automáticos.

## Escopo

`escopo.json` → `robust_ids` dos corretores atendidos. Para incluir/remover
corretor, editar essa lista (o corretor precisa existir em `brokers` com
`robust_user_id` — Admin → Corretores → Sincronizar + migração do de-para).
