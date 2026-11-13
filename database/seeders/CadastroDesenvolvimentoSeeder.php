<?php

namespace Database\Seeders;

use App\Enums\TipoDocumentoMotorista;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\Veiculo;
use App\Services\ArmazenarAnexoMotoristaService;
use App\Services\AtualizarSituacaoMotoristaService;
use App\Services\CrlvVeiculoService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Contas e documentos fictícios para desenvolvimento. Senha padrão: password. */
class CadastroDesenvolvimentoSeeder extends Seeder
{
    private bool $preservarExistentes = false;

    public function run(bool $preservarExistentes = false): void
    {
        $this->preservarExistentes = $preservarExistentes;
        DB::transaction(function (): void {
            // cada app tem a própria conta, como o gestor
            [$busca, $conta] = $this->conta('Passageiro de teste', 'passageiro@example.com', '12345678909', '69999990002');
            Passageiro::firstOrCreate($busca, [...$conta, 'status' => 'ativo']);

            $gestor = Gestor::firstOrCreate(['email' => 'admin@example.com'] + ($this->preservarExistentes ? [] : ['cpf' => '52998224725']), [
                'name' => 'Gestor de teste', 'telefone' => '69999990003',
                'data_nascimento' => '1990-01-15', 'password' => 'password',
            ]);
            if ($gestor->wasRecentlyCreated) {
                $gestor->forceFill(['perfil_acesso' => 'admin'])->save();
            }

            $habilitacao = [
                'numero_registro' => '12345678901', 'cnh_categoria' => 'AB',
                'primeira_habilitacao' => '2012-11-25', 'data_emissao' => now()->toDateString(),
                'cnh_expiracao' => now()->addYears(5)->toDateString(),
                'ear' => true, 'observacao' => 'EAR',
            ];
            [$busca, $conta] = $this->conta('Motorista de teste', 'motorista@example.com', '11144477735', '69999990001');
            $motorista = Motorista::firstOrCreate($busca, [...$conta, ...$habilitacao, 'status' => 'pendente']);
            $cnh = [
                'nome' => $motorista->name, 'cpf' => $motorista->cpf,
                'data_nascimento' => $motorista->data_nascimento?->toDateString(),
                ...$habilitacao,
            ];

            $crlv = [
                'placa' => 'AAA0A01', 'renavam' => '12345678901', 'chassi' => '9BWZZZ377VT004251',
                'numero_crv' => '12345678901', 'codigo_seguranca' => '98765432100',
                'exercicio' => now()->year, 'data_emissao' => now()->toDateString(),
                'nome_proprietario' => $motorista->name, 'cpf_cnpj_proprietario' => $motorista->cpf,
                'marca_modelo' => 'VOLKSWAGEN/GOL', 'cor' => 'PRETA',
                'ano_fabricacao' => 2024, 'ano_modelo' => 2024,
                'categoria' => 'PARTICULAR', 'categoria_veiculo' => 'carro', 'uf' => 'RO',
                'observacao' => 'Veículo fictício para desenvolvimento.',
            ];
            $veiculo = Veiculo::firstOrCreate(['placa' => $crlv['placa'], 'renavam' => $crlv['renavam']], [
                'marca' => 'VOLKSWAGEN', 'modelo' => 'GOL', 'chassi' => $crlv['chassi'],
                'cor' => $crlv['cor'], 'ano_fabricacao' => $crlv['ano_fabricacao'], 'ano_modelo' => $crlv['ano_modelo'],
                'numero_crv' => $crlv['numero_crv'], 'codigo_seguranca' => $crlv['codigo_seguranca'],
                'exercicio' => $crlv['exercicio'], 'data_emissao' => $crlv['data_emissao'],
                'nome_proprietario' => $crlv['nome_proprietario'], 'cpf_cnpj_proprietario' => $crlv['cpf_cnpj_proprietario'],
                'marca_modelo' => $crlv['marca_modelo'], 'categoria_crlv' => $crlv['categoria'],
                'categoria' => 'carro', 'uf' => 'RO', 'status' => 'em_analise', 'observacao' => $crlv['observacao'],
            ]);
            MotoristaVeiculo::firstOrCreate(['motorista_id' => $motorista->id, 'veiculo_id' => $veiculo->id]);

            $nadaConsta = [
                'numero_certidao' => 'TESTE-0001', 'orgao_emissor' => 'Emissor fictício para testes',
                'nome' => $cnh['nome'], 'cpf' => $cnh['cpf'],
                'data_nascimento' => $cnh['data_nascimento'],
                'nome_pai' => 'Pai de teste', 'nome_mae' => 'Mãe de teste',
                'data_emissao' => now()->toDateString(), 'hora_emissao' => now()->format('H:i'),
                'data_validade' => now()->addDays(90)->toDateString(),
                'resultado' => 'NÃO CONSTA - documento fictício para testes',
            ];

            $this->documento($motorista, TipoDocumentoMotorista::CNH, $cnh);
            $this->documento($motorista, TipoDocumentoMotorista::CRLV, $crlv, $veiculo);
            $this->documento($motorista, TipoDocumentoMotorista::NADA_CONSTA, $nadaConsta);
            $this->documento($motorista, TipoDocumentoMotorista::SEGURO_OBRIGATORIO, null);

            if (! $this->preservarExistentes || $veiculo->wasRecentlyCreated || $motorista->wasRecentlyCreated) {
                app(CrlvVeiculoService::class)->sincronizarStatus($veiculo);
                app(AtualizarSituacaoMotoristaService::class)->executar($motorista);
            }
        });
    }

    /**
     * Como procurar a conta de teste e os dados dela, iguais nos dois apps.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function conta(string $nome, string $email, string $cpf, string $telefone): array
    {
        return [
            ['email' => $email] + ($this->preservarExistentes ? [] : ['cpf' => $cpf]),
            ['name' => $nome, 'cpf' => $cpf, 'telefone' => $telefone, 'data_nascimento' => '1992-11-21', 'password' => 'password'],
        ];
    }

    private function documento(Motorista $motorista, TipoDocumentoMotorista $tipo, ?array $informacoes, ?Veiculo $veiculo = null): void
    {
        $diretorio = ArmazenarAnexoMotoristaService::DIRETORIO;
        $arquivo = 'desenvolvimento-'.$motorista->cpf.'-'.$tipo->value.'.pdf';
        $path = $diretorio.'/'.$arquivo;
        $documento = MotoristaDocumento::firstOrCreate([
            'motorista_id' => $motorista->id, 'tipo_documento' => $tipo, 'ordem' => 0, 'path' => $path,
        ], [
            'veiculo_id' => $veiculo?->id, 'informacoes_complementares' => $informacoes,
            'name' => $arquivo, 'type' => 'pdf', 'mime_type' => 'application/pdf',
            'size' => strlen($this->pdf($motorista, $tipo, $informacoes)), 'status' => 'aprovado',
            'url' => rtrim(config('app.url'), '/').'/'.$path,
        ]);

        // Reexecutar o seed não altera decisões da gestão nem arquivos existentes.
        if (! Storage::disk($diretorio)->exists($arquivo)) {
            $pdf = $this->pdf($motorista, $tipo, $documento->informacoes_complementares);
            Storage::disk($diretorio)->put($arquivo, $pdf);
            $documento->update(['size' => strlen($pdf)]);
        }
    }

    /** Gera uma página PDF simples, sem depender de documentos pessoais reais. */
    private function pdf(Motorista $motorista, TipoDocumentoMotorista $tipo, ?array $informacoes): string
    {
        $linhas = [
            'DOCUMENTO FICTÍCIO PARA DESENVOLVIMENTO - SEM VALIDADE',
            'Tipo: '.$tipo->value, 'Motorista: '.$motorista->nome, 'CPF: '.$motorista->cpf,
            '',
        ];
        foreach ($informacoes ?? [] as $campo => $valor) {
            $linhas[] = str_replace('_', ' ', $campo).': '.(is_bool($valor) ? ($valor ? 'Sim' : 'Não') : $valor);
        }
        $conteudo = "BT /F1 10 Tf 50 790 Td 18 TL\n";
        foreach ($linhas as $linha) {
            $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $linha);
            $texto = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $texto);
            $conteudo .= '('.$texto.") Tj T*\n";
        }
        $conteudo .= "ET\n";
        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length '.strlen($conteudo)." >>\nstream\n".$conteudo.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objetos as $indice => $objeto) {
            $offsets[] = strlen($pdf);
            $pdf .= ($indice + 1)." 0 obj\n".$objeto."\nendobj\n";
        }
        $inicioXref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$inicioXref."\n%%EOF\n";
    }
}
