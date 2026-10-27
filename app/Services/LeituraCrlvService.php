<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/** Extrai somente candidatos; a aprovação continua exigindo conferência da gestão. */
class LeituraCrlvService
{
    private float $prazo;

    private array $conflitos = [];

    private const ROTULOS = [
        'placa' => '/^PLACA$/',
        'renavam' => '/^(CODIGO\s+)?RENAVAM$/',
        'chassi' => '/^CHASSI$/',
        'numero_crv' => '/^(NUMERO|N[Oº°.]?)\s+(DO\s+)?CRV$/u',
        'codigo_seguranca' => '/^CODIGO DE SEGURANCA( DO (CLA|CRV|CRLV|CA))?$/',
        'exercicio' => '/^EXERCICIO$/',
        'data_emissao' => '/^(DATA|DATA (DE )?EMISSAO)$/',
        'nome_proprietario' => '/^(NOME|NOME (DO )?PROPRIETARIO)$/',
        'cpf_cnpj_proprietario' => '/^CPF\s*\/?\s*CNPJ?$/',
        'marca_modelo' => '/^MARCA\s*\/?\s*MODELO(?:\s*\/?\s*I?N?VERSAO)?$/',
        'cor' => '/^COR( PREDOMINANTE)?$/',
        'ano_fabricacao' => '/^ANO FABRICACAO$/',
        'ano_modelo' => '/^ANO MODELO$/',
        'categoria' => '/^CATEGORIA$/',
        'categoria_veiculo' => '/^ESPECIE\/TIPO$/',
        'uf' => '/^LOCAL$/',
        'observacao' => '/^OBSERVACOES( (DO )?VEICULO)?$/',
    ];

    public function extrair(UploadedFile $arquivo): array
    {
        $this->prazo = microtime(true) + 20;
        $this->conflitos = [];
        $temporario = sys_get_temp_dir().'/crlv-'.Str::uuid();
        try {
            mkdir($temporario, 0700);
            if ($arquivo->getMimeType() === 'application/pdf') {
                $xml = $this->executar(['pdftotext', '-f', '1', '-l', '10', '-bbox', $arquivo->getRealPath(), '-']);
                $dados = $this->interpretar($this->paginasPdf($xml));
                if (count(array_intersect(['placa', 'renavam', 'chassi', 'exercicio', 'categoria_veiculo'], array_keys($dados))) === 5) {
                    return $this->validarCandidatos($dados);
                }
                // PDFs digitalizados: limita páginas, resolução, tempo e arquivos temporários.
                $this->executar(['pdftoppm', '-f', '1', '-l', '2', '-scale-to', '2400', '-png', $arquivo->getRealPath(), $temporario.'/pagina']);
                $imagens = glob($temporario.'/pagina-*.png');
            } else {
                $imagens = [$arquivo->getRealPath()];
                $dados = [];
            }
            $paginas = [];
            foreach ($imagens as $imagem) {
                $tratada = $this->prepararImagem($imagem, $temporario);
                $palavras = $this->palavras($this->executar(['tesseract', $tratada, 'stdout', '-l', 'por', '--psm', '11', 'tsv']));
                $paginas[] = $palavras;
                $primeiraLeitura = $this->interpretar([$palavras]);
                // Reler cada valor em separado evita ruído de QR Codes, bordas e colunas.
                $regional = $this->lerRegioes($tratada, $palavras, $temporario, $primeiraLeitura);
                foreach ($regional as $campo => $valor) {
                    $dados[$campo] ??= $valor;
                }
                // Em fotos já nítidas, ampliar pode piorar um caractere pequeno.
                // Completar somente o que faltou usando também a imagem original.
                $parcial = array_replace($primeiraLeitura, $regional);
                if (array_diff(['placa', 'renavam', 'chassi', 'exercicio', 'cpf_cnpj_proprietario', 'categoria_veiculo'], array_keys($parcial)) && microtime(true) < $this->prazo - 1) {
                    $originais = $this->palavras($this->executar(['tesseract', $imagem, 'stdout', '-l', 'por', '--psm', '11', 'tsv']));
                    foreach ($this->interpretar([$originais]) as $campo => $valor) {
                        if (! isset($parcial[$campo])) {
                            $dados[$campo] ??= $valor;
                        }
                    }
                }
            }
            $ocr = $this->interpretar($paginas);
            foreach ($ocr as $campo => $valor) {
                if (isset($dados[$campo]) && $dados[$campo] !== $valor) {
                    unset($dados[$campo]); // Não escolher um valor quando PDF e imagem divergem.
                } else {
                    $dados[$campo] = $valor;
                }
            }

            return $this->validarCandidatos($dados);
        } catch (Throwable $exception) {
            // Nunca registrar conteúdo, caminho ou dados pessoais do CRLV nos logs.
            Log::warning('Leitura automática de CRLV indisponível; documento permanece em análise.', ['erro' => $exception::class]);

            return $this->validarCandidatos($dados ?? []);
        } finally {
            foreach (glob($temporario.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($temporario)) {
                rmdir($temporario);
            }
        }
    }

    private function prepararImagem(string $origem, string $diretorio): string
    {
        $imagem = new \Imagick($origem.'[0]');
        try {
            if (method_exists($imagem, 'autoOrient')) {
                $imagem->autoOrient();
            }
            $imagem->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            $imagem->resizeImage(2400, 3200, \Imagick::FILTER_LANCZOS, 1, true);
            $imagem->normalizeImage();
            $imagem->unsharpMaskImage(0, .8, .8, .02);
            $imagem->setImageFormat('png');
            $destino = $diretorio.'/tratada-'.Str::uuid().'.png';
            $imagem->writeImage($destino);

            return $destino;
        } finally {
            $imagem->clear();
        }
    }

    private function palavras(string $tsv): array
    {
        $palavras = [];
        foreach (explode("\n", $tsv) as $linha) {
            $c = explode("\t", $linha, 12);
            if (count($c) !== 12 || $c[0] !== '5' || trim($c[11]) === '') {
                continue;
            }
            $palavras[] = ['text' => trim($c[11]), 'x' => (float) $c[6], 'y' => (float) $c[7],
                'width' => (float) $c[8], 'height' => (float) $c[9], 'confidence' => (float) $c[10]];
        }

        return $palavras;
    }

    private function lerRegioes(string $path, array $palavras, string $diretorio, array $lidos): array
    {
        $imagem = new \Imagick($path);
        $dados = [];
        try {
            foreach ($this->regioes($palavras) as $r) {
                $campo = $r['campo'];
                if (isset($lidos[$campo]) || isset($this->conflitos[$campo])) {
                    continue;
                }
                if (microtime(true) > $this->prazo - .5) {
                    break;
                }
                // O número de CRV mascarado (***) não deve ser preenchido com outro número.
                if ($campo === 'numero_crv' && ! collect($palavras)->contains(fn ($i) => $i['x'] >= $r['left'] && $i['x'] < $r['left'] + $r['width'] && $i['y'] >= $r['top'] && $i['y'] < $r['top'] + $r['height'] && preg_match('/\d/', $i['text']))) {
                    continue;
                }
                $recorte = clone $imagem;
                try {
                    $width = min($r['width'], $imagem->getImageWidth() - $r['left']);
                    $height = min($r['height'], $imagem->getImageHeight() - $r['top']);
                    if ($width <= 0 || $height <= 0) {
                        continue;
                    }
                    $recorte->cropImage((int) $width, (int) $height, (int) $r['left'], (int) $r['top']);
                    $recorte->setImagePage(0, 0, 0, 0);
                    $recorte->resizeImage((int) min(1500, $width * 2), 0, \Imagick::FILTER_LANCZOS, 1);
                    $recorte->borderImage('white', 20, 16);
                    $alvo = $diretorio.'/campo.png';
                    $recorte->writeImage($alvo);
                    foreach (['6', '7'] as $psm) {
                        $valores = $this->palavras($this->executar(['tesseract', $alvo, 'stdout', '-l', 'por', '--psm', $psm, 'tsv']));
                        $linhas = $campo === 'observacao' ? [$valores] : $this->linhas($valores);
                        foreach ($linhas as $linha) {
                            $linha = array_values(array_filter($linha, fn ($i) => $i['confidence'] >= 65));
                            if (! $linha) {
                                continue;
                            }
                            $valor = $this->valor($campo, implode(' ', array_column($linha, 'text')));
                            if ($valor !== null) {
                                $dados[$campo] = $valor;
                                break 2;
                            }
                        }
                    }
                } catch (ProcessTimedOutException) {
                    break;
                } finally {
                    $recorte->clear();
                }
            }
        } finally {
            $imagem->clear();
        }

        return $dados;
    }

    private function validarCandidatos(array $dados): array
    {
        $dados = array_diff_key($dados, $this->conflitos);
        $service = app(CrlvVeiculoService::class);
        $request = new Request(['tipo_documento' => 'crlv', 'informacoes_complementares' => $dados]);
        $service->normalizar($request);
        $dados = $request->input('informacoes_complementares');
        $erros = Validator::make($request->all(), $service->regras($request))->errors()->keys();
        foreach ($erros as $campo) {
            if (str_starts_with($campo, 'informacoes_complementares.')) {
                unset($dados[substr($campo, strlen('informacoes_complementares.'))]);
            }
        }

        return $dados;
    }

    private function executar(array $comando): string
    {
        $processo = new Process($comando, timeout: max(.1, min(15, $this->prazo - microtime(true))));
        $processo->mustRun();

        return $processo->getOutput();
    }

    private function paginasPdf(string $xml): array
    {
        $dom = new DOMDocument;
        if (! @$dom->loadXML($xml, LIBXML_NONET)) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $paginas = [];
        foreach ($xpath->query('//*[local-name()="page"]') as $pagina) {
            $items = [];
            foreach ($xpath->query('.//*[local-name()="word"]', $pagina) as $palavra) {
                $x = (float) $palavra->getAttribute('xMin');
                $y = (float) $palavra->getAttribute('yMin');
                $items[] = ['text' => $palavra->textContent, 'x' => $x, 'y' => $y,
                    'width' => (float) $palavra->getAttribute('xMax') - $x,
                    'height' => (float) $palavra->getAttribute('yMax') - $y];
            }
            $paginas[] = $items;
        }

        return $paginas;
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper(Str::ascii($texto))));
    }

    private function normalizarRotulo(string $texto): string
    {
        return trim(preg_replace('/\s*\/\s*/', '/', preg_replace('/[.\[\]()|:]/', '', $this->normalizar($texto))));
    }

    private function rotulo(string $texto): bool
    {
        $texto = $this->normalizarRotulo($texto);
        foreach (self::ROTULOS as $regex) {
            if (preg_match($regex, $texto)) {
                return true;
            }
        }

        return (bool) preg_match('/^(COMBUSTIVEL|CAPACIDADE|POTENCIA\/CILINDRADA|PESO BRUTO TOTAL|MOTOR|CMT|EIXOS|LOTACAO|CARROCERIA|PLACA ANTERIOR\/UF|CAT|DATA DE QUITACAO|DADOS DO SEGURO DPVAT|INFORMACOES DO SEGURO DPVAT|MENSAGENS SENATRAN|DOCUMENTO EMITIDO.*)$/', $texto);
    }

    private function linhas(array $items): array
    {
        usort($items, fn ($a, $b) => ($a['y'] <=> $b['y']) ?: ($a['x'] <=> $b['x']));
        $linhas = [];
        foreach ($items as $item) {
            $indice = null;
            foreach ($linhas as $i => $linha) {
                if (abs($linha[0]['y'] + $linha[0]['height'] / 2 - $item['y'] - $item['height'] / 2) <= max(3, min($linha[0]['height'], $item['height']) * .75)) {
                    $indice = $i;
                    break;
                }
            }
            if ($indice === null) {
                $linhas[] = [$item];
            } else {
                $linhas[$indice][] = $item;
            }
        }
        foreach ($linhas as &$linha) {
            usort($linha, fn ($a, $b) => $a['x'] <=> $b['x']);
        }

        return $linhas;
    }

    private function regioes(array $items): array
    {
        if (! $items) {
            return [];
        }
        $regioes = [];
        $labels = [];
        foreach ($this->linhas($items) as $linha) {
            for ($inicio = 0; $inicio < count($linha); $inicio++) {
                $grupo = $linha[$inicio];
                for ($fim = $inicio; $fim < min(count($linha), $inicio + 8); $fim++) {
                    if ($fim !== $inicio) {
                        $proximo = $linha[$fim];
                        if (max(12, $grupo['height'] * 2) < $proximo['x'] - $grupo['x'] - $grupo['width']) {
                            break;
                        }
                        $grupo['text'] .= ' '.$proximo['text'];
                        $grupo['width'] = $proximo['x'] + $proximo['width'] - $grupo['x'];
                    }
                    if ($this->rotulo($grupo['text'])) {
                        $labels[] = $grupo;
                    }
                }
            }
        }
        $labels = array_values(array_filter($labels, fn ($i) => ! collect($labels)->contains(fn ($j) => $j['x'] <= $i['x'] && $j['x'] + $j['width'] >= $i['x'] + $i['width'] && abs($j['y'] - $i['y']) < max($j['height'], $i['height']) && $j['width'] > $i['width'])));
        $direita = max(array_map(fn ($i) => $i['x'] + $i['width'], $items)) + 5;
        $coluna = collect($labels)->first(fn ($i) => $this->normalizar($i['text']) === 'CATEGORIA')['x'] ?? null;
        foreach (self::ROTULOS as $campo => $regex) {
            foreach ($labels as $label) {
                if (! preg_match($regex, $this->normalizarRotulo($label['text']))) {
                    continue;
                }
                $left = $label['x'] - ($campo === 'renavam' ? max(5, $label['height'] * 6) : 5);
                $right = $coluna && $label['x'] < $coluna - 30 ? $coluna - 30 : $direita;
                foreach ($labels as $outro) {
                    if ($outro['x'] > $label['x'] + $label['width'] && abs($outro['y'] - $label['y']) < max($outro['height'], $label['height'])) {
                        $right = min($right, $outro['x'] - 4);
                    }
                }
                if ($campo === 'numero_crv') {
                    $right = min($right, $label['x'] + max(100, $label['width'] * 2.5));
                }
                if ($campo === 'renavam') {
                    $right = min($right, $label['x'] + max($label['width'] * 2, $label['height'] * 24));
                }
                $top = $label['y'] + $label['height'];
                $bottom = $top + $label['height'] * ($campo === 'observacao' ? 25 : 8);
                foreach ($labels as $outro) {
                    if ($outro['y'] > $top && $outro['x'] >= $left && $outro['x'] < $right) {
                        $bottom = min($bottom, $outro['y']);
                    }
                }
                $regioes[] = ['campo' => $campo, 'left' => max(0, $left), 'top' => $top, 'width' => $right - max(0, $left), 'height' => $bottom - $top - 1];
            }
        }

        return array_filter($regioes, fn ($r) => $r['width'] > 0 && $r['height'] > 0);
    }

    public function interpretar(array $paginas): array
    {
        $candidatos = [];
        foreach ($paginas as $items) {
            if (! $items) {
                continue;
            }
            foreach ($this->regioes($items) as $regiao) {
                ['campo' => $campo, 'left' => $left, 'top' => $top, 'width' => $width, 'height' => $height] = $regiao;
                $valores = array_filter($items, fn ($i) => $i['y'] >= $top && $i['y'] < $top + $height && $i['x'] >= $left && $i['x'] < $left + $width && ($i['confidence'] ?? 100) >= 65 && preg_match('/[\p{L}\d]/u', $i['text']));
                $textos = array_map(fn ($linha) => implode(' ', array_column($linha, 'text')), $this->linhas($valores));
                foreach ($campo === 'observacao' ? [implode("\n", $textos)] : $textos as $texto) {
                    $valor = $this->valor($campo, $texto);
                    if ($valor !== null) {
                        $candidatos[$campo][$valor] = $valor;
                        break;
                    }
                }
            }
        }

        foreach ($candidatos as $campo => $valores) {
            if (count($valores) > 1) {
                $this->conflitos[$campo] = true;
            }
        }

        return array_map(fn ($valores) => array_values($valores)[0], array_filter($candidatos, fn ($valores) => count($valores) === 1));
    }

    private function valor(string $campo, string $texto): ?string
    {
        $normal = $this->normalizar($texto);
        $id = preg_replace('/[^A-Z0-9]/', '', $normal);
        $digitos = preg_replace('/\D/', '', $texto);

        return match ($campo) {
            'placa' => preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $id) ? $id : null,
            'renavam' => preg_match('/^\d{11}$/', $digitos) ? $digitos : null,
            'chassi' => preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $id) ? $id : null,
            'cpf_cnpj_proprietario' => preg_match('/^(\d{11}|\d{14})$/', $digitos) ? $digitos : null,
            'numero_crv', 'codigo_seguranca' => preg_match('/^\d{1,20}$/', preg_replace('/[.\s\/-]/', '', $texto)) ? $digitos : null,
            'exercicio', 'ano_fabricacao', 'ano_modelo' => preg_match('/\b(19|20)\d{2}\b/', $texto, $m) ? $m[0] : null,
            'data_emissao' => preg_match('/\b(\d{2})[.\/-](\d{2})[.\/-](\d{4})\b/', $texto, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? "$m[3]-$m[2]-$m[1]" : null,
            'uf' => preg_match('/\b(AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)$/', $normal, $m) ? $m[1] : null,
            'categoria_veiculo' => preg_match('/\b(MOTOCICLETA|MOTONETA|CICLOMOTOR|TRICICLO)\b/', $normal) ? 'moto' : (preg_match('/\b(AUTOMOVEL|CAMIONETA|CAMINHONETE|UTILITARIO)\b/', $normal) ? 'carro' : null),
            default => trim($texto) !== '' && ! preg_match('/^[*\s]+$/', $texto) ? trim($texto) : null,
        };
    }
}
