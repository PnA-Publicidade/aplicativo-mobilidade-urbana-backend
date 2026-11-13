<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/** a foto enviada no cadastro vai para o perfil do app (motorista ou passageiro) */
class FotoDePerfil
{
    public static function salvar(Request $request, Model $perfil): void
    {
        $image = $request->file('image', null);

        if ($image) {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
            ]);

            $image = $request->file('image');
            $host = App::environment('local')
                ? $request->getSchemeAndHttpHost()
                : rtrim((string) config('app.url'), '/');
            $extension = $image->extension();
            $imageName = Str::uuid().'.'.$extension;

            File::ensureDirectoryExists(public_path('images'));

            $image->move(public_path('images'), $imageName);
            $perfil->setAttribute('foto', $host.'/images/'.$imageName);
            $perfil->saveOrFail();
        }
    }

    /** apaga o arquivo de uma foto trocada ou removida, só dentro de public/images */
    public static function excluir(?string $url): void
    {
        $caminho = $url === null ? null : parse_url($url, PHP_URL_PATH);

        if (! is_string($caminho) || ! preg_match('~^/images/([^/\\\\]+)$~', $caminho, $matches)) {
            return;
        }

        $nome = rawurldecode($matches[1]);

        if ($nome === '.' || $nome === '..' || str_contains($nome, '/') || str_contains($nome, '\\')) {
            return;
        }

        File::delete(public_path('images/'.$nome));
    }
}
