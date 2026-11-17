<?php

namespace Database\Seeders;

use App\Models\BannerPublicidade;
use App\Models\Cidade;
use Illuminate\Database\Seeder;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Banners de exemplo da tela inicial do passageiro em Porto Velho. Também
 * cria o bucket do S3 local quando ele não existe: o volume do RustFS pode
 * voltar vazio e, sem o bucket, nenhum banner sobe.
 */
class BannersDemonstracaoSeeder extends Seeder
{
    private const BANNERS = [
        ['arquivo' => 'viagem-compartilhada.png', 'titulo' => 'Viagem compartilhada: pague até 50% menos'],
        ['arquivo' => 'seu-anuncio-aqui.png', 'titulo' => 'Seu anúncio aqui'],
    ];

    public function run(): void
    {
        $cidade = Cidade::where('nome', 'Porto Velho')->where('uf', 'RO')->first();

        if ($cidade === null) {
            $this->command->warn('Porto Velho não está cadastrada: banners de exemplo não criados.');

            return;
        }

        $disco = (string) config('publicidade.disco');

        try {
            $this->garantirBucket($disco);
        } catch (Throwable $erro) {
            $this->command->warn('Armazenamento dos banners indisponível: '.$erro->getMessage());

            return;
        }

        foreach (self::BANNERS as $banner) {
            $jaExiste = BannerPublicidade::where('titulo', $banner['titulo'])
                ->whereHas('cidades', fn ($consulta) => $consulta->whereKey($cidade->id))
                ->exists();

            if ($jaExiste) {
                continue;
            }

            $origem = database_path('seeders/arquivos/banners/'.$banner['arquivo']);
            $caminho = config('publicidade.pasta').'/'.Str::uuid().'.png';
            Storage::disk($disco)->put($caminho, (string) file_get_contents($origem), 'public');

            $criado = BannerPublicidade::create([
                'titulo' => $banner['titulo'],
                'name' => $banner['arquivo'],
                'type' => 'png',
                'mime_type' => 'image/png',
                'size' => (int) filesize($origem),
                'path' => $caminho,
            ]);
            $criado->cidades()->attach($cidade->id);
        }
    }

    private function garantirBucket(string $disco): void
    {
        $armazenamento = Storage::disk($disco);

        if (! $armazenamento instanceof AwsS3V3Adapter) {
            return;
        }

        $cliente = $armazenamento->getClient();
        $bucket = (string) config("filesystems.disks.{$disco}.bucket");

        if (! $cliente->doesBucketExist($bucket)) {
            $cliente->createBucket(['Bucket' => $bucket]);
        }

        // o app abre a imagem direto pela URL pública do bucket
        $cliente->putBucketPolicy([
            'Bucket' => $bucket,
            'Policy' => (string) json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => ['AWS' => ['*']],
                    'Action' => ['s3:GetObject'],
                    'Resource' => ["arn:aws:s3:::{$bucket}/*"],
                ]],
            ]),
        ]);
    }
}
