<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // selos (elogios) que um escolhe ao avaliar o outro, gerenciados pela gestão.
        // `perfil_alvo` é quem RECEBE o selo: motorista (escolhido pelo passageiro)
        // ou passageiro (escolhido pelo motorista).
        Schema::create('selos_avaliacao', function (Blueprint $table) {
            $table->id();
            $table->string('chave', 60)->unique();
            $table->string('perfil_alvo', 10); // motorista | passageiro
            $table->string('titulo', 60);
            $table->string('descricao', 200)->nullable();
            $table->string('icone', 16); // emoji que ilustra o selo
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['perfil_alvo', 'ativo', 'ordem']);
        });

        // "Como ter mais avaliações positivas?" no perfil do motorista
        Schema::create('dicas_avaliacao', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 80);
            $table->string('texto', 300);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->index(['ativo', 'ordem']);
        });

        // quem bloqueou quem: o motorista bloqueado pelo passageiro (e o passageiro
        // bloqueado pelo motorista) nunca mais são colocados na mesma corrida
        Schema::create('bloqueios_usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('motorista_id')->constrained('motoristas')->cascadeOnDelete();
            $table->foreignId('passageiro_id')->constrained('passageiros')->cascadeOnDelete();
            $table->string('bloqueado_por', 10); // motorista | passageiro
            $table->foreignId('corrida_id')->nullable()->constrained('corridas')->nullOnDelete();
            $table->timestamps();

            $table->unique(['motorista_id', 'passageiro_id', 'bloqueado_por'], 'bloqueios_usuarios_unico');
            $table->index('passageiro_id');
        });

        $this->semear();
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueios_usuarios');
        Schema::dropIfExists('dicas_avaliacao');
        Schema::dropIfExists('selos_avaliacao');
    }

    private function semear(): void
    {
        $agora = now();

        // o passageiro elogia o motorista
        $motorista = [
            ['carro_limpo_e_confortavel', 'Carro limpo e confortável', '🚗', 'Veículo limpo, sem odores e confortável'],
            ['otimo_atendimento', 'Ótimo atendimento', '🤝', 'Atencioso(a) e prestativo(a) durante toda a viagem'],
            ['superou_as_expectativas', 'Superou as expectativas', '🌟', 'A viagem foi melhor do que o esperado'],
            ['boa_rota', 'Boa rota', '🗺️', 'Escolheu um bom caminho e evitou desvios desnecessários'],
            ['otimo_papo', 'Ótimo papo', '💬', 'Conversa agradável que tornou a viagem mais leve'],
            ['otimas_cortesias', 'Ótimas cortesias', '🍬', 'Ofereceu água, bala ou outra gentileza'],
            ['heroi_da_noite', 'Herói da noite', '🦸', 'Deu segurança e tranquilidade em uma viagem à noite'],
            ['musica_excelente', 'Música excelente', '🎵', 'Som ambiente na medida certa e ótima seleção'],
            ['respeitoso', 'Respeitoso', '🙏', 'Tratou o passageiro com respeito e educação'],
        ];

        // o motorista elogia o passageiro
        $passageiro = [
            ['pontual_no_embarque', 'Pontual no embarque', '⏰', 'Estava pronto(a) no local combinado, sem atrasos'],
            ['educado_e_respeitoso', 'Educado e respeitoso', '🙏', 'Tratou o motorista com educação e respeito'],
            ['boa_comunicacao', 'Boa comunicação', '📱', 'Respondeu rápido e deixou claro onde estava'],
            ['endereco_certo', 'Endereço certo', '📍', 'Informou o local de embarque e o destino corretamente'],
            ['cuidou_do_veiculo', 'Cuidou do veículo', '🚘', 'Manteve o carro limpo e organizado durante a viagem'],
            ['otimo_papo_passageiro', 'Ótimo papo', '💬', 'Conversa agradável durante a viagem'],
            ['viagem_tranquila', 'Viagem tranquila', '😌', 'Foi uma viagem calma, sem imprevistos'],
            ['pagamento_sem_complicacao', 'Pagamento sem complicação', '💵', 'Pagou sem demora ou problemas'],
        ];

        foreach (['motorista' => $motorista, 'passageiro' => $passageiro] as $alvo => $selos) {
            foreach ($selos as $i => [$chave, $titulo, $icone, $descricao]) {
                DB::table('selos_avaliacao')->insert([
                    'chave' => $chave,
                    'perfil_alvo' => $alvo,
                    'titulo' => $titulo,
                    'descricao' => $descricao,
                    'icone' => $icone,
                    'ordem' => $i + 1,
                    'ativo' => true,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        }

        $dicas = [
            ['Cuide do seu veículo', 'Veículo limpo e manutenção em dia ajudam na avaliação.'],
            ['Mantenha a calma', 'Respeite as leis de trânsito para uma viagem segura.'],
            ['Seja pontual', 'Chegue ao embarque no tempo previsto e avise se houver imprevistos.'],
            ['Trate todos com respeito', 'Cumprimente o passageiro e seja educado do início ao fim da viagem.'],
            ['Siga a melhor rota', 'Confira o trajeto no mapa e evite desvios desnecessários.'],
        ];

        foreach ($dicas as $i => [$titulo, $texto]) {
            DB::table('dicas_avaliacao')->insert([
                'titulo' => $titulo,
                'texto' => $texto,
                'ordem' => $i + 1,
                'ativo' => true,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }
};
