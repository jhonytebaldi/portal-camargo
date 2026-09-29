<?php
/* =====================================================================
   listas-3c/schema.php: tabelas da ferramenta "Listas 3C".
   Chamado por admin/migrar.php (idempotente), como os outros módulos.

   A lista é montada em LOTES (a Hostinger corta a requisição web em ~60 s
   e uma lista antiga pode pedir dezenas de páginas ao Robust). Então tudo
   o que o montador sabe fica no banco: a lista guarda em que passo e em
   que página parou (cursor), e cada contato é uma linha em l3c_itens.
   Qualquer requisição curta ou o cron continua de onde a anterior parou.
   ===================================================================== */
declare(strict_types=1);

function l3c_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;
    if (!$tem('l3c_listas')) {
        $pdo->exec("CREATE TABLE l3c_listas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            modelo VARCHAR(40) NOT NULL,
            periodo_de DATE NOT NULL,
            periodo_ate DATE NOT NULL,
            nome VARCHAR(160) NOT NULL,
            parametros JSON NOT NULL,                   -- foto do modelo no momento da criação
            status ENUM('montando','pronta','enviando','enviada','erro','cancelada') NOT NULL DEFAULT 'montando',
            cursor_json JSON NOT NULL,                  -- passo/página em que o montador parou
            progresso DECIMAL(5,2) NOT NULL DEFAULT 0,
            progresso_txt VARCHAR(255) NOT NULL DEFAULT '',
            chamadas_robust INT UNSIGNED NOT NULL DEFAULT 0,
            campanha_id INT UNSIGNED NULL,
            campanha_nome VARCHAR(160) NULL,
            tresc_lista_id INT UNSIGNED NULL,           -- gravado ANTES de subir o mailing (3C não deduplica)
            enviados INT UNSIGNED NOT NULL DEFAULT 0,
            importados INT UNSIGNED NOT NULL DEFAULT 0,
            erro TEXT NULL,
            criado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            aprovado_por INT UNSIGNED NULL,
            aprovado_em DATETIME NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY ix_l3c_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE l3c_itens (
            lista_id INT UNSIGNED NOT NULL,
            atendimento_id INT UNSIGNED NOT NULL,
            cliente_id INT UNSIGNED NULL,
            lead_id INT UNSIGNED NULL,
            stage TINYINT NOT NULL DEFAULT 0,
            origin VARCHAR(120) NOT NULL DEFAULT '',
            atendente VARCHAR(120) NOT NULL DEFAULT '',
            criado_em VARCHAR(40) NULL,
            encerrado_em VARCHAR(40) NULL,
            motivo VARCHAR(190) NULL,
            motivo_em VARCHAR(40) NULL,
            agenda_em VARCHAR(40) NULL,
            obs TEXT NULL,
            nome_bruto VARCHAR(190) NULL,
            email_bruto VARCHAR(190) NULL,
            telefones VARCHAR(190) NULL,
            pessoa_ok TINYINT(1) NOT NULL DEFAULT 0,
            nome VARCHAR(160) NOT NULL DEFAULT '',
            email VARCHAR(190) NOT NULL DEFAULT '',
            telefone VARCHAR(20) NOT NULL DEFAULT '',
            canal VARCHAR(120) NOT NULL DEFAULT '',
            resumo VARCHAR(400) NOT NULL DEFAULT '',
            descarte VARCHAR(80) NULL,                  -- NULL = entra na lista
            enviado TINYINT NOT NULL DEFAULT 0,         -- 0 não, 2 em voo, 1 enviado
            PRIMARY KEY (lista_id, atendimento_id),
            KEY ix_l3ci_cliente (lista_id, cliente_id),
            KEY ix_l3ci_desc (lista_id, descarte),
            CONSTRAINT fk_l3ci_lista FOREIGN KEY (lista_id) REFERENCES l3c_listas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE l3c_config (
            chave VARCHAR(60) NOT NULL PRIMARY KEY,
            valor JSON NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas l3c_listas/l3c_itens/l3c_config';
    }
    // Registrar a ferramenta é o que faz o botão aparecer na home para quem
    // tiver acesso (a home lista a tabela tools). Nasce só para admin: o
    // admin libera para o Guilherme em Admin → Usuários.
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='listas-3c'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem) VALUES ('listas-3c','Listas 3C','Monta a lista do Robust, trata, aprova e sobe no 3C','📞','/listas-3c/',1,35)")->execute();
        $feitos[] = 'tool:listas-3c';
    }
    return $feitos;
}
