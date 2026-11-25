# Área do Construtor — atualização automática (GBRAC / Monteluce)

Quem roda: tarefa agendada do Claude, de hora em hora, seg–sáb, 8h–19h (BRT).
Em cada execução:

1. `GET https://portal.imobcamargo.com.br/construtor/api-importar.php?pendentes=1`
   (header `X-Portal-Token: <chave de serviço>`). Roda a atualização da página se
   - `pedido_em` não é nulo (o construtor ou um admin clicou em "Solicitar atualização"), ou
   - hoje é segunda e `atualizado_em` é anterior a hoje 00:00 (rodada semanal).
   Caso contrário, encerra sem fazer nada.
2. Baixa as fontes (Drive):
   - Planilha `FLUXO FINANCEIRO GBRAC` (id `1nMkhliudRqsVsNY8XoAPmrJ0ly9tCDiNPMY0-8g6z9Q`, hoje em
     MONTELUCE RESIDENCE › 7. Fluxo Clientes) exportada como .xlsx.
   - A Tabela de Vendas mais recente na pasta `3. Tabela de Vendas`
     (id `18N_Ckn4dnc2owYw7e0uK7MlxVHQvthW4`).
   - Base atual: `GET .../api-importar.php?p=gbrac` → `base.json`.
3. CUB SC: se saiu mês novo depois do último da série (`cubInfo.ult` na base), grava
   `cub.json` (`{"2026-12": 3190.00}`) com o valor oficial publicado.
4. `python3 construtor/automacao/gbrac_atualizar.py --planilha fluxo.xlsx --tabela tabela.xlsx --base base.json [--cub cub.json] [--extras extras.json] --out novo.json`
5. Linhas `PENDENTE:` do script:
   - ficha nova sem contrato → achar o contrato de reserva da unidade na pasta MONTELUCE RESIDENCE
     do Drive e preencher `extras.json` (`preco`, `entrada`, `saldo`, `contrato` = data por extenso,
     `cub_pct` = % da variação do CUB cobrado, cláusula 4.3.1) e rodar de novo;
   - distrato novo na planilha → procurar o termo de distrato no Drive; registrar no relatório
     (o admin confere e ajusta no painel).
6. `POST .../api-importar.php?p=gbrac` com `novo.json` (mesmo header). A API preserva distratos
   marcados no portal e limpa o pedido de atualização.

Nada de dado de cliente entra neste repositório: os arquivos de trabalho ficam só na sessão.
A chave de serviço é gerada em `/construtor/dados.php` (admin); gerar outra invalida a anterior.
