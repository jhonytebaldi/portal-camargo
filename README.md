# Portal `dados.imobcamargo.com.br`

Portal interno da Imobiliária Camargo: **um login** na entrada e uma **home
com botões** para as ferramentas internas, com **controle de acesso em 2
níveis** (quais ferramentas cada usuário abre e o que ele vê dentro de cada
uma). PHP + MySQL, publicado na Hostinger via GitHub, atualização diária por
cron.

## Estado atual (incremento 1 — Fundação)

Pronto neste pacote:

- Estrutura do portal e estilo compartilhado.
- Banco: `schema.sql` (usuários, ferramentas, equipes, corretores, permissões)
  e `seed.sql` (ferramentas + admin inicial).
- `lib/db.php` — conexão MySQL lendo a config de fora da web.
- `lib/auth.php` — sessão compartilhada + permissões de **nível 1** (ferramentas)
  e **nível 2** (equipes/corretores do painel). Admin enxerga tudo.
- `login.php` / `logout.php` — login multiusuário com bloqueio por tentativas.
- `index.php` — home com os botões das ferramentas liberadas ao usuário.
- `admin/` — visão geral (o CRUD completo vem no incremento 2).
- `gerar-hash.php` — utilitário para gerar o hash da senha do admin.

Próximos incrementos: **Admin self-service** (usuários, arrastar corretores em
equipes, permissões) → **Módulo Painel dos Corretores** (coletor GHL em PHP) →
**Integração da Busca** e virada.

## Estrutura

```
(raiz do subdomínio = public_html/dados/)
  index.php            home (login → botões)
  login.php logout.php
  gerar-hash.php       (apagar após criar a senha do admin)
  lib/ auth.php db.php layout.php
  admin/               administração
  assets/portal.css
  config/config.example.php   (modelo; a config real fica FORA da web)
  modulos/             (espaço para docs/artefatos dos módulos)
  schema.sql seed.sql

(fora da web, na home da conta:)
  portal-config/config.php   segredos (senha do banco, token GHL)
  painel-dados/              agregado do painel (presenca.json)
  dados-imoveis/             dados da busca (já existe)
```

## Instalação (resumo — passo a passo detalhado no INSTALAR quando formos publicar)

1. Criar um banco MySQL no hPanel e importar `schema.sql`.
2. Copiar `config/config.example.php` para `~/portal-config/config.php` (fora do
   `public_html`) e preencher banco + token GHL.
3. Abrir `gerar-hash.php`, gerar o hash da sua senha, colar em `seed.sql` e
   importar. **Apagar `gerar-hash.php`.**
4. Publicar os arquivos na raiz do subdomínio (via Git deployment).
5. Acessar `https://dados.imobcamargo.com.br/` e entrar.

## Segurança

- Segredos só no servidor, fora do `public_html` e fora do Git (`.gitignore`).
- Senhas com `password_hash`; sessão com cookie seguro; HTTPS.
- Filtragem de acesso feita **no servidor** (não dá para burlar pelo navegador).
