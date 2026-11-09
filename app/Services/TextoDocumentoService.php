<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/** Lê o texto de um PDF ou foto de documento, sem guardar nem registrar o conteúdo. */
class TextoDocumentoService
{
    private const PRAZO_SEGUNDOS = 20;

    /**
     * Texto lido, ou null quando a leitura não está disponível (ferramentas ausentes, tempo esgotado).
     * Com `$imagem`, um PDF é lido como imagem mesmo que tenha texto: a CNH digital, por exemplo,
     * traz só o rodapé da assinatura como texto e os dados dentro de uma imagem.
     */
    public function extrair(UploadedFile $arquivo, bool $imagem = false): ?string
    {
        $temporario = sys_get_temp_dir().'/documento-'.Str::uuid();
        try {
            mkdir($temporario, 0700);
            $origem = $arquivo->getRealPath();
            if ($arquivo->getMimeType() === 'application/pdf') {
                if (! $imagem) {
                    $texto = $this->executar(['pdftotext', '-f', '1', '-l', '3', $origem, '-']);
                    if (mb_strlen(trim($texto)) >= 40) {
                        return $texto;
                    }
                }
                // PDF digitalizado: só a primeira página, em resolução limitada.
                $this->executar(['pdftoppm', '-f', '1', '-l', '1', '-scale-to', '2400', '-png', $origem, $temporario.'/pagina']);
                $origem = (glob($temporario.'/pagina-*.png') ?: [null])[0];
                if ($origem === null) {
                    return null;
                }
            }

            return $this->ocr($origem, $temporario);
        } catch (Throwable $exception) {
            // Nunca registrar o conteúdo nem o caminho do documento.
            Log::warning('Leitura do texto do documento indisponível.', ['erro' => $exception::class]);

            return null;
        } finally {
            foreach (glob($temporario.'/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_dir($temporario)) {
                rmdir($temporario);
            }
        }
    }

    private function ocr(string $origem, string $diretorio): string
    {
        $imagem = new \Imagick($origem.'[0]');
        try {
            if (method_exists($imagem, 'autoOrient')) {
                $imagem->autoOrient();
            }
            $imagem->transformImageColorspace(\Imagick::COLORSPACE_GRAY);
            $imagem->resizeImage(2400, 3200, \Imagick::FILTER_LANCZOS, 1, true);
            $imagem->normalizeImage();
            $imagem->setImageFormat('png');
            $destino = $diretorio.'/tratada.png';
            $imagem->writeImage($destino);
        } finally {
            $imagem->clear();
        }

        return $this->executar(['tesseract', $destino, 'stdout', '-l', 'por', '--psm', '11']);
    }

    private function executar(array $comando): string
    {
        $processo = new Process($comando, timeout: self::PRAZO_SEGUNDOS);
        $processo->mustRun();

        return $processo->getOutput();
    }
}
