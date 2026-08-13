# Integração da Busca ao portal (plano do cutover)

A Busca hoje vive na **raiz** do subdomínio (`public_html/dados/`) com login
próprio. No portal ela vira o módulo `/busca/`. Mudanças necessárias (todas
com **backup antes** e testadas numa cópia de staging):

## 1. Mover os arquivos
`public_html/dados/*` (da busca) → `public_html/dados/busca/`
e o portal assume a raiz `public_html/dados/`.

## 2. Corrigir o `DATA_DIR` do `config.php` da busca
Hoje: `define('DATA_DIR', dirname(__DIR__, 2) . '/dados-imoveis');`
(funciona quando a busca está em `public_html/dados`).

Ao descer para `public_html/dados/busca`, passa a ser **um nível mais fundo**,
então:

```php
define('DATA_DIR', dirname(__DIR__, 3) . '/dados-imoveis');
```

Assim continua apontando para `~/dados-imoveis` (intacto).

## 3. Trocar o guarda de login pela sessão do portal
No `index.php`, `api.php` e `ver.php` da busca, substituir o guarda atual:

```php
// ANTES:
session_start();
if (empty($_SESSION['autenticado'])) { header('Location: login.php'); exit; }
```

por:

```php
// DEPOIS (usa o login do portal + acesso à ferramenta 'busca'):
require_once dirname(__DIR__) . '/lib/auth.php';
require_tool('busca');
```

(`ver.php` é a página pública de compartilhamento — **continua pública**, sem
guarda, como é hoje.)

O `login.php` próprio da busca é **aposentado** (o login passa a ser o do
portal). A senha única atual vira um usuário no `users` do portal.

## 4. Cron
O cron da busca (`sync.php`) continua igual, só muda o caminho:
`/usr/bin/php /home/USUARIO/public_html/dados/busca/sync.php`

## 5. Redirecionar links antigos
Quem tiver `dados.imobcamargo.com.br/` salvo cai agora na home do portal.
Se necessário, criamos um atalho/redirect de rotas antigas para `/busca/`.

## Riscos
- A busca usa `RewriteEngine Off` no `.htaccess` dela — manter no `/busca/`.
- Conferir permissões de escrita de `~/dados-imoveis` após a mudança.
- Testar `sync.php` manual uma vez após mover, antes de confiar no cron.
