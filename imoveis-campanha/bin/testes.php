<?php
/* imoveis-campanha/bin/testes.php: as regras da ficha (lib/Ficha.php), sem banco nem rede.
   Rodar: php imoveis-campanha/bin/testes.php */
declare(strict_types=1);
require_once __DIR__ . '/../lib/Ficha.php';
$falhas = 0; $n = 0;
function t(string $nome, $obtido, $esperado): void {
    global $falhas, $n; $n++;
    if ($obtido === $esperado) return;
    $falhas++; echo "FALHOU: $nome\n  esperado: " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n  obtido:   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n";
}
$out23 = new DateTimeImmutable('2026-10-23T15:00:00-03:00');
t('parcelas: out/2026 até mai/2027 = 7 (a conta da tela da r23 e do Trigger)', ImcFicha::parcelasQueFaltam('2027-05', $out23), 7);
t('parcelas: 31/10 23h em SP ainda é outubro', ImcFicha::parcelasQueFaltam('2027-05', new DateTimeImmutable('2026-11-01T02:00:00Z')), 7);
t('parcelas: mês passado = 0, nunca negativo', ImcFicha::parcelasQueFaltam('2026-01', $out23), 0);
t('mês curto', ImcFicha::mesCurto('2027-05'), 'mai/2027');
t('mês longo', ImcFicha::mesLongo('2027-05'), 'maio de 2027');

$rob = ImcFicha::doRobust(['tipo' => 'Apartamento', 'endereco_bairro' => 'Petrópolis', 'quartos' => 2, 'suites' => 0, 'vagas' => 1,
                           'valor_venda' => 302900, 'mes_conclusao' => 11, 'ano_conclusao' => 2028]);
t('Robust: mes_conclusao conta de zero (11 = dezembro)', $rob['entrega_prevista'], 'dezembro de 2028');
t('Robust: valor em reais', $rob['valor'], 'R$ 302.900');
t('Robust: zero suítes fica vazio (não é fato para ela dizer)', $rob['suites'], '');
t('Robust: sem ano, sem entrega', ImcFicha::doRobust(['mes_conclusao' => 3])['entrega_prevista'], '');

$atuais = [
    ['chave' => 'nome', 'rotulo' => 'Nome', 'valor' => 'Vega', 'pode_falar' => true],
    ['chave' => 'valor', 'rotulo' => 'Valor', 'valor' => 'R$ 351.000', 'pode_falar' => false],
    ['chave' => 'renda', 'rotulo' => 'Renda', 'valor' => 'R$ 8.000', 'pode_falar' => true],
];
$j = ImcFicha::juntar($atuais, ['valor' => ['Valor', 'R$ 360.000', 'robust'], 'renda' => ['Renda', '', 'corretor'], 'metragem' => ['Metragem', '46 m²', 'corretor']]);
t('juntar: ordem antiga mantida, novo no fim', array_column($j, 'chave'), ['nome', 'valor', 'metragem']);
t('juntar: valor trocado e o LIMITE (pode_falar=false) preservado', [$j[1]['valor'], $j[1]['pode_falar']], ['R$ 360.000', false]);
t('juntar: campo esvaziado sai', in_array('renda', array_column($j, 'chave'), true), false);
t('juntar: campo novo nasce liberado', $j[2]['pode_falar'], true);
$l = ImcFicha::limitar($j, ['nome' => '1', 'metragem' => '1']);
t('limitar: desmarcado fica desligado, marcado liga', array_column($l, 'pode_falar'), [true, false, true]);

t('chave livre sem acento e sem colisão', ImcFicha::chaveLivre('Home club', ['home_club']), 'home_club_2');
t('id do imóvel novo', ImcFicha::idNovo('Geminado Vila Nova!', 'Cód. 1588'), 'portal-1588-geminado-vila-nova');
t('id cabe no banco (^[a-z0-9-]{3,80}$)', (bool)preg_match('/^[a-z0-9-]{3,80}$/', ImcFicha::idNovo(str_repeat('Apartamento Ótimo ', 10), '1')), true);

$ficha = [
    ['chave' => 'valor', 'rotulo' => 'Valor', 'valor' => 'R$ 319.900', 'pode_falar' => true],
    ['chave' => 'entrada', 'rotulo' => 'Entrada', 'valor' => 'R$ 32.000', 'pode_falar' => true],
    ['chave' => 'entrega_prevista', 'rotulo' => 'Entrega prevista', 'valor' => 'junho de 2027', 'pode_falar' => true],
    ['chave' => 'fgts', 'rotulo' => 'FGTS', 'valor' => 'aceita FGTS', 'pode_falar' => true],
    ['chave' => 'abertura', 'rotulo' => 'Abertura', 'valor' => '5,5 m', 'pode_falar' => true],
    ['chave' => 'condominio', 'rotulo' => 'Condomínio', 'valor' => 'R$ 280', 'pode_falar' => false],
    ['chave' => 'parcelas_ate', 'rotulo' => 'Parcela até', 'valor' => 'maio de 2027', 'pode_falar' => true],
];
t('usados: número da entrada e mês da parcela; o ano 2027 não marca a entrega', ImcFicha::usados('A entrada é de R$ 32.000, em até 7 parcelas até maio de 2027.', $ficha), ['entrada', 'parcelas_ate']);
t('usados: "entrada" solta no texto não marca o campo Entrada (ele tem número)', ImcFicha::usados('Aceita sim, o FGTS pode entrar na entrada.', $ficha), ['fgts']);
t('usados: "32 mil" vale 32.000', ImcFicha::usados('A entrada é de 32 mil.', $ficha), ['entrada']);
t('usados: a entrega pelo valor inteiro', ImcFicha::usados('A entrega está prevista para junho de 2027.', $ficha), ['entrega_prevista']);
t('usados: campo limitado nunca aparece', ImcFicha::usados('O condomínio é R$ 280.', $ficha), []);
t('usados: campo sem número pelo nome', ImcFicha::usados('São 62 m², com 5,5 m de abertura.', $ficha), ['abertura']);
$comNome = array_merge($ficha, [['chave' => 'tipo', 'rotulo' => 'Tipo', 'valor' => 'Geminado', 'pode_falar' => true], ['chave' => 'bairro', 'rotulo' => 'Bairro', 'valor' => 'Vila Nova', 'pode_falar' => true]]);
t('usados: o nome do imóvel não marca Tipo nem Bairro', ImcFicha::usados('A entrada do Geminado Vila Nova é R$ 32.000. Esse geminado te interessou?', $comNome, 'Geminado Vila Nova'), ['entrada']);
t('usados: o bairro citado fora do nome marca', ImcFicha::usados('O geminado fica na Vila Nova, em Joinville.', $comNome, 'Geminado Vila Nova'), ['bairro']);
t('passou ao corretor', [ImcFicha::passouAoCorretor('Isso eu confirmo com o Rafael, que cuida desse imóvel.'), ImcFicha::passouAoCorretor('O corretor confirma esse detalhe do condomínio pra você!'), ImcFicha::passouAoCorretor('A entrada é R$ 32.000.')], [true, true, false]);

echo $falhas ? "$falhas de $n FALHARAM\n" : "$n testes ok\n";
exit($falhas ? 1 : 0);
