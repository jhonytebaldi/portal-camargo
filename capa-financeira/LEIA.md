# Capa Financeira → Omie (módulo do portal)

Recebe as capas financeiras (.xlsx do Drive `CAPA FINANCEIRA - BEIER`), extrai contas a pagar/receber,
alerta divergências e duplicatas, e (fases seguintes) gera as planilhas de importação do Omie e os recibos.

## Instalação
1. `admin/migrar.php` cria as tabelas `cf_*`, registra a ferramenta `capa-financeira` e os dicionários padrão.
2. Libere a ferramenta para os usuários do financeiro em Admin → Usuários.
3. Opcional no `config.php`: `define('CF_DATA_DIR', '/home/.../capa-dados');` (pasta FORA do public_html).
   Sem isso o módulo usa `../capa-dados` ao lado do `portal-config`, e em último caso `capa-financeira/dados` (com .htaccess negando acesso).
4. Em Pessoas, importe a planilha `DADOS CORRETORES EQUIPE.xlsx` e complete razão social / departamento / conta padrão.

## Regras do parser
Implementação de referência em Python (`capa_parser.py`) + 13 capas reais ficam fora do repositório
(pasta do projeto "Financeiro Camargo" no PC do Jhony). Toda mudança de regra: alterar nos dois e rodar
`php capa-financeira/bin/paridade.php <pasta-com-xlsx-e-json> 2026-09-14` → tem que dar 0 diferenças.

## Fluxo
upload.php (parse + diff com versão anterior do mesmo COD + possível duplicata) → revisar.php (pessoa, data,
natureza, nota fiscal, alertas graves com "ok") → confirmar (gera `COD-P001`/`COD-R001`, reaproveita códigos
da versão anterior, marca a anterior como substituída).

## Vendas — formulário da capa em etapas (`vendas/`)
Em vez de enviar a planilha pronta, a equipe preenche a capa no portal, por etapas, e baixa o .xlsx quando quiser
(`COMPRADORES_x_CONSTRUTORA_dd-mm-aaaa.xlsx`, com a data da última edição). Regras de negócio no doc do projeto
"formulario-capa-venda-regras.md". Fase 1 (pronta): etapa 1 — dados da venda.
- Tabelas `cv_vendas`, `cv_compradores`, `cv_historico` (criadas por `admin/migrar.php` → `vendas/schema.php`).
- `vendas/lib/Venda.php` é a fonte única dos cálculos (linhas 9–27 da capa), da descrição padrão e das validações;
  a tela pede a prévia ao servidor (`vendas/api.php`, ação `previa`), não recalcula em JS.
- Compradores: CPF ou CNPJ (inclusive o CNPJ alfanumérico), conferidos/cadastrados SEMPRE no Omie da Vertical ao
  concluir a etapa 1 (`vendas/lib/OmieCompradores.php`, com confirmação na tela).
- Histórico: cada campo alterado (antes → depois), conclusão, vínculo/cadastro no Omie e download do .xlsx, com o usuário.
- Trava otimista (`rev`): se duas pessoas editam a mesma venda, a segunda gravação é recusada (nada se perde em silêncio).
- Código do imóvel: mostra o que a Busca tem (construtora/endereço/bairro) em itálico, SEM pré-preencher.
- `.xlsx`: `vendas/xlsx.php` + `vendas/lib/CapaXlsx.php` (gravador próprio `lib/XlsxWriter.php`, fórmulas com valor em cache).
- Desenvolvimento local: `define('CV_OMIE_FAKE', true)` no config.php troca o Omie por um JSON (NUNCA em produção).
- O Parser passou a aceitar também os rótulos do MODELO_ATUALIZADO (BAIRRO, UNIDADE/ENDEREÇO, COD. IMÓVEL e
  "VALOR PARA CALCULO DA COMISSÃO DA IMOB e VGV"). **Replicar no `capa_parser.py` de referência** (paridade).
- Fase 2 (pronta): etapa 2 — distribuição (`vendas/lib/Distribuicao.php`, `cv_vendas.e2_dados`): blocos comissão / bônus /
  administrativos pós-processo, nomes só do cadastro de Pessoas, bônus fechando 100% e "Conclusão do processo"
  (quem concluiu recebe; o valor é descontado de linhas de comissão). Etapa 1 ganhou CEP (ViaCEP no navegador) e
  lista de bairros validada (`cf_config` 'bairros', bairro novo só com confirmação). Venda cancelada pode ser descancelada.
- Fase 3 (pronta): etapa 3 — fluxo do cliente (`vendas/lib/Fluxo.php`, `cv_vendas.e3_dados`): parcelas com quem paga
  (comprador ou terceiro), conta corrente (Sicoob por padrão), referência, recebida/arras, travas e "conferi com o contrato".
  Ao concluir, gera a planilha da capa e importa pelo MESMO caminho do upload (`_importar.php` → `cf_importar_capa`), como
  nova versão da capa da venda (em revisão); depois grava cliente (CPF/CNPJ de quem paga) e conta em cada linha.
  Quem paga é conferido/cadastrado no Omie da EMPRESA DA VENDA (é lá que ficam as contas a receber).
  Cancelar venda com títulos no Omie: lista os títulos, exclui os sem baixa (OmieEnvio::excluir confere de novo) e mantém os
  pagos/recebidos; sem nada restante no Omie, descarta as capas e a etapa 3 volta a rascunho.
- Fase 4 (pronta): etapa 4 — pagamentos aos corretores (`vendas/lib/Pagamentos.php`, `cv_vendas.e4_dados`): etapas Arras/50/75/100
  sobre a comissão base (cascata por data; arras que cobre gatilhos junta), bônus na quitação, líquido de NF, brinde+cesta na
  última, diretores (arras + restante na de 50%), conclusão do processo (desconto no 1º ou 2º pagamento, nunca parcial).
  Data: cliente paga até segunda → quinta da mesma semana; terça–domingo → quinta da seguinte; quinta feriado → quarta
  (`cf_config` 'feriados'). Travas → data manual nas etapas depois da de 50% (bônus não); condição → data manual.
  Só o financeiro libera (admins + `cf_config` 'cv_liberadores', em Configurações). Liberar/desfazer regera a capa da venda
  (contas a receber + contas a pagar LIBERADAS, com o valor/data/condição do momento da liberação) e importa como nova versão;
  na confirmação, linhas iguais herdam o status "exportado". Liberação de título já no Omie não pode ser desfeita aqui.
  Etapa 4 concluída = tudo liberado; venda concluída = tudo exportado (conferido ao abrir a etapa 4).
