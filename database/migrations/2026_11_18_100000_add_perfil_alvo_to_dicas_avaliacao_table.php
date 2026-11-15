<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "Como ter mais avaliações positivas?" existe para o motorista e para o passageiro
        Schema::table('dicas_avaliacao', function (Blueprint $table) {
            $table->string('perfil_alvo', 20)->default('motorista')->after('id');
            $table->index(['perfil_alvo', 'ativo', 'ordem']);
        });

        $dicas = [
            ['Seja pontual', 'Esteja no ponto de embarque na hora combinada: o motorista agradece.'],
            ['Confira o endereço', 'Um endereço certo evita voltas e deixa a corrida mais rápida.'],
            ['Trate com respeito', 'Educação e gentileza fazem diferença para quem está dirigindo.'],
            ['Cuide do veículo', 'Evite sujar o carro e feche as portas com cuidado.'],
            ['Pague sem complicação', 'Tenha o pagamento em dia e o troco, quando for em dinheiro.'],
        ];
        $agora = now();

        foreach ($dicas as $i => [$titulo, $texto]) {
            DB::table('dicas_avaliacao')->insert([
                'perfil_alvo' => 'passageiro',
                'titulo' => $titulo,
                'texto' => $texto,
                'ordem' => $i + 1,
                'ativo' => true,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('dicas_avaliacao')->where('perfil_alvo', 'passageiro')->delete();

        Schema::table('dicas_avaliacao', function (Blueprint $table) {
            $table->dropIndex(['perfil_alvo', 'ativo', 'ordem']);
            $table->dropColumn('perfil_alvo');
        });
    }
};
