<?php

namespace Database\Seeders;

use App\Enums\TipoDocumentoMotorista;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Massa para simular a cidade de verdade: 50 mil passageiros ativos, prontos
 * para pedir corrida, e 4 mil motoristas liberados, com veículo aprovado,
 * documentos em dia e online, espalhados por Porto Velho.
 *
 *   php artisan db:seed --class=MassaSimulacaoSeeder
 *
 * Todas as contas usam o domínio @simulacao.test e a senha "simulacao". Pode
 * rodar de novo: só completa o que falta. Para apagar a massa, veja `limpar()`.
 *
 * O motorista fica online com `visto_em` de agora, e o servidor derruba quem
 * não manda a posição há mais de 90 s. Para manter a frota online durante uma
 * simulação, chame `MassaSimulacaoSeeder::renovarOnline()` de tempos em tempos.
 */
class MassaSimulacaoSeeder extends Seeder
{
    // quantidades da massa; os testes diminuem para rodar rápido
    public static int $passageiros = 50_000;

    public static int $motoristas = 4_000;

    public const DOMINIO = 'simulacao.test';

    public const SENHA = 'simulacao';

    private const LOTE = 1_000;

    private const IBGE_PORTO_VELHO = 1100205;

    // centro de Porto Velho e o espalhamento da frota (~12 km)
    private const CENTRO_LATITUDE = -8.7619;

    private const CENTRO_LONGITUDE = -63.9039;

    private const ESPALHAMENTO = 0.11;

    private const NOMES = [
        'Ana', 'Bruno', 'Carlos', 'Daniela', 'Eduardo', 'Fernanda', 'Gabriel', 'Helena', 'Igor', 'Juliana',
        'Kleber', 'Larissa', 'Marcos', 'Natália', 'Otávio', 'Patrícia', 'Rafael', 'Sabrina', 'Thiago', 'Vanessa',
        'Wesley', 'Aline', 'Diego', 'Elaine', 'Felipe', 'Gisele', 'Henrique', 'Isabela', 'João', 'Karina',
        'Lucas', 'Mariana', 'Nelson', 'Paula', 'Renato', 'Simone', 'Tatiane', 'Victor', 'Amanda', 'Leandro',
    ];

    private const SOBRENOMES = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 'Pereira', 'Lima', 'Gomes',
        'Costa', 'Ribeiro', 'Martins', 'Carvalho', 'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa',
        'Rocha', 'Dias', 'Nascimento', 'Andrade', 'Moreira', 'Nunes', 'Marques', 'Machado', 'Mendes', 'Freitas',
    ];

    private const MODELOS_CARRO = [
        ['Fiat', 'Argo'], ['Chevrolet', 'Onix'], ['Volkswagen', 'Gol'], ['Hyundai', 'HB20'], ['Renault', 'Kwid'],
        ['Fiat', 'Mobi'], ['Toyota', 'Etios'], ['Honda', 'City'], ['Chevrolet', 'Prisma'], ['Nissan', 'Versa'],
    ];

    private const MODELOS_MOTO = [['Honda', 'CG 160'], ['Yamaha', 'Factor 150'], ['Honda', 'Biz 125'], ['Honda', 'Pop 110']];

    private const CORES = ['Branco', 'Preto', 'Prata', 'Cinza', 'Vermelho', 'Azul'];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('A massa de simulação não roda em produção.');

            return;
        }

        $cidadeId = DB::table('cidades')->where('ibge', self::IBGE_PORTO_VELHO)->value('id');

        if ($cidadeId === null) {
            $this->command?->error('Porto Velho não está cadastrada: rode as migrations antes.');

            return;
        }

        mt_srand(2026);
        $senha = Hash::make(self::SENHA);

        $this->passageiros($senha);
        $this->motoristas($senha, (int) $cidadeId);

        $this->command?->info(sprintf(
            'Massa pronta: %d passageiros ativos e %d motoristas online (login por e-mail, senha "%s").',
            DB::table('passageiros')->where('email', 'like', '%@'.self::DOMINIO)->count(),
            DB::table('motoristas')->where('email', 'like', '%@'.self::DOMINIO)->count(),
            self::SENHA,
        ));
    }

    /** Põe todos os motoristas da massa online de novo, com a posição de agora. */
    public static function renovarOnline(): int
    {
        return DB::table('status_buscas')
            ->whereIn('motorista_id', DB::table('motoristas')->where('email', 'like', '%@'.self::DOMINIO)->select('id'))
            ->update(['disponivel' => true, 'visto_em' => now(), 'updated_at' => now()]);
    }

    /** Apaga de vez as contas, veículos e documentos da massa. */
    public static function limpar(): void
    {
        DB::transaction(function () {
            $motoristas = DB::table('motoristas')->where('email', 'like', '%@'.self::DOMINIO)->pluck('id');
            $veiculos = DB::table('motorista_veiculos')->whereIn('motorista_id', $motoristas)->pluck('veiculo_id');

            foreach ($motoristas->chunk(self::LOTE) as $ids) {
                DB::table('status_buscas')->whereIn('motorista_id', $ids)->delete();
                DB::table('motorista_documentos_anexos')->whereIn('motorista_id', $ids)->delete();
                DB::table('motorista_veiculos')->whereIn('motorista_id', $ids)->delete();
                DB::table('motoristas')->whereIn('id', $ids)->delete();
            }

            foreach ($veiculos->chunk(self::LOTE) as $ids) {
                DB::table('veiculos')->whereIn('id', $ids)->delete();
            }

            DB::table('passageiros')->where('email', 'like', '%@'.self::DOMINIO)->delete();
        });
    }

    private function passageiros(string $senha): void
    {
        $existentes = DB::table('passageiros')->where('email', 'like', '%@'.self::DOMINIO)->count();
        $agora = now();

        for ($inicio = $existentes + 1; $inicio <= self::$passageiros; $inicio += self::LOTE) {
            $fim = min($inicio + self::LOTE - 1, self::$passageiros);
            $linhas = [];

            for ($n = $inicio; $n <= $fim; $n++) {
                $linhas[] = [
                    'name' => $this->nome($n),
                    'email' => "passageiro{$n}@".self::DOMINIO,
                    'telefone' => '6991'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                    // faixa de CPF própria dos passageiros: nunca coincide com a dos motoristas
                    'cpf' => $this->cpf(100_000_000 + $n),
                    'data_nascimento' => $this->dataDeNascimento($n),
                    'email_verified_at' => $agora,
                    'password' => $senha,
                    'status' => 'ativo',
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            DB::table('passageiros')->insert($linhas);
            $this->command?->info("Passageiros: {$fim}/".self::$passageiros);
        }
    }

    private function motoristas(string $senha, int $cidadeId): void
    {
        $existentes = DB::table('motoristas')->where('email', 'like', '%@'.self::DOMINIO)->count();

        for ($inicio = $existentes + 1; $inicio <= self::$motoristas; $inicio += self::LOTE) {
            $fim = min($inicio + self::LOTE - 1, self::$motoristas);

            DB::transaction(fn () => $this->lote($inicio, $fim, $senha, $cidadeId));
            $this->command?->info("Motoristas: {$fim}/".self::$motoristas);
        }
    }

    private function lote(int $inicio, int $fim, string $senha, int $cidadeId): void
    {
        $agora = now();
        $motoristas = [];
        $veiculos = [];

        for ($n = $inicio; $n <= $fim; $n++) {
            $moto = $n % 8 === 0;

            $motoristas[] = [
                'name' => $this->nome($n + 7),
                'email' => "motorista{$n}@".self::DOMINIO,
                'telefone' => '6992'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                'cpf' => $this->cpf(300_000_000 + $n),
                'data_nascimento' => $this->dataDeNascimento($n + 11),
                'email_verified_at' => $agora,
                'password' => $senha,
                'cidade_id' => $cidadeId,
                'numero_registro' => str_pad((string) (70_000_000_000 + $n), 11, '0', STR_PAD_LEFT),
                'cnh_categoria' => $moto ? 'A' : 'B',
                'primeira_habilitacao' => $agora->copy()->subYears(2 + $n % 15)->toDateString(),
                'data_emissao' => $agora->copy()->subYears(1)->toDateString(),
                'cnh_expiracao' => $agora->copy()->addYears(3 + $n % 3)->toDateString(),
                'ear' => true,
                'status' => 'ativo',
                'liberado_em' => $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];

            [$marca, $modelo] = $moto
                ? self::MODELOS_MOTO[$n % count(self::MODELOS_MOTO)]
                : self::MODELOS_CARRO[$n % count(self::MODELOS_CARRO)];

            $veiculos[] = [
                'marca' => $marca,
                'modelo' => $modelo,
                'ano_fabricacao' => 2016 + $n % 10,
                'ano_modelo' => 2017 + $n % 10,
                'cor' => self::CORES[$n % count(self::CORES)],
                'placa' => $this->placa($n),
                'renavam' => str_pad((string) (80_000_000_000 + $n), 11, '0', STR_PAD_LEFT),
                'categoria' => $moto ? 'moto' : 'carro',
                'assentos' => $moto ? 1 : 4,
                'eletrico' => false,
                'taxi' => false,
                'eletrico_solicitado' => false,
                'taxi_solicitado' => false,
                'status' => 'aprovado',
                'uf' => 'RO',
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        DB::table('motoristas')->insert($motoristas);
        DB::table('veiculos')->insert($veiculos);

        $motoristaPorEmail = DB::table('motoristas')
            ->whereIn('email', array_column($motoristas, 'email'))
            ->pluck('id', 'email');
        $veiculoPorRenavam = DB::table('veiculos')
            ->whereIn('renavam', array_column($veiculos, 'renavam'))
            ->pluck('id', 'renavam');

        $vinculos = [];
        $documentos = [];
        $status = [];

        foreach ($motoristas as $i => $motorista) {
            $motoristaId = $motoristaPorEmail[$motorista['email']];
            $veiculoId = $veiculoPorRenavam[$veiculos[$i]['renavam']];

            $vinculos[] = ['motorista_id' => $motoristaId, 'veiculo_id' => $veiculoId, 'created_at' => $agora, 'updated_at' => $agora];

            foreach (TipoDocumentoMotorista::valores() as $tipo) {
                $arquivo = "simulacao-{$tipo}.pdf";

                $documentos[] = [
                    'motorista_id' => $motoristaId,
                    // só o CRLV é do veículo
                    'veiculo_id' => $tipo === TipoDocumentoMotorista::CRLV->value ? $veiculoId : null,
                    'tipo_documento' => $tipo,
                    'ordem' => 0,
                    'name' => $arquivo,
                    'type' => 'pdf',
                    'mime_type' => 'application/pdf',
                    'size' => 1,
                    'path' => "motorista_documentos_anexos/{$arquivo}",
                    'url' => rtrim((string) config('app.url'), '/')."/motorista_documentos_anexos/{$arquivo}",
                    'status' => 'aprovado',
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            $status[] = [
                'motorista_id' => $motoristaId,
                'veiculo_id' => $veiculoId,
                'disponivel' => true,
                'latitude' => $this->espalhar(self::CENTRO_LATITUDE),
                'longitude' => $this->espalhar(self::CENTRO_LONGITUDE),
                'visto_em' => $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        DB::table('motorista_veiculos')->insert($vinculos);
        DB::table('motorista_documentos_anexos')->insert($documentos);
        DB::table('status_buscas')->insert($status);
    }

    private function nome(int $n): string
    {
        return self::NOMES[$n % count(self::NOMES)].' '
            .self::SOBRENOMES[intdiv($n, count(self::NOMES)) % count(self::SOBRENOMES)].' '
            .self::SOBRENOMES[intdiv($n, 7) % count(self::SOBRENOMES)];
    }

    private function dataDeNascimento(int $n): string
    {
        return now()->subYears(20 + $n % 45)->subDays($n % 360)->toDateString();
    }

    /** Média de dois sorteios: mais gente perto do centro do que na periferia. */
    private function espalhar(float $centro): float
    {
        $sorteio = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax()) / 2;

        return round($centro + ($sorteio - 0.5) * 2 * self::ESPALHAMENTO, 7);
    }

    /** Placa no padrão Mercosul, única para cada número (até 26 mil). */
    private function placa(int $n): string
    {
        return 'SIM'.intdiv($n, 2600) % 10
            .chr(65 + intdiv($n, 100) % 26)
            .str_pad((string) ($n % 100), 2, '0', STR_PAD_LEFT);
    }

    /** CPF com dígitos verificadores válidos a partir de uma base de 9 dígitos. */
    private function cpf(int $base): string
    {
        $digitos = array_map('intval', str_split(str_pad((string) $base, 9, '0', STR_PAD_LEFT)));

        foreach ([10, 11] as $peso) {
            $soma = 0;

            foreach ($digitos as $posicao => $digito) {
                $soma += $digito * ($peso - $posicao);
            }

            $resto = ($soma * 10) % 11;
            $digitos[] = $resto === 10 ? 0 : $resto;
        }

        return implode('', $digitos);
    }
}
