<?php

namespace App\Http\Controllers\Ajuda;

use App\Enums\StatusTicket;
use App\Events\TicketLido;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Usuario\ChamadosAjudaController;
use App\Http\Resources\TicketResource;
use App\Http\Resources\TicketTransferResource;
use App\Models\ChamadoAjuda;
use App\Models\ChamadoMensagem;
use App\Models\ChamadoMensagemAnexo;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Services\TicketsAjudaService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class TicketsController extends Controller
{
    public function __construct(private TicketsAjudaService $service) {}

    private function acesso(Request $request, ?ChamadoAjuda $ticket = null): Motorista|Passageiro|Gestor
    {
        $actor = $request->user();
        if ($actor instanceof Gestor) {
            abort_unless($actor->pode('ajuda.gerenciar'), 403);
            if ($ticket) {
                abort_unless($actor->podeAcessarTicket($ticket), 404);
            }
        } else {
            abort_unless($actor instanceof Motorista || $actor instanceof Passageiro, 403);
            if ($ticket) {
                abort_unless($this->service->ehDoSolicitante($ticket, $actor), 404);
            }
        }

        return $actor;
    }

    public function index(Request $request)
    {
        $actor = $this->acesso($request);
        $dados = $request->validate([
            'status' => ['nullable', Rule::enum(StatusTicket::class)],
            'solicitante_tipo' => 'nullable|in:passageiro,motorista',
            'search' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
            'fila' => 'nullable|in:todos,entrada,meus',
        ]);
        $query = ChamadoAjuda::with(['solicitante', 'responsavel', 'corrida']);
        if (! $actor instanceof Gestor) {
            $query->where('solicitante_tipo', $actor->perfilDoApp())->where('solicitante_id', $actor->id);
        }
        if ($actor instanceof Gestor) {
            $query->comNaoLidas($actor->id);
            if (! $actor->visualizaTodosTickets() || ($dados['fila'] ?? null) === 'meus') {
                $query->whereNotIn('status', ['resolved', 'closed']);
            }
            if (! $actor->visualizaTodosTickets()) {
                $query->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'waiting' THEN 1 WHEN 'open' THEN 2 ELSE 3 END")->orderBy('fila_entrada_em');
                $query->where(function ($q) use ($actor) {
                    $q->where('gestor_responsavel_id', $actor->id);
                    if ($actor->visualizaEntradaTickets()) {
                        $q->orWhereNull('gestor_responsavel_id');
                    }
                });
            }
            if (($dados['fila'] ?? null) === 'entrada') {
                $query->whereNull('gestor_responsavel_id');
            } elseif (($dados['fila'] ?? null) === 'meus') {
                $query->where('gestor_responsavel_id', $actor->id);
            }
        }
        if (! empty($dados['status'])) {
            $query->where('status', $dados['status']);
        }
        if (! empty($dados['solicitante_tipo'])) {
            $query->where('solicitante_tipo', $dados['solicitante_tipo']);
        }
        if ($search = trim($dados['search'] ?? '')) {
            $query->where(function ($q) use ($search) {
                $q->where('motivo', 'like', '%'.$search.'%')->orWhereHas('solicitante', fn ($user) => $user->where('name', 'like', '%'.$search.'%')->orWhere('cpf', 'like', '%'.$search.'%'));
                if (preg_match('/^(?:AJ-)?(\d+)$/i', $search, $match)) {
                    $q->orWhere('id', (int) $match[1]);
                }
            });
        }

        return TicketResource::collection($query->orderByDesc('ultima_mensagem_em')->orderByDesc('id')->paginate($dados['per_page'] ?? 20));
    }

    public function operadores(Request $request)
    {
        abort_unless($this->acesso($request) instanceof Gestor, 403);
        $dados = $request->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1', 'exclude_user_id' => 'nullable|integer|min:1']);
        $query = Gestor::whereIn('perfil_acesso', ['atendimento', 'suporte_tecnico', 'gestao', 'admin']);
        if ($search = trim($dados['search'] ?? '')) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if (! empty($dados['exclude_user_id'])) {
            $query->where('id', '!=', $dados['exclude_user_id']);
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(20);
        $page->setCollection($page->getCollection()->map(fn (Gestor $gestor) => $gestor->only(['id', 'name', 'perfil_acesso', 'perfil_acesso_nome'])));

        return response()->json($page);
    }

    public function transferir(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $this->acesso($request, $ticket);
        abort_unless($gestor instanceof Gestor, 403);
        $dados = $request->validate(['to_user_id' => 'required|integer|min:1', 'note' => 'nullable|string|max:1000']);
        $nota = trim($dados['note'] ?? '');
        $registro = $this->service->transferir($ticket, $gestor, $dados['to_user_id'], $nota === '' ? null : $nota);

        return new TicketResource($registro->load(['solicitante', 'responsavel', 'corrida']));
    }

    public function transferencias(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $this->acesso($request, $ticket);
        abort_unless($gestor instanceof Gestor && $gestor->visualizaTodosTickets(), 403);
        $request->validate(['page' => 'nullable|integer|min:1']);

        return TicketTransferResource::collection($ticket->transferencias()->orderByDesc('id')->paginate(20));
    }

    public function solicitantes(Request $request)
    {
        abort_unless($this->acesso($request) instanceof Gestor, 403);
        $dados = $request->validate(['search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $search = trim($dados['search'] ?? '');
        $buscar = fn (string $modelo) => $modelo::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($f) => $f->where('name', 'like', '%'.$search.'%')->orWhere('cpf', 'like', '%'.$search.'%')))
            ->get(['id', 'name', 'cpf'])
            ->map(fn (Motorista|Passageiro $perfil) => $perfil->only(['id', 'name', 'cpf']) + ['tipo' => $perfil->perfilDoApp()]);
        $perfis = $buscar(Passageiro::class)->concat($buscar(Motorista::class))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $pagina = (int) ($dados['page'] ?? 1);

        return response()->json(new LengthAwarePaginator($perfis->forPage($pagina, 20)->values(), $perfis->count(), 20, $pagina));
    }

    public function store(Request $request)
    {
        $actor = $this->acesso($request);
        $dados = $request->validate([
            'solicitante_id' => $actor instanceof Gestor ? 'required|integer' : 'prohibited',
            'solicitante_tipo' => $actor instanceof Gestor ? 'required|in:passageiro,motorista' : 'nullable|in:passageiro,motorista',
            'motivo' => ['required', Rule::in(ChamadosAjudaController::MOTIVOS)],
            'descricao' => 'required|string|min:10|max:1000', 'corrida_id' => 'nullable|integer|min:1',
        ]);
        $solicitante = $actor instanceof Gestor
            ? ($dados['solicitante_tipo'] === 'motorista' ? Motorista::findOrFail((int) $dados['solicitante_id']) : Passageiro::findOrFail((int) $dados['solicitante_id']))
            : $actor;
        $ticket = $this->service->criar($solicitante, $dados, $actor instanceof Gestor ? $actor : null);

        return (new TicketResource($ticket->load(['solicitante', 'responsavel', 'corrida'])))->response()->setStatusCode(201);
    }

    public function show(Request $request, ChamadoAjuda $ticket)
    {
        $this->acesso($request, $ticket);

        return new TicketResource($ticket->load(['solicitante', 'responsavel', 'corrida']));
    }

    public function mensagens(Request $request, ChamadoAjuda $ticket)
    {
        $this->acesso($request, $ticket);
        $dados = $request->validate(['before_id' => 'nullable|integer|min:1', 'after_id' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:100']);
        abort_if(isset($dados['before_id'], $dados['after_id']), 422, 'Use apenas um cursor.');
        $limit = $dados['limit'] ?? 50;
        $query = $ticket->mensagens()->with('anexos');
        $after = isset($dados['after_id']);
        if ($after) {
            $query->where('id', '>', $dados['after_id']);
        }
        if (isset($dados['before_id'])) {
            $query->where('id', '<', $dados['before_id']);
        }
        $items = $after ? $query->orderBy('id')->limit($limit + 1)->get() : $query->orderByDesc('id')->limit($limit + 1)->get();
        $more = $items->count() > $limit;
        $items = $items->take($limit)->sortBy('id')->values();

        // O cursor de IDs não recupera edições em mensagens já carregadas.
        $atualizadas = $ticket->mensagens()->with('anexos')->where('tipo_sistema', 'fila_atendimento')->get()->map->dados();

        return response()->json(['data' => $items->map->dados(), 'atualizadas' => $atualizadas, 'has_more' => $more, 'next_before_id' => $items->first()?->id, 'next_after_id' => $items->last()?->id]);
    }

    public function enviar(Request $request, ChamadoAjuda $ticket)
    {
        $actor = $this->acesso($request, $ticket);
        $dados = $request->validate([
            'conteudo' => 'nullable|string|max:4000', 'client_message_id' => 'required|uuid',
            'imagens' => 'sometimes|array|max:3',
            'imagens.*' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=12000,max_height=12000',
            'autor_tipo' => 'prohibited', 'gestor_id' => 'prohibited',
        ]);
        $texto = trim($dados['conteudo'] ?? '');
        $imagens = array_values($request->file('imagens', []));
        abort_if($texto === '' && $imagens === [], 422, 'Escreva uma mensagem ou adicione uma imagem.');
        $message = $this->service->enviar($ticket, $actor, $texto, $dados['client_message_id'], $imagens);

        return response()->json(['data' => $message->dados()], 201);
    }

    public function anexo(Request $request, ChamadoAjuda $ticket, ChamadoMensagem $mensagem, ChamadoMensagemAnexo $anexo)
    {
        $this->acesso($request, $ticket);
        abort_unless($mensagem->chamado_id === $ticket->id && $anexo->mensagem_id === $mensagem->id, 404);
        $disk = Storage::disk($anexo->disk);
        abort_unless($disk->exists($anexo->path), 404);

        return $disk->response($anexo->path, $anexo->nome, [
            'Content-Type' => $anexo->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function leitura(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $this->acesso($request, $ticket);
        abort_unless($gestor instanceof Gestor, 403);
        $dados = $request->validate(['ultima_mensagem_id' => 'required|integer|min:1']);
        $cursor = DB::transaction(function () use ($ticket, $gestor, $dados) {
            $ator = Gestor::whereKey($gestor->id)->lockForUpdate()->firstOrFail();
            $atual = ChamadoAjuda::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_unless($ator->podeAcessarTicket($atual), 404);
            abort_unless($atual->mensagens()->whereKey($dados['ultima_mensagem_id'])->exists(), 422, 'Mensagem inválida para este atendimento.');
            $anterior = (int) DB::table('ticket_leituras')->where('ticket_id', $ticket->id)->where('gestor_id', $ator->id)->value('ultima_mensagem_id');
            $cursor = max($anterior, $dados['ultima_mensagem_id']);
            if ($cursor > $anterior) {
                DB::table('ticket_leituras')->updateOrInsert(['ticket_id' => $ticket->id, 'gestor_id' => $ator->id], ['ultima_mensagem_id' => $cursor, 'lida_em' => now()]);
                event(new TicketLido($ticket->id, $ator->id, $cursor));
            }

            return $cursor;
        }, 3);

        return response()->json(['data' => ['ticket_id' => $ticket->id, 'ultima_mensagem_id' => $cursor]]);
    }

    public function assumir(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $this->acesso($request, $ticket);
        abort_unless($gestor instanceof Gestor, 403);

        return new TicketResource($this->service->assumir($ticket, $gestor)->load(['solicitante', 'responsavel', 'corrida']));
    }

    public function status(Request $request, ChamadoAjuda $ticket)
    {
        $gestor = $this->acesso($request, $ticket);
        abort_unless($gestor instanceof Gestor, 403);
        $dados = $request->validate(['status' => ['required', Rule::enum(StatusTicket::class)]]);

        return new TicketResource($this->service->status($ticket, $gestor, StatusTicket::from($dados['status']))->load(['solicitante', 'responsavel', 'corrida']));
    }
}
