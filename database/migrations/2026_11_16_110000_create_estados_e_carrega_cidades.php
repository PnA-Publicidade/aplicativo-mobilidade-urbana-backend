<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** código do estado no IBGE => [nome, sigla] */
    private const ESTADOS = [
        11 => ['Rondônia', 'RO'], 12 => ['Acre', 'AC'], 13 => ['Amazonas', 'AM'], 14 => ['Roraima', 'RR'],
        15 => ['Pará', 'PA'], 16 => ['Amapá', 'AP'], 17 => ['Tocantins', 'TO'], 21 => ['Maranhão', 'MA'],
        22 => ['Piauí', 'PI'], 23 => ['Ceará', 'CE'], 24 => ['Rio Grande do Norte', 'RN'], 25 => ['Paraíba', 'PB'],
        26 => ['Pernambuco', 'PE'], 27 => ['Alagoas', 'AL'], 28 => ['Sergipe', 'SE'], 29 => ['Bahia', 'BA'],
        31 => ['Minas Gerais', 'MG'], 32 => ['Espírito Santo', 'ES'], 33 => ['Rio de Janeiro', 'RJ'],
        35 => ['São Paulo', 'SP'], 41 => ['Paraná', 'PR'], 42 => ['Santa Catarina', 'SC'],
        43 => ['Rio Grande do Sul', 'RS'], 50 => ['Mato Grosso do Sul', 'MS'], 51 => ['Mato Grosso', 'MT'],
        52 => ['Goiás', 'GO'], 53 => ['Distrito Federal', 'DF'],
    ];

    public function up(): void
    {
        // o id é o código do estado no IBGE (11 = Rondônia), o mesmo começo do código das cidades
        Schema::create('estados', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->char('sigla', 2)->unique();
            $table->timestamps();
        });

        $agora = now();
        DB::table('estados')->insert(array_map(
            fn (int $id, array $estado) => ['id' => $id, 'nome' => $estado[0], 'sigla' => $estado[1], 'created_at' => $agora, 'updated_at' => $agora],
            array_keys(self::ESTADOS),
            self::ESTADOS,
        ));

        Schema::table('cidades', function (Blueprint $table) {
            $table->foreignId('uf_id')->nullable()->after('uf')->constrained('estados');
        });

        // todos os municípios do IBGE (database/data/municipios.php, tirado do dump brasil.sql);
        // as cidades que já existem ficam com o mesmo id, porque tarifas, banners e corridas apontam para elas
        $existentes = array_flip(DB::table('cidades')->pluck('ibge')->map(fn ($ibge) => (int) $ibge)->all());
        $novas = [];
        foreach (require database_path('data/municipios.php') as [$ibge, $estado, $nome]) {
            if (! isset($existentes[$ibge])) {
                $novas[] = ['nome' => $nome, 'uf' => self::ESTADOS[$estado][1], 'uf_id' => $estado, 'ibge' => $ibge, 'created_at' => $agora, 'updated_at' => $agora];
            }
        }
        foreach (array_chunk($novas, 500) as $lote) {
            DB::table('cidades')->insert($lote);
        }

        // as que já existiam ganham o estado pela sigla
        foreach (self::ESTADOS as $id => [, $sigla]) {
            DB::table('cidades')->whereNull('uf_id')->where('uf', $sigla)->update(['uf_id' => $id]);
        }

        Schema::table('cidades', function (Blueprint $table) {
            $table->foreignId('uf_id')->nullable(false)->change();
            $table->unique('ibge');
        });
    }

    public function down(): void
    {
        // ponytail: as cidades carregadas ficam; apagá-las quebraria tarifas, banners e corridas que já apontem para elas
        Schema::table('cidades', function (Blueprint $table) {
            $table->dropUnique(['ibge']);
            $table->dropConstrainedForeignId('uf_id');
        });

        Schema::dropIfExists('estados');
    }
};
