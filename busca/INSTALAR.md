# Ferramenta de Imóveis — instalação na Hostinger

## 1. Enviar os arquivos

No **Gerenciador de Arquivos** da Hostinger, entre em `public_html` e crie
uma pasta para a ferramenta.

**Não use o nome `imoveis`** — o site já tem uma página nesse endereço e ela
tomaria a frente, mostrando a lista pública em vez da ferramenta.
Use `busca`, `ferramenta` ou `crm-busca`. Os exemplos abaixo usam `busca`.

O jeito mais seguro de enviar é subir o **ZIP inteiro** para dentro da pasta e
usar a opção **Extrair** do gerenciador — assim nenhum arquivo troca de
conteúdo. Se for enviar um a um, confira que estes chegaram:

    index.php     login.php     api.php
    sync.php      config.php    gerar-hash.php
    .htaccess     dados/  (pasta, com o .htaccess de dentro)

Confira que cada arquivo tem o conteúdo certo: abra e veja o comentário da
primeira linha, que diz o nome do próprio arquivo.

Se o `.htaccess` não aparecer no envio, ative **"Mostrar arquivos ocultos"**
nas opções do gerenciador.

## 2. Preencher o config.php

Abra `config.php` no editor do gerenciador. Procure as **linhas 9, 10 e 15**,
que estão assim:

    define('ROBUST_NICKNAME', 'COLE_O_NICKNAME_AQUI');
    define('ROBUST_API_KEY',  'COLE_A_CHAVE_AQUI');
    define('SENHA_HASH', '$2y$10$COLE_O_HASH_GERADO_AQUI');

Troque **apenas o texto entre aspas**, deixando o resto da linha igual:

    define('ROBUST_NICKNAME', 'imobcamargo');
    define('ROBUST_API_KEY',  'a1b2c3d4...');
    define('SENHA_HASH', '$2y$10$udWhp2RLxIahF...');

Atenção:
- Mantenha as **aspas simples** e o **ponto e vírgula** no fim de cada linha.
- Na linha 15, apague o `$2y$10$` que já está lá antes de colar — o hash novo
  já vem com esse prefixo, senão ele fica duplicado e o login não funciona.

A chave está no Robust em: **Painel de Controle > Configurações >
Dados Administrativos**.

## 3. Gerar o hash da senha (pode fazer antes do passo 2)

Abra no navegador:

    https://seudominio.com.br/busca/gerar-hash.php

Digite a senha no campo e clique em **Gerar hash**. A página mostra o
resultado e confirma quantos caracteres ela leu — confira antes de copiar.

O formulário existe por um motivo: senhas com `#` **não funcionam pela URL**.
O navegador corta tudo depois do `#`, então `?s=Camargo!2026#Imoveis` enviaria
apenas `Camargo!2026` e geraria o hash da senha errada.

Copie o resultado (começa com `$2y$10$`) para o `SENHA_HASH` do config.php.
**Depois APAGUE o arquivo gerar-hash.php.**

## 4. Primeira carga

Acesse `https://seudominio.com.br/busca/` e entre com a senha.
Clique em **Sincronizar com o CRM** — traz os imóveis e as fotos (~40s).
Depois clique em **Enviar planilha (XLS)** e mande o export do Robust —
isso carrega as descrições e vale para toda a equipe.

## 5. Agendar a atualização diária

No painel da Hostinger: **Avançado > Cron Jobs > Criar novo**.

- Frequência: uma vez por dia (sugestão: 05:00)
- Comando:

      /usr/bin/php /home/SEU_USUARIO/public_html/busca/sync.php

Descubra o caminho exato em Gerenciador de Arquivos — ele aparece no topo.
Normalmente é `/home/u123456789/public_html/busca/sync.php`.

## Como funciona no dia a dia

| O quê | Quando | Fonte |
|---|---|---|
| Preço, quartos, status, fotos, proprietário | Todo dia, sozinho | API do Robust |
| Descrição, área, entrega, amenidades | Quando você enviar o XLS | Planilha |

A API **nunca apaga** o que veio do XLS. Um imóvel novo no CRM aparece na hora
com os dados que a API tem, marcado como *"aguardando descrição"* até você
enviar uma planilha que o inclua.

## Manutenção

- **Trocar a senha**: reenvie o `gerar-hash.php`, gere o novo hash, cole no
  config.php e apague o arquivo de novo.
- **Ver o histórico**: `dados/sync.log` (não é acessível pela web).
- **Se algo der errado**: o sync nunca substitui uma base boa por uma vazia,
  e o upload guarda a versão anterior em `dados/xls.json.bak`.

## Segurança

- A chave da API fica só no servidor, nunca no navegador.
- A pasta `dados/` é bloqueada pelo `.htaccess`.
- Telefone de proprietário **não** é publicado — só os nomes.
- Login bloqueia por 15 minutos após 6 tentativas erradas.
