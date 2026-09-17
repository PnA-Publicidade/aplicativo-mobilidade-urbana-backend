<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motoristas', function (Blueprint $table) {
            // a CNH deixa de ser exigida no cadastro: ela chega depois, no
            // envio de documentos
            $table->string('cnh_numero')->nullable()->change();
            $table->string('cnh_categoria')->nullable()->change();
            $table->date('cnh_expiracao')->nullable()->change();
            $table->boolean('ear')->nullable()->change();

            // aprovação do motorista, separada de users.status: o mesmo
            // usuário continua passageiro ativo enquanto o cadastro de
            // motorista está em análise
            $table->string('status')->default('pendente')->after('user_id');
        });

        DB::table('motoristas')->update(['status' => 'aprovado']);
    }

    public function down(): void
    {
        Schema::table('motoristas', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
