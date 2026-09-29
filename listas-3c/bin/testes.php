<?php
/* =====================================================================
   listas-3c/bin/testes.php: testes das regras puras (sem banco, sem rede).
   Rodar:  php listas-3c/bin/testes.php     (sai com código 1 se falhar)
   ===================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/Tratamento.php';
require_once __DIR__ . '/../lib/Modelos.php';

$falhas = 0; $n = 0;
function igual($obtido, $esperado, string $nome): void {
    global $falhas, $n; $n++;
    if ($obtido === $esperado) { echo "ok   $nome\n"; return; }
    $falhas++; echo "FALHA $nome\n      esperado: " . var_export($esperado, true) . "\n      obtido:   " . var_export($obtido, true) . "\n";
}
$T = 'L3cTratamento';
$pad = L3cModelos::padroes();

// Nome limpo: emoji, símbolo, caixa, e o e-mail quando o nome não serve.
igual($T::nomeLimpo('🏡✨ MARIA DA SILVA 🙏'), 'Maria da Silva', 'nome com emoji e caixa alta');
igual($T::nomeLimpo('joão pedro de souza'), 'João Pedro de Souza', 'nome minúsculo com acento');
igual($T::nomeLimpo('🔥🔥', 'carla.menezes92@gmail.com'), 'Carla Menezes', 'só emoji cai para o e-mail');
igual($T::nomeLimpo('.', ''), '', 'nome vazio sem e-mail');
igual($T::nomeLimpo('Ana Costa - Hotel 🏨'), 'Ana Costa Hotel', 'hífen solto sai');
igual($T::nomeLimpo("Ana-Luíza D'Ávila 2"), "Ana-luíza D'ávila", 'hífen e apóstrofo ficam, número sai');

// Telefone discável.
igual($T::telefone('+55 (47) 98888-7777'), '47988887777', 'celular com +55 e máscara');
igual($T::telefone('4733334444'), '4733334444', 'fixo');
igual($T::telefone('550'), null, 'lixo de formulário');
igual($T::telefone('(20) 98888-7777'), null, 'DDD inexistente');
igual($T::telefone('47 8888-7777 1'), null, 'celular sem o 9');
igual($T::telefone('5547988887777'), '47988887777', 'DDI colado');

// Motivo: casa com acento/caixa/travessão diferentes; "nunca" vence o modelo.
igual($T::chaveMotivo('Atendimento encerrado - Não Comprador – Busca para Parente/Amigo'), $T::chaveMotivo('não comprador - busca para parente amigo'), 'chave ignora prefixo, travessão e acento');
$rec = $pad['encerrados_recentes']; $ant = $pad['encerrados_antigos'];
igual($T::descartePorMotivo('Sem retorno após todas as tentativas', $rec['motivos'], $rec['motivos_nunca']), null, 'recente: sem retorno entra');
igual($T::descartePorMotivo('Sem Entrada', $rec['motivos'], $rec['motivos_nunca']), 'motivo fora do modelo', 'recente: sem entrada fica fora');
igual($T::descartePorMotivo('Sem Entrada', $ant['motivos'], $ant['motivos_nunca']), null, 'antiga: sem entrada entra');
igual($T::descartePorMotivo('Número incorreto', $ant['motivos'], $ant['motivos_nunca']), 'motivo que nunca entra', 'número incorreto nunca entra');
igual($T::descartePorMotivo('Número incorreto', ['Número incorreto'], $ant['motivos_nunca']), 'motivo que nunca entra', 'nunca vence mesmo marcado no modelo');
igual($T::descartePorMotivo(null, $rec['motivos'], $rec['motivos_nunca']), 'sem motivo de encerramento registrado', 'sem motivo');
igual($T::descartePorMotivo('Desatualizado além do permitido!', $ant['motivos'], $ant['motivos_nunca']), 'motivo fora do modelo', 'desatualizado não foi classificado pelo Jhony: fora por padrão');

// Origem de corretor.
igual($T::origemDeCorretor('Campanha do Corretor', L3cModelos::ORIGENS_CORRETOR), true, 'campanha do corretor');
igual($T::origemDeCorretor('Celular do Corretor', L3cModelos::ORIGENS_CORRETOR), true, 'celular do corretor');
igual($T::origemDeCorretor('Telefone da Imobiliária', L3cModelos::ORIGENS_CORRETOR), false, 'telefone da imobiliária não é do corretor');

// Resumo curto, datas no fuso do Robust e teto de tamanho.
$r = $T::resumo(['stage' => 1, 'criado_em' => '2026-09-11T23:30:00-03:00', 'encerrado_em' => '2026-09-20T10:00:00-03:00',
                 'motivo' => 'Sem retorno após todas as tentativas', 'atendente' => 'Fulano', 'obs' => "cliente\nquer 2 quartos"]);
igual($r, 'Etapa Atendimento; entrou 11/09/26; encerrado 20/09/26 (Sem retorno após todas as tentativas); corretor Fulano. Obs. do atendimento no Robust: cliente quer 2 quartos', 'resumo');
igual(mb_strlen($T::resumo(['stage' => 0, 'obs' => str_repeat('x', 900)])), 280, 'resumo cortado em 280');
igual($T::canal('site'), 'Site', 'canal com caixa');
igual($T::canal('0 - Não selecionou origem'), 'Sem origem', 'canal vazio');

// Máscara de demonstração não deixa passar dígito do assinante.
igual($T::mascarar('47988887777', 'telefone'), '(47) •••••-••••', 'máscara de telefone');
igual(str_contains($T::mascarar('Maria Silva', 'nome'), 'aria'), false, 'máscara de nome');
// A observação some no modo demonstração, mas com uma frase que se explica.
$mr = $T::mascararResumo($r);
igual(str_contains($mr, '2 quartos'), false, 'máscara tira a observação');
igual(str_contains($mr, 'escondida no modo demonstração'), true, 'máscara diz o que escondeu');
igual($T::mascararResumo('Etapa Lead; entrou 11/09/26'), 'Etapa Lead; entrou 11/09/26', 'resumo sem observação fica igual');

// Nome da lista no padrão da Campanha Padrão do 3C.
igual($T::nomePadrao($pad['encerrados_recentes'], '2026-09-15', '2026-09-25'), '[15-09 a 25-09] L.A.A ENCERRADOS MOTIVOS PORTAL', 'nome: encerrados recentes');
igual($T::nomePadrao($pad['encerrados_antigos'], '2026-03-01', '2026-03-31'), '[01-03 a 31-03] L.A.A.V ENCERRADOS MOTIVOS PORTAL', 'nome: encerrados antigos com visita');
igual($T::nomePadrao($pad['primeiras_etapas'], '2026-09-10', '2026-09-18'), '[10-09 a 18-09] L.A.A ATIVOS PORTAL', 'nome: primeiras etapas');
igual($T::nomePadrao($pad['agendamento_vencido'], '2026-09-01', '2026-09-04'), '[01-09 a 04-09] AGENDAMENTO VENCIDO PORTAL', 'nome: agendamento vencido');

// Telefone no formato que o filtro de ligações do 3C casa (55 + DDD + número).
igual($T::telefone3c('47988887777'), '5547988887777', 'telefone com 55 para o 3C');

// Modelos: proposta e negociado nunca entram, mesmo editados.
$ed = L3cModelos::todos(['primeiras_etapas' => ['etapas' => [0, 1, 4, 5]]]);
igual($ed['primeiras_etapas']['etapas'], [0, 1], 'etapas 4 e 5 são cortadas');

echo "\n$n testes, $falhas falhas\n";
exit($falhas ? 1 : 0);
