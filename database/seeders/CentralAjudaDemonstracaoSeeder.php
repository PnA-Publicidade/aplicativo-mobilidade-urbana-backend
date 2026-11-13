<?php

namespace Database\Seeders;

use App\Models\ChamadoAjuda;
use App\Models\ChamadoMensagem;
use App\Models\Corrida;
use App\Models\Gestor;
use App\Models\Motorista;
use App\Models\Passageiro;
use App\Models\TicketTransfer;
use App\Services\TicketAnexosService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CentralAjudaDemonstracaoSeeder extends Seeder
{
    public const PREFIXO = 'DEMO-AJUDA-';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demonstração da Central de Ajuda permitida somente em local ou testing.');
        }
        Event::fakeFor(fn () => DB::transaction(function () {
            $passageiro = Passageiro::where('email', 'passageiro@example.com')->firstOrFail();
            $motorista = Motorista::where('email', 'motorista@example.com')->firstOrFail();
            $admin = Gestor::where('perfil_acesso', 'admin')->firstOrFail();
            $operadores = [Gestor::where('perfil_acesso', 'atendimento')->firstOrFail(), Gestor::where('perfil_acesso', 'suporte_tecnico')->firstOrFail()];
            $corrida = Corrida::where('codigo_corrida', HistoricoCorridaDemonstracaoSeeder::CODIGO)->firstOrFail();
            $cenarios = [];
            for ($i = 1; $i <= 24; $i++) {
                $cenarios[] = ['numero' => $i, 'status' => ['open', 'in_progress', 'resolved', 'waiting'][($i - 1) % 4]];
            }
            // Primeiro prepara os atendimentos ativos, depois posiciona quem espera.
            usort($cenarios, fn ($a, $b) => ($a['status'] === 'waiting') <=> ($b['status'] === 'waiting'));
            foreach ($cenarios as $cenario) {
                $i = $cenario['numero'];
                $status = $cenario['status'];
                $codigo = self::PREFIXO.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
                $tipo = intdiv($i - 1, 4) % 2 === 0 ? 'passageiro' : 'motorista';
                $solicitante = $tipo === 'passageiro' ? $passageiro : $motorista;
                $descricao = $codigo.' · Demonstração de atendimento: conferir '.($i % 2 === 0 ? 'pagamento e valores da corrida.' : 'cadastro e funcionamento do aplicativo.');
                if (ChamadoAjuda::where('descricao', $descricao)->where('solicitante_tipo', $tipo)->where('solicitante_id', $solicitante->id)->exists()) {
                    continue;
                }
                $quando = now()->subMinutes(30 + $i * 10);
                $operador = $status === 'open' ? null : $operadores[intdiv($i - 1, 4) % 2];
                $posicao = null;
                if ($status === 'waiting') {
                    $fila = ChamadoAjuda::where('gestor_responsavel_id', $operador->id)->whereIn('status', ['waiting', 'in_progress']);
                    $posicao = max((int) (clone $fila)->max('fila_posicao') + 1, (clone $fila)->where('status', 'in_progress')->count());
                }
                $ticket = ChamadoAjuda::forceCreate(['solicitante_id' => $solicitante->id, 'solicitante_tipo' => $tipo, 'corrida_id' => $i % 2 === 0 ? $corrida->id : null, 'motivo' => $i % 2 === 0 ? 'tarifa_incorreta' : 'outros', 'descricao' => $descricao, 'status' => $status, 'gestor_responsavel_id' => $operador?->id, 'fila_entrada_em' => in_array($status, ['waiting', 'in_progress'], true) ? $quando : null, 'fila_posicao' => $status === 'in_progress' ? 0 : $posicao, 'resolvido_em' => $status === 'resolved' ? $quando->copy()->addMinutes(4) : null, 'created_at' => $quando, 'updated_at' => $quando]);
                $mensagem = ChamadoMensagem::forceCreate(['chamado_id' => $ticket->id, 'autor_tipo' => 'usuario', 'autor_nome' => $solicitante->name, 'conteudo' => $descricao, 'client_message_id' => (string) Str::uuid(), 'created_at' => $quando, 'updated_at' => $quando]);
                if ($i % 3 === 0) {
                    $this->anexo($mensagem, $codigo);
                }
                if ($operador) {
                    TicketTransfer::create(['ticket_id' => $ticket->id, 'transferred_by_user_id' => $admin->id, 'to_user_id' => $operador->id, 'transferred_by_name' => $admin->name, 'to_user_name' => $operador->name, 'type' => 'queue_distribution', 'note' => 'Distribuição fictícia para testar o histórico de transferências.', 'created_at' => $quando->copy()->addMinute()]);
                }
                if ($status === 'waiting') {
                    ChamadoMensagem::forceCreate(['chamado_id' => $ticket->id, 'autor_tipo' => 'sistema', 'autor_nome' => 'Sistema', 'tipo_sistema' => 'fila_atendimento', 'conteudo' => 'Demonstração: aguarde sua vez na fila de atendimento.', 'created_at' => $quando->copy()->addMinutes(2), 'updated_at' => $quando->copy()->addMinutes(2)]);
                } elseif (in_array($status, ['in_progress', 'resolved'], true)) {
                    ChamadoMensagem::forceCreate(['chamado_id' => $ticket->id, 'autor_tipo' => 'gestor', 'gestor_id' => $operador->id, 'autor_nome' => $operador->name, 'conteudo' => 'Olá! Este é um atendimento fictício para demonstrar a conversa e os dados da corrida.', 'client_message_id' => (string) Str::uuid(), 'created_at' => $quando->copy()->addMinutes(2), 'updated_at' => $quando->copy()->addMinutes(2)]);
                    if ($status === 'resolved') {
                        ChamadoMensagem::forceCreate(['chamado_id' => $ticket->id, 'autor_tipo' => 'sistema', 'autor_nome' => 'Sistema', 'conteudo' => 'Atendimento finalizado. Para outro assunto, abra um novo ticket.', 'created_at' => $quando->copy()->addMinutes(4), 'updated_at' => $quando->copy()->addMinutes(4)]);
                    }
                }
                $ticket->forceFill(['ultima_mensagem_em' => $ticket->mensagens()->max('created_at')])->save();
            }
        }));
        $this->command?->info('Central de Ajuda: 24 tickets de demonstração, quatro status, conversas, anexos e transferências.');
    }

    private function anexo(ChamadoMensagem $mensagem, string $codigo): void
    {
        $disk = Storage::disk(TicketAnexosService::DISK);
        $path = 'demonstracao/'.$codigo.'.png';
        if (! $disk->exists($path)) {
            // PNG sintético portátil, sem documentos pessoais ou serviços externos.
            $pixels = '';
            for ($linha = 0; $linha < 180; $linha++) {
                $cor = $linha < 35 || ($linha > 70 && $linha < 85) ? "\x19\x76\xd2" : "\xef\xf6\xff";
                $pixels .= "\0".str_repeat($cor, 400);
            }
            $chunk = fn ($tipo, $dados) => pack('N', strlen($dados)).$tipo.$dados.pack('N', crc32($tipo.$dados));
            $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 400, 180, 8, 2, 0, 0, 0)).$chunk('IDAT', gzcompress($pixels)).$chunk('IEND', '');
            if (! $disk->put($path, $png)) {
                throw new RuntimeException('Não foi possível gravar o anexo da demonstração.');
            }
        }
        $conteudo = $disk->get($path);
        $mensagem->anexos()->create(['ordem' => 0, 'disk' => TicketAnexosService::DISK, 'path' => $path, 'nome' => 'imagem-ficticia-'.$codigo.'.png', 'mime_type' => 'image/png', 'tamanho' => strlen($conteudo), 'largura' => 400, 'altura' => 180, 'sha256' => hash('sha256', $conteudo)]);
    }
}
