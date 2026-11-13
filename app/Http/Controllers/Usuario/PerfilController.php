<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\ReputacaoService;
use App\Services\RestricaoNotaService;
use App\Support\FotoDePerfil;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** o perfil logado nos apps (motorista ou passageiro): dados, foto, telefone e e-mail */
class PerfilController extends Controller
{
    public function usuarioLogado(Request $request): JsonResponse
    {
        $perfil = PerfilLogado::de($request);
        abort_if($perfil->indisponivelNoApp(), 403, 'Este perfil está indisponível. Entre em contato com o suporte.');

        $passageiroId = $perfil instanceof Passageiro ? $perfil->id : null;
        // nota base 5,0 até a primeira avaliação real; "novo" enquanto tem poucas corridas
        $reputacao = $passageiroId === null ? null : app(ReputacaoService::class)->doPassageiro($passageiroId);

        return response()->json([
            ...$perfil->dadosDoApp(),
            'nota_passageiro' => $reputacao['nota'] ?? ReputacaoService::notaBase(),
            // o perfil do passageiro mostra quantas corridas ele já fez
            'corridas_passageiro' => $reputacao['corridas'] ?? 0,
            'passageiro_novo' => $reputacao['novo'] ?? true,
            'corridas_para_deixar_de_ser_novo' => ReputacaoService::corridasParaDeixarDeSerNovo(),
            // aviso, suspensão ou bloqueio por nota baixa (em modo sombra não aparece alerta)
            'situacao_nota' => $passageiroId === null ? null : app(RestricaoNotaService::class)->situacao(RestricaoNotaService::PASSAGEIRO, $passageiroId),
        ]);
    }

    public function alterarFotoPerfil(Request $request, string $id): JsonResponse
    {
        $request->validate(['image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120']);
        $perfil = $this->proprio($request, $id);
        $fotoAnterior = $perfil->getAttribute('foto');
        FotoDePerfil::salvar($request, $perfil);
        FotoDePerfil::excluir($fotoAnterior);

        return response()->json([
            'user' => ['foto' => $perfil->getAttribute('foto')],
            'success' => true,
            'message' => 'Foto alterada com sucesso!',
        ]);
    }

    public function removerFotoPerfil(Request $request, string $id): JsonResponse
    {
        $perfil = $this->proprio($request, $id);
        $foto = $perfil->getAttribute('foto');
        $perfil->setAttribute('foto', null);
        $perfil->saveOrFail();
        FotoDePerfil::excluir($foto);

        return response()->json(['success' => true, 'message' => 'Foto removida com sucesso!']);
    }

    /** telefone ou e-mail novo: primeiro vai um código, a troca só acontece depois de confirmado */
    public function enviarCodigoDeContato(Request $request): JsonResponse
    {
        $perfil = $this->logado($request);
        [$campo, $valor] = $this->novoContato($request, $perfil);
        $codigo = (string) random_int(1000, 9999);

        Cache::put(
            $this->chaveDoContato($perfil, $campo),
            ['valor' => $valor, 'codigo' => Hash::make($codigo)],
            now()->addMinutes(30),
        );

        // ponytail: SMS e e-mail ainda são simulados, como no login; o envio de verdade entra aqui
        return response()->json(array_filter([
            'message' => 'Código enviado.',
            'codigo' => app()->environment(['local', 'testing']) ? $codigo : null,
        ]));
    }

    public function alterarContato(Request $request): JsonResponse
    {
        $perfil = $this->logado($request);
        [$campo, $valor] = $this->novoContato($request, $perfil);
        $request->validate(['codigo' => 'required|digits:4']);
        $chave = $this->chaveDoContato($perfil, $campo);
        $pedido = Cache::get($chave);

        // o código vale só para o valor para o qual foi pedido
        if (! is_array($pedido) || $pedido['valor'] !== $valor || ! Hash::check($request->string('codigo')->toString(), $pedido['codigo'])) {
            throw ValidationException::withMessages(['codigo' => 'Código inválido ou expirado.']);
        }

        $perfil->update([$campo => $valor]);
        Cache::forget($chave);

        return response()->json([
            'user' => $perfil->fresh()?->dadosDoApp(),
            'message' => $campo === 'telefone' ? 'Telefone alterado.' : 'E-mail alterado.',
        ]);
    }

    /** a foto é só do próprio perfil (o id da rota é o do perfil logado) */
    private function proprio(Request $request, string $id): Motorista|Passageiro
    {
        $perfil = $this->logado($request);
        abort_unless((string) $perfil->id === $id, 403, 'Você só pode alterar a foto da própria conta.');

        return $perfil;
    }

    private function logado(Request $request): Motorista|Passageiro
    {
        $perfil = PerfilLogado::de($request);
        abort_if($perfil->indisponivelNoApp(), 403, 'Este perfil está indisponível.');

        return $perfil;
    }

    /**
     * O telefone (só os números, como no cadastro) ou o e-mail novo, livre nas contas do mesmo app.
     *
     * @return array{0: 'telefone'|'email', 1: string}
     */
    private function novoContato(Request $request, Motorista|Passageiro $perfil): array
    {
        $campo = $request->validate(['campo' => 'required|in:telefone,email'])['campo'];
        $valor = trim((string) $request->input('valor'));
        $request->merge(['valor' => $campo === 'telefone' ? preg_replace('/\D/', '', $valor) : mb_strtolower($valor)]);

        $request->validate([
            'valor' => [
                'required',
                ...($campo === 'telefone' ? ['regex:/^\d{10,11}$/'] : ['email', 'max:255']),
                Rule::unique($perfil->getTable(), $campo)->ignore($perfil->getKey()),
                // trocar pelo mesmo não precisa de código
                Rule::notIn([(string) $perfil->getAttribute($campo)]),
            ],
        ], [
            'valor.required' => $campo === 'telefone' ? 'Informe o novo telefone.' : 'Informe o novo e-mail.',
            'valor.regex' => 'Informe o telefone com DDD.',
            'valor.email' => 'Informe um e-mail válido.',
            'valor.unique' => $campo === 'telefone' ? 'Este telefone já está em outra conta.' : 'Este e-mail já está em outra conta.',
            'valor.not_in' => $campo === 'telefone' ? 'Este já é o seu telefone.' : 'Este já é o seu e-mail.',
        ]);

        return [$campo, (string) $request->input('valor')];
    }

    private function chaveDoContato(Motorista|Passageiro $perfil, string $campo): string
    {
        return "troca_contato:{$perfil->perfilDoApp()}:{$perfil->getKey()}:$campo";
    }
}
