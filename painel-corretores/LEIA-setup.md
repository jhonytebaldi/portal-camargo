# Painel dos Corretores — instalação

Módulo de **presença aproximada** da equipe (fonte: GHL API v2).
Path público: `/painel-corretores/` (já registrado na tabela `tools`).

## 1. Config (arquivo fora da web: `portal-config/config.php`)
Confirme que existem estas linhas (o `config.example.php` já traz o modelo):

```php
define('GHL_TOKEN',    'pit-...seu-token...');   // Private Integration Token
define('GHL_LOCATION', '9o1WOaGvZNxhcdSgqAaG');
// Opcional: onde o coletor grava o agregado. Se não definir, usa
// <raiz-do-domínio>/painel-dados (fora do public_html) automaticamente.
define('PAINEL_DATA_DIR', dirname(__DIR__) . '/painel-dados');
```

O corretor precisa estar na tabela `brokers` (use **Admin → Corretores →
Sincronizar do GHL**).

## 2. Cron diário (hPanel → Avançado → Cron Jobs)
Rode o coletor 1x/dia (ex.: 06h). Comando:

```
/usr/bin/php /home/USUARIO/domains/portal.imobcamargo.com.br/public_html/painel-corretores/coletor.php
```

Troque `USUARIO` pelo seu usuário Hostinger. Sugestão de agenda: `0 6 * * *`.
O coletor varre o **mês corrente até hoje** e grava `presenca_agg.json`.

Para um período específico (uso manual via SSH):
```
php coletor.php 2026-08-01 2026-08-14
```

## 3. Atualizar na hora
Um **admin** logado pode abrir `/painel-corretores/coletor.php` no navegador
(há o link "atualizar agora" no topo do painel). Demora alguns minutos —
é normal, ele relê todas as conversas do mês.

## Como funciona o acesso
- **Nível 1:** só quem tem a ferramenta `painel-corretores` liberada abre a página.
- **Nível 2:** cada um vê apenas os corretores das **equipes liberadas** para ele,
  mais o **próprio corretor vinculado** ao seu usuário. Admin vê todos.
