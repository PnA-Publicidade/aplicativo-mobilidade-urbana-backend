<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Contas separadas, fases 2 e 3: tudo o que apontava para users passa a apontar
// para o perfil do app (motorista ou passageiro), e a tabela users sai. Sem
// volta: os dados de users não são recriados no rollback.
return new class extends Migration
{
    public function up(): void
    {
        // só do passageiro: cotação, casa/trabalho/favoritos e sugestão de lugar
        $this->trocarUserPor('cotacoes_corrida', 'passageiro_id', 'passageiros', ['cotacoes_corrida_user_id_foreign']);
        $this->trocarUserPor('locais_salvos', 'passageiro_id', 'passageiros', ['locais_salvos_user_id_tipo_index'], ['passageiro_id', 'tipo']);
        $this->trocarUserPor('sugestoes_locais', 'passageiro_id', 'passageiros', ['sugestoes_locais_user_id_foreign']);

        // dos dois apps: o perfil que já estava na linha diz em qual tabela procurar
        $this->trocarUserPorPerfil('chamados_ajuda', 'solicitante_tipo', 'solicitante_id', ['chamados_ajuda_user_id_created_at_index'], ['created_at']);
        $this->trocarUserPorPerfil('notificacoes', 'perfil', 'perfil_id', ['notificacoes_user_id_lida_em_index', 'notificacoes_user_id_perfil_index'], ['lida_em']);
        $this->trocarUserPorPerfil('dispositivos_push', 'perfil', 'perfil_id', ['dispositivos_push_user_id_perfil_index']);

        // a mensagem do cliente é sempre de quem abriu o chamado, e a da corrida,
        // do passageiro ou do motorista dela (autor_tipo): o user_id sobrava
        foreach (['chamado_mensagens', 'corrida_mensagens'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }

        // a avaliação guarda quem avaliou pelo id do perfil (tipo_usuario diz qual)
        foreach (['motorista' => 'motoristas', 'passageiro' => 'passageiros'] as $tipo => $perfis) {
            DB::table('avaliacoes_corridas')->where('tipo_usuario', $tipo)->update([
                'usuario_id' => DB::raw("(SELECT $perfis.id FROM $perfis WHERE $perfis.user_id = avaliacoes_corridas.usuario_id LIMIT 1)"),
            ]);
        }
        DB::table('avaliacoes_corridas')->whereNull('usuario_id')->delete();
        Schema::table('avaliacoes_corridas', function (Blueprint $table): void {
            $table->dropUnique(['corrida_id', 'usuario_id']);
            $table->renameColumn('usuario_id', 'avaliador_id');
        });
        Schema::table('avaliacoes_corridas', function (Blueprint $table): void {
            // motorista 1 e passageiro 1 são pessoas diferentes
            $table->unique(['corrida_id', 'tipo_usuario', 'avaliador_id']);
        });

        // na negociação, quem propõe já está em motorista_id ou é o passageiro da corrida
        Schema::table('corrida_negociacoes', fn (Blueprint $table) => $table->dropColumn('usuario_id'));

        Schema::table('motoristas', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
        Schema::table('passageiros', fn (Blueprint $table) => $table->dropColumn('user_id'));

        Schema::dropIfExists('users');
    }

    public function down(): void
    {
        throw new RuntimeException('A tabela users foi apagada e não volta pelo rollback.');
    }

    /**
     * user_id vira o id do perfil numa tabela de um app só. Linha sem perfil
     * correspondente não tem mais dono e sai.
     *
     * @param  list<string>  $indices  índices com user_id, apagados antes
     * @param  list<string>  $novoIndice
     */
    private function trocarUserPor(string $tabela, string $coluna, string $perfis, array $indices, array $novoIndice = []): void
    {
        Schema::table($tabela, fn (Blueprint $table) => $table->unsignedBigInteger($coluna)->nullable()->after('id'));
        DB::table($tabela)->update([
            $coluna => DB::raw("(SELECT $perfis.id FROM $perfis WHERE $perfis.user_id = $tabela.user_id LIMIT 1)"),
        ]);
        DB::table($tabela)->whereNull($coluna)->delete();

        Schema::table($tabela, function (Blueprint $table) use ($indices): void {
            $table->dropForeign(['user_id']);
            foreach (array_diff($indices, ["{$table->getTable()}_user_id_foreign"]) as $indice) {
                $table->dropIndex($indice);
            }
            $table->dropColumn('user_id');
        });
        Schema::table($tabela, function (Blueprint $table) use ($coluna, $perfis, $novoIndice): void {
            $table->unsignedBigInteger($coluna)->nullable(false)->change();
            $table->foreign($coluna)->references('id')->on($perfis)->cascadeOnDelete();
            if ($novoIndice !== []) {
                $table->index($novoIndice);
            }
        });
    }

    /**
     * user_id vira o id do perfil numa tabela dos dois apps, pela coluna que já
     * diz o perfil da linha (motorista ou passageiro).
     *
     * @param  list<string>  $indices  índices com user_id, apagados antes
     * @param  list<string>  $depoisDoPerfil  colunas que completam o índice novo
     */
    private function trocarUserPorPerfil(string $tabela, string $tipo, string $coluna, array $indices, array $depoisDoPerfil = []): void
    {
        Schema::table($tabela, fn (Blueprint $table) => $table->unsignedBigInteger($coluna)->nullable()->after('id'));
        foreach (['motorista' => 'motoristas', 'passageiro' => 'passageiros'] as $perfil => $perfis) {
            DB::table($tabela)->where($tipo, $perfil)->update([
                $coluna => DB::raw("(SELECT $perfis.id FROM $perfis WHERE $perfis.user_id = $tabela.user_id LIMIT 1)"),
            ]);
        }
        DB::table($tabela)->whereNull($coluna)->delete();

        Schema::table($tabela, function (Blueprint $table) use ($indices): void {
            $table->dropForeign(['user_id']);
            foreach ($indices as $indice) {
                $table->dropIndex($indice);
            }
            $table->dropColumn('user_id');
        });
        Schema::table($tabela, function (Blueprint $table) use ($tipo, $coluna, $depoisDoPerfil): void {
            $table->unsignedBigInteger($coluna)->nullable(false)->change();
            $table->index([$tipo, $coluna, ...$depoisDoPerfil]);
        });
    }
};
