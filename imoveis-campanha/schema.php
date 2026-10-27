<?php
/* =====================================================================
   imoveis-campanha/schema.php: registra a ferramenta "Imóveis da campanha".
   Idempotente, no molde do listas-3c/schema.php: roda sozinho na primeira
   visita de um admin à home ou ao módulo, sem admin/migrar.php.

   No MySQL do portal só fica a configuração (a chave da ligação com a
   Bianca, cifrada). A ficha, o "pode falar" de cada campo, as respostas e a
   prévia moram no banco da Bianca, que é de onde ela lê na hora de
   responder: duas cópias da ficha seriam duas verdades.
   ===================================================================== */
declare(strict_types=1);

const IMC_SCHEMA_VERSAO = 1;

function imc_instalar_se_preciso(): bool
{
    if (PHP_SAPI !== 'cli') {
        if (!is_admin()) return false;
        if (($_SESSION['imc_schema'] ?? 0) === IMC_SCHEMA_VERSAO) return false;
    }
    try {
        $feitos = imc_migrar(db());
    } catch (Throwable $e) {
        error_log('imoveis-campanha: instalação automática falhou: ' . $e->getMessage());
        return false;
    }
    if (PHP_SAPI !== 'cli') $_SESSION['imc_schema'] = IMC_SCHEMA_VERSAO;
    return (bool)$feitos;
}

function imc_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'imc_config'")->fetchColumn() > 0;
    if (!$tem) {
        $pdo->exec("CREATE TABLE imc_config (
            chave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor JSON NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela imc_config';
    }
    // Nasce só para admin; o admin libera para cada corretor em Admin → Usuários.
    // Corretor (usuário com broker_id) vê e edita só os imóveis dele.
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='imoveis-campanha'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem) VALUES ('imoveis-campanha','Imóveis da campanha','O que a Bianca pode falar de cada imóvel do anúncio, e o que ela falou','🏷️','/imoveis-campanha/',1,36)")->execute();
        $feitos[] = 'tool:imoveis-campanha';
    }
    return $feitos;
}
