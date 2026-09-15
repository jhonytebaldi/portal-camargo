<?php
/* =====================================================================
   capa-financeira/lib/Pix.php — reconhecimento, formatação e validação
   de chaves Pix (servidor). O mesmo algoritmo existe em JS (pix.js) para
   a tela; aqui é a palavra final antes de gravar.
   Tipos: cpf | cnpj | email | telefone | aleatoria (EVP/UUID)
   ===================================================================== */
declare(strict_types=1);

final class Pix
{
    /** @return array{tipo:?string, valor:?string, valido:bool, erro:?string} valor = normalizado p/ gravar */
    public static function analisar(?string $raw): array
    {
        $s = trim((string)$raw);
        if ($s === '') return ['tipo' => null, 'valor' => null, 'valido' => true, 'erro' => null];
        // e-mail
        if (str_contains($s, '@')) {
            $e = mb_strtolower($s, 'UTF-8');
            $ok = (bool)filter_var($e, FILTER_VALIDATE_EMAIL);
            return ['tipo' => 'email', 'valor' => $e, 'valido' => $ok, 'erro' => $ok ? null : 'e-mail inválido'];
        }
        // chave aleatória (UUID)
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) {
            return ['tipo' => 'aleatoria', 'valor' => strtolower($s), 'valido' => true, 'erro' => null];
        }
        if (preg_match('/^[0-9a-f]{32}$/i', $s)) {
            $h = strtolower($s);
            $u = substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
            return ['tipo' => 'aleatoria', 'valor' => $u, 'valido' => true, 'erro' => null];
        }
        $d = preg_replace('/\D+/', '', $s) ?? '';
        $temMais = str_starts_with($s, '+');
        // telefone: +55DDDNÚMERO (Pix exige E.164). Aceita 10/11 dígitos nacionais ou 12/13 com 55.
        if ($temMais || strlen($d) === 10 || strlen($d) === 12 || strlen($d) === 13) {
            $n = $d;
            if (strlen($n) === 12 || strlen($n) === 13) { if (!str_starts_with($n, '55')) return ['tipo' => null, 'valor' => null, 'valido' => false, 'erro' => strlen($n) . ' dígitos: se for CNPJ falta dígito; se for telefone, comece com +55']; }
            else $n = '55' . $n;
            $ok = strlen($n) === 12 || strlen($n) === 13;
            return ['tipo' => 'telefone', 'valor' => '+' . $n, 'valido' => $ok, 'erro' => $ok ? null : 'telefone incompleto'];
        }
        if (strlen($d) === 11) {
            $ok = self::cpfValido($d);
            // 11 dígitos também pode ser celular sem +55 quando começa com DDD válido e 9 — CPF tem prioridade se os dígitos verificadores baterem
            if (!$ok && preg_match('/^[1-9][0-9]9[0-9]{8}$/', $d)) return ['tipo' => 'telefone', 'valor' => '+55' . $d, 'valido' => true, 'erro' => null];
            return ['tipo' => 'cpf', 'valor' => self::fmtCpf($d), 'valido' => $ok, 'erro' => $ok ? null : 'CPF inválido (dígito verificador)'];
        }
        if (strlen($d) === 14) {
            $ok = self::cnpjValido($d);
            return ['tipo' => 'cnpj', 'valor' => self::fmtCnpj($d), 'valido' => $ok, 'erro' => $ok ? null : 'CNPJ inválido (dígito verificador)'];
        }
        return ['tipo' => null, 'valor' => $s, 'valido' => false, 'erro' => 'não parece CPF, CNPJ, e-mail, telefone nem chave aleatória'];
    }

    public static function fmtCpf(string $d): string { return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $d) ?? $d; }
    public static function fmtCnpj(string $d): string { return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d) ?? $d; }

    public static function cpfValido(string $d): bool
    {
        if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) return false;
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) $soma += (int)$d[$i] * (($t + 1) - $i);
            $dv = (10 * $soma) % 11 % 10;
            if ((int)$d[$t] !== $dv) return false;
        }
        return true;
    }

    public static function cnpjValido(string $d): bool
    {
        if (strlen($d) !== 14 || preg_match('/^(\d)\1{13}$/', $d)) return false;
        $calc = function (string $base, array $pesos): int {
            $soma = 0;
            foreach ($pesos as $i => $p) $soma += (int)$base[$i] * $p;
            $r = $soma % 11;
            return $r < 2 ? 0 : 11 - $r;
        };
        $dv1 = $calc(substr($d, 0, 12), [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $dv2 = $calc(substr($d, 0, 13), [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        return (int)$d[12] === $dv1 && (int)$d[13] === $dv2;
    }
}
