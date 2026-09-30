# Listas 3C (módulo do portal)

Monta a lista de ligação a partir do Robust, trata os contatos, mostra a prévia
para aprovar e sobe numa campanha do 3C. Pedido do Jhony na reunião de 24/09:
tirar do trabalho manual de exportar do Robust, tratar na IA e subir no 3C.

## Instalação (nenhum passo técnico)

1. **Publicar**: o merge no `main` basta. A Hostinger publica o `main` sozinha
   (todo arquivo servido em portal.imobcamargo.com.br tem a data de
   modificação segundos depois do último commit).
2. **Tabelas**: nada a fazer. Na primeira vez que um admin abre a home do
   portal (ou `/listas-3c/`), `schema.php` cria as tabelas `l3c_*` e registra
   a ferramenta; o botão "Listas 3C" já aparece nessa mesma visita. É
   idempotente e, depois da primeira vez na sessão, não consulta nada.
   `admin/migrar.php` continua funcionando, para quem preferir.
3. **Chaves**: Listas 3C → **Configurar integrações** (só admin).
   - Robust: se a Busca de Imóveis já sincroniza, a tela mostra
     "Funcionando · a mesma da Busca" e não há o que fazer.
   - 3C: colar o token e clicar em **Testar e salvar**. A tela testa contra a
     API e só grava se funcionar. Ali também se marcam as campanhas liberadas.
   - As chaves ficam no MySQL cifradas (AES-256-GCM, chave derivada da senha
     do banco do `config.php`; riscos e escolha em `lib/Integracoes.php`).
   - O `config.php` continua valendo como alternativa (`ROBUST_NICKNAME`,
     `ROBUST_API_KEY`, `TRESC_BASE_URL`, `TRESC_API_TOKEN`,
     `L3C_CAMPANHAS_PERMITIDAS`). Ordem: tela, depois config.php, depois a
     chave da Busca (só Robust).
4. **Cron (opcional)**: hPanel → Avançado → Cron Jobs, a cada 5 minutos:
   `php /home/USUARIO/domains/portal.imobcamargo.com.br/public_html/listas-3c/bin/processar.php`
   A tela monta a lista sozinha enquanto está aberta; o cron só garante que a
   lista continua se a aba for fechada no meio.
5. Liberar para o Guilherme: Admin → Usuários, marcar "Listas 3C". Quem não é
   admin usa o módulo, mas não vê nem abre a tela de integrações.

## Os 4 modelos

| Modelo | Quem entra |
|---|---|
| Encerrados recuperáveis (lista recente) | cadastrados no período, encerrados em Lead, Atendimento ou Agendamento por: agendou e não veio, bloqueou ligações, lead de corretor que saiu, parou de responder, sem retorno após todas as tentativas |
| Encerrados recuperáveis (lista antiga) | os de cima, também em Visita, e mais: curioso, optou por esperar, depende de outra negociação, restrição no nome, sem entrada, desistiu de comprar |
| Agendamento vencido | ainda em Agendamento, a data do agendamento caiu no período, já passou e não houve outro marcado para a frente |
| Primeiras etapas | ativos em Lead, Atendimento ou Agendamento cadastrados no período; quem tem agendamento para a frente fica fora |

Em todos: motivo da lista "nunca" fica fora (duplicado, número incorreto,
comprou em outra imobiliária etc.), lead de origem "do corretor" com menos de
30 dias e atendimento ainda aberto fica fora (encerrado não; e a caixa
"Incluir leads recentes de origem do corretor", na hora de montar, traz todos),
telefone sem DDD válido e telefone repetido ficam fora, e
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

**Nada sobe sozinho.** Montar a lista só lê o Robust e para em "Pronta para
aprovar". Ela só vai para o 3C quando alguém escolhe a campanha e clica em
**Aprovar e subir**. A Campanha Padrão vem marcada por padrão (é onde o time
sobe as listas feitas à mão).

**Nome:** se o campo ficar vazio, a lista ganha o padrão das listas feitas à mão
na Campanha Padrão (lido por GET em 29/09/2026): `[15-09 a 25-09] L.A.A
ENCERRADOS MOTIVOS PORTAL`. Período, iniciais das etapas (Lead, Atendimento,
Agendamento, Visita), tipo e `PORTAL` no fim para separar da lista feita à mão.

**Duplicatas:** antes de criar a lista, o portal tira quem já está na campanha
escolhida, por duas fontes: (1) telefone que este portal já subiu nela;
(2) telefone que já recebeu ligação nela nos últimos 60 dias
(`GET /calls?campaigns[]=&numbers[]=`, em janelas de 30 dias, porque o 3C
recusa mais de 31). A API do 3C não tem rota que devolva o conteúdo de uma
lista (`GET .../lists/{l}/mailing` dá 405), então contato de lista feita à mão
que ainda não foi discado não é visto. Atenção: `numbers[0]=` (o que o
`http_build_query` gera) faz o 3C ignorar o filtro; tem de ser `numbers[]=`.
Se todos já estiverem na campanha, nenhuma lista é criada.
O histórico é o banco do portal no servidor, o mesmo para todo computador.
A conferência roda ao aprovar (só aí se sabe a campanha); na lista pronta,
um aviso já mostra quantos contatos este portal subiu antes, por campanha.

Motivo marcado em "Nunca entram" aparece travado nos modelos, com o aviso de
desmarcar lá para usar; "Nunca entram" continua vencendo o modelo.

Aprovar cria uma lista nova na campanha escolhida (`POST /campaigns/{c}/lists`)
e grava o id na hora (o 3C não deduplica por nome), depois sobe em lotes de 100
(`POST .../lists/{l}/mailing`) com as colunas identifier, phone, nome, email,
contactid (`robust-atd-<id>`), mensagem (o resumo) e origem (o canal). A tela
mostra o `imported_lines` do 3C, não o status HTTP: os filtros do gestor da
campanha descartam número na importação e o 3C ainda responde 200.

## Testes

`php listas-3c/bin/testes.php` roda as regras puras (nome, telefone, motivo,
resumo, máscara, corretor, motivos travados) sem banco nem rede.
`PORTAL_CONFIG=<config de teste> php listas-3c/bin/teste-conferencia.php` prova
a conferência de repetidos num MySQL de teste (o nome do banco precisa ter
"teste"), com um 3C falso local: nada vai para o 3C de verdade.

Na tela da lista, **Modo demonstração** mascara nome, e-mail, telefone e a
observação do atendimento (texto livre do corretor no Robust, que às vezes traz
nome ou telefone), para mostrar em reunião ou gravar a tela. Fora desse modo,
e no 3C, a observação vai inteira, com o rótulo "Obs. do atendimento no Robust".
