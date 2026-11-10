# Busca Bancos

Busca unificada nos extratos (OFX) de todas as contas das 3 empresas, para conferir
se entradas e saídas registradas na planilha antiga de fato aconteceram.

- `index.php` busca (caixa única: nome, CPF/CNPJ, valor, data, nº de cobrança) + detalhes, conferido, anotação, exportação
- `cobertura.php` grade conta × mês, buracos, conferência pelo saldo, o que cada banco informa
- `importar.php` upload de .ofx + script do Google que sincroniza a pasta do Drive (`api-importar.php`, token em `bb_config`)
- `conferencia.php` confere uma planilha (Livro Diário em .csv) linha a linha contra os extratos
- `omie.php` extrato do banco × extrato do Omie (usa `recibos-omie/lib/OmieApi.php` e `OMIE_CONTAS`)
- `contas.php` empresa/apelido/conta do Omie de cada conta, logos dos bancos, nomes próprios (transferências internas)

Tabelas: `bb_contas`, `bb_arquivos`, `bb_lanc`, `bb_config`, `bb_conf`, `bb_conf_linhas` (`schema.php`, idempotente,
instala sozinho na primeira visita de um admin). Acesso: ferramenta `busca-bancos` no admin do portal.

Decisões:
- Anti-duplicado por impressão digital (conta + data + valor + descrição + nome + ordem entre iguais), não pelo FITID:
  o FITID do Sicoob é data+valor+sequência e muda entre exportações.
- Período de cada conta = união dos períodos (DTSTART/DTEND) dos arquivos importados.
- Nenhum banco informa a chave Pix no OFX. Sicredi traz CPF/CNPJ na descrição; Sicoob só nome cortado; Asaas nome + nº da cobrança.
- O banco anotado na planilha antiga não é confiável: a conferência procura em todas as contas.
