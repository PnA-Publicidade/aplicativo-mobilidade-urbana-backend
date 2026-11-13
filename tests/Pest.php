<?php

use App\Models\Gestor;
use App\Services\TextoDocumentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Os testes enviam arquivos de mentira: a leitura devolve um texto com a cara
        // do documento pedido. Os testes de conferência trocam esta leitura.
        app()->instance(TextoDocumentoService::class, new class extends TextoDocumentoService
        {
            public function extrair(UploadedFile $arquivo, bool $imagem = false): ?string
            {
                return match (request()->input('tipo_documento')) {
                    'cnh' => 'CARTEIRA NACIONAL DE HABILITACAO NOME FILIACAO VALIDADE REGISTRO',
                    'nada_consta' => 'CERTIDAO NEGATIVA DE ANTECEDENTES CRIMINAIS NADA CONSTA',
                    'seguro_obrigatorio' => 'SPVAT SEGURO OBRIGATORIO DE DANOS PESSOAIS APOLICE',
                    default => 'CERTIFICADO DE REGISTRO E LICENCIAMENTO DE VEICULO RENAVAM CHASSI PLACA',
                };
            }
        });
    })
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

// nos testes o app é o mesmo entre os pedidos: o guard e o JWT guardam o token anterior
// (e as claims extras, como a perfil=gestao do token de gestor)
function novoPedido(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt')->unsetToken()->claims([]);
}

/**
 * Token real de um gestor: a gestão confere o JWT (claim perfil=gestao), não
 * basta actingAs. O perfil de acesso decide as permissões (PermissoesGestaoService).
 */
function tokenDeGestor(string $perfil = 'admin'): string
{
    $gestor = Gestor::forceCreate([
        'name' => 'Gestor '.$perfil, 'email' => fake()->unique()->safeEmail(),
        'cpf' => fake()->unique()->numerify('###########'), 'data_nascimento' => '1985-01-01',
        'password' => 'senha-de-teste', 'perfil_acesso' => $perfil,
    ]);
    $token = auth('gestores')->claims(['perfil' => 'gestao'])->login($gestor);
    novoPedido();

    return $token;
}

/**
 * Dados que o envio de CRLV e de Nada Consta exige; os outros tipos não têm campos.
 *
 * @return array<string, array<string, mixed>>
 */
function informacoesDoDocumento(string $tipo): array
{
    return match ($tipo) {
        'crlv' => ['informacoes_complementares' => [
            'placa' => 'ABC1D23',
            'renavam' => '12345678901',
            'chassi' => '9BWZZZ377VT004251',
            'exercicio' => 2025,
            'cpf_cnpj_proprietario' => '12345678909',
            'nome_proprietario' => 'Proprietário Teste',
            'marca_modelo' => 'VW/GOL',
            'categoria_veiculo' => 'carro',
            'cor' => 'Prata',
            'ano_fabricacao' => 2020,
            'ano_modelo' => 2020,
            'uf' => 'RO',
        ]],
        'nada_consta' => ['informacoes_complementares' => [
            'numero_certidao' => 'NC-2026/001',
            'orgao_emissor' => 'Polícia Federal',
            'nome' => 'Motorista Teste',
            'cpf' => '12345678909',
            'data_emissao' => '2026-10-01',
            'data_validade' => '2026-12-31',
            'resultado' => 'Nada consta.',
        ]],
        'seguro_obrigatorio' => ['informacoes_complementares' => ['data_validade' => now()->addYear()->toDateString()]],
        default => [],
    };
}
