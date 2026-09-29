<?php
/* =====================================================================
   listas-3c/lib/Integracoes.php: de onde vêm as chaves do Robust e do 3C.

   Por que existe: o Jhony não é técnico. Pedir para ele editar o
   config.php por FTP/Gerenciador de Arquivos é um passo que ele não vai
   conseguir dar. Então as chaves passam a ser coladas numa tela do próprio
   módulo ("Configurar integrações", só admin) e guardadas no MySQL.

   Ordem de procura de cada valor (o primeiro que existir vence):
     1. o que foi salvo na tela (l3c_config 'integracoes', cifrado);
     2. a constante no ~/portal-config/config.php (continua valendo, para
        quem prefere o jeito antigo);
     3. só para o Robust: a chave que a Busca de Imóveis JÁ usa
        (busca/credenciais.php no servidor). Assim ninguém precisa pedir a
        chave do Robust de novo: se a Busca sincroniza, o módulo também lê.

   CIFRA (e o risco, dito com clareza):
     A chave de cifra é derivada (HKDF-SHA256) da senha do banco que já
     está no config.php (DB_PASS + DB_USER + DB_NAME). Não inventamos um
     arquivo de chave novo porque criar arquivo fora da web exigiria alguém
     com acesso ao servidor, que é justamente o passo que queremos tirar.
     O que isso protege: um dump/backup do banco (exportação do phpMyAdmin,
     cópia de segurança da Hostinger) não traz as chaves legíveis.
     O que NÃO protege: quem lê o config.php já lê a senha do banco e,
     portanto, consegue decifrar. É o mesmo nível de proteção que o
     config.php já dá ao DB_PASS e ao GHL_TOKEN hoje.
     Efeito colateral: se a senha do banco for trocada, as chaves salvas
     deixam de abrir. A tela avisa e pede para colar de novo (nada quebra
     em silêncio). Quem quiser uma chave independente define
     L3C_CHAVE_CIFRA no config.php.
   ===================================================================== */
declare(strict_types=1);

final class L3cIntegracoes
{
    public const TRESC_BASE_PADRAO = 'https://camargogestao.3c.plus/api/v1';

    /** campo => [constante do config.php, é segredo?] */
    public const CAMPOS = [
        'robust_nickname'      => ['ROBUST_NICKNAME', false],
        'robust_api_key'       => ['ROBUST_API_KEY', true],
        'tresc_base_url'       => ['TRESC_BASE_URL', false],
        'tresc_api_token'      => ['TRESC_API_TOKEN', true],
        'campanhas_permitidas' => ['L3C_CAMPANHAS_PERMITIDAS', false],
    ];

    /** Valores que vieram "de fábrica" nos modelos e não são chave de verdade. */
    private const PLACEHOLDERS = ['', 'COLE_A_CHAVE_AQUI', 'COLE_O_NICKNAME_AQUI', '...'];

    private static ?array $salvasCache = null;

    /* ---------------- cifra ---------------- */

    private static function chave(): string
    {
        portal_load_config();
        if (defined('L3C_CHAVE_CIFRA') && (string)L3C_CHAVE_CIFRA !== '') {
            $base = (string)L3C_CHAVE_CIFRA;
        } else {
            $base = (defined('DB_PASS') ? (string)DB_PASS : '') . "\0"
                  . (defined('DB_USER') ? (string)DB_USER : '') . "\0"
                  . (defined('DB_NAME') ? (string)DB_NAME : '');
        }
        // O "info" amarra a chave a este uso: a mesma senha do banco nunca
        // vira a mesma chave em outro módulo que copiar esta ideia.
        return hash_hkdf('sha256', $base, 32, 'portal-camargo/listas-3c/integracoes/v1');
    }

    public static function cifrar(string $claro): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($claro, 'aes-256-gcm', self::chave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) throw new RuntimeException('Falha ao cifrar (openssl).');
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    /** null = não abriu (senha do banco mudou ou dado corrompido). */
    public static function decifrar(string $cifrado): ?string
    {
        if (strncmp($cifrado, 'v1:', 3) !== 0) return null;
        $b = base64_decode(substr($cifrado, 3), true);
        if ($b === false || strlen($b) < 29) return null;
        // GCM confere a etiqueta: chave errada devolve false, nunca lixo.
        $claro = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::chave(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $claro === false ? null : $claro;
    }

    /* ---------------- leitura ---------------- */

    /** O que está salvo no banco (sem decifrar). */
    public static function salvas(): array
    {
        if (self::$salvasCache !== null) return self::$salvasCache;
        try {
            $st = db()->prepare("SELECT valor FROM l3c_config WHERE chave = 'integracoes'");
            $st->execute();
            $v = $st->fetchColumn();
            return self::$salvasCache = ($v === false ? [] : (array)json_decode((string)$v, true));
        } catch (Throwable $e) {
            // Tabela ainda não criada (antes do primeiro acesso de um admin):
            // segue para o config.php, sem derrubar a página.
            return self::$salvasCache = [];
        }
    }

    /**
     * Chave do Robust que a Busca de Imóveis já usa. Lida como TEXTO (sem
     * executar o arquivo): o credenciais.php da Busca define também
     * SENHA_HASH/GITHUB_* e incluí-lo aqui poderia colidir com constantes
     * do portal. Só as duas linhas do Robust interessam.
     */
    public static function daBusca(): array
    {
        $arq = dirname(__DIR__, 2) . '/busca/credenciais.php';
        if (!is_readable($arq)) return [];
        $txt = (string)file_get_contents($arq);
        $out = [];
        foreach (['ROBUST_NICKNAME' => 'robust_nickname', 'ROBUST_API_KEY' => 'robust_api_key'] as $const => $campo) {
            if (preg_match("/define\\(\\s*['\"]" . $const . "['\"]\\s*,\\s*'([^']*)'\\s*\\)/", $txt, $m)
                || preg_match("/define\\(\\s*['\"]" . $const . "['\"]\\s*,\\s*\"([^\"]*)\"\\s*\\)/", $txt, $m)) {
                if (!in_array($m[1], self::PLACEHOLDERS, true)) $out[$campo] = $m[1];
            }
        }
        return $out;
    }

    /**
     * Valor efetivo de um campo e de onde ele veio.
     * @return array{valor: mixed, origem: ?string, problema: ?string}
     *   origem: 'tela' | 'config' | 'busca' | null
     */
    public static function valor(string $campo): array
    {
        [$const, $segredo] = self::CAMPOS[$campo];
        $problema = null;
        $s = self::salvas();
        if (array_key_exists($campo, $s)) {
            $v = $s[$campo];
            if ($segredo) {
                $v = self::decifrar((string)$v);
                if ($v === null) $problema = 'A chave salva na tela não abriu mais (a senha do banco mudou?). Cole de novo.';
            }
            if ($v !== null && $v !== '' && $v !== []) return ['valor' => $v, 'origem' => 'tela', 'problema' => null];
        }
        portal_load_config();
        if (defined($const)) {
            $v = constant($const);
            if (!in_array($v, self::PLACEHOLDERS, true) && $v !== []) return ['valor' => $v, 'origem' => 'config', 'problema' => $problema];
        }
        if (str_starts_with($campo, 'robust_')) {
            $b = self::daBusca();
            if (isset($b[$campo])) return ['valor' => $b[$campo], 'origem' => 'busca', 'problema' => $problema];
        }
        if ($campo === 'tresc_base_url') return ['valor' => self::TRESC_BASE_PADRAO, 'origem' => null, 'problema' => null];
        return ['valor' => null, 'origem' => null, 'problema' => $problema];
    }

    /* ---------------- gravação (só a tela de admin chama) ---------------- */

    /**
     * $novos: só os campos que a pessoa preencheu. Segredo vazio = manter o
     * atual (a tela nunca devolve a chave ao navegador). $limpar: campos a
     * apagar do banco (volta a valer o config.php / a Busca).
     */
    public static function salvar(array $novos, array $limpar = []): void
    {
        $s = self::salvas();
        foreach ($limpar as $c) unset($s[$c]);
        foreach ($novos as $c => $v) {
            if (!isset(self::CAMPOS[$c])) continue;
            $s[$c] = self::CAMPOS[$c][1] ? self::cifrar((string)$v) : $v;
        }
        db()->prepare("INSERT INTO l3c_config (chave, valor) VALUES ('integracoes', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([json_encode($s, JSON_UNESCAPED_UNICODE)]);
        self::$salvasCache = null;
    }

    /** "••••1a2b": o bastante para reconhecer qual chave está lá, sem expô-la. */
    public static function mascara(?string $v): string
    {
        if ($v === null || $v === '') return '';
        return '••••' . substr($v, -4);
    }
}
