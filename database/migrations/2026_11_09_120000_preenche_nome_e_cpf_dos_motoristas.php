<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // quem se cadastrou pelo app ficou sem nome e CPF no motorista: vêm do usuário
    public function up(): void
    {
        DB::table('motoristas')->join('users', 'users.id', '=', 'motoristas.user_id')
            ->where(fn ($consulta) => $consulta->whereNull('motoristas.nome')->orWhereNull('motoristas.cpf'))
            ->get(['motoristas.id', 'motoristas.nome', 'motoristas.cpf', 'users.name as nome_usuario', 'users.cpf as cpf_usuario'])
            ->each(fn ($linha) => DB::table('motoristas')->where('id', $linha->id)->update([
                'nome' => $linha->nome ?? $linha->nome_usuario,
                'cpf' => $linha->cpf ?? $linha->cpf_usuario,
            ]));
    }

    public function down(): void {}
};
