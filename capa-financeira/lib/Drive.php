<?php
/* =====================================================================
   capa-financeira/lib/Drive.php — Google Drive pela conta de serviço.
   A chave (JSON da conta de serviço) fica em cf_data_dir()/drive-sa.json,
   FORA do repositório; a pasta das capas é compartilhada com o e-mail da
   conta. Só usa a API v3 do Drive: listar, baixar e renomear.
   Toda falha vira RuntimeException com causa + o que fazer.
   ===================================================================== */
declare(strict_types=1);

final class CfDrive
{
    public const PASTA_PADRAO = '1ad7fofD_p_VVo1ZaG74G0txOBKVRv9gY';
    private const ESCOPO = 'https://www.googleapis.com/auth/drive';
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const PASTA = 'application/vnd.google-apps.folder';

    public static function arquivoChave(): string { return cf_data_dir() . '/drive-sa.json'; }

    /** chave da conta de serviço (ou null se ainda não foi enviada / está ilegível) */
    public static function chave(): ?array
    {
        $f = self::arquivoChave();
        if (!is_file($f)) return null;
        $j = json_decode((string)@file_get_contents($f), true);
        return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
    }
    public static function configurado(): bool { return self::chave() !== null; }
    public static function email(): ?string { return self::chave()['client_email'] ?? null; }
    public static function pasta(): string { $p = trim((string)cf_config('drive_pasta', '')); return $p !== '' ? $p : self::PASTA_PADRAO; }

    /** valida e grava o JSON da conta de serviço. @return string e-mail da conta */
    public static function gravarChave(string $json): string
    {
        $j = json_decode($json, true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['client_email']) || empty($j['private_key']))
            throw new RuntimeException('Este arquivo não é a chave de uma conta de serviço do Google (faltam "type": "service_account", "client_email" ou "private_key"). Envie o .json baixado em Google Cloud › Contas de serviço › portal-capas › Chaves.');
        if (!@openssl_pkey_get_private($j['private_key'])) throw new RuntimeException('A chave privada dentro do arquivo está ilegível. Gere uma chave nova no Google Cloud e envie de novo.');
        $f = self::arquivoChave();
        if (@file_put_contents($f, json_encode(['type' => 'service_account', 'client_email' => $j['client_email'], 'private_key' => $j['private_key'], 'private_key_id' => $j['private_key_id'] ?? null]), LOCK_EX) === false)
            throw new RuntimeException('Não consegui gravar a chave no servidor (pasta de dados sem permissão de escrita). Me avise com esta mensagem.');
        @chmod($f, 0600);
        @unlink(cf_data_dir() . '/drive-token.json');
        return (string)$j['client_email'];
    }

    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    /** token de acesso (guardado em arquivo até perto de vencer) */
    private static function token(): string
    {
        $ch = self::chave();
        if (!$ch) throw new RuntimeException('O acesso ao Google Drive ainda não foi configurado. Um administrador precisa enviar a chave da conta de serviço em Capa Financeira › Configurações › Google Drive.');
        $cache = cf_data_dir() . '/drive-token.json';
        $t = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
        if (is_array($t) && ($t['email'] ?? '') === $ch['client_email'] && ($t['expira'] ?? 0) > time() + 60) return (string)$t['token'];
        $agora = time();
        $cab = self::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $corpo = self::b64(json_encode(['iss' => $ch['client_email'], 'scope' => self::ESCOPO, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $agora, 'exp' => $agora + 3600]));
        if (!openssl_sign($cab . '.' . $corpo, $ass, $ch['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('Não consegui assinar o pedido com a chave da conta de serviço (chave corrompida). Envie a chave de novo em Configurações › Google Drive.');
        [$http, $resp] = self::http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $cab . '.' . $corpo . '.' . self::b64($ass)]));
        $j = json_decode($resp, true);
        if ($http !== 200 || empty($j['access_token'])) {
            $d = is_array($j) ? (($j['error_description'] ?? '') ?: ($j['error'] ?? '')) : '';
            throw new RuntimeException('O Google recusou a chave da conta de serviço (' . ($d ?: 'HTTP ' . $http) . '). A chave pode ter sido excluída ou desativada no Google Cloud: gere uma nova em Contas de serviço › portal-capas › Chaves e envie em Configurações › Google Drive.');
        }
        @file_put_contents($cache, json_encode(['email' => $ch['client_email'], 'token' => $j['access_token'], 'expira' => $agora + (int)($j['expires_in'] ?? 3600)]), LOCK_EX);
        @chmod($cache, 0600);
        return (string)$j['access_token'];
    }

    /** @return array{0:int,1:string} [http, corpo] */
    private static function http(string $metodo, string $url, array $cab = [], ?string $corpo = null, int $timeout = 20): array
    {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $cab, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true]);
        if ($corpo !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $corpo);
        $r = curl_exec($c); $http = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
        if ($r === false) throw new RuntimeException('Sem resposta do Google Drive (' . $err . '). Costuma ser momentâneo: tente de novo em um minuto.');
        return [$http, (string)$r];
    }

    /** chamada autenticada à API do Drive; devolve o corpo (JSON decodificado, ou bruto quando $bruto) */
    private static function api(string $metodo, string $caminho, array $query = [], ?array $json = null, bool $bruto = false): mixed
    {
        $url = 'https://www.googleapis.com/drive/v3/' . $caminho . ($query ? '?' . http_build_query($query) : '');
        $cab = ['Authorization: Bearer ' . self::token()];
        if ($json !== null) $cab[] = 'Content-Type: application/json';
        [$http, $r] = self::http($metodo, $url, $cab, $json !== null ? json_encode($json, JSON_UNESCAPED_UNICODE) : null, $bruto ? 40 : 20);
        if ($http >= 200 && $http < 300) return $bruto ? $r : (json_decode($r, true) ?? []);
        $j = json_decode($r, true); $m = (string)($j['error']['message'] ?? '');
        $email = (string)self::email();
        if ($http === 404) throw new RuntimeException('O Drive não encontrou a pasta/arquivo (ou a conta do portal não tem acesso). Confira se a pasta das capas está compartilhada com ' . $email . ' e se o arquivo não foi movido ou excluído.');
        if ($http === 403 && stripos($m, 'has not been used') !== false) throw new RuntimeException('A API do Google Drive não está ativada no projeto do Google Cloud. Ative "Google Drive API" no projeto Portal Camargo e tente de novo em alguns minutos.');
        if ($http === 403) throw new RuntimeException('O Drive negou o acesso (' . $m . '). Confira se a pasta está compartilhada com ' . $email . ($metodo !== 'GET' ? ' como EDITOR (para renomear é preciso ser editor)' : '') . '.');
        if ($http === 401) { @unlink(cf_data_dir() . '/drive-token.json'); throw new RuntimeException('O acesso ao Drive expirou no meio da operação. Tente de novo.'); }
        if ($http === 429 || $http >= 500) throw new RuntimeException('O Google Drive está indisponível ou limitando as chamadas agora (HTTP ' . $http . '). Aguarde um minuto e tente de novo.');
        throw new RuntimeException('Erro do Google Drive (HTTP ' . $http . ($m !== '' ? ': ' . $m : '') . '). Tente de novo; se persistir, me avise com esta mensagem.');
    }

    /** todos os .xlsx da pasta e das subpastas (até 2 níveis), com o caminho da subpasta.
        @return array{pasta:string,arquivos:list<array{id:string,nome:string,subpasta:string,modificado:string,md5:string,tamanho:int}>} */
    public static function listar(): array
    {
        $raiz = self::pasta();
        $info = self::api('GET', 'files/' . rawurlencode($raiz), ['fields' => 'id,name', 'supportsAllDrives' => 'true']);
        $pais = [$raiz => '']; $arquivos = []; $fila = [$raiz]; $nivel = 0;
        while ($fila && $nivel < 3) {
            $prox = [];
            foreach (array_chunk($fila, 25) as $grupo) {
                $q = '(' . implode(' or ', array_map(fn($p) => "'" . $p . "' in parents", $grupo)) . ') and trashed = false';
                $tok = null;
                do {
                    $r = self::api('GET', 'files', array_filter(['q' => $q, 'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,md5Checksum,size,parents)', 'pageSize' => 1000,
                        'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'pageToken' => $tok]));
                    foreach ($r['files'] ?? [] as $f) {
                        $pai = $f['parents'][0] ?? $raiz; $sub = $pais[$pai] ?? '';
                        if ($f['mimeType'] === self::PASTA) { $pais[$f['id']] = ($sub !== '' ? $sub . ' / ' : '') . $f['name']; $prox[] = $f['id']; }
                        elseif ($f['mimeType'] === self::XLSX || preg_match('/\.xlsx$/i', (string)$f['name']))
                            $arquivos[] = ['id' => $f['id'], 'nome' => (string)$f['name'], 'subpasta' => $sub, 'modificado' => (string)($f['modifiedTime'] ?? ''), 'md5' => (string)($f['md5Checksum'] ?? ''), 'tamanho' => (int)($f['size'] ?? 0)];
                    }
                    $tok = $r['nextPageToken'] ?? null;
                } while ($tok);
            }
            $fila = $prox; $nivel++;
        }
        usort($arquivos, fn($a, $b) => [$a['subpasta'], mb_strtolower($a['nome'])] <=> [$b['subpasta'], mb_strtolower($b['nome'])]);
        return ['pasta' => (string)($info['name'] ?? ''), 'arquivos' => $arquivos];
    }

    /** nome, md5 e pasta-mãe de um arquivo */
    public static function meta(string $id): array
    {
        return self::api('GET', 'files/' . rawurlencode($id), ['fields' => 'id,name,mimeType,md5Checksum,size,modifiedTime,trashed', 'supportsAllDrives' => 'true']);
    }

    /** baixa o conteúdo do arquivo para $destino */
    public static function baixar(string $id, string $destino): void
    {
        $bin = self::api('GET', 'files/' . rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true'], null, true);
        if (strlen($bin) < 100 || !str_starts_with($bin, 'PK')) throw new RuntimeException('O arquivo baixado do Drive não é um .xlsx válido (é uma planilha do Google ou está vazio?). Baixe-o como .xlsx e suba na pasta, ou envie pelo campo de arquivo.');
        if (@file_put_contents($destino, $bin) === false) throw new RuntimeException('Não consegui gravar o arquivo no servidor. Tente de novo; se persistir, me avise.');
    }

    public static function renomear(string $id, string $nome): void
    {
        self::api('PATCH', 'files/' . rawurlencode($id), ['supportsAllDrives' => 'true', 'fields' => 'id,name'], ['name' => $nome]);
    }

    /** nome sem o prefixo "ok - " e sem extensão, normalizado para comparar */
    public static function nomeBase(string $nome): string
    {
        $n = preg_replace('/\.xlsx$/i', '', trim($nome)) ?? $nome;
        $n = preg_replace('/^\s*ok\b[\s\-–_:]*/iu', '', $n) ?? $n;
        return CapaParser::key($n);
    }
    /* ---- ficha de cada arquivo: o cabeçalho da capa (COD, clientes, construtora, bairro, unidade, data da venda) lido uma vez e
            guardado por id + md5 — é o que permite filtrar a lista do Drive por construtora, COD etc. sem baixar tudo a cada vez ---- */
    public const FICHA_V = 2;   // muda quando a ficha ganha campo novo: as antigas são relidas
    private static function arqFichas(): string { return cf_data_dir() . '/drive-fichas.json'; }
    public static function fichas(): array
    {
        $f = self::arqFichas();
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($j) ? $j : [];
    }
    /** grava as fichas novas por cima do que estiver no arquivo (outra leitura pode ter gravado no meio) e tira as de arquivos que saíram da pasta */
    public static function gravarFichas(array $novas, array $idsVivos): void
    {
        $t = self::fichas();
        foreach ($novas as $id => $f) $t[$id] = $f;
        $t = array_intersect_key($t, array_flip($idsVivos));
        @file_put_contents(self::arqFichas(), json_encode($t, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    /** baixa o arquivo e lê o cabeçalho da capa. Arquivo que não é capa vira ficha com 'erro' (não tenta de novo até o arquivo mudar). */
    public static function lerFicha(string $id, string $md5): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cfd');
        try {
            self::baixar($id, $tmp);
            $r = cf_parser()->parse($tmp); $c = $r['capa'];
            // vencimentos das linhas (únicos, por tipo): servem aos filtros "com contas em tal período" da lista do Drive
            $dp = []; $dr = [];
            foreach ($r['linhas'] ?? [] as $ln) { $d = (string)($ln['data'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue; if (($ln['tipo'] ?? '') === 'PAGAR') $dp[$d] = 1; else $dr[$d] = 1; }
            ksort($dp); ksort($dr);
            return ['md5' => $md5, 'v' => self::FICHA_V, 'dp' => array_keys($dp), 'dr' => array_keys($dr), 'cod' => $c['cod'] !== null ? (string)$c['cod'] : '', 'cliente' => (string)($c['cliente'] ?? ''), 'construtora' => (string)($c['construtora'] ?? ''),
                    'bairro' => (string)($c['bairro'] ?? ''), 'unidade' => (string)($c['unidade'] ?? ''), 'venda' => (string)($c['data_venda'] ?? ''), 'linhas' => count($r['linhas'] ?? [])];
        } catch (Throwable $e) {
            return ['md5' => $md5, 'v' => self::FICHA_V, 'erro' => mb_substr($e->getMessage(), 0, 160)];
        } finally { @unlink($tmp); }
    }

    /** o que o NOME do arquivo diz sobre a venda: chave dos clientes (sem "ok", "aditivo", "cópia de"…), se é aditivo (e qual nº),
        e se o próprio nome manda não subir. @return array{chave:string,aditivo:bool,n:int,nao_subir:bool,distrato:bool} */
    public static function venda(string $nome): array
    {
        $k = CapaParser::key(preg_replace('/\.xlsx$/i', '', trim($nome)) ?? $nome);
        $naoSubir = (bool)preg_match('/\bNAO SUBIR\b/', $k);
        $distrato = (bool)preg_match('/\bDISTRAT/', $k);
        $incompleto = (bool)preg_match('/\bINCOMPLET/', $k);
        $adit = (bool)preg_match('/\b(?:ADITIVO|ADITVO|ADTVO|ADTIVO|ADIT)\b\s*(\d{1,2})?/', $k, $m);
        $n = $adit ? max(1, (int)($m[1] ?? 1)) : 0;
        $k = preg_replace('/\bNAO SUBIR( NO OMIE)?\b|\bDISTRAT\w*|\b(?:ADITIVO|ADITVO|ADTVO|ADTIVO|ADIT)\b\s*\d{0,2}|\bCOPIA DE\b|\bATUALIZAD[OA]\b|\bMANTER\b|\bINCOMPLET[OA]\b|\bXLSX\b|\(\d+\)/', ' ', $k) ?? $k;
        $k = preg_replace('/^[\s\-–_:,.]*OK\b/', ' ', $k) ?? $k;                      // "ok - ", "OK "
        $k = preg_replace('/^[\s\-–_:,.]*[A-Z]{2,4}\s+-\s+/', ' ', $k) ?? $k;          // iniciais de quem lançou: "ISA - ", "TAI - "
        $k = trim(preg_replace('/\s+/', ' ', preg_replace('/[\-–_:,.]+/', ' ', $k) ?? $k) ?? $k);
        return ['chave' => $k, 'aditivo' => $adit, 'n' => $n, 'nao_subir' => $naoSubir, 'distrato' => $distrato, 'incompleto' => $incompleto];
    }
    /** duas chaves de nome de arquivo são da mesma venda? Critério estreito de propósito (um falso positivo trava um arquivo válido):
        nomes iguais, com diferença de digitação, ou a lista de compradores de um contida exatamente na do outro. */
    public static function mesmaVenda(string $a, string $b): bool
    {
        if ($a === '' || $b === '') return false;
        if ($a === $b || CapaParser::grafiaParecida($a, $b)) return true;
        $p = fn(string $s) => array_values(array_filter(array_map('trim', preg_split('/\s+E\s+/', $s) ?: [])));
        $pa = $p($a); $pb = $p($b);
        [$men, $mai] = count($pa) <= count($pb) ? [$pa, $pb] : [$pb, $pa];
        if (!$men || count($men) === count($mai)) return false;
        foreach ($men as $x) if (count(explode(' ', $x)) < 3 || !in_array($x, $mai, true)) return false;
        return true;
    }
    public static function temOk(string $nome): bool { return (bool)preg_match('/^\s*ok\b/iu', $nome); }
}
