<?php

namespace App\Services;

use App\Enums\TipoDocumentoMotorista;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Confere se o arquivo enviado é mesmo do tipo escolhido (uma CNH no campo do
 * CRLV, por exemplo). Se a leitura do texto não estiver disponível, o envio é
 * recusado. O Seguro não passa por aqui: fica para a análise da gestão, e seus
 * sinais servem só para recusá-lo no campo dos outros documentos.
 */
class VerificacaoTipoDocumentoService
{
    /** Pontos mínimos para reconhecer um tipo de documento. */
    private const MINIMO = 3;

    /** Um sinal vale isto ou mais quando é próprio do documento; palavras soltas ("validade", "registro") valem 1 e sozinhas não bastam. */
    private const CARACTERISTICO = 2;

    /** Menos que isso é um arquivo ilegível (foto escura, tremida ou em branco). */
    private const TEXTO_MINIMO = 25;

    /** @var array<string, array<string, int>> expressão (sobre o texto sem acento e em maiúsculas) => pontos */
    private const SINAIS = [
        'cnh' => [
            '/CARTEIRA NACIONAL DE HABILITACAO/' => 3,
            '/PERMISSAO PARA DIRIGIR/' => 3,
            '/DRIVER LICENSE/' => 2,
            '/HABILITACAO/' => 1,
            '/FILIACAO/' => 1,
            '/CAT\.? ?HAB/' => 2,
            '/(1A|PRIMEIRA) HABILITACAO/' => 2,
            '/VALIDADE/' => 1,
            '/REGISTRO/' => 1,
        ],
        'crlv' => [
            '/CERTIFICADO DE REGISTRO E LICENCIAMENTO/' => 3,
            '/\bCRLV\b/' => 2,
            '/RENAVAM/' => 2,
            '/CHASSI/' => 1,
            '/\bPLACA\b/' => 1,
            '/EXERCICIO/' => 1,
            '/COMBUSTIVEL/' => 1,
            '/ESPECIE/' => 1,
        ],
        'nada_consta' => [
            '/ANTECEDENTES CRIMINAIS/' => 3,
            '/NADA CONSTA/' => 3,
            '/CERTIDAO NEGATIVA/' => 3,
            '/CERTIDAO DE ANTECEDENTES/' => 3,
            '/CERTIDAO/' => 1,
            '/POLICIA/' => 1,
            '/SECRETARIA DE (ESTADO DE )?SEGURANCA/' => 1,
        ],
        'seguro_obrigatorio' => [
            '/SEGURO OBRIGATORIO/' => 3,
            '/SPVAT/' => 3,
            '/BILHETE DE SEGURO/' => 3,
            '/SEGURO DE DANOS PESSOAIS/' => 3,
            '/DPVAT/' => 2,
            '/APOLICE/' => 1,
            '/SEGURADORA/' => 1,
            '/VIGENCIA/' => 1,
        ],
    ];

    private const ROTULOS = [
        'cnh' => 'uma CNH',
        'crlv' => 'um CRLV',
        'nada_consta' => 'um Nada consta',
        'seguro_obrigatorio' => 'um seguro',
    ];

    public function __construct(private TextoDocumentoService $leitor) {}

    /**
     * @throws ValidationException quando o arquivo não é do tipo escolhido
     */
    public function validar(UploadedFile $arquivo, TipoDocumentoMotorista $esperado, string $campo = 'arquivo'): void
    {
        if ($esperado === TipoDocumentoMotorista::SEGURO_OBRIGATORIO) {
            return;
        }

        $texto = $this->leitor->extrair($arquivo);
        if ($texto === null) {
            // sem conseguir ler, não há como saber o que é: nada entra sem conferência
            $mensagem = 'Não foi possível verificar o documento agora. Tente novamente em alguns instantes.';
            if (config('app.debug') && $this->leitor->ultimoErro !== null) {
                $mensagem .= ' [debug] '.$this->leitor->ultimoErro;
            }
            throw ValidationException::withMessages([$campo => $mensagem]);
        }
        // PDF com texto mas sem nada reconhecível (a CNH digital traz só o rodapé da assinatura):
        // os dados estão numa imagem, então lê a página também como imagem.
        if ($arquivo->getMimeType() === 'application/pdf' && max($this->pontos($this->normalizar($texto))) < self::MINIMO) {
            $texto .= "\n".($this->leitor->extrair($arquivo, true) ?? '');
        }
        $erro = $this->erro($texto, $esperado, $campo === 'arquivo');
        if ($erro !== null) {
            throw ValidationException::withMessages([$campo => $erro]);
        }
    }

    /** Mensagem para o motorista, ou null quando o texto confere com o tipo escolhido. */
    public function erro(string $texto, TipoDocumentoMotorista $esperado, bool $exigirReconhecimento = true): ?string
    {
        $texto = $this->normalizar($texto);
        if (mb_strlen($texto) < self::TEXTO_MINIMO) {
            return $exigirReconhecimento
                ? 'Não conseguimos ler o arquivo. Envie uma foto nítida, bem iluminada, ou o PDF do documento.'
                : null;
        }

        $pontos = $this->pontos($texto);
        $doEsperado = $pontos[$esperado->value];
        $maior = max($pontos);
        if ($doEsperado >= self::MINIMO && $doEsperado >= $maior) {
            return null;
        }

        $outro = array_search($maior, $pontos, true);
        if ($maior >= self::MINIMO && $outro !== $esperado->value) {
            return 'O arquivo enviado parece ser '.self::ROTULOS[$outro].', e não '.self::ROTULOS[$esperado->value].'. Envie o documento correto.';
        }

        // verso da CNH e outras páginas sem título só são recusados quando são de outro documento
        return $exigirReconhecimento
            ? 'Não reconhecemos o arquivo como '.self::ROTULOS[$esperado->value].'. Envie uma foto nítida ou o PDF do documento.'
            : null;
    }

    private function normalizar(string $texto): string
    {
        return trim(preg_replace('/\s+/', ' ', strtoupper(Str::ascii($texto))));
    }

    /** @return array<string, int> */
    public function pontos(string $texto): array
    {
        return array_map(function (array $sinais) use ($texto): int {
            $total = 0;
            $caracteristico = false;
            foreach ($sinais as $expressao => $valor) {
                if (preg_match($expressao, $texto) === 1) {
                    $total += $valor;
                    $caracteristico = $caracteristico || $valor >= self::CARACTERISTICO;
                }
            }

            return $caracteristico ? $total : 0;
        }, self::SINAIS);
    }
}
