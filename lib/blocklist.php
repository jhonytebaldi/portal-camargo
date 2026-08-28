<?php
/* =====================================================================
   lib/blocklist.php — Lista de Bloqueio do portal (a nível geral).

   Números de telefone cadastrados aqui são IGNORADOS nas análises das
   ferramentas que o admin marcar (tools.usa_blocklist = 1). Serve para
   tirar das métricas conversas internas entre corretores, conversas
   pessoais, testes, etc.

   Qualquer ferramenta usa assim:
     require_once __DIR__ . '/../lib/blocklist.php';
     if (blocklist_ativa('painel-corretores')) {
         $set = blocklist_set();
         if (fone_bloqueado($telefoneDaConversa, $set)) { ...ignora... }
     }
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

/** Forma canônica de um telefone p/ comparação robusta (BR, Joinville-friendly):
 *  só dígitos, sem +55, e sem o 9º dígito de celular → DDD + 8 dígitos. */
function fone_canon(string $s): string {
    $d = preg_replace('/\D+/', '', $s) ?? '';
    if ($d === '') return '';
    if (strlen($d) > 11 && strncmp($d, '55', 2) === 0) $d = substr($d, 2);  // tira código do país
    if (strlen($d) === 11 && ($d[2] ?? '') === '9') $d = substr($d, 0, 2) . substr($d, 3); // tira 9º dígito
    if (strlen($d) > 10) $d = substr($d, -10);
    return $d;
}

/** Conjunto (canon => 1) de todos os números bloqueados. Em cache no request. */
function blocklist_set(): array {
    static $set = null;
    if ($set !== null) return $set;
    $set = [];
    try {
        foreach (db()->query('SELECT phone_canon FROM blocklist')->fetchAll(PDO::FETCH_COLUMN) as $c) {
            if ($c !== '' && $c !== null) $set[(string)$c] = 1;
        }
    } catch (Throwable $e) { /* tabela ainda não existe: lista vazia */ }
    return $set;
}

/** Este telefone está bloqueado? (passe o $set de blocklist_set() p/ reuso). */
function fone_bloqueado(?string $phone, ?array $set = null): bool {
    if ($phone === null || $phone === '') return false;
    $c = fone_canon($phone);
    if ($c === '' || strlen($c) < 8) return false;
    $set = $set ?? blocklist_set();
    return isset($set[$c]);
}

/** A ferramenta $slug respeita a lista de bloqueio? (tools.usa_blocklist) */
function blocklist_ativa(string $slug): bool {
    static $cache = [];
    if (array_key_exists($slug, $cache)) return $cache[$slug];
    $on = false;
    try {
        $st = db()->prepare('SELECT usa_blocklist FROM tools WHERE slug = ?');
        $st->execute([$slug]);
        $on = (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) { $on = false; }
    return $cache[$slug] = $on;
}
