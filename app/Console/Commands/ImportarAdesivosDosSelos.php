<?php

namespace App\Console\Commands;

use App\Support\ImagemDoSelo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Coloca os adesivos (PNG) como imagem dos selos de avaliação do motorista.
 * Serve para o primeiro cadastro: depois disso a gestão troca as imagens pela tela.
 */
class ImportarAdesivosDosSelos extends Command
{
    protected $signature = 'selos:importar-adesivos
        {pasta? : pasta com os PNG (padrão: database/assets/adesivos)}
        {--forcar : troca a imagem de selos que já têm uma}';

    protected $description = 'Importa os adesivos como imagem dos selos de avaliação do motorista';

    /**
     * Arquivo => selo. Selo que ainda não existe (cuidadoso_no_transito) é criado.
     *
     * @var array<string, array{chave: string, titulo: string, icone: string, descricao: string}>
     */
    private const ADESIVOS = [
        'atendimento-excelente.png' => ['chave' => 'otimo_atendimento', 'titulo' => 'Ótimo atendimento', 'icone' => '🤝', 'descricao' => 'Atencioso(a) e prestativo(a) durante toda a viagem'],
        'boa-rota.png' => ['chave' => 'boa_rota', 'titulo' => 'Boa rota', 'icone' => '🗺️', 'descricao' => 'Escolheu um bom caminho e evitou desvios desnecessários'],
        'carro-limpo-e-confortavel.png' => ['chave' => 'carro_limpo_e_confortavel', 'titulo' => 'Carro limpo e confortável', 'icone' => '🚗', 'descricao' => 'Veículo limpo, sem odores e confortável'],
        'cuidadoso-no-transito.png' => ['chave' => 'cuidadoso_no_transito', 'titulo' => 'Cuidadoso no trânsito', 'icone' => '🛡️', 'descricao' => 'Dirige com cuidado e respeita as regras de trânsito'],
        'otimo-papo.png' => ['chave' => 'otimo_papo', 'titulo' => 'Ótimo papo', 'icone' => '💬', 'descricao' => 'Conversa agradável que tornou a viagem mais leve'],
        'respeitoso.png' => ['chave' => 'respeitoso', 'titulo' => 'Respeitoso', 'icone' => '🙏', 'descricao' => 'Tratou o passageiro com respeito e educação'],
    ];

    public function handle(): int
    {
        $pasta = rtrim((string) ($this->argument('pasta') ?: database_path('assets/adesivos')), '/\\');
        $importados = 0;

        foreach (self::ADESIVOS as $arquivo => $selo) {
            $origem = $pasta.DIRECTORY_SEPARATOR.$arquivo;

            if (! is_file($origem)) {
                $this->warn("Faltou o arquivo $arquivo");

                continue;
            }

            $atual = DB::table('selos_avaliacao')->where('chave', $selo['chave'])->first();

            if ($atual !== null && filled($atual->imagem_path) && ! $this->option('forcar')) {
                $this->line("{$selo['titulo']}: já tem imagem (use --forcar para trocar)");

                continue;
            }

            $destino = $selo['chave'].'.png';
            Storage::disk(ImagemDoSelo::DISCO)->put($destino, (string) file_get_contents($origem), 'public');

            if ($atual === null) {
                DB::table('selos_avaliacao')->insert([
                    ...$selo,
                    'perfil_alvo' => 'motorista',
                    'imagem_path' => $destino,
                    'ordem' => ((int) DB::table('selos_avaliacao')->where('perfil_alvo', 'motorista')->max('ordem')) + 1,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('selos_avaliacao')->where('id', $atual->id)->update(['imagem_path' => $destino, 'updated_at' => now()]);
            }

            $this->info("{$selo['titulo']}: imagem importada");
            $importados++;
        }

        $this->line("Selos com imagem nova: $importados");

        return self::SUCCESS;
    }
}
