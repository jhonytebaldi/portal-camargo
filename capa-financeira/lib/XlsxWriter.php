<?php
/* =====================================================================
   capa-financeira/lib/XlsxWriter.php — gravador mínimo de .xlsx (sem
   dependências; só ZipArchive). Gera a planilha inteira do zero, com:
     - texto, número, data (vira serial do Excel) e fórmula (com valor em
       cache — quem LÊ o arquivo sem abrir no Excel, como o leitor de capas
       do portal, enxerga o resultado; o Excel recalcula ao abrir);
     - estilos simples (negrito, itálico, cor, fundo, borda, alinhamento,
       quebra de linha, formatos R$ / % / data);
     - largura de coluna, mesclagem, validação de dados por lista.
   Uso:
     $x = new XlsxWriter(); $s = $x->planilha('MODELO');
     $x->set($s, 'B9', 318000, ['fmt' => 'brl']);
     $x->set($s, 'B13', ['f' => 'B9-B10', 'v' => 313000], ['fmt' => 'brl', 'bold' => true]);
     $x->set($s, 'G4', ['d' => '2026-04-09'], ['fmt' => 'data']);
     $x->salvar('/tmp/capa.xlsx');
   ===================================================================== */
declare(strict_types=1);

final class XlsxWriter
{
    private array $sheets = [];          // nome => [cells, cols, merges, dv, freeze]
    private array $fonts = ['<font><sz val="11"/><name val="Calibri"/></font>'];
    private array $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
    private array $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
    private array $numFmts = [];         // código => id
    private array $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
    private array $xfIdx = [];

    public const FMT = ['brl' => '"R$" #,##0.00', 'pct' => '0.00%', 'pct1' => '0.0%', 'data' => 'dd/mm/yyyy', 'datahora' => 'dd/mm/yyyy hh:mm', 'int' => '0', 'num' => '#,##0.00'];

    public function planilha(string $nome): string
    {
        $nome = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $nome) ?? 'Planilha', 0, 31);
        $this->sheets[$nome] = ['cells' => [], 'cols' => [], 'merges' => [], 'dv' => [], 'freeze' => null, 'alturas' => []];
        return $nome;
    }

    public function largura(string $sheet, string $col, float $w): void { $this->sheets[$sheet]['cols'][self::colIdx($col)] = $w; }
    public function altura(string $sheet, int $linha, float $h): void { $this->sheets[$sheet]['alturas'][$linha] = $h; }
    public function mesclar(string $sheet, string $range): void { $this->sheets[$sheet]['merges'][] = $range; }
    public function validacaoLista(string $sheet, string $sqref, string $formula): void { $this->sheets[$sheet]['dv'][] = [$sqref, $formula]; }
    public function congelar(string $sheet, string $ref): void { $this->sheets[$sheet]['freeze'] = $ref; }

    /** @param mixed $v string | int | float | null | ['f'=>fórmula sem "=", 'v'=>valor em cache] | ['d'=>'Y-m-d' ou 'Y-m-d H:i'] */
    public function set(string $sheet, string $ref, mixed $v, array $estilo = []): void
    {
        [$c, $r] = self::ref($ref);
        $this->sheets[$sheet]['cells'][$r][$c] = [$v, $estilo ? $this->estilo($estilo) : 0];
    }

    /* ---------------- estilos ---------------- */

    private function estilo(array $e): int
    {
        ksort($e);
        $k = json_encode($e);
        if (isset($this->xfIdx[$k])) return $this->xfIdx[$k];
        $font = '<font>' . (!empty($e['bold']) ? '<b/>' : '') . (!empty($e['italic']) ? '<i/>' : '') . '<sz val="' . (float)($e['size'] ?? 11) . '"/>'
              . (!empty($e['color']) ? '<color rgb="FF' . ltrim($e['color'], '#') . '"/>' : '') . '<name val="Calibri"/></font>';
        $fontId = $this->idx($this->fonts, $font);
        $fillId = 0;
        if (!empty($e['fill'])) $fillId = $this->idx($this->fills, '<fill><patternFill patternType="solid"><fgColor rgb="FF' . ltrim($e['fill'], '#') . '"/><bgColor indexed="64"/></patternFill></fill>');
        $borderId = 0;
        if (!empty($e['border'])) { $b = '<left style="thin"><color rgb="FFBFBFBF"/></left><right style="thin"><color rgb="FFBFBFBF"/></right><top style="thin"><color rgb="FFBFBFBF"/></top><bottom style="thin"><color rgb="FFBFBFBF"/></bottom>'; $borderId = $this->idx($this->borders, "<border>$b<diagonal/></border>"); }
        $numId = 0;
        if (!empty($e['fmt'])) {
            $code = self::FMT[$e['fmt']] ?? $e['fmt'];
            if ($code === '0.00%') $numId = 10;
            elseif ($code === '0') $numId = 1;
            else { if (!isset($this->numFmts[$code])) $this->numFmts[$code] = 164 + count($this->numFmts); $numId = $this->numFmts[$code]; }
        }
        $al = '';
        if (!empty($e['align']) || !empty($e['wrap']) || !empty($e['valign'])) {
            $al = '<alignment' . (!empty($e['align']) ? ' horizontal="' . $e['align'] . '"' : '') . (!empty($e['valign']) ? ' vertical="' . $e['valign'] . '"' : '') . (!empty($e['wrap']) ? ' wrapText="1"' : '') . '/>';
        }
        $xf = '<xf numFmtId="' . $numId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"'
            . ($numId ? ' applyNumberFormat="1"' : '') . ($fontId ? ' applyFont="1"' : '') . ($fillId ? ' applyFill="1"' : '') . ($borderId ? ' applyBorder="1"' : '')
            . ($al ? ' applyAlignment="1">' . $al . '</xf>' : '/>');
        $this->xfs[] = $xf;
        return $this->xfIdx[$k] = count($this->xfs) - 1;
    }
    private function idx(array &$lista, string $xml): int
    {
        $i = array_search($xml, $lista, true);
        if ($i !== false) return (int)$i;
        $lista[] = $xml;
        return count($lista) - 1;
    }

    /* ---------------- referências ---------------- */

    public static function colIdx(string $col): int
    {
        $n = 0;
        foreach (str_split(strtoupper($col)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n;
    }
    public static function colNome(int $n): string
    {
        $s = '';
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    }
    /** "B12" → [2, 12] */
    public static function ref(string $ref): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper($ref), $m)) throw new InvalidArgumentException("referência inválida: $ref");
        return [self::colIdx($m[1]), (int)$m[2]];
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
    private static function serial(string $iso): float
    {
        $d = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        return ($d->getTimestamp() - $base->getTimestamp()) / 86400;
    }

    /* ---------------- gravação ---------------- */

    private function sheetXml(array $s): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($s['freeze']) {
            [$c, $r] = self::ref($s['freeze']);
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane' . ($c > 1 ? ' xSplit="' . ($c - 1) . '"' : '') . ($r > 1 ? ' ySplit="' . ($r - 1) . '"' : '') . ' topLeftCell="' . $s['freeze'] . '" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        }
        if ($s['cols']) {
            ksort($s['cols']);
            $x .= '<cols>';
            foreach ($s['cols'] as $i => $w) $x .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($s['cells']);
        foreach ($s['cells'] as $r => $cols) {
            ksort($cols);
            $x .= '<row r="' . $r . '"' . (isset($s['alturas'][$r]) ? ' ht="' . $s['alturas'][$r] . '" customHeight="1"' : '') . '>';
            foreach ($cols as $c => [$v, $st]) {
                $ref = self::colNome($c) . $r;
                $sa = $st ? ' s="' . $st . '"' : '';
                if ($v === null || $v === '') { $x .= '<c r="' . $ref . '"' . $sa . '/>'; continue; }
                if (is_array($v) && isset($v['f'])) {
                    $cache = $v['v'] ?? null;
                    if (is_string($cache)) $x .= '<c r="' . $ref . '"' . $sa . ' t="str"><f>' . self::esc(ltrim($v['f'], '=')) . '</f><v>' . self::esc($cache) . '</v></c>';
                    else $x .= '<c r="' . $ref . '"' . $sa . '><f>' . self::esc(ltrim($v['f'], '=')) . '</f>' . ($cache !== null ? '<v>' . self::num((float)$cache) . '</v>' : '') . '</c>';
                } elseif (is_array($v) && isset($v['d'])) {
                    $x .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num(self::serial($v['d'])) . '</v></c>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $sa . '><v>' . self::num((float)$v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $sa . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc((string)$v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($s['merges']) { $x .= '<mergeCells count="' . count($s['merges']) . '">'; foreach ($s['merges'] as $m) $x .= '<mergeCell ref="' . $m . '"/>'; $x .= '</mergeCells>'; }
        if ($s['dv']) {
            $x .= '<dataValidations count="' . count($s['dv']) . '">';
            foreach ($s['dv'] as [$sq, $f]) $x .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="' . $sq . '"><formula1>' . self::esc($f) . '</formula1></dataValidation>';
            $x .= '</dataValidations>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
        return $x;
    }
    private static function num(float $f): string
    {
        if (floor($f) == $f && abs($f) < 1e15) return (string)(int)$f;
        return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    public function salvar(string $destino): void
    {
        if (!$this->sheets) $this->planilha('Planilha1');
        $z = new ZipArchive();
        if ($z->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('não consegui criar o arquivo .xlsx');
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $z->addFromString('[Content_Types].xml', $ct . '</Types>');
        $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $agora = gmdate('Y-m-d\TH:i:s\Z');
        $z->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Portal Camargo</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:modified></cp:coreProperties>');
        $z->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Portal Camargo</Application></Properties>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $i = 0;
        foreach ($this->sheets as $nome => $s) {
            $i++;
            $wb .= '<sheet name="' . self::esc($nome) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $z->addFromString('xl/worksheets/sheet' . $i . '.xml', $this->sheetXml($s));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $z->addFromString('xl/workbook.xml', $wb . '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $nf = '';
        if ($this->numFmts) { $nf = '<numFmts count="' . count($this->numFmts) . '">'; foreach ($this->numFmts as $code => $id) $nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::esc($code) . '"/>'; $nf .= '</numFmts>'; }
        $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . $nf
            . '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->xfs) . '">' . implode('', $this->xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $z->close();
    }
}
