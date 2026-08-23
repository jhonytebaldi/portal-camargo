# Plano de Ação Diário — instalação

Módulo que mostra, para cada corretor, o plano do dia gerado pela tarefa
agendada do Claude (seg–sex de manhã): clientes ativos do funil do Robust
(Lead → Proposta) priorizados pela conversa real do GHL, com ação
recomendada, telefones do cliente, score e check de execução.
Path público: `/plano-acao/` (registrado na tabela `tools` pela migração).

## 1. Migração do banco
Logado como admin, abra `/admin/migrar.php`. Ela cria (idempotente):
- `brokers.robust_user_id` — o de-para GHL ↔ Robust;
- a ferramenta `plano-acao` na tabela `tools`;
- as tabelas `pa_clientes`, `pa_planos`, `pa_itens`.

## 2. Config (`~/portal-config/config.php`, fora da web)
Acrescente (modelo no `config/config.example.php`):

```php
define('PLANO_ACAO_TOKEN', '<token longo aleatório>');
```

Esse token autentica SÓ os dois endpoints de API do módulo. Gere um valor
próprio (ex.: `php -r "echo bin2hex(random_bytes(32));"`) e informe o mesmo
valor à tarefa agendada do Claude.

## 3. De-para de corretores
Preencher `brokers.robust_user_id` conforme a planilha
`modulos/plano-acao/depara-corretores.xlsx` conferida pelo gestor
(o Claude gera o SQL de UPDATE a partir da planilha confirmada).
Corretor sem `robust_user_id` não tem plano visível fora do admin.

## 4. Permissões
- Nível 1: liberar a ferramenta **Plano de Ação Diário** para cada usuário
  (Admin → Usuários), como nas demais.
- Nível 2 (o mesmo escopo do Painel de Presença):
  - corretor: vincular o usuário ao corretor (`users.broker_id`) → vê só o
    próprio plano;
  - gestor: liberar as equipes dele em Admin → Equipes → vê os planos da
    equipe, separados por corretor;
  - admin: vê tudo, filtrável por corretor.

## 5. Endpoints (usados pela tarefa agendada; não são páginas)
- `GET /plano-acao/api-estado.php` — header `X-Portal-Token` — devolve o
  de-para, o cache de clientes (vínculo GHL, última análise, resumo) e a
  data do último plano. É a memória do gerador entre execuções.
- `POST /plano-acao/api-importar.php` — header `X-Portal-Token`, JSON com
  o plano do dia. Reimportar o mesmo dia substitui o plano **preservando
  os checks** já feitos (casados por atendimento).

Nenhum cron no hPanel é necessário: quem agenda é o Claude. O portal só
recebe, exibe e guarda os checks.
