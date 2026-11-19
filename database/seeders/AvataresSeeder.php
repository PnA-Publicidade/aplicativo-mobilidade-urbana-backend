<?php

namespace Database\Seeders;

use App\Support\ImagemDoAvatar;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Avatares de exemplo para o passageiro escolher no lugar da foto, em coleções
 * (imagens do DiceBear, licença livre). Cada imagem é baixada e guardada como
 * arquivo; se o download falhar, o avatar usa a URL. Pode rodar de novo: o que
 * já existe (mesma coleção e título) não é recriado.
 *
 *   php artisan db:seed --class=AvataresSeeder
 */
class AvataresSeeder extends Seeder
{
    /** estilo do DiceBear => nome da coleção */
    private const COLECOES = [
        'adventurer' => 'Aventureiros',
        'fun-emoji' => 'Emojis',
        'bottts' => 'Robôs',
        'big-smile' => 'Sorrisos',
        'pixel-art' => 'Pixel',
    ];

    private const SEMENTES = ['Sol', 'Lua', 'Mar', 'Rio', 'Mata', 'Vento', 'Ouro', 'Jade'];

    public function run(): void
    {
        $ordem = (int) DB::table('avatares')->max('ordem');
        $criados = 0;

        foreach (self::COLECOES as $estilo => $colecao) {
            foreach (self::SEMENTES as $indice => $semente) {
                $titulo = $colecao.' '.($indice + 1);

                if (DB::table('avatares')->where('colecao', $colecao)->where('titulo', $titulo)->exists()) {
                    continue;
                }

                $url = "https://api.dicebear.com/9.x/{$estilo}/png?seed={$semente}&size=256&backgroundColor=b6e3f4,c0aede,d1d4f9,ffd5dc,ffdfbf";

                DB::table('avatares')->insert([
                    'titulo' => $titulo,
                    'colecao' => $colecao,
                    'imagem_path' => $this->baixar($url),
                    'imagem_url' => $url,
                    'ordem' => ++$ordem,
                    'ativo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $criados++;
            }
        }

        $this->command?->info("Avatares criados: {$criados}.");
    }

    /** Guarda a imagem como arquivo; nulo se não deu (vale então a URL). */
    private function baixar(string $url): ?string
    {
        try {
            $resposta = Http::timeout(20)->get($url);

            if (! $resposta->successful() || $resposta->body() === '') {
                return null;
            }

            $nome = Str::uuid().'.png';
            Storage::disk(ImagemDoAvatar::DISCO)->put($nome, $resposta->body(), 'public');

            return $nome;
        } catch (Throwable) {
            return null;
        }
    }
}
