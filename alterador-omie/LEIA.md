# Alterador Omie (`/alterador-omie/`)

Altera em massa campos de contas a pagar/receber no Omie pela API, com prévia,
conferência título a título, histórico e desfazer. Acesso: ferramenta
`alterador-omie` (admin → usuários). As tabelas se criam sozinhas na primeira
visita de um admin (ou em `/admin/migrar.php`).

## Arquivos
- `index.php` + `assets/ao.js|ao.css` — tela (tabela com filtro por coluna, alterações, prévia, execução, histórico).
- `api.php` — ações JSON (estado, titulos, forn, atualizar, previa, criar, enviar, conferir, parar, retomar, reconferir, desfazer, receitas, exportar CSV).
- `lib/AoTitulo.php` — título normalizado (Listar/Consultar × PesquisarLancamentos) e campos alteráveis.
- `lib/AoRegras.php` — motor das regras (marcadores `{seq}` etc., datas úteis/feriados de Joinville, bloqueios). Puro, testável na CLI.
- `lib/AoCache.php` — títulos em cache (`<CF_DATA_DIR>/alterador-omie/`), leitura completa em partes, incremental, catálogo.
- `lib/AoExec.php` — envio (relê → recalcula → Alterar mínimo) e conferência em 3 passos.
- Tabelas: `ao_rodadas`, `ao_itens` (antes/alvo/payload/depois por título), `ao_receitas`.

## Regras do Omie descobertas nos testes (17/11/2026, Vertical, títulos de teste já excluídos)
- `AlterarConta*` é parcial (só id + campos). Texto vazio e rateio vazio são **ignorados** (não dá para limpar).
- **Observação**: o Alterar **acrescenta uma linha** (`|`) — não substitui. Linha igual à última não é repetida.
- **Código de integração** não muda (o Omie usa o código para localizar o título → "Lançamento não cadastrado").
- **Título pago**: vencimento, previsão e valor são aceitos ("alterado com sucesso") e ignorados. Nº doc, NF, parcela,
  emissão, categoria, departamentos e observação mudam.
- Limites: `numero_documento` e `numero_documento_fiscal` 20 caracteres; `numero_parcela` 7 ("1/3" vira "001/003").
- Consulta **idêntica** repetida em < ~60 s devolve a resposta **antiga** (ou REDUNDANT) → cada leitura de um título
  usa uma variante diferente (Consultar por id / por cód. integração / PesquisarLancamentos com nRegPorPagina variável).
- `PesquisarLancamentos` pode levar alguns segundos a refletir uma alteração.
- Erros passageiros novos: `Client-1880` ("já existe uma requisição desse método sendo executada") e
  "esta requisição já foi processada ou está sendo processada… tente novamente às hh:mm:ss" (alterações seguidas no
  mesmo título). O `OmieApi` não trava mais o reenvio nesses casos.
- `valor_pag` do Listar/Consultar **não é "valor já pago"** (vem igual ao valor do documento em muitos títulos em
  aberto). Baixa = pela situação (`PAG|RECEB|LIQUID`). Corrigido também na Capa Financeira e em Vendas.
- `filtrar_por_data_de/_ate` do ListarContas* = data de inclusão **ou alteração** (é o que a atualização incremental usa).

## Teste local
`php -S` com `PORTAL_CONFIG` apontando para um config com MySQL local e `OMIE_CONTAS`; motor de regras testável
com um script CLI que inclui `lib/AoRegras.php`.
