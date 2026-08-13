-- =====================================================================
-- Dados iniciais do portal.
-- 1) Registra as ferramentas (módulos).
-- 2) Cria um usuário admin inicial.
--
-- A SENHA do admin NÃO fica aqui. Rode primeiro `gerar-hash.php` (no
-- servidor) para gerar o hash da senha que você quer e cole no lugar de
-- COLE_O_HASH_AQUI antes de importar este arquivo. Apague o gerar-hash.php
-- depois. (Mesmo padrão da busca.)
-- =====================================================================
SET NAMES utf8mb4;

INSERT INTO tools (slug, nome, descricao, icone, caminho, ativo, ordem) VALUES
  ('busca',             'Busca de Imóveis',      'Busca e auditoria do acervo (Robust CRM)', '🏠', '/busca/', 1, 10),
  ('painel-corretores', 'Painel dos Corretores', 'Presença aproximada da equipe (GHL)',      '📊', '/painel-corretores/', 1, 20),
  ('funil',             'Funil diário',          'Funil de vendas da Camargo',               '📈', '/funil/', 0, 30),
  ('conciliador',       'Conciliador',           'Conciliação (em breve)',                   '🧮', '/conciliador/', 0, 40)
ON DUPLICATE KEY UPDATE nome=VALUES(nome), descricao=VALUES(descricao), icone=VALUES(icone), caminho=VALUES(caminho);

-- Admin inicial (troque o nome/login e cole o hash da senha):
INSERT INTO users (nome, login, senha_hash, papel, ativo) VALUES
  ('Jhony Tebaldi', 'jhony', '$2y$10$COLE_O_HASH_AQUI', 'admin', 1)
ON DUPLICATE KEY UPDATE papel='admin', ativo=1;

-- Admin enxerga tudo automaticamente (ver lib/auth.php), então não é
-- necessário popular user_tools/user_teams para ele.
