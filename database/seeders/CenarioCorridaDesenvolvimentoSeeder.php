<?php

namespace Database\Seeders;

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\Passageiro;
use App\Models\StatusBusca;
use App\Models\User;
use App\Models\Veiculo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CenarioCorridaDesenvolvimentoSeeder extends Seeder
{
    public const TELEFONE_MOTORISTA = '69999990001';

    public const TELEFONE_PASSAGEIRO = '69999990002';

    public function run(): void
    {
        DB::transaction(function () {
            $usuarioMotorista = User::updateOrCreate(
                ['email' => 'motorista.codex@example.test'],
                [
                    'name' => 'Motorista Teste',
                    'telefone' => self::TELEFONE_MOTORISTA,
                    'cpf' => '90000000001',
                    'data_nascimento' => '1990-01-01',
                    'foto' => null,
                    'foto_thumbnail' => null,
                    'status' => 'ativo',
                    'password' => 'desenvolvimento',
                ]
            );

            $motorista = Motorista::updateOrCreate(
                ['user_id' => $usuarioMotorista->id],
                [
                    'status' => 'aprovado',
                    'cnh_numero' => '99999999999',
                    'cnh_categoria' => 'AB',
                    'cnh_expiracao' => now()->addYears(5)->toDateString(),
                    'ear' => true,
                ]
            );

            $veiculo = Veiculo::updateOrCreate(
                ['placa' => 'COD3X01'],
                [
                    'marca' => 'Teste',
                    'modelo' => 'Mobilidade',
                    'ano_fabricacao' => 2025,
                    'ano_modelo' => 2026,
                    'cor' => 'Preto',
                    'renavam' => '90000000001',
                    'categoria' => 'pop',
                    'status' => 'aprovado',
                    'uf' => 'RO',
                ]
            );

            MotoristaVeiculo::updateOrCreate([
                'motorista_id' => $motorista->id,
                'veiculo_id' => $veiculo->id,
            ]);

            foreach (['cnh', 'crlv', 'nada_consta', 'seguro_obrigatorio'] as $tipo) {
                MotoristaDocumento::updateOrCreate(
                    ['motorista_id' => $motorista->id, 'tipo_documento' => $tipo],
                    [
                        'name' => "$tipo-desenvolvimento.pdf",
                        'type' => 'pdf',
                        'mime_type' => 'application/pdf',
                        'size' => 1,
                        'path' => "motorista_documentos/$tipo-desenvolvimento.pdf",
                        'status' => 'aprovado',
                        'observacao' => 'Dado fictício para desenvolvimento.',
                    ]
                );
            }

            StatusBusca::updateOrCreate(
                ['motorista_id' => $motorista->id],
                [
                    'veiculo_id' => $veiculo->id,
                    'disponivel' => false,
                    'latitude' => -8.761160,
                    'longitude' => -63.900430,
                    'visto_em' => now(),
                ]
            );

            $usuarioPassageiro = User::updateOrCreate(
                ['email' => 'passageiro.codex@example.test'],
                [
                    'name' => 'Passageiro Teste',
                    'telefone' => self::TELEFONE_PASSAGEIRO,
                    'cpf' => '90000000002',
                    'data_nascimento' => '1990-01-01',
                    'foto' => null,
                    'foto_thumbnail' => null,
                    'status' => 'ativo',
                    'password' => 'desenvolvimento',
                ]
            );

            Passageiro::updateOrCreate(
                ['user_id' => $usuarioPassageiro->id],
                ['media_avaliacao' => null]
            );
        });

        $this->command->info('Cenário de desenvolvimento pronto: motorista 69999990001 e passageiro 69999990002.');
    }
}
