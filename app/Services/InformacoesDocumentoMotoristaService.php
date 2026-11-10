<?php

namespace App\Services;

use App\Enums\TipoDocumentoMotorista;
use Illuminate\Http\Request;

class InformacoesDocumentoMotoristaService
{
    public function normalizar(Request $request): void
    {
        // Compatibilidade com os clientes que ainda enviam cnh/crlv.
        $tipo = $request->input('tipo_documento');
        if (! $request->exists('informacoes_complementares') && in_array($tipo, ['cnh', 'crlv'], true)) {
            if ($request->exists($tipo)) {
                $request->merge(['informacoes_complementares' => $request->input($tipo)]);
            }
        }
        if ($tipo === TipoDocumentoMotorista::NADA_CONSTA->value && is_array($request->input('informacoes_complementares'))) {
            $dados = $request->input('informacoes_complementares');
            foreach ($dados as $campo => $valor) {
                if (is_string($valor)) {
                    $dados[$campo] = trim($valor);
                }
            }
            if (isset($dados['cpf']) && is_string($dados['cpf'])) {
                $dados['cpf'] = preg_replace('/\D/', '', $dados['cpf']);
            }
            $request->merge(['informacoes_complementares' => $dados]);
        }
    }

    public function regras(Request $request): array
    {
        if ($request->input('tipo_documento') === TipoDocumentoMotorista::CRLV->value) {
            return app(CrlvVeiculoService::class)->regras($request);
        }
        if ($request->input('tipo_documento') === TipoDocumentoMotorista::NADA_CONSTA->value) {
            return [
                'veiculo_id' => 'prohibited',
                'informacoes_complementares' => 'required|array:numero_certidao,orgao_emissor,nome,nome_pai,nome_mae,cpf,data_nascimento,data_emissao,hora_emissao,data_validade,resultado',
                'informacoes_complementares.numero_certidao' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\/-]+$/'],
                'informacoes_complementares.orgao_emissor' => 'required|string|max:255',
                'informacoes_complementares.nome' => 'required|string|max:255',
                'informacoes_complementares.nome_pai' => 'nullable|string|max:255',
                'informacoes_complementares.nome_mae' => 'nullable|string|max:255',
                'informacoes_complementares.cpf' => ['required', 'string', 'regex:/^[0-9]{11}$/'],
                'informacoes_complementares.data_nascimento' => 'nullable|date_format:Y-m-d',
                'informacoes_complementares.data_emissao' => 'required|date_format:Y-m-d',
                'informacoes_complementares.hora_emissao' => 'nullable|date_format:H:i',
                'informacoes_complementares.data_validade' => 'required|date_format:Y-m-d|after_or_equal:informacoes_complementares.data_emissao',
                'informacoes_complementares.resultado' => 'required|string|max:5000',
            ];
        }
        if ($request->input('tipo_documento') !== TipoDocumentoMotorista::CNH->value) {
            // no seguro só a validade: nem sempre ela está no arquivo, e aí a gestão preenche
            return [
                'veiculo_id' => 'prohibited',
                'informacoes_complementares' => 'nullable|array:data_validade',
                'informacoes_complementares.data_validade' => 'nullable|date_format:Y-m-d',
            ];
        }

        return [
            'veiculo_id' => 'prohibited',
            'informacoes_complementares' => 'sometimes|nullable|array:nome,cpf,data_nascimento,numero_registro,cnh_categoria,primeira_habilitacao,data_emissao,cnh_expiracao,ear,observacao',
            'informacoes_complementares.nome' => 'nullable|string|max:255',
            'informacoes_complementares.cpf' => ['nullable', 'string', 'regex:/^[0-9]{11}$/'],
            'informacoes_complementares.data_nascimento' => 'nullable|date_format:Y-m-d',
            'informacoes_complementares.numero_registro' => 'nullable|string|max:20',
            'informacoes_complementares.cnh_categoria' => 'nullable|string|max:20',
            'informacoes_complementares.primeira_habilitacao' => 'nullable|date_format:Y-m-d',
            'informacoes_complementares.data_emissao' => 'nullable|date_format:Y-m-d',
            'informacoes_complementares.cnh_expiracao' => 'nullable|date_format:Y-m-d',
            'informacoes_complementares.ear' => 'nullable|boolean',
            'informacoes_complementares.observacao' => 'nullable|string|max:5000',
        ];
    }
}
