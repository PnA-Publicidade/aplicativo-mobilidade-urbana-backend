<?php

use App\Enums\TipoDocumentoMotorista as Tipo;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\User;
use App\Services\TextoDocumentoService;
use App\Services\VerificacaoTipoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

const TEXTO_CNH = 'REPUBLICA FEDERATIVA DO BRASIL CARTEIRA NACIONAL DE HABILITAÇÃO NOME FILIAÇÃO VALIDADE REGISTRO CAT. HAB B';
const TEXTO_CRLV = 'CERTIFICADO DE REGISTRO E LICENCIAMENTO DE VEÍCULO EXERCÍCIO 2026 PLACA ABC1D23 RENAVAM 12345678901 CHASSI 9BWZZZ377VT004251 COMBUSTÍVEL GASOLINA DADOS DO SEGURO DPVAT';
const TEXTO_NADA_CONSTA = 'SECRETARIA DE SEGURANÇA PÚBLICA CERTIDÃO NEGATIVA DE ANTECEDENTES CRIMINAIS NADA CONSTA em nome de Fulano';
const TEXTO_SEGURO = 'SPVAT SEGURO OBRIGATÓRIO DE DANOS PESSOAIS APÓLICE SEGURADORA LÍDER VIGÊNCIA 2026';

function lendo(?string $texto): void
{
    app()->instance(TextoDocumentoService::class, new class($texto) extends TextoDocumentoService
    {
        public function __construct(private ?string $texto) {}

        public function extrair(UploadedFile $arquivo, bool $imagem = false): ?string
        {
            return $this->texto;
        }
    });
}

it('reconhece cada tipo de documento e recusa os trocados', function (string $texto, Tipo $correto) {
    $servico = app(VerificacaoTipoDocumentoService::class);

    foreach (Tipo::cases() as $tipo) {
        $erro = $servico->erro($texto, $tipo);
        if ($tipo === $correto) {
            expect($erro)->toBeNull();
        } else {
            expect($erro)->toContain('parece ser');
        }
    }
})->with([
    'CNH' => [TEXTO_CNH, Tipo::CNH],
    'CRLV' => [TEXTO_CRLV, Tipo::CRLV],
    'Nada consta' => [TEXTO_NADA_CONSTA, Tipo::NADA_CONSTA],
    'Seguro' => [TEXTO_SEGURO, Tipo::SEGURO_OBRIGATORIO],
]);

it('CNH digital: o texto do PDF é só o rodapé da assinatura e os dados estão na imagem da página', function () {
    app()->instance(TextoDocumentoService::class, new class extends TextoDocumentoService
    {
        public function extrair(UploadedFile $arquivo, bool $imagem = false): ?string
        {
            return $imagem
                ? 'CARTEIRA NACIONAL DE HABILITACAO NOME FILIACAO CAT. HAB. B VALIDADE'
                : 'DOCUMENTO ASSINADO COM CERTIFICADO DIGITAL EM CONFORMIDADE COM A MEDIDA PROVISORIA SUA VALIDADE PODERA SER CONFIRMADA';
        }
    });
    $pdf = pdfDeTexto(['CNH digital de teste']);
    $servico = app(VerificacaoTipoDocumentoService::class);

    $servico->validar($pdf, Tipo::CNH);
    expect(fn () => $servico->validar($pdf, Tipo::NADA_CONSTA))->toThrow(ValidationException::class);
});

it('recusa arquivo ilegível ou sem relação com documento, mas não o verso sem título', function () {
    $servico = app(VerificacaoTipoDocumentoService::class);

    expect($servico->erro('xx', Tipo::CNH))->toContain('Não conseguimos ler')
        ->and($servico->erro('foto de um gato em cima do sofá da sala de casa', Tipo::CNH))->toContain('Não reconhecemos')
        ->and($servico->erro('OBSERVAÇÕES LOCAL ASSINATURA DO EMISSOR DATA EMISSÃO', Tipo::CNH, false))->toBeNull()
        ->and($servico->erro(TEXTO_CRLV, Tipo::CNH, false))->toContain('parece ser um CRLV');
});

it('o envio de um CRLV no campo da CNH é recusado e não substitui a CNH atual', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $this->actingAs($usuario, 'jwt')->postJson('/api/motorista/cadastro/aprovar-dev')->assertOk();
    $motorista = Motorista::where('user_id', $usuario->id)->firstOrFail();
    // CNH vencida: é quando o reenvio é liberado
    MotoristaDocumento::where('motorista_id', $motorista->id)->update(['created_at' => now()->subYears(2)]);
    $motorista->update(['cnh_expiracao' => now()->subDay()->toDateString()]);
    $antes = MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'cnh')->count();

    lendo(TEXTO_CRLV);
    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('crlv.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.arquivo.0', 'O arquivo enviado parece ser um CRLV, e não uma CNH. Envie o documento correto.');

    expect(MotoristaDocumento::where('motorista_id', $motorista->id)->where('tipo_documento', 'cnh')->count())->toBe($antes)
        ->and($motorista->fresh()->status)->toBe('ativo');

    lendo(TEXTO_CNH);
    $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('cnh.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated();
});

it('a mesma conferência vale para o Nada consta e para o verso da CNH', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $envio = fn (string $tipo, array $extra = []) => $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => $tipo,
        'arquivo' => UploadedFile::fake()->create('arquivo.pdf', 100, 'application/pdf'),
        ...$extra,
    ], ['Accept' => 'application/json']);

    lendo(TEXTO_CNH);
    $envio('nada_consta')->assertUnprocessable()->assertJsonPath('errors.arquivo.0', fn ($m) => str_contains($m, 'parece ser uma CNH'));
    lendo(TEXTO_SEGURO);
    $envio('nada_consta')->assertUnprocessable();
    lendo(TEXTO_NADA_CONSTA);
    $envio('nada_consta')->assertCreated();

    lendo(TEXTO_CRLV);
    $envio('cnh', ['arquivo' => UploadedFile::fake()->image('frente.png'), 'arquivo_verso' => UploadedFile::fake()->image('verso.png')])
        ->assertUnprocessable();
});

it('o Seguro entra sem conferência, no app e na gestão', function () {
    Storage::fake('motorista_documentos_anexos');
    $usuario = User::factory()->create();
    $motorista = Motorista::create(['user_id' => $usuario->id, 'status' => 'pendente']);
    $arquivo = fn () => UploadedFile::fake()->create('seguro.pdf', 100, 'application/pdf');

    // outro documento, ou sem nenhuma leitura disponível: nada disso importa
    foreach ([TEXTO_CNH, null] as $texto) {
        lendo($texto);
        $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
            'tipo_documento' => 'seguro_obrigatorio', 'arquivo' => $arquivo(),
        ], ['Accept' => 'application/json'])->assertCreated();
        MotoristaDocumento::query()->update(['status' => 'reprovado']);
    }
    $this->actingAs(User::factory()->create(), 'jwt')->post('/api/motorista-documentos', [
        'motorista_id' => $motorista->id, 'tipo_documento' => 'seguro_obrigatorio', 'arquivo' => $arquivo(),
    ], ['Accept' => 'application/json'])->assertCreated();
});

it('sem leitura disponível o envio é recusado', function () {
    Storage::fake('motorista_documentos_anexos');
    lendo(null);

    $this->actingAs(User::factory()->create(), 'jwt')->post('/api/motorista/cadastro/documentos', [
        'tipo_documento' => 'cnh',
        'arquivo' => UploadedFile::fake()->create('qualquer.pdf', 100, 'application/pdf'),
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.arquivo.0', 'Não foi possível verificar o documento agora. Tente novamente em alguns instantes.');

    expect(MotoristaDocumento::count())->toBe(0);
});

function pdfDeTexto(array $linhas): UploadedFile
{
    $conteudo = "BT /F1 14 Tf 40 780 Td 18 TL\n".implode('', array_map(fn ($l) => "($l) Tj T*\n", $linhas)).'ET';
    $objetos = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length '.strlen($conteudo)." >>\nstream\n$conteudo\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    $pdf = "%PDF-1.4\n";
    $posicoes = [];
    foreach ($objetos as $i => $objeto) {
        $posicoes[] = strlen($pdf);
        $pdf .= ($i + 1)." 0 obj\n$objeto\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
    foreach ($posicoes as $posicao) {
        $pdf .= sprintf("%010d 00000 n \n", $posicao);
    }
    $pdf .= "trailer\n<< /Size ".(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    $caminho = sys_get_temp_dir().'/pdf-'.uniqid().'.pdf';
    file_put_contents($caminho, $pdf);

    return new UploadedFile($caminho, 'orcamento.pdf', 'application/pdf', null, true);
}

it('com a leitura real, um orçamento qualquer não entra como CNH, Nada consta nem CRLV', function () {
    if (trim((string) shell_exec('which tesseract pdftotext 2>/dev/null')) === '') {
        $this->markTestSkipped('Sem pdftotext/tesseract neste ambiente.');
    }
    Storage::fake('motorista_documentos_anexos');
    app()->instance(TextoDocumentoService::class, new TextoDocumentoService);
    $usuario = User::factory()->create();
    $motorista = Motorista::create(['user_id' => $usuario->id, 'status' => 'pendente']);
    $orcamento = ['PEN6 MARKETING Rua Brasilia 2930', 'Fornecedor RD GRAFICA E COMUNICACAO VISUAL', 'Cliente ALERO ASSEMBLEIA LEGISLATIVA', 'PRODUCAO E EXIBICAO OUT DOOR PORTO VELHO', 'HONORARIOS VALOR FINAL TOTAL 0,00'];

    foreach (['cnh', 'nada_consta', 'crlv'] as $tipo) {
        $this->actingAs($usuario, 'jwt')->post('/api/motorista/cadastro/documentos', [
            'tipo_documento' => $tipo, 'arquivo' => pdfDeTexto($orcamento),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo');
    }
    // o mesmo envio feito pela gestão, em nome do motorista
    $this->actingAs(User::factory()->create(), 'jwt')->post('/api/motorista-documentos', [
        'motorista_id' => $motorista->id, 'tipo_documento' => 'cnh', 'arquivo' => pdfDeTexto($orcamento),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('arquivo');
    expect(MotoristaDocumento::count())->toBe(0);
});
