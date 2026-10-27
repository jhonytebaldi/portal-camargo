<?php
/* =====================================================================
   imoveis-campanha/lib/Bianca.php: o portal fala com o banco da Bianca.

   A ficha de cada imóvel mora no banco da Bianca (Supabase), porque é de lá
   que a Bianca lê na hora de responder o lead; o portal só edita. Não há
   login do portal no Supabase: ele chama SÓ as funções camargo_portal_*,
   com a chave do portal (p_chave). A chave abre essas funções e nada mais
   do banco; as tabelas ficam fechadas para quem não é a Bianca.

   A "anon key" abaixo é pública de propósito (é a mesma que vai no
   navegador do app da Bianca): sozinha não lê nenhuma tabela da Camargo.
   O segredo é a chave do portal, salva cifrada na tela de integração.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../../listas-3c/lib/Integracoes.php';   // cifrar/decifrar (mesma cifra do 3C)

final class ImcBianca
{
    public const URL_PADRAO = 'https://xtkorgedlxwfuaqyxguq.supabase.co';
    public const ANON = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6Inh0a29yZ2VkbHh3ZnVhcXl4Z3VxIiwicm9sZSI6ImFub24iLCJpYXQiOjE3MzQwMDY4NDMsImV4cCI6MjA0OTU4Mjg0M30.oMQTNvIdtIHsFgSGczo6fWEOGsOCWJkENKOzPrXrP8c';

    public function __construct(private string $chave, private string $url = self::URL_PADRAO) {}

    /** Chave salva na tela (cifrada em imc_config), depois a constante IMC_BIANCA_CHAVE do config.php. */
    public static function chaveSalva(): ?string
    {
        try {
            $st = db()->prepare("SELECT valor FROM imc_config WHERE chave = 'bianca_chave'");
            $st->execute();
            $v = $st->fetchColumn();
            if ($v !== false && $v !== '') {
                $claro = L3cIntegracoes::decifrar((string)json_decode((string)$v, true));
                if ($claro) return $claro;
            }
        } catch (Throwable $e) { /* tabela ainda não criada: segue para o config.php */ }
        portal_load_config();
        return defined('IMC_BIANCA_CHAVE') && (string)IMC_BIANCA_CHAVE !== '' ? (string)IMC_BIANCA_CHAVE : null;
    }

    public static function salvarChave(string $chave): void
    {
        db()->prepare("INSERT INTO imc_config (chave, valor) VALUES ('bianca_chave', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute([json_encode(L3cIntegracoes::cifrar($chave))]);
    }

    public static function doConfig(): self
    {
        $chave = self::chaveSalva();
        if (!$chave) throw new RuntimeException('A ligação com a Bianca ainda não foi configurada. Um admin abre Imóveis da campanha → Ligação com a Bianca.');
        portal_load_config();
        // IMC_BIANCA_URL só existe para o teste local (um Supabase falso); em produção vale o padrão.
        return new self($chave, defined('IMC_BIANCA_URL') ? (string)IMC_BIANCA_URL : self::URL_PADRAO);
    }

    /** Chama uma função camargo_portal_* e devolve o JSON dela. Erro do banco vira RuntimeException com a palavra do banco. */
    public function rpc(string $funcao, array $args = []): mixed
    {
        if (!preg_match('/^camargo_portal_[a-z_]+$/', $funcao)) throw new InvalidArgumentException('função fora do portal');
        $ch = curl_init(rtrim($this->url, '/') . '/rest/v1/rpc/' . $funcao);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['apikey: ' . self::ANON, 'Authorization: Bearer ' . self::ANON, 'Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS     => json_encode(['p_chave' => $this->chave] + $args, JSON_UNESCAPED_UNICODE),
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) throw new RuntimeException("sem resposta da Bianca ($err)");
        $j = json_decode((string)$resp, true);
        if ($code >= 300) throw new RuntimeException((string)($j['message'] ?? "HTTP $code"));
        return $j;
    }
}
