<?php

namespace App\Http\Controllers\Motorista;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMetodoResgateRequest;
use App\Models\MetodoResgate;
use App\Models\Motorista;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MetodosResgateController extends Controller
{
    private const CAMPOS_PIX = ['pix_tipo', 'pix_chave'];

    private const CAMPOS_CONTA = [
        'titular_nome',
        'banco_codigo',
        'banco_nome',
        'agencia',
        'agencia_digito',
        'conta',
        'conta_digito',
        'conta_tipo',
    ];

    public function index(Request $request): JsonResponse
    {
        $motorista = $this->motorista($request);

        return response()->json([
            'data' => MetodoResgate::where('motorista_id', $motorista->id)
                ->orderByDesc('principal')
                ->orderBy('tipo', 'desc')
                ->get(),
        ]);
    }

    public function store(StoreMetodoResgateRequest $request): JsonResponse
    {
        $motorista = $this->motorista($request);
        $dados = $request->validated();
        $ehPix = $dados['tipo'] === 'pix';

        $campos = ['documento' => $dados['documento']];
        foreach ([...self::CAMPOS_PIX, ...self::CAMPOS_CONTA] as $campo) {
            $doTipo = in_array($campo, self::CAMPOS_PIX, true) === $ehPix;
            $campos[$campo] = $doTipo ? ($dados[$campo] ?? null) : null;
        }

        $metodo = DB::transaction(function () use ($motorista, $dados, $campos) {
            $temPrincipal = MetodoResgate::where('motorista_id', $motorista->id)
                ->lockForUpdate()
                ->get()
                ->contains('principal', true);

            $metodo = MetodoResgate::updateOrCreate(
                ['motorista_id' => $motorista->id, 'tipo' => $dados['tipo']],
                $campos,
            );

            if (! $temPrincipal) {
                $metodo->update(['principal' => true]);
            }

            return $metodo;
        });

        if (! $ehPix) {
            Cache::forget(StoreMetodoResgateRequest::chaveDoCodigo((int) $request->user()->id));
        }

        $criado = $metodo->wasRecentlyCreated;

        return response()->json(['data' => $metodo->refresh()], $criado ? 201 : 200);
    }

    public function principal(Request $request, int $metodo): JsonResponse
    {
        $motorista = $this->motorista($request);
        $escolhido = MetodoResgate::where('motorista_id', $motorista->id)->findOrFail($metodo);

        DB::transaction(function () use ($motorista, $escolhido) {
            MetodoResgate::where('motorista_id', $motorista->id)->update(['principal' => false]);
            $escolhido->update(['principal' => true]);
        });

        return response()->json(['data' => $escolhido->refresh()]);
    }

    public function destroy(Request $request, int $metodo): Response
    {
        $motorista = $this->motorista($request);
        $removido = MetodoResgate::where('motorista_id', $motorista->id)->findOrFail($metodo);

        DB::transaction(function () use ($motorista, $removido) {
            $removido->delete();

            if ($removido->principal) {
                MetodoResgate::where('motorista_id', $motorista->id)
                    ->limit(1)
                    ->update(['principal' => true]);
            }
        });

        return response()->noContent();
    }

    /**
     * Código de 6 dígitos para confirmar a conta bancária. Ainda não há
     * provedor de SMS: o código vai para o log e, em desenvolvimento, volta
     * na resposta.
     */
    public function enviarCodigo(Request $request): JsonResponse
    {
        $this->motorista($request);
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put(
            StoreMetodoResgateRequest::chaveDoCodigo((int) $request->user()->id),
            StoreMetodoResgateRequest::resumoDoCodigo($codigo),
            now()->addMinutes((int) config('saques.codigo_sms_validade_minutos')),
        );

        Log::info('Código da conta bancária (SMS simulado)', ['user_id' => $request->user()->id]);

        return response()->json(array_filter([
            'enviado' => true,
            'codigo_teste' => config('saques.codigo_sms_na_resposta') ? $codigo : null,
        ], fn ($valor) => $valor !== null));
    }

    private function motorista(Request $request): Motorista
    {
        $motorista = Motorista::where('user_id', $request->user()->id)->first();

        abort_if($motorista === null, 403, 'Cadastro de motorista não encontrado.');

        return $motorista;
    }
}
