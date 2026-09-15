<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('status_buscas', function (Blueprint $table) {
            $table->integer('motorista_id')->unique()->after('id');
            $table->integer('veiculo_id')->nullable()->after('motorista_id');
            $table->boolean('disponivel')->default(false)->after('veiculo_id');
            $table->decimal('latitude', 10, 7)->nullable()->after('disponivel');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('visto_em')->nullable()->after('longitude');

            $table->index(['disponivel', 'visto_em']);
        });
    }

    public function down(): void
    {
        Schema::table('status_buscas', function (Blueprint $table) {
            $table->dropIndex(['disponivel', 'visto_em']);
            $table->dropColumn([
                'motorista_id', 'veiculo_id', 'disponivel',
                'latitude', 'longitude', 'visto_em',
            ]);
        });
    }
};
