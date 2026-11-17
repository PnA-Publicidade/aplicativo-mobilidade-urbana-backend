<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBannerPublicidadeRequest extends FormRequest
{
    /**
     * Qualquer conta logada, como o resto do painel de gestão por enquanto
     * (não existe papel de gestão; ver pendências de produção).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trocar a imagem exige multipart: envie por POST com _method=PUT (o PHP
     * não lê arquivos de um PUT de verdade).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'titulo' => 'sometimes|required|string|max:120',
            'arquivo' => 'sometimes|required|file|mimes:jpg,jpeg,png,webp|max:'.config('publicidade.tamanho_maximo_kb'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'arquivo.mimes' => 'Envie a imagem do banner em JPG, PNG ou WEBP.',
            'arquivo.max' => 'A imagem do banner pode ter no máximo :max KB.',
        ];
    }
}
