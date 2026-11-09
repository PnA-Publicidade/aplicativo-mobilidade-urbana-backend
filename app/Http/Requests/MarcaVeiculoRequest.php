<?php

namespace App\Http\Requests;

use App\Models\MarcaVeiculo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MarcaVeiculoRequest extends FormRequest
{
    /** A permissão da rota é conferida pelo middleware permissao.gestao. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('nome'))) {
            $this->merge(['nome' => trim($this->input('nome'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $atual = $this->route('marca');

        return [
            'nome' => [
                'required',
                'string',
                'max:60',
                Rule::unique('marcas_veiculos', 'nome')->ignore($atual instanceof MarcaVeiculo ? $atual->id : null),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['nome.unique' => 'Já existe uma marca com esse nome.'];
    }
}
