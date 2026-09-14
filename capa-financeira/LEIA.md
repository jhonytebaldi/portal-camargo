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
