<?php
/* =====================================================================
   listas-3c/lib/Apis.php: clientes HTTP do Robust (leitura) e do 3C.

   Credenciais: ver lib/Integracoes.php (tela "Configurar integrações",
   depois config.php, depois a chave que a Busca já usa, no caso do Robust).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Integracoes.php';

final class L3cRobust
{
    private const BASE = 'https://api.robustcrm.io/v1';
    public int $chamadas = 0;

    public function __construct(private string $nick, private string $chave) {}

    public static function doConfig(): self
    {
        // A mensagem fala com quem está na tela (não com quem mexe no
        // servidor): o caminho de conserto agora é a tela de integrações.
        $nick = L3cIntegracoes::valor('robust_nickname');
        $chave = L3cIntegracoes::valor('robust_api_key');
        if (!$chave['valor'] || !$nick['valor']) {
            throw new RuntimeException($chave['problema'] ?? 'A chave do Robust ainda não foi cadastrada. Um admin abre Listas 3C → Configurar integrações.');
        }
        return new self((string)$nick['valor'], (string)$chave['valor']);
    }

    /**
     * GET com repetição. Medido em 28/09/2026 com pouca carga: 735 GETs
     * entre 1 e 8 por segundo (incluindo 70 s seguidos a 6/s) e nenhum 429.
     * Mesmo assim o módulo espera 150 ms entre chamadas (no máximo ~6/s),
     * dentro do que foi medido, e trata 429 esperando o Retry-After.
     */
    public function get(string $caminho, array $q = []): array
    {
        $url = self::BASE . $caminho . ($q ? '?' . http_build_query($q) : '');
        $ultimo = '';
        for ($i = 1; $i <= 3; $i++) {
            usleep(150000);
            $this->chamadas++;
            $ch = curl_init($url);
            $retryAfter = 0;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 40,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => ['X-Nickname: ' . $this->nick, 'X-API-Key: ' . $this->chave, 'Accept: application/json'],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                    if (stripos($h, 'retry-after:') === 0) $retryAfter = (int)trim(substr($h, 12));
                    return strlen($h);
                },
            ]);
            $resp = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($resp !== false && $code === 200) {
                $j = json_decode((string)$resp, true);
                if (is_array($j)) return $j;
                $ultimo = "JSON inválido em $caminho";
            } elseif ($resp === false) {
                $ultimo = "falha de rede em $caminho: $err";
            } else {
                $ultimo = "HTTP $code em $caminho";
                // Credencial errada ou filtro inválido: repetir não resolve.
                if (in_array($code, [400, 401, 403, 404], true)) throw new RuntimeException($ultimo);
                if ($code === 429) { sleep(max(2, min(30, $retryAfter ?: 5 * $i))); continue; }
            }
            if ($i < 3) sleep($i * 2);
        }
        throw new RuntimeException($ultimo . ' (após 3 tentativas)');
    }
}

final class L3cTresC
{
    public function __construct(private string $base, private string $token) {}

    public static function doConfig(): self
    {
        $tok = L3cIntegracoes::valor('tresc_api_token');
        if (!$tok['valor']) {
            throw new RuntimeException($tok['problema'] ?? 'O token do 3C ainda não foi cadastrado. Um admin abre Listas 3C → Configurar integrações.');
        }
        $base = (string)L3cIntegracoes::valor('tresc_base_url')['valor'];
        return new self(rtrim($base, '/'), (string)$tok['valor']);
    }

    /**
     * Campanhas que este portal pode usar. Se a tela de integrações (ou o
     * config.php, L3C_CAMPANHAS_PERMITIDAS) tiver uma lista de ids, só elas:
     * é a trava que deixa testar na campanha de teste sem risco de cair
     * numa real. Vazio = todas as campanhas do 3C.
     */
    public static function permitidas(): ?array
    {
        $v = L3cIntegracoes::valor('campanhas_permitidas')['valor'];
        return $v ? array_values(array_map('intval', (array)$v)) : null;
    }

    public static function exigePermitida(int $campanhaId): void
    {
        $p = self::permitidas();
        if ($p !== null && !in_array($campanhaId, $p, true)) {
            throw new RuntimeException("Campanha $campanhaId não está entre as campanhas permitidas (Configurar integrações).");
        }
    }

    /** [id => nome] das campanhas do 3C (filtradas pela trava, se houver). */
    public function campanhas(): array
    {
        $out = $this->todasCampanhas();
        $p = self::permitidas();
        if ($p !== null) $out = array_intersect_key($out, array_flip($p));
        return $out;
    }

    /** Todas as campanhas do 3C, sem a trava: a tela de integrações mostra
     *  a lista inteira para o admin escolher quais liberar. */
    public function todasCampanhas(): array
    {
        $j = $this->req('GET', '/campaigns', ['per_page' => 100]);
        $out = [];
        foreach (($j['data'] ?? []) as $c) $out[(int)$c['id']] = (string)$c['name'];
        return $out;
    }

    /**
     * Cria a lista na campanha. O 3C NÃO deduplica por nome (duas chamadas
     * iguais = duas listas), então quem chama grava o id na hora e nunca
     * chama de novo para a mesma lista do portal.
     * A API v1 quer form-encoded aqui; JSON devolve 422 "name obrigatório".
     */
    public function criarLista(int $campanhaId, string $nome): int
    {
        self::exigePermitida($campanhaId);
        $j = $this->req('POST', "/campaigns/$campanhaId/lists", [], ['name' => $nome], false);
        $id = $j['data']['id'] ?? null;
        if (!$id) throw new RuntimeException('3C não devolveu o id da lista: ' . substr(json_encode($j), 0, 300));
        return (int)$id;
    }

    /**
     * Sobe contatos. Atenção: HTTP 200 não quer dizer que entrou; os filtros
     * do gestor da campanha (Não Perturbe, bloqueio, filtro inteligente)
     * descartam na importação e o 3C responde imported_lines menor. Por
     * isso o módulo guarda e mostra o imported_lines, não o status.
     */
    public function subirMailing(int $campanhaId, int $listaId, array $header, array $linhas): int
    {
        self::exigePermitida($campanhaId);
        $j = $this->req('POST', "/campaigns/$campanhaId/lists/$listaId/mailing", [], ['header' => $header, 'mailing' => $linhas], true);
        return (int)($j['imported_lines'] ?? 0);
    }

    private function req(string $metodo, string $caminho, array $q = [], ?array $corpo = null, bool $json = false): array
    {
        $q['api_token'] = $this->token;
        $ch = curl_init($this->base . $caminho . '?' . http_build_query($q));
        $h = ['Accept: application/json'];
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_CUSTOMREQUEST => $metodo];
        if ($corpo !== null) {
            if ($json) { $h[] = 'Content-Type: application/json'; $opts[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE); }
            else { $h[] = 'Content-Type: application/x-www-form-urlencoded'; $opts[CURLOPT_POSTFIELDS] = http_build_query($corpo); }
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("3C: falha de rede em $caminho: $err");
        $j = json_decode((string)$resp, true);
        if ($code < 200 || $code >= 300) throw new RuntimeException("3C respondeu $code em $caminho: " . substr((string)$resp, 0, 300));
        return is_array($j) ? $j : [];
    }
}
