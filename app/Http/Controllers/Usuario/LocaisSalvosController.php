<?php

namespace App\Http\Controllers\Usuario;

use App\Http\Controllers\Controller;
use App\Models\LocalSalvo;
use App\Support\PerfilLogado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocaisSalvosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $locais = LocalSalvo::where('passageiro_id', $this->passageiroId($request))
            ->orderByRaw("CASE tipo WHEN 'casa' THEN 0 WHEN 'trabalho' THEN 1 ELSE 2 END")
            ->orderBy('nome')
            ->orderBy('id')
            ->get();

        return response()->json($locais);
    }

    public function store(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tipo' => ['required', 'string', Rule::in(LocalSalvo::TIPOS)],
            'nome' => ['nullable', 'string', 'max:60'],
            'endereco' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $passageiroId = $this->passageiroId($request);

        if (in_array($dados['tipo'], LocalSalvo::TIPOS_UNICOS, true)) {
            $local = DB::transaction(fn () => LocalSalvo::updateOrCreate(
                ['passageiro_id' => $passageiroId, 'tipo' => $dados['tipo']],
                $dados,
            ));

            return response()->json($local, $local->wasRecentlyCreated ? 201 : 200);
        }

        $favoritos = LocalSalvo::where('passageiro_id', $passageiroId)->where('tipo', 'favorito')->count();

        if ($favoritos >= LocalSalvo::LIMITE_FAVORITOS) {
            throw ValidationException::withMessages([
                'tipo' => 'Você pode guardar até '.LocalSalvo::LIMITE_FAVORITOS.' favoritos.',
            ]);
        }

        $local = LocalSalvo::create([...$dados, 'passageiro_id' => $passageiroId]);

        return response()->json($local, 201);
    }

    public function destroy(Request $request, int $localSalvo): Response
    {
        LocalSalvo::where('passageiro_id', $this->passageiroId($request))
            ->findOrFail($localSalvo)
            ->delete();

        return response()->noContent();
    }

    private function passageiroId(Request $request): int
    {
        return (PerfilLogado::passageiro($request) ?? abort(403, 'Locais salvos são do passageiro.'))->id;
    }
}
