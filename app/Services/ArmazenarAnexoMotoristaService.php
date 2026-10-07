<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class ArmazenarAnexoMotoristaService
{
    public const DIRETORIO = 'motorista_documentos_anexos';

    public function salvar(UploadedFile $arquivo, Request $request): array
    {
        $dados = [
            'name' => $arquivo->getClientOriginalName(),
            'type' => $arquivo->extension(),
            'mime_type' => $arquivo->getMimeType(),
            'size' => $arquivo->getSize(),
        ];
        $nome = time().'_'.Str::uuid().'.'.$dados['type'];
        $salvo = $arquivo->storeAs('', $nome, self::DIRETORIO);
        abort_if($salvo === false, 500, 'Não foi possível armazenar o arquivo.');

        $path = self::DIRETORIO.'/'.$salvo;
        $host = App::environment('local')
            ? $request->getSchemeAndHttpHost()
            : rtrim((string) config('app.url'), '/');

        return [...$dados, 'path' => $path, 'url' => $host.'/'.$path];
    }

    public function regrasVerso(Request $request, string $formatos = 'jpg,jpeg,png', int $limite = 2048): array
    {
        $arquivo = $request->file('arquivo');
        $fotoCnh = $request->input('tipo_documento') === 'cnh'
            && $arquivo instanceof UploadedFile
            && $arquivo->isValid()
            && str_starts_with($arquivo->getMimeType() ?? '', 'image/');

        return [
            'bail',
            Rule::requiredIf($fotoCnh),
            Rule::prohibitedIf(! $fotoCnh),
            'file',
            'mimes:'.$formatos,
            'max:'.$limite,
        ];
    }

    public function salvarEnvio(Request $request): array
    {
        $anexo = $this->salvar($request->file('arquivo'), $request);
        try {
            $anexo['verso'] = $request->hasFile('arquivo_verso')
                ? $this->salvar($request->file('arquivo_verso'), $request)
                : null;
        } catch (Throwable $exception) {
            $this->excluir($anexo['path']);
            throw $exception;
        }

        return $anexo;
    }

    public function excluirEnvio(array $anexo): void
    {
        $this->excluir($anexo['path'] ?? null);
        $this->excluir($anexo['verso']['path'] ?? null);
    }

    public function excluir(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, self::DIRETORIO.'/')) {
            $arquivo = substr($path, strlen(self::DIRETORIO) + 1);
            Storage::disk(self::DIRETORIO)->delete($arquivo);

            return;
        }

        Storage::disk('local')->delete($path);
    }
}
