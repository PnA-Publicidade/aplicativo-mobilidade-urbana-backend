<?php

namespace App\Services;

use App\Models\Corrida;
use App\Support\ImagemDoSelo;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bloqueio entre motorista e passageiro, feito na tela de avaliação.
 *
 * - O motorista que bloqueia um passageiro não recebe mais chamadas dele.
 * - O passageiro que bloqueia um motorista não toca mais para ele.
 * O bloqueio vale nas duas direções para o despacho: se um dos dois bloqueou,
 * os dois nunca são colocados na mesma corrida.
 */
class BloqueioUsuarioService
{
    public const MOTORISTA = 'motorista';

    public const PASSAGEIRO = 'passageiro';

    /** Bloqueia o outro lado da corrida finalizada. Repetir o bloqueio não duplica. */
    public function bloquear(string $perfil, int $perfilId, int $corridaId): int
    {
        $corrida = Corrida::find($corridaId);

        if ($corrida === null || $corrida->motorista_id === null) {
            throw new RuntimeException('Corrida não encontrada.', 404);
        }

        $ehDoMotorista = $perfil === self::MOTORISTA && $corrida->motorista_id === $perfilId;
        $ehDoPassageiro = $perfil === self::PASSAGEIRO && $corrida->passageiro_id === $perfilId;

        if (! $ehDoMotorista && ! $ehDoPassageiro) {
            throw new RuntimeException('Corrida não encontrada.', 404);
        }

        if ($corrida->status_corrida !== 'finalizada') {
            throw new RuntimeException('Só dá para bloquear depois que a corrida terminar.', 409);
        }

        $chaves = [
            'motorista_id' => $corrida->motorista_id,
            'passageiro_id' => $corrida->passageiro_id,
            'bloqueado_por' => $perfil,
        ];

        $existente = DB::table('bloqueios_usuarios')->where($chaves)->value('id');

        if ($existente !== null) {
            return (int) $existente;
        }

        return (int) DB::table('bloqueios_usuarios')->insertGetId([
            ...$chaves,
            'corrida_id' => $corrida->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Desfaz um bloqueio que a própria pessoa fez. */
    public function desbloquear(string $perfil, int $perfilId, int $bloqueioId): bool
    {
        $coluna = $perfil === self::MOTORISTA ? 'motorista_id' : 'passageiro_id';

        return DB::table('bloqueios_usuarios')
            ->where('id', $bloqueioId)
            ->where('bloqueado_por', $perfil)
            ->where($coluna, $perfilId)
            ->delete() > 0;
    }

    /**
     * Bloqueados por quem pergunta, com o contexto da avaliação que a pessoa deu
     * na corrida do bloqueio: nota, selos e comentário (se houver).
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    public function listar(string $perfil, int $perfilId, int $porPagina = 20): LengthAwarePaginator
    {
        $doMotorista = $perfil === self::MOTORISTA;
        $coluna = $doMotorista ? 'b.motorista_id' : 'b.passageiro_id';

        // o outro lado: o passageiro para quem é motorista e vice-versa
        $consulta = DB::table('bloqueios_usuarios as b')
            ->where('b.bloqueado_por', $perfil)
            ->where($coluna, $perfilId)
            ->leftJoin('corridas as c', 'c.id', '=', 'b.corrida_id')
            // a avaliação que quem bloqueou deu naquela corrida (avaliador = id do perfil)
            ->leftJoin('avaliacoes_corridas as a', fn ($join) => $join
                ->on('a.corrida_id', '=', 'b.corrida_id')
                ->where('a.tipo_usuario', '=', $perfil)
                ->where('a.avaliador_id', '=', $perfilId)
                ->where('a.automatica', false));

        $consulta->leftJoin($doMotorista ? 'passageiros as o' : 'motoristas as o', 'o.id', '=', $doMotorista ? 'b.passageiro_id' : 'b.motorista_id');

        $pagina = $consulta
            ->orderByDesc('b.id')
            ->select(['b.id', 'b.created_at', 'c.codigo_corrida', 'o.name as nome', 'a.nota', 'a.comentario', 'a.elogios'])
            ->paginate($porPagina);

        $titulos = $this->titulosDosSelos();

        $pagina->setCollection($pagina->getCollection()->map(fn ($linha): array => [
            'id' => (int) $linha->id,
            'nome' => $this->primeiroNome((string) ($linha->nome ?? '')),
            'corrida' => $linha->codigo_corrida,
            'bloqueado_em' => $linha->created_at,
            'nota' => $linha->nota === null ? null : (int) $linha->nota,
            'comentario' => filled($linha->comentario) ? (string) $linha->comentario : null,
            'selos' => collect((array) json_decode((string) $linha->elogios, true))
                ->map(fn ($chave) => $titulos[$chave] ?? null)->filter()->values()->all(),
        ]));

        return $pagina;
    }

    /** Algum dos dois bloqueou o outro? */
    public function existeEntre(int $motoristaId, int $passageiroId): bool
    {
        return DB::table('bloqueios_usuarios')
            ->where('motorista_id', $motoristaId)
            ->where('passageiro_id', $passageiroId)
            ->exists();
    }

    /** Subconsulta com os passageiros que não podem ser atendidos por este motorista. */
    public function passageirosBloqueadosDoMotorista(int $motoristaId): Builder
    {
        return DB::table('bloqueios_usuarios')->where('motorista_id', $motoristaId)->select('passageiro_id');
    }

    /**
     * @return array<string, array{titulo: string, icone: string, imagem: string|null}>
     */
    private function titulosDosSelos(): array
    {
        return DB::table('selos_avaliacao')->get(['chave', 'titulo', 'icone', 'imagem_path', 'imagem_url'])
            ->mapWithKeys(fn ($s) => [$s->chave => ['titulo' => $s->titulo, 'icone' => $s->icone, 'imagem' => ImagemDoSelo::url($s->imagem_path, $s->imagem_url)]])
            ->all();
    }

    private function primeiroNome(string $nome): string
    {
        return trim(explode(' ', trim($nome))[0]) ?: 'Usuário';
    }
}
