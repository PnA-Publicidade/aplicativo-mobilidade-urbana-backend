<?php

namespace App\Services;

use App\Models\Motorista;
use App\Models\MotoristaDocumento;
use App\Models\MotoristaVeiculo;
use App\Models\Veiculo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SolicitacaoVeiculoService
{
    public function validarCategoria(string $selecionada, array $informacoes): void
    {
        $identificada = $informacoes['categoria_veiculo'] ?? null;
        if (! in_array($identificada, ['carro', 'moto'], true)) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível identificar se o CRLV é de carro ou moto. Envie um PDF legível ou uma foto nítida do documento inteiro.',
            ]);
        }
        if ($identificada !== $selecionada) {
            throw ValidationException::withMessages([
                'arquivo' => "O CRLV enviado é de {$identificada}, mas você selecionou {$selecionada}. Selecione a opção correta e envie novamente.",
            ]);
        }
    }

    /** A categoria conferida não pode contradizer o arquivo original. */
    public function validarCategoriaDocumento(MotoristaDocumento $documento, array $informacoes): void
    {
        $solicitacao = $documento->solicitacao_veiculo;
        $identificada = $solicitacao['categoria_identificada'] ?? null;
        if ($identificada === null) {
            // Envios antigos não possuem identificação confiável: reler o anexo,
            // sem confiar no campo editável nem na opção escolhida pelo motorista.
            $disk = Storage::disk(ArmazenarAnexoMotoristaService::DIRETORIO);
            $nome = basename((string) $documento->path);
            if ($nome !== '' && $disk->exists($nome)) {
                $arquivo = new UploadedFile($disk->path($nome), $documento->name, $documento->mime_type, null, true);
                $identificada = app(LeituraCrlvService::class)->extrair($arquivo)['categoria_veiculo'] ?? null;
            }
        }
        $this->validarCategoria((string) ($solicitacao['categoria'] ?? ''), ['categoria_veiculo' => $identificada]);
        if (($informacoes['categoria_veiculo'] ?? null) !== $identificada) {
            throw ValidationException::withMessages([
                'informacoes_complementares.categoria_veiculo' => "O tipo do veículo deve corresponder ao CRLV: {$identificada}.",
            ]);
        }
    }

    public function validar(array $informacoes): array
    {
        $request = new Request(['tipo_documento' => 'crlv', 'informacoes_complementares' => $informacoes]);
        $service = app(CrlvVeiculoService::class);
        $service->normalizar($request);
        $dados = Validator::make($request->all(), $service->regras($request))->validate();

        return $dados['informacoes_complementares'];
    }

    public function vincularNaAprovacao(MotoristaDocumento $documento, Motorista $motorista): void
    {
        $dados = $this->validar($documento->informacoes_complementares ?? []);
        $this->validarCategoriaDocumento($documento, $dados);
        if (Veiculo::where('placa', $dados['placa'])->orWhere('renavam', $dados['renavam'])
            ->orWhere('chassi', $dados['chassi'])->lockForUpdate()->exists()) {
            throw ValidationException::withMessages([
                'informacoes_complementares.placa' => 'O veículo já está cadastrado. Use o fluxo de compartilhamento ou renovação do CRLV.',
            ]);
        }
        $origem = MotoristaVeiculo::where('motorista_id', $motorista->id)->exists() ? 'veiculos' : 'documentos';
        $veiculo = app(CrlvVeiculoService::class)->registrarOuAtualizar($motorista, $dados, null, $origem);
        $documento->update(['veiculo_id' => $veiculo->id, 'informacoes_complementares' => $dados]);
        $documento->unsetRelation('veiculo');
    }
}
