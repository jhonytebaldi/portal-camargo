<?php
/* =====================================================================
   capa-financeira/lib/Pdf.php — gerador de PDF mínimo, sem dependências
   (Hostinger não tem FPDF/TCPDF). Só o que o recibo precisa: página A4,
   Helvetica/Helvetica-Bold (fontes padrão do PDF, codificação WinAnsi),
   texto com quebra de linha e justificação, imagem JPEG, linhas.
   Coordenadas em pontos (1/72"), origem no canto superior esquerdo.
   ===================================================================== */
declare(strict_types=1);

final class Pdf
{
    public const A4_W = 595.28, A4_H = 841.89;
    private const WIDTHS = [
        'Helvetica' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
        'Helvetica-Bold' => [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];
    private array $pages = [];      // conteúdo (stream) de cada página
    private array $images = [];     // nome => [dados, w, h]
    private string $cur = '';
    private bool $iniciada = false;
    private float $y = 0;
    public float $margemEsq = 56.7, $margemDir = 56.7, $margemTopo = 42.5;

    public function __construct() { $this->novaPagina(); }

    public function novaPagina(): void
    {
        if ($this->iniciada) $this->pages[] = $this->cur;
        $this->iniciada = true; $this->cur = ''; $this->y = $this->margemTopo;
    }
    public function y(): float { return $this->y; }
    public function setY(float $y): void { $this->y = $y; }
    public function larguraUtil(): float { return self::A4_W - $this->margemEsq - $this->margemDir; }

    /** largura de um texto (UTF-8) em pontos */
    public function largura(string $txt, string $fonte, float $tam): float
    {
        $s = self::win($txt); $w = 0; $tab = self::WIDTHS[$fonte] ?? self::WIDTHS['Helvetica'];
        for ($i = 0, $n = strlen($s); $i < $n; $i++) $w += $tab[ord($s[$i])] ?: 500;
        return $w * $tam / 1000;
    }

    /** imagem JPEG (caminho) com largura dada, alinhada: E | C | D */
    public function imagemJpeg(string $path, float $w, string $alinha = 'C'): void
    {
        $dados = file_get_contents($path); if ($dados === false) throw new RuntimeException("imagem não encontrada: $path");
        $info = getimagesizefromstring($dados); if (!$info || $info[2] !== IMAGETYPE_JPEG) throw new RuntimeException('só JPEG');
        $nome = 'Im' . (count($this->images) + 1);
        $this->images[$nome] = [$dados, $info[0], $info[1], $info['channels'] ?? 3];
        $h = $w * $info[1] / $info[0];
        $x = $alinha === 'C' ? (self::A4_W - $w) / 2 : ($alinha === 'D' ? self::A4_W - $this->margemDir - $w : $this->margemEsq);
        $yPdf = self::A4_H - $this->y - $h;
        $this->cur .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $x, $yPdf, $nome);
        $this->y += $h;
    }

    /** uma linha de texto em x,y (y = topo da linha) */
    public function textoEm(float $x, float $y, string $txt, string $fonte = 'Helvetica', float $tam = 11): void
    {
        $this->cur .= sprintf("BT /%s %.2f Tf %.2f %.2f Td (%s) Tj ET\n", $fonte === 'Helvetica-Bold' ? 'F2' : 'F1', $tam, $x, self::A4_H - $y - $tam * 0.8, self::esc(self::win($txt)));
    }

    /** linha simples alinhada (E/C/D), avança y */
    public function linha(string $txt, string $fonte = 'Helvetica', float $tam = 11, string $alinha = 'E', float $entrelinha = 1.35): void
    {
        $w = $this->largura($txt, $fonte, $tam);
        $x = $alinha === 'C' ? (self::A4_W - $w) / 2 : ($alinha === 'D' ? self::A4_W - $this->margemDir - $w : $this->margemEsq);
        $this->textoEm($x, $this->y, $txt, $fonte, $tam);
        $this->y += $tam * $entrelinha;
    }

    public function espaco(float $pt): void { $this->y += $pt; }

    /**
     * Parágrafo com trechos [texto, negrito?] — quebra por palavra, justificado
     * (menos a última linha). Avança y.
     */
    public function paragrafo(array $trechos, float $tam = 11, float $entrelinha = 1.45, bool $justificar = true): void
    {
        // quebra em tokens (palavra + espaço) mantendo o estilo
        $tokens = [];
        foreach ($trechos as [$t, $b]) {
            $f = $b ? 'Helvetica-Bold' : 'Helvetica';
            foreach (preg_split('/(\s+)/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $p) $tokens[] = [$p, $f, $this->largura($p, $f, $tam), trim($p) === ''];
        }
        $maxW = $this->larguraUtil(); $linhas = []; $atual = []; $wAtual = 0;
        foreach ($tokens as $tk) {
            if ($tk[3]) { if ($atual) { $atual[] = $tk; $wAtual += $tk[2]; } continue; }
            if ($atual && $wAtual + $tk[2] > $maxW) {
                while ($atual && end($atual)[3]) { $wAtual -= end($atual)[2]; array_pop($atual); }
                $linhas[] = [$atual, $wAtual]; $atual = []; $wAtual = 0;
            }
            $atual[] = $tk; $wAtual += $tk[2];
        }
        while ($atual && end($atual)[3]) { $wAtual -= end($atual)[2]; array_pop($atual); }
        if ($atual) $linhas[] = [$atual, $wAtual];
        $n = count($linhas);
        foreach ($linhas as $i => [$tks, $w]) {
            $espacos = count(array_filter($tks, fn($t) => $t[3]));
            $extra = ($justificar && $i < $n - 1 && $espacos > 0) ? ($maxW - $w) / $espacos : 0;
            $x = $this->margemEsq;
            foreach ($tks as $t) {
                if (!$t[3]) $this->textoEm($x, $this->y, $t[0], $t[1], $tam);
                $x += $t[2] + ($t[3] ? $extra : 0);
            }
            $this->y += $tam * $entrelinha;
        }
    }

    /** linha pontilhada centralizada (assinatura) */
    public function linhaPontilhada(float $w = 360): void
    {
        $x = (self::A4_W - $w) / 2; $yPdf = self::A4_H - $this->y;
        $this->cur .= sprintf("q 0.6 w [1 2] 0 d %.2f %.2f m %.2f %.2f l S Q\n", $x, $yPdf, $x + $w, $yPdf);
        $this->y += 4;
    }

    public function salvar(string $destino): void { file_put_contents($destino, $this->bytes()); }

    public function bytes(): string
    {
        $pages = $this->pages; $pages[] = $this->cur;
        $objs = [];
        $objs[] = '<< /Type /Catalog /Pages 2 0 R >>';                                   // 1
        $objs[] = '';                                                                       // 2 (pages, preenchido depois)
        $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';       // 3
        $objs[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';  // 4
        $imgRefs = [];
        foreach ($this->images as $nome => [$d, $w, $h, $ch]) {
            $objs[] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $w, $h, $ch === 1 ? 'DeviceGray' : 'DeviceRGB', strlen($d), $d);
            $imgRefs[$nome] = count($objs);
        }
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >> /XObject << ' . implode(' ', array_map(fn($n, $r) => "/$n $r 0 R", array_keys($imgRefs), $imgRefs)) . ' >> >>';
        $pageIds = [];
        foreach ($pages as $c) {
            $objs[] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($c), $c); $cid = count($objs);
            $objs[] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources %s /Contents %d 0 R >>', self::A4_W, self::A4_H, $res, $cid);
            $pageIds[] = count($objs);
        }
        $objs[1] = '<< /Type /Pages /Kids [' . implode(' ', array_map(fn($i) => "$i 0 R", $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offs = [];
        foreach ($objs as $i => $o) { $offs[] = strlen($out); $out .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($offs as $o) $out .= sprintf("%010d 00000 n \n", $o);
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    private static function win(string $utf8): string { return (string)iconv('UTF-8', 'Windows-1252//TRANSLIT', $utf8); }
    private static function esc(string $s): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\(', '\)', ' ', ' '], $s); }
}
