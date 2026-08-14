<?php
/* =====================================================================
   sync.php — roda pelo cron 1x por dia (ou manualmente).
   1. Puxa os imóveis ativos da API do Robust
   2. Puxa as fotos de cada imóvel
   3. Junta com os dados do XLS (descrição, área, entrega...)
   4. Grava imoveis.json, que é o arquivo que a ferramenta lê

   REGRA DO MERGE:
     - A API manda em: preço, quartos, banheiros, status, fotos,
       proprietário, captador, endereço  (ela sempre tem o dado mais fresco)
     - O XLS manda em: descrição, área, entrega, amenidades, parcelamento,
       suítes, vagas  (a API não devolve esses campos)
     - A API NUNCA apaga o que ela não sabe.
   ===================================================================== */

require_once __DIR__ . '/config.php';

@set_time_limit(600);
$inicio = microtime(true);
$GLOBALS['SYNC_INICIO'] = $inicio;
$GLOBALS['API_FALHAS'] = 0;
$GLOBALS['API_FORA_DO_AR'] = false;

/** Chamada autenticada à API do Robust. */
function api_get(string $caminho, int $tentativas = 3) {
    // Disjuntor: quando a API do Robust cai, TODAS as ~160 chamadas falham.
    // Repetir cada uma 3 vezes com espera levaria o sync a mais de 15 minutos
    // para no fim dar erro do mesmo jeito. Depois de algumas falhas seguidas,
    // paramos na hora e dizemos o que esta acontecendo.
    if (($GLOBALS['API_FORA_DO_AR'] ?? false)) {
        throw new Exception('API do Robust fora do ar (interrompido apos falhas seguidas)');
    }
    $url = 'https://api.robustcrm.io/v1' . $caminho;
    $ultimoErro = '';

    // O sync faz cerca de 160 chamadas seguidas. Sem repetir, um unico 502 do
    // servidor do Robust — que acontece de vez em quando — abortava tudo e o
    // usuario via "O servidor recusou a sincronizacao".
    for ($i = 1; $i <= $tentativas; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => [
                'X-Nickname: ' . ROBUST_NICKNAME,
                'X-API-Key: '  . ROBUST_API_KEY,
                'Accept: application/json',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp !== false && $code === 200) {
            $j = json_decode($resp, true);
            if ($j !== null) { $GLOBALS['API_FALHAS'] = 0; return $j; }
            $ultimoErro = "JSON invalido em $caminho";
        } elseif ($resp === false) {
            $ultimoErro = "falha de rede em $caminho: $err";
        } else {
            $ultimoErro = "HTTP $code em $caminho";
            // Credencial errada ou caminho inexistente: repetir nao resolve.
            if (in_array($code, [400, 401, 403, 404], true)) throw new Exception($ultimoErro);
        }

        if ($i < $tentativas) {
            log_sync("aviso: $ultimoErro - tentando de novo ($i/$tentativas)");
            sleep($i * 2);   // 2s, depois 4s
        }
    }
    $GLOBALS['API_FALHAS'] = ($GLOBALS['API_FALHAS'] ?? 0) + 1;
    if ($GLOBALS['API_FALHAS'] >= 4) {
        $GLOBALS['API_FORA_DO_AR'] = true;
        log_sync('API do Robust fora do ar — sync interrompido');
    }
    throw new Exception($ultimoErro . " (apos $tentativas tentativas)");
}


/* ---------------------------------------------------------------------
   Normalização de bairro e mapa de regiões de Joinville.
   Precisa existir aqui (e não só no navegador) porque os imóveis que vêm
   apenas da API nunca passam pelo pipeline do XLS: sem isto, "ITINGA",
   "Itinga" e "itinga" viram três bairros diferentes e os imóveis novos
   ficam sem região.
   --------------------------------------------------------------------- */

function txt_norm($s) {
    // Sem mbstring de propósito: nem toda hospedagem tem a extensão.
    $s = trim((string)$s);
    $de = ['Á','À','Ã','Â','Ä','É','Ê','Ë','Í','Ï','Ó','Ô','Õ','Ö','Ú','Ü','Ç',
           'á','à','ã','â','ä','é','ê','ë','í','ï','ó','ô','õ','ö','ú','ü','ç'];
    $para = ['a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c',
             'a','a','a','a','a','e','e','e','i','i','o','o','o','o','u','u','c'];
    return strtolower(str_replace($de, $para, $s));
}

// Grafia oficial de cada bairro (chave = versão normalizada).
// Grafia oficial de cada bairro (chave = versão sem acento, minúscula).
$GLOBALS['BAIRRO_NOME'] = [
    'centro' => 'Centro',
    'america' => 'América',
    'atiradores' => 'Atiradores',
    'gloria' => 'Glória',
    'saguacu' => 'Saguaçu',
    'bom retiro' => 'Bom Retiro',
    'santo antonio' => 'Santo Antônio',
    'costa e silva' => 'Costa e Silva',
    'jardim sofia' => 'Jardim Sofia',
    'jardim paraiso' => 'Jardim Paraíso',
    'rio bonito' => 'Rio Bonito',
    'zona industrial norte' => 'Zona Industrial Norte',
    'aventureiro' => 'Aventureiro',
    'iririu' => 'Iririú',
    'jardim iririu' => 'Jardim Iririú',
    'comasa' => 'Comasa',
    'boa vista' => 'Boa Vista',
    'espinheiros' => 'Espinheiros',
    'vila cubatao' => 'Vila Cubatão',
    'zona industrial tupy' => 'Zona Industrial Tupy',
    'morro do amaral' => 'Morro do Amaral',
    'anita garibaldi' => 'Anita Garibaldi',
    'bucarein' => 'Bucarein',
    'floresta' => 'Floresta',
    'guanabara' => 'Guanabara',
    'itaum' => 'Itaum',
    'fatima' => 'Fátima',
    'adhemar garcia' => 'Adhemar Garcia',
    'joao costa' => 'João Costa',
    'jarivatuba' => 'Jarivatuba',
    'parque guarani' => 'Parque Guarani',
    'paranaguamirim' => 'Paranaguamirim',
    'petropolis' => 'Petrópolis',
    'boehmerwald' => 'Boehmerwald',
    'itinga' => 'Itinga',
    'profipo' => 'Profipo',
    'santa catarina' => 'Santa Catarina',
    'ulysses guimaraes' => 'Ulysses Guimarães',
    'escolinha' => 'Escolinha',
    'vila nova' => 'Vila Nova',
    'morro do meio' => 'Morro do Meio',
    'nova brasilia' => 'Nova Brasília',
    'sao marcos' => 'São Marcos',
    'pirabeiraba (centro)' => 'Pirabeiraba (Centro)',
    'dona francisca' => 'Dona Francisca',
    'estrada da ilha' => 'Estrada da Ilha',
    'estrada bonita' => 'Estrada Bonita',
    'rio da prata' => 'Rio da Prata',
    'quiriri' => 'Quiriri',
    'vigorelli' => 'Vigorelli',
    'centro (pirabeiraba)' => 'Pirabeiraba (Centro)',
    'dona francisca (pirabeiraba)' => 'Dona Francisca',
    'dona francisca (piraberaba)' => 'Dona Francisca',
    'pirabeiraba' => 'Pirabeiraba (Centro)',
    'volta redonda' => 'Volta Redonda',
    'paranaguamirim' => 'Paranaguamirim',
];

$GLOBALS['BAIRRO_REGIAO'] = [
    'centro' => 'Centro',
    'america' => 'Norte',
    'atiradores' => 'Norte',
    'gloria' => 'Norte',
    'saguacu' => 'Norte',
    'bom retiro' => 'Norte',
    'santo antonio' => 'Norte',
    'costa e silva' => 'Norte',
    'jardim sofia' => 'Norte',
    'jardim paraiso' => 'Norte',
    'rio bonito' => 'Norte',
    'zona industrial norte' => 'Norte',
    'aventureiro' => 'Leste',
    'iririu' => 'Leste',
    'jardim iririu' => 'Leste',
    'comasa' => 'Leste',
    'boa vista' => 'Leste',
    'espinheiros' => 'Leste',
    'vila cubatao' => 'Leste',
    'zona industrial tupy' => 'Leste',
    'morro do amaral' => 'Leste',
    'anita garibaldi' => 'Sul',
    'bucarein' => 'Sul',
    'floresta' => 'Sul',
    'guanabara' => 'Sul',
    'itaum' => 'Sul',
    'fatima' => 'Sul',
    'adhemar garcia' => 'Sul',
    'joao costa' => 'Sul',
    'jarivatuba' => 'Sul',
    'parque guarani' => 'Sul',
    'paranaguamirim' => 'Sul',
    'petropolis' => 'Sul',
    'boehmerwald' => 'Sul',
    'itinga' => 'Sul',
    'profipo' => 'Sul',
    'santa catarina' => 'Sul',
    'ulysses guimaraes' => 'Sul',
    'escolinha' => 'Sul',
    'vila nova' => 'Oeste',
    'morro do meio' => 'Oeste',
    'nova brasilia' => 'Oeste',
    'sao marcos' => 'Oeste',
    'pirabeiraba (centro)' => 'Pirabeiraba (distrito)',
    'dona francisca' => 'Pirabeiraba (distrito)',
    'estrada da ilha' => 'Pirabeiraba (distrito)',
    'estrada bonita' => 'Pirabeiraba (distrito)',
    'rio da prata' => 'Pirabeiraba (distrito)',
    'quiriri' => 'Pirabeiraba (distrito)',
    'vigorelli' => 'Norte',
    'centro (pirabeiraba)' => 'Pirabeiraba (distrito)',
    'dona francisca (pirabeiraba)' => 'Pirabeiraba (distrito)',
    'dona francisca (piraberaba)' => 'Pirabeiraba (distrito)',
    'pirabeiraba' => 'Pirabeiraba (distrito)',
    'volta redonda' => 'Sul',   // Araquari, agrupado como Sul a pedido
];

// Coordenada oficial do centro de cada bairro. É melhor que a média dos
// imóveis: com 2 ou 3 imóveis, a média puxa para onde eles estão, e chegou
// a errar 3,1 km no Itinga.
$GLOBALS['BAIRRO_COORD'] = [
    'centro' => [-26.29800, -48.84594],
    'america' => [-26.28339, -48.85015],
    'atiradores' => [-26.30287, -48.85227],
    'gloria' => [-26.29708, -48.87292],
    'saguacu' => [-26.28330, -48.84085],
    'bom retiro' => [-26.26294, -48.84518],
    'santo antonio' => [-26.26952, -48.85413],
    'costa e silva' => [-26.27497, -48.88278],
    'jardim sofia' => [-26.23776, -48.83371],
    'jardim paraiso' => [-26.21441, -48.81985],
    'rio bonito' => [-26.15513, -48.90420],
    'zona industrial norte' => [-26.26264, -48.92651],
    'aventureiro' => [-26.22703, -48.81542],
    'iririu' => [-26.27122, -48.82132],
    'jardim iririu' => [-26.26075, -48.80998],
    'comasa' => [-26.28234, -48.80385],
    'boa vista' => [-26.30733, -48.82799],
    'espinheiros' => [-26.28442, -48.78718],
    'vila cubatao' => [-26.21784, -48.80028],
    'zona industrial tupy' => [-26.29417, -48.81134],
    'morro do amaral' => [-26.30148, -48.76479],
    'anita garibaldi' => [-26.31727, -48.84664],
    'bucarein' => [-26.31627, -48.84217],
    'floresta' => [-26.33241, -48.84523],
    'guanabara' => [-26.31905, -48.82786],
    'itaum' => [-26.33029, -48.83575],
    'fatima' => [-26.32125, -48.81273],
    'adhemar garcia' => [-26.32057, -48.80149],
    'joao costa' => [-26.34154, -48.80276],
    'jarivatuba' => [-26.33329, -48.80550],
    'parque guarani' => [-26.35633, -48.80533],
    'paranaguamirim' => [-26.34756, -48.79232],
    'petropolis' => [-26.34618, -48.83093],
    'boehmerwald' => [-26.36287, -48.81991],
    'itinga' => [-26.37894, -48.82649],
    'profipo' => [-26.36547, -48.84072],
    'santa catarina' => [-26.34900, -48.84629],
    'ulysses guimaraes' => [-26.32521, -48.79536],
    'escolinha' => [-26.37200, -48.82500],
    'vila nova' => [-26.29087, -48.88725],
    'morro do meio' => [-26.33718, -48.90148],
    'nova brasilia' => [-26.33795, -48.88735],
    'sao marcos' => [-26.31009, -48.88595],
    'pirabeiraba (centro)' => [-26.20556, -48.90972],
    'dona francisca' => [-26.19806, -48.92078],
    'estrada da ilha' => [-26.22985, -48.86256],
    'estrada bonita' => [-26.13550, -48.91120],
    'rio da prata' => [-26.17485, -48.95736],
    'quiriri' => [-26.13333, -49.01667],
    'vigorelli' => [-26.22444, -48.76822],
    'centro (pirabeiraba)' => [-26.20556, -48.90972],
    'pirabeiraba' => [-26.20556, -48.90972],
];

// Localidades rurais de Pirabeiraba: são muito espalhadas, então a busca por
// proximidade nelas usa um raio maior — senão não devolveria nada.
$GLOBALS['BAIRRO_RURAL'] = ['estrada da ilha', 'estrada bonita', 'rio da prata', 'quiriri'];

/** Endereço reduzido ao essencial, para comparar se mudou. */
function normalizaEndereco($e) {
    $e = txt_norm($e);
    $e = preg_replace('/[^a-z0-9 ]/', ' ', $e);
    return trim(preg_replace('/\s+/', ' ', $e));
}

/** Devolve [bairro com grafia oficial, região]. */
function bairro_regiao($bruto) {
    $n = txt_norm($bruto);
    $nome = $GLOBALS['BAIRRO_NOME'][$n] ?? null;
    if ($nome === null) {
        // Bairro fora do mapa: preserva o texto e só arruma a caixa das
        // palavras, deixando conectivos em minúsculo.
        $palavras = preg_split('/\s+/', strtolower(trim((string)$bruto)));
        $conect = ['e','de','do','da','dos','das'];
        foreach ($palavras as $i => $p) {
            if ($p === '') continue;
            $palavras[$i] = ($i > 0 && in_array($p, $conect, true)) ? $p : ucfirst($p);
        }
        $nome = implode(' ', $palavras);
    }
    $reg = $GLOBALS['BAIRRO_REGIAO'][$n] ?? null;
    return [$nome, $reg];
}

try {
    log_sync('--- início do sync ---');

    // ---------- 1. Lista de imóveis ativos ----------
    // Modo "só remontar": usado quando o usuário acabou de enviar uma planilha.
    // Reaproveita o que já foi baixado da API (api.json) em vez de buscar tudo
    // de novo. Sem isso a requisição levava 35-60s e o servidor devolvia 504
    // antes de o navegador receber resposta.
    $somenteMerge = !empty($GLOBALS['SYNC_SOMENTE_MERGE']) && file_exists(ARQ_API);

    if ($somenteMerge) {
        $imoveis = json_decode(@file_get_contents(ARQ_API), true) ?: [];
        log_sync('remontando a partir do cache: ' . count($imoveis) . ' imóveis');
    } else {
        $imoveis = [];
        $pagina  = 1;
        do {
            $r = api_get("/imoveis?status=1&per_page=500&page=$pagina");
            foreach (($r['data'] ?? []) as $item) $imoveis[] = $item;
            $totalPaginas = $r['meta']['pages'] ?? 1;
            $pagina++;
        } while ($pagina <= $totalPaginas);
        log_sync('imóveis ativos na API: ' . count($imoveis));
    }

    if (count($imoveis) === 0) {
        // Proteção: nunca substitui uma base boa por uma vazia.
        throw new Exception('API retornou zero imóveis — mantendo a base anterior.');
    }

    // ---------- 2. Fotos e anexos ----------
    // Só busca fotos de quem mudou, ou de quem ainda não tem foto salva.
    $fotosAnteriores = [];
    if (file_exists(ARQ_MERGE)) {
        foreach ((json_decode(@file_get_contents(ARQ_MERGE), true)['imoveis'] ?? []) as $ant) {
            if (!empty($ant['fotos']) || !empty($ant['atu'])) {
                $fotosAnteriores[$ant['c']] = [
                    'fotos' => $ant['fotos'] ?? [],
                    'upd'   => $ant['upd'] ?? null,
                    'atu'   => $ant['atu'] ?? null,
                    'lat'   => $ant['lat'] ?? null,
                    'lng'   => $ant['lng'] ?? null,
                ];
            }
        }
    }

    // Hospedagem compartilhada costuma cortar a requisicao por volta de 60s.
    // Se o tempo apertar, paramos e gravamos o que ja temos, em vez de sermos
    // mortos no meio e nao gravar nada.
    $limiteSegundos = (php_sapi_name() === 'cli') ? 1800 : 200;

    // MODO RAPIDO (padrao no botao da ferramenta)
    // A listagem responde em ~2s e ja traz preco, quartos, status e data de
    // entrega — o que muda no dia a dia. Ja 'atualizado_em', coordenadas e
    // fotos so existem no endpoint de detalhe, uma chamada por imovel: sao
    // ~160 chamadas que levam de 30s a 3min conforme o humor da API do Robust,
    // tempo demais para uma requisicao web (o servidor corta antes).
    // Entao: pelo navegador roda o modo rapido, reaproveitando esses dados; o
    // cron noturno roda o modo completo, sem limite de tempo, e os atualiza.
    $completo = (php_sapi_name() === 'cli') || !empty($_GET['completo']);
    if (!$completo) log_sync('modo rapido: so a listagem, detalhes vem do cache');

    $comFoto = 0;
    foreach ($imoveis as &$im) {
        if (!$completo) {
            // Reaproveita o que o ultimo sync completo ja tinha lido.
            $c = $fotosAnteriores[(int)$im['id']] ?? null;
            if ($c) {
                $im['_fotos'] = $c['fotos'] ?? [];
                $im['_atu']   = $c['atu'] ?? null;
                $im['_lat']   = $c['lat'] ?? null;
                $im['_lng']   = $c['lng'] ?? null;
                if (!empty($im['_fotos'])) $comFoto++;
                continue;
            }
            // Imovel novo, que nunca passou por um sync completo: vale a pena
            // buscar o detalhe dele, sao poucos.
        }
        if (!empty($GLOBALS['API_FORA_DO_AR'])) {
            throw new Exception('A API do Robust parou de responder no meio da sincronizacao. '
                . 'Nada foi alterado — tente de novo em alguns minutos.');
        }
        if (microtime(true) - $GLOBALS['SYNC_INICIO'] > $limiteSegundos) {
            log_sync('tempo esgotado no laco de detalhes — seguindo com o que ja foi lido');
            break;
        }
        if ($somenteMerge) {
            // O cache de api.json já guarda _fotos, _atu, _lat e _lng.
            if (!empty($im['_fotos'])) $comFoto++;
            continue;
        }
        $id  = (int)$im['id'];
        $upd = $im['updated_at'] ?? null;
        $cache = $fotosAnteriores[$id] ?? null;

        // 1) DETALHE — sempre. É a única fonte de 'atualizado_em' (data da
        //    conferência do anúncio). Não dá para usar cache aqui: a conferência
        //    muda essa data SEM alterar updated_at, que é justamente o caso
        //    que queremos capturar.
        try {
            $d = api_get("/imoveis/$id", 1);   // 1 tentativa: o laco ja tolera falha
            $d = $d['data'] ?? $d;
            $im['_atu'] = $d['atualizado_em'] ?? null;
            // Coordenadas: só ~25% dos imóveis têm, mas bastam algumas por
            // bairro para calcular o centro dele e medir distâncias reais.
            $la = $d['endereco_latitude']  ?? null;
            $lo = $d['endereco_longitude'] ?? null;
            $im['_lat'] = is_numeric($la) ? (float)$la : null;
            $im['_lng'] = is_numeric($lo) ? (float)$lo : null;
        } catch (Exception $e) {
            $im['_atu'] = $cache['atu'] ?? null;
            $im['_lat'] = $cache['lat'] ?? null;
            $im['_lng'] = $cache['lng'] ?? null;
            log_sync("aviso: detalhe do imóvel $id falhou (" . $e->getMessage() . ')');
        }

        // 2) FOTOS — só quando o cadastro mudou ou ainda não temos nenhuma.
        //    Usamos /files e não o 'arquivos' do detalhe: aquele vem de um
        //    cache do CRM que às vezes lista menos fotos do que existem.
        if ($cache && ($cache['upd'] ?? null) === $upd && !empty($cache['fotos'])) {
            $im['_fotos'] = $cache['fotos'];
            $comFoto++;
            continue;
        }
        try {
            $f = api_get("/imoveis/$id/files", 1);
            $fotos = [];
            foreach (($f['data'] ?? []) as $arq) {
                if (($arq['filetype'] ?? '') !== 'media') continue;
                if (($arq['visible'] ?? true) !== true && ($arq['visible'] ?? 1) != 1) continue;
                $u = $arq['urls'] ?? [];
                if (empty($u['full']) && empty($u['small'])) continue;
                $fotos[] = [
                    'p'   => $u['small'] ?? $u['full'],
                    'g'   => $u['full']  ?? $u['small'],
                    'leg' => $arq['legend'] ?? '',
                ];
            }
            $im['_fotos'] = $fotos;
            if ($fotos) $comFoto++;
        } catch (Exception $e) {
            $im['_fotos'] = $cache['fotos'] ?? [];
            if (!empty($im['_fotos'])) $comFoto++;
            log_sync("aviso: fotos do imóvel $id falharam (" . $e->getMessage() . ')');
        }
    }
    unset($im);
    log_sync('imóveis com foto: ' . $comFoto . ($completo ? ' (sync completo)' : ' (modo rapido)'));

    file_put_contents(ARQ_API, json_encode($imoveis, JSON_UNESCAPED_UNICODE));

    // Coordenadas obtidas por geocodificação do endereço (geocodificar.php),
    // usadas só onde o CRM não tem a posição preenchida.
    $geo = [];
    if (file_exists(DATA_DIR . '/geocode.json')) {
        $geo = json_decode(@file_get_contents(DATA_DIR . '/geocode.json'), true) ?: [];
    }

    // ---------- 3. Merge com o XLS ----------
    $xls = [];
    $xlsData = null;
    if (file_exists(ARQ_XLS)) {
        $j = json_decode(@file_get_contents(ARQ_XLS), true);
        $xlsData = $j['gerado_em'] ?? null;
        foreach (($j['imoveis'] ?? []) as $x) $xls[(int)$x['c']] = $x;
    }

    $saida = [];
    $semDescricao = 0;
    foreach ($imoveis as $a) {
        $id = (int)$a['id'];
        $x  = $xls[$id] ?? null;

        // Base: tudo que veio do XLS (descrição, área, entrega, amenidades...)
        $reg = $x ?: [];

        // A API sobrescreve APENAS o que ela realmente sabe.
        $reg['c']  = $id;
        $reg['t']  = $a['tipo']             ?? ($reg['t']  ?? '');
        list($_b, $_r) = bairro_regiao($a['endereco_bairro'] ?? ($reg['b'] ?? ''));
        $reg['b'] = $_b;
        // A região sempre vem do mapa de bairros — inclusive para os imóveis
        // que só existem na API e nunca passaram por um XLS.
        if ($_r !== null) $reg['r'] = $_r;
        elseif (!isset($reg['r'])) $reg['r'] = null;
        $reg['ci'] = $a['endereco_cidade']  ?? ($reg['ci'] ?? '');
        $reg['p']  = isset($a['valor_venda']) ? (float)$a['valor_venda'] : ($reg['p'] ?? null);
        if (isset($a['quartos']) && $a['quartos'] !== null && $a['quartos'] !== '') {
            $reg['q'] = (int)$a['quartos'];
        }
        // Os campos proprietario_1/captador_1 trazem só o ID; os nomes estão
        // em *_detalhes. Pegamos APENAS o nome — telefone e e-mail ficam de fora.
        $nomesProp = [];
        foreach (($a['proprietarios_detalhes'] ?? []) as $pd) {
            if (!empty($pd['nome'])) $nomesProp[] = trim($pd['nome']);
        }
        if ($nomesProp) $reg['prop'] = implode(', ', $nomesProp);
        elseif (empty($reg['prop']) || is_numeric($reg['prop'])) $reg['prop'] = '';

        $nomesCap = [];
        foreach (($a['captadores_detalhes'] ?? []) as $cd) {
            if (!empty($cd['nome'])) $nomesCap[] = trim($cd['nome']);
        }
        if ($nomesCap) $reg['cap'] = $nomesCap;
        elseif (empty($reg['cap']) || !is_array($reg['cap'])) $reg['cap'] = [];
        // Data de entrega: a API é a fonte melhor. Vem na própria listagem
        // (mes_conclusao / ano_conclusao), está mais completa que o XLS e não
        // depende de exportação manual. mes_conclusao = 0 significa que o mês
        // não foi preenchido — nesse caso guardamos só o ano, sem inventar.
        $anoC = isset($a['ano_conclusao']) ? (int)$a['ano_conclusao'] : 0;
        $mesC = isset($a['mes_conclusao']) ? (int)$a['mes_conclusao'] : null;
        if ($anoC >= 2000 && $anoC <= 2100) {
            $reg['ea'] = $anoC;
            // ATENÇÃO: mes_conclusao conta a partir de ZERO (0 = janeiro,
            // 11 = dezembro). Confirmado de tres formas: a tela do Robust
            // mostra Dezembro para o valor 11; o campo nunca vale 12; e 11 e
            // o valor mais comum da base, coerente com entrega de obra em
            // dezembro. Ler o numero cru deixava toda entrega um mes adiantada.
            $reg['em'] = ($mesC !== null && $mesC >= 0 && $mesC <= 11) ? $mesC + 1 : null;
            $reg['ef'] = ($reg['em'] === null) ? 'API (só ano)' : 'API';
            // Mês em branco impede calcular prazo e parcelas: vira pendência.
            if ($reg['em'] === null && !in_array('mês da entrega', $reg['f'] ?? [], true)) {
                $reg['f'] = array_merge($reg['f'] ?? [], ['mês da entrega']);
                $reg['g'] = ($reg['g'] ?? 0) + 2;
            }
        }

        // 'alt' = última ALTERAÇÃO de campo | 'atu' = última ATUALIZAÇÃO (conferência)
        $reg['alt']  = $a['updated_at'] ?? ($reg['alt'] ?? null);
        $reg['upd']  = $a['updated_at'] ?? null;
        $reg['atu']  = $a['_atu'] ?? null;
        $reg['lat']  = $a['_lat'] ?? null;
        $reg['lng']  = $a['_lng'] ?? null;
        $reg['geo']  = null;   // origem da coordenada
        if ($reg['lat'] !== null) {
            $reg['geo'] = 'crm';
        } elseif (!empty($geo[(string)$id]['la'])) {
            $g = $geo[(string)$id];
            // A coordenada guardada só vale para o endereço que a gerou. Se o
            // endereço foi corrigido no CRM depois, a coordenada antiga está
            // errada e deve ser descartada — senão uma correção de cadastro
            // nunca se refletiria aqui.
            $endAtual = normalizaEndereco($reg['e'] ?? '');
            $endUsado = normalizaEndereco($g['ruaUsada'] ?? '');
            if ($endUsado === '' || $endUsado === $endAtual) {
                $reg['lat'] = $g['la'];
                $reg['lng'] = $g['lo'];
                // 'numero' = acertou o número da casa; 'rua' = caiu no meio da rua.
                $reg['geo'] = $g['q'] === 'numero' ? 'endereco' : 'rua';
            } else {
                $reg['geo'] = null;   // será refeito no próximo geocodificar.php
            }
        }
        $reg['fotos'] = $a['_fotos'] ?? [];

        // Endereço legível
        $end = trim(($a['endereco_logradouro'] ?? '') . ' ' . ($a['endereco_numero'] ?? ''));
        if ($end !== '') $reg['e'] = $end;

        // Telefone do proprietário: removido a pedido (não vai para a web).
        unset($reg['tel']);

        // Sinaliza quem ainda não tem descrição carregada por XLS.
        $reg['semDesc'] = $x ? 0 : 1;
        if (!$x) {
            $semDescricao++;
            if (empty($reg['ti'])) {
                $partes = [$reg['t'] ?: 'Imóvel'];
                if (!empty($reg['q'])) $partes[] = $reg['q'] . ' quarto' . ($reg['q'] > 1 ? 's' : '');
                $reg['ti'] = implode(', ', $partes) . ($reg['b'] ? ' no ' . $reg['b'] : '');
                $reg['tiGerado'] = 1;
            }
            // Campos que dependem da descrição ficam nulos, nunca inventados.
            foreach (['d','a','af','pt','pa','de','am','su','v','ba'] as $k) {
                if (!array_key_exists($k, $reg)) $reg[$k] = ($k === 'am') ? [] : null;
            }
            // ea/em ficam de fora da limpeza: vêm da API e valem mesmo sem XLS.
            if (!array_key_exists('ea', $reg)) $reg['ea'] = null;
            if (!array_key_exists('em', $reg)) $reg['em'] = null;
            $reg['f'] = ['descrição não carregada'];
            $reg['g'] = 3;
            $reg['s'] = strtolower(($reg['ti'] ?? '') . ' ' . $reg['b'] . ' ' . $reg['t']);
        }
        $saida[] = $reg;
    }

    // ---------- centros de bairro (para a busca por proximidade) ----------
    // O centro sai da média das coordenadas dos imóveis do próprio bairro.
    // Só vale com coordenada plausível para a região de Joinville — assim um
    // endereço geocodificado errado não desloca o bairro inteiro.
    $acc = [];
    foreach ($saida as $r) {
        $la = $r['lat'] ?? null; $lo = $r['lng'] ?? null; $b = $r['b'] ?? '';
        if ($b === '' || $la === null || $lo === null) continue;
        // O centro do bairro só usa posição vinda do CRM ou do número exato:
        // alimentar com aproximação de rua degradaria o cálculo com o tempo.
        if (!in_array($r['geo'] ?? '', ['crm', 'endereco'], true)) continue;
        if ($la < -26.9 || $la > -25.9 || $lo < -49.4 || $lo > -48.4) continue;
        if (!isset($acc[$b])) $acc[$b] = ['la' => 0, 'lo' => 0, 'n' => 0];
        $acc[$b]['la'] += $la; $acc[$b]['lo'] += $lo; $acc[$b]['n']++;
    }
    $centros = [];
    // 1) Media dos imoveis do bairro — serve so de reserva.
    foreach ($acc as $b => $v) {
        $centros[$b] = ['la' => round($v['la'] / $v['n'], 6),
                        'lo' => round($v['lo'] / $v['n'], 6),
                        'n'  => $v['n'], 'fonte' => 'imoveis'];
    }
    // 2) Coordenada oficial: sobrepoe a media onde existir e acrescenta os
    //    bairros sem nenhum imovel geocodificado. E mais confiavel: com dois
    //    ou tres imoveis a media puxa para onde eles estao (chegou a errar
    //    3,1 km no Itinga).
    foreach ($GLOBALS['BAIRRO_COORD'] as $k => $c) {
        $nome = $GLOBALS['BAIRRO_NOME'][$k] ?? $k;
        $centros[$nome] = ['la' => $c[0], 'lo' => $c[1],
                           'n' => isset($centros[$nome]) ? $centros[$nome]['n'] : 0,
                           'fonte' => 'oficial'];
    }
    $porOrigem = ['crm' => 0, 'endereco' => 0, 'rua' => 0];
    foreach ($saida as $r) if (!empty($r['geo'])) $porOrigem[$r['geo']] = ($porOrigem[$r['geo']] ?? 0) + 1;
    $oficiais = 0;
    foreach ($centros as $c) if (isset($c['fonte']) && $c['fonte'] === 'oficial') $oficiais++;
    log_sync('centros de bairro: ' . count($centros) . " (oficiais: $oficiais)"
        . ' | coordenadas: CRM ' . $porOrigem['crm']
        . ', endereço ' . $porOrigem['endereco'] . ', rua ' . $porOrigem['rua']);

    // Pontos de referência (shoppings, faculdades, terminais...) enviados
    // por planilha. Vão junto na base para a busca e os cards usarem.
    $refs = [];
    $refsMeta = null;
    if (file_exists(DATA_DIR . '/referencias.json')) {
        $rj = json_decode(@file_get_contents(DATA_DIR . '/referencias.json'), true);
        $refs = $rj['pontos'] ?? [];
        $refsMeta = $rj['gerado_em'] ?? null;
    }

    $meta = [
        'gerado_em'      => date('c'),
        'centros'        => $centros,
        'referencias'    => $refs,
        'bairros_rurais' => array_values(array_map(
            function ($k) { return isset($GLOBALS['BAIRRO_NOME'][$k]) ? $GLOBALS['BAIRRO_NOME'][$k] : $k; },
            $GLOBALS['BAIRRO_RURAL'])),
        'coordenadas'    => $porOrigem,
        'refs_gerado_em' => $refsMeta,
        'total'          => count($saida),
        'com_foto'       => $comFoto,
        'sem_descricao'  => $semDescricao,
        'xls_gerado_em'  => $xlsData,
        'fonte'          => 'API Robust + XLS',
    ];

    $tmp = ARQ_MERGE . '.tmp';
    file_put_contents($tmp, json_encode(['meta' => $meta, 'imoveis' => $saida], JSON_UNESCAPED_UNICODE));
    rename($tmp, ARQ_MERGE);   // troca atômica: a ferramenta nunca lê arquivo pela metade

    $seg = round(microtime(true) - $inicio, 1);
    log_sync("OK: {$meta['total']} imóveis, $comFoto com foto, $semDescricao sem descrição, {$seg}s");

    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'meta' => $meta], JSON_UNESCAPED_UNICODE);
    } else {
        echo "Sync OK: {$meta['total']} imóveis ({$seg}s)\n";
    }

} catch (Exception $e) {
    log_sync('ERRO: ' . $e->getMessage());
    if (php_sapi_name() !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'ERRO: ' . $e->getMessage() . "\n";
    }
}
