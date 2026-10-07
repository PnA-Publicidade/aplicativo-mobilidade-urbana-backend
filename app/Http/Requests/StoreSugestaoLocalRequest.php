<?php

namespace App\Http\Requests;

use App\Models\SugestaoLocal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSugestaoLocalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'string', Rule::in(SugestaoLocal::TIPOS)],
            'nome' => ['required_if:tipo,novo_local', 'nullable', 'string', 'max:120'],
            'endereco' => ['required_if:tipo,novo_local,alteracao_local', 'nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'descricao' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required_if' => 'Informe o nome do lugar.',
            'endereco.required_if' => 'Informe o endereço do lugar.',
            'descricao.required' => 'Conte o que precisa mudar.',
            'descricao.min' => 'Escreva pelo menos 10 letras.',
        ];
    }
}
