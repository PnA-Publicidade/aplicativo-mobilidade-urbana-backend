    <?php

    use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('produtos_corridas', function (Blueprint $table) {
            $table->id();

            // Mudado de enum para string para aceitar qualquer produto
            $table->string('nome');
            $table->string('codigo')->unique(); // Unique evita códigos duplicados
            $table->string('estrategia_precificacao');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produtos_corridas');
    }
};
