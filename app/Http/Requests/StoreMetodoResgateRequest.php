<?php

namespace App\Http\Requests;

use App\Models\MetodoResgate;
use App\Support\DocumentoBrasileiro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreMetodoResgateRequest extends FormRequest
{
    public static function chaveDoCodigo(int $userId): string
    {
        return "codigo-metodo-resgate:{$userId}";
    }

    public static function resumoDoCodigo(string $codigo): string
    {
        return hash_hmac('sha256', $codigo, (string) config('app.key'));
    }

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $pixTipo = $this->input('pix_tipo');
        $chave = trim((string) $this->input('pix_chave'));

        $chave = match ($pixTipo) {
            'cpf', 'cnpj' => DocumentoBrasileiro::digitos($chave),
            // +55 na frente é o mesmo número
            'telefone' => (string) preg_replace('/^55(?=\d{10,11}$)/', '', DocumentoBrasileiro::digitos($chave)),
            'email', 'aleatoria' => mb_strtolower($chave),
            default => $chave,
        };

        $this->merge(array_filter([
            'pix_chave' => $this->has('pix_chave') ? $chave : null,
            'documento' => $this->has('documento') ? DocumentoBrasileiro::digitos($this->input('documento')) : null,
            'titular_nome' => $this->has('titular_nome') ? trim((string) $this->input('titular_nome')) : null,
            'agencia' => $this->has('agencia') ? DocumentoBrasileiro::digitos($this->input('agencia')) : null,
            'conta' => $this->has('conta') ? DocumentoBrasileiro::digitos($this->input('conta')) : null,
            'agencia_digito' => $this->filled('agencia_digito') ? mb_strtoupper(trim((string) $this->input('agencia_digito'))) : null,
            'conta_digito' => $this->filled('conta_digito') ? mb_strtoupper(trim((string) $this->input('conta_digito'))) : null,
        ], fn ($valor) => $valor !== null));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(MetodoResgate::TIPOS)],
            'pix_tipo' => ['required_if:tipo,pix', 'nullable', Rule::in(MetodoResgate::TIPOS_PIX)],
            'pix_chave' => ['required_if:tipo,pix', 'nullable', 'string', 'max:140'],
            'documento' => ['required', 'string'],
            'titular_nome' => ['required_if:tipo,conta', 'nullable', 'string', 'min:3', 'max:120'],
            'banco_codigo' => ['required_if:tipo,conta', 'nullable', 'digits:3'],
            'banco_nome' => ['required_if:tipo,conta', 'nullable', 'string', 'max:80'],
            'agencia' => ['required_if:tipo,conta', 'nullable', 'digits_between:1,5'],
            'agencia_digito' => ['nullable', 'string', 'size:1', 'alpha_num'],
            'conta' => ['required_if:tipo,conta', 'nullable', 'digits_between:1,13'],
            'conta_digito' => ['required_if:tipo,conta', 'nullable', 'string', 'size:1', 'alpha_num'],
            'conta_tipo' => ['required_if:tipo,conta', 'nullable', Rule::in(MetodoResgate::TIPOS_CONTA)],
            'codigo' => ['required_if:tipo,conta', 'nullable', 'digits:6'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pix_chave.required_if' => 'Informe a chave Pix.',
            'pix_tipo.required_if' => 'Escolha o tipo da chave Pix.',
            'titular_nome.required_if' => 'Informe o nome do titular da conta.',
            'banco_codigo.required_if' => 'Escolha o banco.',
            'agencia.required_if' => 'Informe a agência.',
            'conta.required_if' => 'Informe a conta.',
            'conta_digito.required_if' => 'Informe o dígito da conta.',
            'conta_tipo.required_if' => 'Escolha o tipo da conta.',
            'codigo.required_if' => 'Informe o código enviado por SMS.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $documento = (string) $this->input('documento');
                if (! in_array(strlen($documento), [11, 14], true) || ! DocumentoBrasileiro::cpfOuCnpjValido($documento)) {
                    $validator->errors()->add('documento', 'Informe um CPF ou CNPJ válido.');
                }

                if ($this->input('tipo') === 'pix' && ! $this->chavePixValida()) {
                    $validator->errors()->add('pix_chave', 'A chave Pix não corresponde ao tipo escolhido.');
                }

                if ($this->input('tipo') === 'conta' && $this->filled('codigo') && ! $this->codigoConfere()) {
                    $validator->errors()->add('codigo', 'Código inválido ou expirado. Peça um novo.');
                }
            },
        ];
    }

    private function chavePixValida(): bool
    {
        $chave = (string) $this->input('pix_chave');

        return match ($this->input('pix_tipo')) {
            'cpf' => DocumentoBrasileiro::cpfValido($chave),
            'cnpj' => DocumentoBrasileiro::cnpjValido($chave),
            'telefone' => (bool) preg_match('/^\d{10,11}$/', $chave),
            'email' => filter_var($chave, FILTER_VALIDATE_EMAIL) !== false,
            'aleatoria' => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $chave),
            default => false,
        };
    }

    private function codigoConfere(): bool
    {
        $esperado = Cache::get(self::chaveDoCodigo((int) $this->user()?->id));

        return is_string($esperado)
            && hash_equals($esperado, self::resumoDoCodigo((string) $this->input('codigo')));
    }
}
