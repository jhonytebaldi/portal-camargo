# Imóveis da campanha (módulo do portal)

O que a Bianca pode falar de cada imóvel do anúncio, e tudo o que ela falou.
Pedido do Jhony na reunião de 22/10/2026, condição dele para ligar a Bianca
no imóvel do anúncio: as condições mudam todo mês, então a Camargo tem de
poder **alterar** a ficha, **limitar** o que ela fala (campo a campo) e
**auditar** cada resposta.

## As telas

| Tela | O que faz |
|---|---|
| Lista | cartão por imóvel, filtro ligados/desligados, o interruptor que liga a Bianca naquele imóvel e quantas conversas ela respondeu sobre ele |
| Cadastro | o código do Robust preenche tipo, bairro, quartos, suítes, vagas, valor e entrega; o corretor completa metragem, abertura, entrada, mês da última parcela (o "faltam N parcelas" é conta do sistema), FGTS e o que o anúncio não responde |
| O que ela fala | campo a campo, ela pode ou não falar. Desligado: o dado não chega à Bianca e, se o lead perguntar, ela diz que o corretor confirma |
| Como ela responde | pergunta como o lead e vê a resposta que ela daria, com os campos usados marcados. **Nada é enviado**: a task da prévia não tem envio nem ferramentas |
| Respostas | toda resposta que ela deu sobre o imóvel: a pergunta, a resposta, a conversa antes dela e o número que a trava tirou (número que ela escreveu sem estar na ficha não sai) |

Corretor (usuário com corretor vinculado em Admin → Usuários) vê e edita só os
imóveis dele; admin vê todos e escolhe o corretor de cada imóvel.

## Onde mora a ficha

No banco da Bianca, que é de onde ela lê na hora de responder. O portal não
guarda cópia: duas cópias seriam duas verdades. O portal fala com o banco da
Bianca só pelas funções `camargo_portal_*`, com uma chave própria; a chave
abre essas funções e nada mais. No MySQL do portal fica só a chave, cifrada
(`imc_config`, mesma cifra das chaves do Listas 3C).

## Instalação (nenhum passo técnico)

1. **Publicar**: o merge no `main` basta (a Hostinger publica sozinha).
2. **Tabela e botão**: na primeira visita de um admin à home, `schema.php`
   cria `imc_config` e registra a ferramenta (só admin). `admin/migrar.php`
   também chama.
3. **Chave**: Imóveis da campanha → **Ligação com a Bianca** (só admin): colar
   a chave que a Bianca mandou e **Testar e salvar**. A tela lê a lista de
   imóveis antes de gravar; chave errada não grava. O repositório é público:
   a chave nunca vai para o código.
4. Liberar para cada corretor: Admin → Usuários, marcar "Imóveis da campanha"
   e vincular o corretor.

## Como a Bianca acha o imóvel na conversa

Pelo anúncio: o `Source ID` da Meta, quando a Bianca já conhece o anúncio, ou
o **"Cód. 1588" no texto do anúncio** (ou o "imóvel cód. 1588" do site). Por
isso o cadastro pede o código do Robust: ele precisa estar no texto do
anúncio. Imóvel desligado nunca entra; e por cima de tudo vale a chave geral
da Bianca, que a Bianca liga só com a Camargo de acordo.

## Testes

`php imoveis-campanha/bin/testes.php` roda as regras da ficha (conta das
parcelas, campos do Robust, juntar sem perder o limite, marcar o que a
resposta usou) sem banco nem rede.
