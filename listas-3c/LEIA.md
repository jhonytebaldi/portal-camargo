# Listas 3C (módulo do portal)

Monta a lista de ligação a partir do Robust, trata os contatos, mostra a prévia
para aprovar e sobe numa campanha do 3C. Pedido do Jhony na reunião de 24/09:
tirar do trabalho manual de exportar do Robust, tratar na IA e subir no 3C.

## Instalação

1. `admin/migrar.php` cria as tabelas `l3c_*` e registra a ferramenta
   `listas-3c` (o botão aparece na home para admin; libere para o Guilherme em
   Admin → Usuários).
2. No `~/portal-config/config.php` (fora da web e do Git), acrescente:

   ```php
   define('ROBUST_NICKNAME', 'imobcamargo');
   define('ROBUST_API_KEY',  '...');            // a mesma chave da Busca
   define('TRESC_BASE_URL',  'https://camargogestao.3c.plus/api/v1');
   define('TRESC_API_TOKEN', '...');            // token de serviço do 3C
   // Opcional: trava de campanhas. Com ela, só estas aparecem e só nelas
   // o módulo sobe lista. Sem ela, todas as campanhas do 3C aparecem.
   // define('L3C_CAMPANHAS_PERMITIDAS', [316173]);
   ```

3. Cron do hPanel (Avançado → Cron Jobs), a cada 5 minutos:
   `php /home/USUARIO/public_html/dados/listas-3c/bin/processar.php`
   A tela monta a lista sozinha enquanto está aberta; o cron só garante que a
   lista continua se a aba for fechada no meio.

## Os 4 modelos

| Modelo | Quem entra |
|---|---|
| Encerrados recuperáveis (lista recente) | cadastrados no período, encerrados em Lead, Atendimento ou Agendamento por: agendou e não veio, bloqueou ligações, lead de corretor que saiu, parou de responder, sem retorno após todas as tentativas |
| Encerrados recuperáveis (lista antiga) | os de cima, também em Visita, e mais: curioso, optou por esperar, depende de outra negociação, restrição no nome, sem entrada, desistiu de comprar |
| Agendamento vencido | ainda em Agendamento, a data do agendamento caiu no período, já passou e não houve outro marcado para a frente |
| Primeiras etapas | ativos em Lead, Atendimento ou Agendamento cadastrados no período; quem tem agendamento para a frente fica fora |

Em todos: motivo da lista "nunca" fica fora (duplicado, número incorreto,
comprou em outra imobiliária etc.), lead de origem "do corretor" com menos de
30 dias fica fora, telefone sem DDD válido e telefone repetido ficam fora, e
encerrado que já tem outro atendimento ativo fica fora. Tudo editável em
**Editar modelos**; listas já montadas guardam o modelo que usaram.

Motivos que o Jhony não classificou na reunião e ficam **fora por padrão**:
"Desatualizado além do permitido!", "Encerrar vários", "Outros", "Cliente não
quer agendar", "Cliente busca imóvel em outra cidade", entre outros. É só
marcar na tela se quiser.

## Como a lista é montada (e por que em passos)

A Hostinger corta a requisição web em ~60 s. Cada chamada trabalha no máximo
8 s (a barra anda mais vezes), grava onde parou (`l3c_listas.cursor_json`) e a tela
chama de novo. O cron trabalha em passos de 5 min.

1. `GET /v1/atendimentos` por período de cadastro (500 por página).
2. Encerrados: o motivo vem do andamento `tipo=encerrado`
   (`GET /v1/andamentos?tipo=encerrado&criado_de=&criado_ate=`).
   Depois `GET /v1/atendimentos?ativo=true` para tirar quem já voltou.
3. Agendamento: `GET /v1/agenda?de=&ate=&origem_crm=true` e
   `GET /v1/andamentos?ids=` para ligar cada evento ao atendimento.
4. `GET /v1/pessoas?ids=` (nome, e-mail, até 5 telefones), e
   `GET /v1/leads?ids=` para quem não tem telefone ou nome de verdade no cadastro.
5. Tratamento sem rede: nome limpo (emoji fora; se não sobrar nome, usa o
   começo do e-mail), telefone discável, canal de origem, resumo curto.

Medido em 28/09/2026 (local, API real): encerrados de 11 a 15/09 com 283
atendimentos em 13 chamadas e 19 s; encerrados de março com 2.442 atendimentos
em 74 chamadas, 14 requisições de no máximo 10 s cada (2 min no total).

**Limite do Robust:** a documentação só fixa limite para rotas de escrita
(60 por minuto). Para leitura, 735 GETs entre 1 e 8 por segundo, incluindo
70 s seguidos a 6 por segundo, não deram nenhum 429. O módulo espera 150 ms
entre chamadas e trata 429 esperando o Retry-After.

## Subida no 3C

Aprovar cria uma lista nova na campanha escolhida (`POST /campaigns/{c}/lists`)
e grava o id na hora (o 3C não deduplica por nome), depois sobe em lotes de 100
(`POST .../lists/{l}/mailing`) com as colunas identifier, phone, nome, email,
contactid (`robust-atd-<id>`), mensagem (o resumo) e origem (o canal). A tela
mostra o `imported_lines` do 3C, não o status HTTP: os filtros do gestor da
campanha descartam número na importação e o 3C ainda responde 200.

## Testes

`php listas-3c/bin/testes.php` roda as regras puras (nome, telefone, motivo,
resumo, máscara) sem banco nem rede.

Na tela da lista, **Ocultar dados pessoais** mascara nome, e-mail, telefone e
observação (para mostrar em reunião ou gravar a tela).
