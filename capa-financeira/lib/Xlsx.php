<?php
/* =====================================================================
   capa-financeira/lib/Xlsx.php — leitor mínimo de .xlsx (sem dependências).

   Lê a primeira planilha (ou a de nome dado) e devolve as células como
   [linha][coluna] => valor, com:
     - strings (shared strings, inline e resultado de fórmula em texto)
     - números (float) — valores calculados de fórmulas incluídos (cache)
     - datas: números com estilo de data viram DateTimeImmutable
     - booleanos
   Suficiente para a Capa Financeira; não escreve, não avalia fórmulas.
   ===================================================================== */
declare(strict_types=1);

final class Xlsx
{
    /** @var array<int, array<int, mixed>> */
    private array $cells = [];
    private int $maxRow = 0;
    private int $maxCol = 0;

    /** Formatos de data embutidos do Excel (numFmtId). */
    private const BUILTIN_DATE = [14,15,16,17,18,19,20,21,22,27,28,29,30,31,32,33,34,35,36,45,46,47,50,51,52,53,54,55,56,57,58];

    public static function open(string $path, ?string $sheetName = null): self
    {
        $z = new ZipArchive();
        if ($z->open($path) !== true) throw new RuntimeException('Não foi possível abrir o arquivo .xlsx');
        $x = new self();
        try {
            $shared = self::readSharedStrings($z);
            $dateStyles = self::readDateStyles($z);
            $sheetPath = self::resolveSheetPath($z, $sheetName);
            $xml = $z->getFromName($sheetPath);
            if ($xml === false) throw new RuntimeException('Planilha não encontrada dentro do .xlsx');
            $x->parseSheet($xml, $shared, $dateStyles);
        } finally { $z->close(); }
        return $x;
    }

    /** Valor da célula (1-based). Null se vazia. */
    public function cell(int $row, int $col): mixed { return $this->cells[$row][$col] ?? null; }
    /** Valor por referência A1. */
    public function ref(string $a1): mixed
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/i', $a1, $m)) return null;
        return $this->cell((int)$m[2], self::colIndex(strtoupper($m[1])));
    }
    public function maxRow(): int { return $this->maxRow; }
    public function maxCol(): int { return $this->maxCol; }

    public static function colIndex(string $letters): int
    {
        $n = 0;
        foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }

    /* ---------------- internos ---------------- */

    private static function readSharedStrings(ZipArchive $z): array
    {
        $xml = $z->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return [];
        $out = [];
        $r = new XMLReader();
        $r->XML($xml);
        $ok = $r->read();
        while ($ok) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'si') {
                $cur = '';
                // lê o <si> inteiro e concatena todos os <t>
                $node = $r->expand();
                if ($node) {
                    foreach ($node->getElementsByTagName('t') as $t) $cur .= $t->textContent;
                }
                $out[] = $cur;
                $ok = $r->next();   // pula para o próximo <si> sem descer nos filhos
                continue;
            }
            $ok = $r->read();
        }
        return $out;
    }

    /** Conjunto de índices de estilo (cellXfs) que representam data. */
    private static function readDateStyles(ZipArchive $z): array
    {
        $xml = $z->getFromName('xl/styles.xml');
        if ($xml === false) return [];
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) return [];
        $custom = [];
        foreach ($doc->getElementsByTagName('numFmt') as $nf) {
            $id = (int)$nf->getAttribute('numFmtId');
            $code = $nf->getAttribute('formatCode');
            $custom[$id] = self::formatIsDate($code);
        }
        $dateXf = [];
        $cellXfs = $doc->getElementsByTagName('cellXfs')->item(0);
        if (!$cellXfs) return [];
        $i = 0;
        foreach ($cellXfs->getElementsByTagName('xf') as $xf) {
            $id = (int)$xf->getAttribute('numFmtId');
            $isDate = in_array($id, self::BUILTIN_DATE, true) || (!empty($custom[$id]));
            if ($isDate) $dateXf[$i] = true;
            $i++;
        }
        return $dateXf;
    }

    /** Heurística igual à do openpyxl: formato com d/m/y/h/s fora de colchetes/aspas é data. */
    private static function formatIsDate(string $code): bool
    {
        $c = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? '';
        if ($c === '' || stripos($c, 'General') !== false) return false;
        return (bool)preg_match('/[dmyhs]/i', $c);
    }

    private static function resolveSheetPath(ZipArchive $z, ?string $name): string
    {
        $wb = $z->getFromName('xl/workbook.xml');
        $rels = $z->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) return 'xl/worksheets/sheet1.xml';
        $doc = new DOMDocument(); @$doc->loadXML($wb);
        $rdoc = new DOMDocument(); @$rdoc->loadXML($rels);
        $relMap = [];
        foreach ($rdoc->getElementsByTagName('Relationship') as $r) {
            $relMap[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }
        $first = null;
        foreach ($doc->getElementsByTagName('sheet') as $s) {
            $rid = $s->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')
                ?: $s->getAttribute('r:id');
            $target = $relMap[$rid] ?? null;
            if (!$target) continue;
            $path = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            if ($first === null) $first = $path;
            if ($name !== null && $s->getAttribute('name') === $name) return $path;
        }
        return $first ?? 'xl/worksheets/sheet1.xml';
    }

    private function parseSheet(string $xml, array $shared, array $dateStyles): void
    {
        $r = new XMLReader();
        $r->XML($xml);
        $ok = $r->read();
        while ($ok) {
            if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'c') { $ok = $r->read(); continue; }
            $ref = $r->getAttribute('r') ?? '';
            $t = $r->getAttribute('t') ?? '';
            $s = $r->getAttribute('s');
            $node = $r->expand();
            $v = null; $is = null;
            if ($node) {
                foreach ($node->childNodes as $ch) {
                    if ($ch->nodeName === 'v') $v = $ch->textContent;
                    elseif ($ch->nodeName === 'is') { $is = ''; foreach ($ch->getElementsByTagName('t') as $tt) $is .= $tt->textContent; }
                }
            }
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) { $ok = $r->next(); continue; }
            $row = (int)$m[2]; $col = self::colIndex($m[1]);
            $val = null;
            if ($t === 's') { $val = $shared[(int)$v] ?? ''; }
            elseif ($t === 'inlineStr') { $val = $is ?? ''; }
            elseif ($t === 'str') { $val = (string)$v; }
            elseif ($t === 'b') { $val = $v === '1'; }
            elseif ($t === 'e') { $val = null; }               // erro (#DIV/0!) = vazio
            elseif ($v !== null && $v !== '') {
                $num = (float)$v;
                if ($s !== null && isset($dateStyles[(int)$s])) $val = self::serialToDate($num);
                else $val = $num;
            }
            if ($val === '' ) $val = null;
            if ($val !== null) {
                $this->cells[$row][$col] = $val;
                if ($row > $this->maxRow) $this->maxRow = $row;
                if ($col > $this->maxCol) $this->maxCol = $col;
            }
            $ok = $r->next();
        }
    }

    /** Serial do Excel (base 1900) → data. Mesma convenção do openpyxl. */
    public static function serialToDate(float $serial): ?DateTimeImmutable
    {
        if ($serial < 1) return null;
        $days = (int)floor($serial);
        if ($days >= 60) $days -= 1;              // bug do ano 1900 do Excel
        $base = new DateTimeImmutable('1899-12-31 00:00:00', new DateTimeZone('UTC'));
        $d = $base->modify("+{$days} days");
        return $d ?: null;
    }
}
