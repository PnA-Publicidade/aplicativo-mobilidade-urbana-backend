<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrlvVeiculoService
{
    public function regras(Request $request): array
    {
        $cadastro = $request->input('tipo_documento') === 'crlv' && ! $request->filled('veiculo_id');

        return [
            'veiculo_id' => 'nullable|prohibited_unless:tipo_documento,crlv|integer|exists:veiculos,id',
            'informacoes_complementares' => 'required_if:tipo_documento,crlv|prohibited_unless:tipo_documento,crlv|array:placa,renavam,numero_crv,codigo_seguranca,chassi,exercicio,data_emissao,nome_proprietario,cpf_cnpj_proprietario,marca_modelo,cor,ano_fabricacao,ano_modelo,categoria,categoria_veiculo,uf,observacao',
            'informacoes_complementares.placa' => ['required_if:tipo_documento,crlv', 'string', 'regex:/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/'],
            'informacoes_complementares.renavam' => 'required_if:tipo_documento,crlv|digits:11',
            'informacoes_complementares.numero_crv' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'informacoes_complementares.codigo_seguranca' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
            'informacoes_complementares.chassi' => ['required_if:tipo_documento,crlv', 'string', 'regex:/^[A-HJ-NPR-Z0-9]{17}$/'],
            'informacoes_complementares.exercicio' => 'required_if:tipo_documento,crlv|integer|between:1900,'.(now()->year + 1),
            'informacoes_complementares.data_emissao' => 'nullable|date_format:Y-m-d',
            'informacoes_complementares.nome_proprietario' => 'nullable|string|max:255',
            'informacoes_complementares.cpf_cnpj_proprietario' => ['required_if:tipo_documento,crlv', 'string', 'regex:/^(?:[0-9]{11}|[0-9]{14})$/'],
            'informacoes_complementares.marca_modelo' => [Rule::requiredIf($cadastro), 'nullable', 'string', 'max:255', ...($cadastro ? ['regex:/^[^\/]+\s*\/\s*.+$/'] : [])],
            'informacoes_complementares.categoria_veiculo' => [Rule::requiredIf($cadastro), 'nullable', 'in:carro,moto,bicicleta'],
            'informacoes_complementares.cor' => [Rule::requiredIf($cadastro), 'nullable', 'string', 'max:40'],
            'informacoes_complementares.ano_fabricacao' => [Rule::requiredIf($cadastro), 'nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'informacoes_complementares.ano_modelo' => [Rule::requiredIf($cadastro), 'nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'informacoes_complementares.categoria' => 'nullable|string|max:60',
            'informacoes_complementares.uf' => [Rule::requiredIf($cadastro), 'nullable', 'string', 'size:2'],
            'informacoes_complementares.observacao' => 'nullable|string|max:5000',
        ];
    }

    public function normalizar(Request $request): void
    {
        if ($request->input('tipo_documento') !== 'crlv' || ! is_array($request->input('informacoes_complementares'))) {
            return;
        }
        $dados = $request->input('informacoes_complementares');
        foreach (['placa', 'chassi', 'uf'] as $campo) {
            if (isset($dados[$campo]) && is_scalar($dados[$campo])) {
                $dados[$campo] = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $dados[$campo]));
            }
        }
        foreach (['renavam', 'cpf_cnpj_proprietario'] as $campo) {
            if (isset($dados[$campo]) && is_scalar($dados[$campo])) {
                $dados[$campo] = preg_replace('/\D/', '', (string) $dados[$campo]);
            }
        }
        foreach (['numero_crv', 'codigo_seguranca'] as $campo) {
            if (isset($dados[$campo]) && is_scalar($dados[$campo]) && preg_match('/^[0-9.\s-]+$/', (string) $dados[$campo])) {
                $dados[$campo] = preg_replace('/\D/', '', (string) $dados[$campo]);
            }
        }
        if (isset($dados['categoria_veiculo']) && is_string($dados['categoria_veiculo'])) {
            $dados['categoria_veiculo'] = strtolower(trim($dados['categoria_veiculo']));
        }
        $request->merge(['informacoes_complementares' => $dados]);
    }

    public function veiculoVinculado(int $motoristaId, int $veiculoId): Veiculo
    {
        if (! MotoristaVeiculo::where('motorista_id', $motoristaId)->where('veiculo_id', $veiculoId)->exists()) {
            throw ValidationException::withMessages(['veiculo_id' => 'Selecione um veículo vinculado a este motorista.']);
        }

        return Veiculo::lockForUpdate()->findOrFail($veiculoId);
    }

    public function registrarOuAtualizar(Motorista $motorista, array $dados, ?int $veiculoId, string $origem = 'documentos'): Veiculo
    {
        $possuiVeiculo = MotoristaVeiculo::where('motorista_id', $motorista->id)->exists();
        if ($origem === 'veiculos' && ! $possuiVeiculo) {
            throw ValidationException::withMessages(['origem' => 'Cadastre o primeiro veículo na área de Documentos.']);
        }

        $veiculo = $veiculoId !== null
            ? $this->veiculoVinculado($motorista->id, $veiculoId)
            : Veiculo::where(function ($query) use ($dados) {
                $query->where('placa', $dados['placa'])->orWhere('renavam', $dados['renavam'])->orWhere('chassi', $dados['chassi']);
            })->lockForUpdate()->first();

        if ($veiculo !== null) {
            $this->validar($veiculo, $dados);
        }
        $vinculado = $veiculo !== null && MotoristaVeiculo::where('motorista_id', $motorista->id)->where('veiculo_id', $veiculo->id)->exists();
        if ($origem === 'documentos' && $possuiVeiculo && ! $vinculado) {
            throw ValidationException::withMessages(['origem' => 'Cadastre veículos adicionais em Motoristas > Veículos > Adicionar veículo.']);
        }

        if ($veiculo === null) {
            [$marca, $modelo] = array_map('trim', explode('/', $dados['marca_modelo'], 2));
            $veiculo = Veiculo::firstOrCreate(['placa' => $dados['placa'], 'renavam' => $dados['renavam']], [
                'marca' => $marca,
                'modelo' => $modelo,
                'ano_fabricacao' => $dados['ano_fabricacao'],
                'ano_modelo' => $dados['ano_modelo'],
                'cor' => $dados['cor'],
                'categoria' => $dados['categoria_veiculo'],
                'uf' => $dados['uf'],
                'status' => 'em_analise',
            ]);
        }
        $veiculo = Veiculo::lockForUpdate()->findOrFail($veiculo->id);
        $this->validar($veiculo, $dados);
        $this->validarSubstituicao($veiculo, $dados);
        $this->salvarDados($veiculo, $dados);
        app(CompartilhamentoVeiculoService::class)->vincular($motorista, $veiculo);

        return $veiculo;
    }

    private function validarSubstituicao(Veiculo $veiculo, array $dados): void
    {
        $documento = $veiculo->ultimoCrlv()->first();
        if ($documento === null || $documento->status === 'reprovado') {
            return;
        }
        $exercicio = (int) ($documento->informacoes_complementares['exercicio'] ?? $veiculo->exercicio);
        if ($exercicio < 1900 || $exercicio >= now()->year) {
            throw ValidationException::withMessages(['arquivo' => 'Este veículo já possui CRLV cadastrado. A substituição só é permitida quando o exercício do documento atual for anterior ao ano atual.']);
        }
        if ((int) $dados['exercicio'] < now()->year) {
            throw ValidationException::withMessages(['informacoes_complementares.exercicio' => 'Para renovar, envie um CRLV com exercício do ano atual ou posterior.']);
        }
    }

    public function sincronizarStatus(Veiculo $veiculo): void
    {
        $veiculo = Veiculo::lockForUpdate()->findOrFail($veiculo->id);
        $veiculo->update(['status' => $veiculo->ultimoCrlv()->first()?->status ?? 'em_analise']);
        // Atualiza os perfis após liberar o bloqueio do veículo, sem disputar
        // os bloqueios das sessões online de outros motoristas.
        DB::afterCommit(function () use ($veiculo): void {
            foreach (Motorista::whereIn('id', MotoristaVeiculo::select('motorista_id')->where('veiculo_id', $veiculo->id))->get() as $motorista) {
                app(AtualizarSituacaoMotoristaService::class)->executar($motorista);
            }
        });
    }

    public function conferencia(Veiculo $veiculo, array $dados): array
    {
        $resultado = [];
        foreach (['placa', 'renavam', 'chassi'] as $campo) {
            $atual = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) $veiculo->$campo));
            $lido = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string) ($dados[$campo] ?? '')));
            $resultado[$campo] = $atual === '' ? 'nao_cadastrado' : ($lido === '' ? 'nao_informado' : ($atual === $lido ? 'confere' : 'divergente'));
        }

        return $resultado;
    }

    public function salvarDados(Veiculo $veiculo, array $dados): void
    {
        $this->validar($veiculo, $dados);
        $campos = $dados;
        if (array_key_exists('categoria', $campos)) {
            $campos['categoria_crlv'] = $campos['categoria'];
            unset($campos['categoria']);
        }
        if (! empty($campos['categoria_veiculo'])) {
            $campos['categoria'] = $campos['categoria_veiculo'];
        }
        unset($campos['categoria_veiculo']);
        foreach (['cor', 'ano_fabricacao', 'ano_modelo', 'uf'] as $campo) {
            if (! isset($campos[$campo]) || $campos[$campo] === '') {
                unset($campos[$campo]);
            }
        }
        $veiculo->update($campos);
    }

    public function validar(Veiculo $veiculo, array $dados): void
    {
        $erros = [];
        foreach ($this->conferencia($veiculo, $dados) as $campo => $situacao) {
            if (in_array($situacao, ['divergente', 'nao_informado'], true)) {
                $erros['informacoes_complementares.'.$campo] = 'O '.strtoupper($campo).' do documento não corresponde ao veículo selecionado.';
            }
        }
        if ($erros) {
            throw ValidationException::withMessages($erros);
        }
    }
}
