<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->foreignId('metodo_resgate_id')->nullable()->constrained('metodos_resgate')->nullOnDelete();
            $table->decimal('valor', 10, 2);
            $table->decimal('taxa', 10, 2)->default(0);
            // processando | concluido | falhou — falhou não desconta do saldo
            $table->string('status', 20)->default('processando');
            $table->string('gateway', 30);
            $table->string('gateway_id', 120)->nullable();
            // como o destino estava no momento do saque, mesmo se o método mudar depois
            $table->string('destino', 160);
            $table->string('erro', 255)->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['motorista_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saques');
    }
};
