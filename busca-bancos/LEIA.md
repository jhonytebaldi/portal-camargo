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

Formatos vistos (nov/2026):
- Inter (077): `<NAME>` completo, MEMO `Pix enviado: "Cp :ISPB-NOME"`, CHECKNUM/REFNUM = "077" (ruído, ignorado), FITID = data+077+seq,
  LEDGERBAL com a data do download (não é o saldo do fim do período: ignorado na conferência pelo saldo).
- Caixa: só PDF, e o PDF é uma imagem (pdfmake + JPEG do "Extrato por período"). `assets/pdf-caixa.js` extrai o JPEG, lê com
  tesseract.js (em `assets/ocr/`, local, sem CDN) e só importa se saldo anterior ± lançamentos = saldo impresso em cada linha;
  `BbImportador::importarCaixaPdf` confere de novo no servidor. Data do movimento = a do "SALDO DIA" do grupo (a hora impressa é a do processamento).
- Omie Cash (450): não tem arquivo. `BbOmie::sincronizarCash` puxa `financas/extrato/ ListarExtrato` das 3 empresas e grava só os
  movimentos "Conciliado" (os "Previsto" são contas a pagar em aberto). Impressão digital = código do lançamento no Omie.
- `BbOmie::vincular` liga cada conta ao cadastro de contas correntes do Omie pelo número e corrige a empresa (a pasta do Drive
  engana: o Sicoob 266.692-8 e o Asaas estão na pasta IMOBILIARIA mas são da Vertical).
