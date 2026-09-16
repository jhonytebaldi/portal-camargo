<?php
/* =====================================================================
   capa-financeira/lib/OmieXlsx.php — preenche o modelo oficial de
   importação do Omie SEM regravar a planilha com biblioteca: abre o .xlsx
   (zip), troca só as linhas de dados (r >= 6) da 1ª aba dentro do XML e
   copia todo o resto (proteção, aba Config oculta, validações, estilos)
   byte a byte. Assim o Omie reconhece o modelo como o original.

   Uso: OmieXlsx::gerar($modelo, $linhas, $destino)
     $linhas = [ ['B' => ['s' => 'texto'], 'F' => ['n' => 123.45], 'K' => ['d' => '2026-09-30'], ...], ... ]
     s = texto (inline string), n = número, d = data ISO (vira serial Excel)
   ===================================================================== */
declare(strict_types=1);

final class OmieXlsx
{
    public const LINHA_INICIAL = 6;

    /** @param array<int, array<string, array{s?:string, n?:float|int, d?:string}>> $linhas */
    public static function gerar(string $modelo, array $linhas, string $destino): void
    {
        if (!is_file($modelo)) throw new RuntimeException("modelo não encontrado: $modelo");
        if (count($linhas) > 10000) throw new RuntimeException('o Omie aceita no máximo 10.000 linhas por planilha');

        $zin = new ZipArchive();
        if ($zin->open($modelo) !== true) throw new RuntimeException('não abriu o modelo');
        $sheetPath = self::primeiraAba($zin);
        $xml = $zin->getFromName($sheetPath);
        if ($xml === false) throw new RuntimeException('aba de dados não encontrada no modelo');

        $novo = self::substituirLinhas($xml, $linhas);

        if (is_file($destino)) @unlink($destino);
        $zout = new ZipArchive();
        if ($zout->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('não consegui criar o arquivo de saída');
        for ($i = 0; $i < $zin->numFiles; $i++) {
            $nome = $zin->getNameIndex($i);
            if ($nome === false) continue;
            if (str_ends_with($nome, '/')) { $zout->addEmptyDir($nome); continue; }
            $conteudo = $nome === $sheetPath ? $novo : $zin->getFromIndex($i);
            $zout->addFromString($nome, (string)$conteudo);
        }
        $zout->close();
        $zin->close();
    }

    /** caminho (dentro do zip) da primeira aba do workbook */
    private static function primeiraAba(ZipArchive $z): string
    {
        $wb = (string)$z->getFromName('xl/workbook.xml');
        $rels = (string)$z->getFromName('xl/_rels/workbook.xml.rels');
        if (!preg_match('/<sheet [^>]*r:id="([^"]+)"/', $wb, $m)) return 'xl/worksheets/sheet1.xml';
        if (!preg_match('/<Relationship [^>]*Id="' . preg_quote($m[1], '/') . '"[^>]*Target="([^"]+)"/', $rels, $t)
            && !preg_match('/<Relationship [^>]*Target="([^"]+)"[^>]*Id="' . preg_quote($m[1], '/') . '"/', $rels, $t)) return 'xl/worksheets/sheet1.xml';
        $alvo = ltrim($t[1], '/');
        return str_starts_with($alvo, 'xl/') ? $alvo : 'xl/' . $alvo;
    }

    /** troca as linhas r>=6 do sheetData pelas geradas, usando os estilos da linha 6 do modelo */
    private static function substituirLinhas(string $xml, array $linhas): string
    {
        if (!preg_match('/<sheetData>(.*)<\/sheetData>/s', $xml, $m, PREG_OFFSET_CAPTURE)) throw new RuntimeException('sheetData não encontrado');
        $sd = $m[1][0]; $ini = $m[1][1]; $fim = $ini + strlen($sd);

        // estilos por coluna da 1ª linha de dados (linha 6) — o Omie lê os formatos (data/número) por aí
        $estilos = []; $ultimaCol = 'B';
        if (preg_match('/<row r="6"[^>]*>(.*?)<\/row>/s', $sd, $r6)) {
            preg_match_all('/<c r="([A-Z]+)6"(?:[^>]*?s="(\d+)")?[^>]*?(?:\/>|>.*?<\/c>)/s', $r6[1], $cs, PREG_SET_ORDER);
            foreach ($cs as $c) { $estilos[$c[1]] = $c[2] ?? null; $ultimaCol = $c[1]; }
        }
        // atributos da linha 6 (spans etc.) — reaproveita sem o "r"
        $attrsRow = '';
        if (preg_match('/<row r="6"([^>]*)>/', $sd, $ar)) $attrsRow = $ar[1];

        // mantém só as linhas 1..5 (cabeçalho do modelo)
        $cabecalho = '';
        if (preg_match_all('/<row r="(\d+)"[^>]*>.*?<\/row>|<row r="(\d+)"[^>]*\/>/s', $sd, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $r) { $n = (int)($r[1] !== '' ? $r[1] : $r[2]); if ($n < self::LINHA_INICIAL) $cabecalho .= $r[0]; }
        }

        $colunas = array_keys($estilos);
        $out = $cabecalho; $n = self::LINHA_INICIAL;
        foreach ($linhas as $linha) {
            $cells = '';
            foreach ($colunas as $col) {
                $s = $estilos[$col] !== null ? ' s="' . $estilos[$col] . '"' : '';
                $v = $linha[$col] ?? null;
                if ($v === null || $v === '' || $v === []) { $cells .= '<c r="' . $col . $n . '"' . $s . '/>'; continue; }
                if (isset($v['d']) && $v['d'] !== null && $v['d'] !== '') { $cells .= '<c r="' . $col . $n . '"' . $s . '><v>' . self::serial($v['d']) . '</v></c>'; }
                elseif (isset($v['n']) && $v['n'] !== null && $v['n'] !== '') { $cells .= '<c r="' . $col . $n . '"' . $s . '><v>' . self::num($v['n']) . '</v></c>'; }
                elseif (isset($v['s']) && $v['s'] !== null && $v['s'] !== '') { $cells .= '<c r="' . $col . $n . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($v['s']) . '</t></is></c>'; }
                else $cells .= '<c r="' . $col . $n . '"' . $s . '/>';
            }
            $out .= '<row r="' . $n . '"' . $attrsRow . '>' . $cells . '</row>';
            $n++;
        }
        $xml = substr($xml, 0, $ini) . $out . substr($xml, $fim);
        // dimensão: A1 até a última coluna/linha usada
        $ultLinha = max(self::LINHA_INICIAL, $n - 1);
        $xml = preg_replace('/<dimension ref="[^"]*"\/>/', '<dimension ref="A1:' . $ultimaCol . $ultLinha . '"/>', $xml, 1) ?? $xml;
        return $xml;
    }

    public static function serial(string $iso): int
    {
        // dias desde 30/12/1899 (sistema 1900 do Excel), sem hora
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($iso, 0, 10), new DateTimeZone('UTC'));
        if (!$d) throw new RuntimeException("data inválida: $iso");
        return (int)floor(($d->getTimestamp() + 2209161600) / 86400);
    }
    private static function num(float|int|string $v): string
    {
        $s = number_format((float)$v, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }
    private static function esc(string $s): string
    {
        // remove controles inválidos em XML 1.0 e escapa
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
