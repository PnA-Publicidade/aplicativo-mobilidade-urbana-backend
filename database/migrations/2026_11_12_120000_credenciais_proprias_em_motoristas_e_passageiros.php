<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fase 1 das contas separadas: motorista e passageiro passam a ter os próprios
// dados de acesso, como os gestores. Por enquanto eles são copiados de users,
// que continua valendo para as rotas antigas até sair (fase 3).
return new class extends Migration
{
    private const CREDENCIAIS = ['telefone', 'email', 'email_verified_at', 'password', 'remember_token'];

    public function up(): void
    {
        // um nome só no motorista: o da CNH, ou o do cadastro enquanto ela não é lida
        Schema::table('motoristas', function (Blueprint $table): void {
            $table->renameColumn('nome', 'name');
        });
        Schema::table('motoristas', function (Blueprint $table): void {
            $table->string('telefone')->nullable()->unique()->after('name');
            $table->string('email')->nullable()->unique()->after('telefone');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('password')->nullable()->after('email_verified_at');
            $table->rememberToken()->after('password');
        });
        Schema::table('passageiros', function (Blueprint $table): void {
            $table->string('name')->nullable()->after('user_id');
            $table->string('telefone')->nullable()->unique()->after('name');
            // a cobrança Pix e cartão manda o CPF do passageiro
            $table->string('cpf', 11)->nullable()->unique()->after('telefone');
            $table->date('data_nascimento')->nullable()->after('cpf');
            $table->string('email')->nullable()->unique()->after('data_nascimento');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('password')->nullable()->after('email_verified_at');
            $table->rememberToken()->after('password');
        });

        foreach (['motoristas', 'passageiros'] as $tabela) {
            DB::table($tabela)->join('users', 'users.id', '=', "$tabela.user_id")
                ->get([
                    "$tabela.id", "$tabela.name", "$tabela.cpf", "$tabela.data_nascimento",
                    'users.name as conta_name', 'users.cpf as conta_cpf',
                    'users.data_nascimento as conta_data_nascimento',
                    ...array_map(fn (string $coluna) => "users.$coluna", self::CREDENCIAIS),
                ])
                ->each(fn (object $linha) => DB::table($tabela)->where('id', $linha->id)->update([
                    // no motorista vale o que veio da CNH; o resto vem da conta
                    'name' => $linha->name ?? $linha->conta_name,
                    'cpf' => $linha->cpf ?? $linha->conta_cpf,
                    'data_nascimento' => $linha->data_nascimento ?? $linha->conta_data_nascimento,
                    ...array_map(fn (string $coluna) => $linha->{$coluna}, array_combine(self::CREDENCIAIS, self::CREDENCIAIS)),
                ]));
        }
    }

    public function down(): void
    {
        Schema::table('passageiros', function (Blueprint $table): void {
            $table->dropUnique(['telefone']);
            $table->dropUnique(['cpf']);
            $table->dropUnique(['email']);
            $table->dropColumn(['name', 'cpf', 'data_nascimento', ...self::CREDENCIAIS]);
        });
        Schema::table('motoristas', function (Blueprint $table): void {
            $table->dropUnique(['telefone']);
            $table->dropUnique(['email']);
            $table->dropColumn(self::CREDENCIAIS);
        });
        Schema::table('motoristas', function (Blueprint $table): void {
            $table->renameColumn('name', 'nome');
        });
    }
};
