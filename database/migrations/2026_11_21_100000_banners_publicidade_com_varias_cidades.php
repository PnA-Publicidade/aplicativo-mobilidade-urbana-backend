<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O banner deixa de pertencer a uma cidade só: passa a ter várias, numa
     * tabela de ligação. A cidade que cada banner já tinha vira a primeira
     * linha da ligação, e a coluna cidade_id sai.
     */
    public function up(): void
    {
        Schema::create('banner_publicidade_cidade', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_publicidade_id')->constrained('banner_publicidades')->cascadeOnDelete();
            $table->foreignId('cidade_id')->constrained('cidades')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['banner_publicidade_id', 'cidade_id']);
        });

        $agora = now();
        DB::table('banner_publicidades')
            ->whereNotNull('cidade_id')
            ->orderBy('id')
            ->get(['id', 'cidade_id'])
            ->each(fn ($banner) => DB::table('banner_publicidade_cidade')->insert([
                'banner_publicidade_id' => $banner->id,
                'cidade_id' => $banner->cidade_id,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]));

        Schema::table('banner_publicidades', function (Blueprint $table) {
            $table->dropForeign(['cidade_id']);
            $table->dropColumn('cidade_id');
        });
    }

    public function down(): void
    {
        Schema::table('banner_publicidades', function (Blueprint $table) {
            $table->unsignedBigInteger('cidade_id')->nullable()->after('id');
        });

        // volta com a primeira cidade de cada banner (as outras se perdem)
        DB::table('banner_publicidade_cidade')
            ->orderBy('id')
            ->get(['banner_publicidade_id', 'cidade_id'])
            ->unique('banner_publicidade_id')
            ->each(fn ($ligacao) => DB::table('banner_publicidades')
                ->where('id', $ligacao->banner_publicidade_id)
                ->update(['cidade_id' => $ligacao->cidade_id]));

        Schema::table('banner_publicidades', function (Blueprint $table) {
            $table->foreign('cidade_id')->references('id')->on('cidades')->restrictOnDelete();
        });

        Schema::dropIfExists('banner_publicidade_cidade');
    }
};
