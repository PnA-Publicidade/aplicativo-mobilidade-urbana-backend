<?php

namespace App\Http\Requests;

use App\Models\ModeloVeiculo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModeloVeiculoRequest extends FormRequest
{
    /** A permissão da rota é conferida pelo middleware permissao.gestao. */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('modelo'))) {
            $this->merge(['modelo' => trim($this->input('modelo'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $atual = $this->route('modelo');
        $ano = ['nullable', 'integer', 'between:1900,'.(now()->year + 1)];

        return [
            'marca_id' => ['required', 'integer', Rule::exists('marcas_veiculos', 'id')],
            'modelo' => [
                'required',
                'string',
                'max:120',
                Rule::unique('modelos_veiculos')
                    ->where('marca_id', $this->input('marca_id'))
                    ->ignore($atual instanceof ModeloVeiculo ? $atual->id : null),
            ],
            'tipo' => ['required', Rule::in(['carro', 'moto'])],
            'ano_minimo_entrega' => $ano,
            'ano_minimo_corrida' => $ano,
            // não existe moto elétrica no catálogo
            'ano_minimo_eletrico' => [...$ano, 'prohibited_if:tipo,moto'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'modelo.unique' => 'Esta marca já tem um modelo com esse nome.',
            'ano_minimo_eletrico.prohibited_if' => 'Moto não tem ano mínimo de elétrico.',
        ];
    }
}
