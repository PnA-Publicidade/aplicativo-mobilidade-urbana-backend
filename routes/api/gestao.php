<?php

use App\Http\Controllers\Ajuda\TicketsController;
use App\Http\Controllers\Auth\GestorAutenticacaoController;
use App\Http\Controllers\Corrida\ChatCorridaController;
use App\Http\Controllers\Gestao\ConfiguracoesController;
use App\Http\Controllers\Gestao\CorridasController;
use App\Http\Controllers\Gestao\CuponsController;
use App\Http\Controllers\Gestao\DicasAvaliacaoController;
use App\Http\Controllers\Gestao\DinamicaController;
use App\Http\Controllers\Gestao\FotoPerfilController;
use App\Http\Controllers\Gestao\GanhosMotoristasController;
use App\Http\Controllers\Gestao\HistoricoCorridaSaldoController;
use App\Http\Controllers\Gestao\PlataformaLucrosController;
use App\Http\Controllers\Gestao\RegrasAcessoController;
use App\Http\Controllers\Gestao\RegrasDespachoController;
use App\Http\Controllers\Gestao\RestricoesMotoristasController;
use App\Http\Controllers\Gestao\RestricoesNotaController;
use App\Http\Controllers\Gestao\SaldoMotoristasController;
use App\Http\Controllers\Gestao\AvataresController;
use App\Http\Controllers\Gestao\SelosAvaliacaoController;
use App\Http\Controllers\Gestao\SimulacaoChamadasController;
use App\Http\Controllers\Gestao\TaxasMotoristasController;
use App\Http\Controllers\Gestao\VeiculoUsuariosController;
use App\Http\Controllers\Gestor\GestorController;
use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\MotoristaDocumentoController;
use App\Http\Controllers\Veiculo\MarcaVeiculoController;
use App\Http\Controllers\Veiculo\ModeloVeiculoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('gestao')->name('gestao.')->group(function () {
    Route::post('auth/login', [GestorAutenticacaoController::class, 'login'])
        ->middleware('throttle:10,1')->name('auth.login');
    Route::post('auth/refresh', [GestorAutenticacaoController::class, 'refresh'])
        ->middleware('throttle:30,1')->name('auth.refresh');

    Route::middleware(['auth:gestores', 'gestor'])->group(function () {
        Route::get('usuario-logado', [GestorAutenticacaoController::class, 'usuarioLogado']);
        Route::post('auth/logout', [GestorAutenticacaoController::class, 'logout'])->name('auth.logout');

        Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request))->middleware('throttle:120,1');

        Route::middleware('permissao.gestao')->group(function () {
            Route::post('plataforma-lucros/custos-avulsos', [PlataformaLucrosController::class, 'registrarCusto'])->middleware('throttle:10,1');
            Route::get('plataforma-lucros', [PlataformaLucrosController::class, 'index']);
            Route::post('plataforma-lucros/sincronizar', [PlataformaLucrosController::class, 'sincronizar'])->middleware('throttle:20,1');
            Route::get('plataforma-lucros/{lucro}', [PlataformaLucrosController::class, 'show'])->whereNumber('lucro');
            Route::patch('plataforma-lucros/{lucro}/custos', [PlataformaLucrosController::class, 'conciliar'])->whereNumber('lucro')->middleware('throttle:20,1');
            Route::post('plataforma-lucros/{lucro}/recebimento', [PlataformaLucrosController::class, 'recebimento'])->whereNumber('lucro')->middleware('throttle:10,1');
            Route::get('motoristas-ganhos/motoristas', [GanhosMotoristasController::class, 'motoristas']);
            Route::get('motoristas-ganhos/resumo', [GanhosMotoristasController::class, 'resumo']);
            Route::get('motoristas-ganhos/detalhes', [GanhosMotoristasController::class, 'detalhes']);
            Route::get('motoristas-saldo/{movimentacao}/corrida/mensagens', HistoricoCorridaSaldoController::class)->whereNumber('movimentacao')->name('motoristas-saldo.corrida');
            Route::get('motoristas-saldo', [SaldoMotoristasController::class, 'index'])->name('motoristas-saldo.index');
            Route::get('motoristas-saldo/{movimentacao}', [SaldoMotoristasController::class, 'show'])->whereNumber('movimentacao')->name('motoristas-saldo.show');
            Route::get('ajuda/solicitantes', [TicketsController::class, 'solicitantes']);
            Route::get('ajuda/operadores', [TicketsController::class, 'operadores']);
            Route::post('ajuda/tickets/{ticket}/transfer', [TicketsController::class, 'transferir'])->whereNumber('ticket')->middleware('throttle:30,1');
            Route::get('ajuda/tickets/{ticket}/transferencias', [TicketsController::class, 'transferencias'])->whereNumber('ticket');
            Route::get('ajuda/tickets', [TicketsController::class, 'index']);
            Route::post('ajuda/tickets', [TicketsController::class, 'store'])->middleware('throttle:10,1');
            Route::get('ajuda/tickets/{ticket}', [TicketsController::class, 'show'])->whereNumber('ticket');
            Route::get('ajuda/tickets/{ticket}/corrida/mensagens', [ChatCorridaController::class, 'historicoTicket'])->whereNumber('ticket');
            Route::get('ajuda/tickets/{ticket}/mensagens', [TicketsController::class, 'mensagens'])->whereNumber('ticket');
            Route::get('ajuda/tickets/{ticket}/mensagens/{mensagem}/anexos/{anexo}', [TicketsController::class, 'anexo'])->whereNumber(['ticket', 'mensagem', 'anexo']);
            Route::post('ajuda/tickets/{ticket}/mensagens', [TicketsController::class, 'enviar'])->whereNumber('ticket')->middleware('throttle:60,1');
            Route::post('ajuda/tickets/{ticket}/leitura', [TicketsController::class, 'leitura'])->whereNumber('ticket')->middleware('throttle:120,1');
            Route::post('ajuda/tickets/{ticket}/assumir', [TicketsController::class, 'assumir'])->whereNumber('ticket');
            Route::patch('ajuda/tickets/{ticket}/status', [TicketsController::class, 'status'])->whereNumber('ticket');
            Route::get('despacho/regras', [RegrasDespachoController::class, 'index']);
            Route::put('despacho/regras', [RegrasDespachoController::class, 'update'])->middleware('throttle:30,1');
            Route::get('despacho/painel', [RegrasDespachoController::class, 'painel']);
            Route::get('dinamica', [DinamicaController::class, 'index']);
            Route::put('dinamica/geral', [DinamicaController::class, 'atualizarGeral'])->middleware('throttle:20,1');
            Route::put('dinamica/cidades/{cidade}', [DinamicaController::class, 'salvarCidade'])->whereNumber('cidade')->middleware('throttle:30,1');
            Route::get('dinamica/cidades/{cidade}/regioes', [DinamicaController::class, 'regioes'])->whereNumber('cidade');
            Route::post('dinamica/cidades/{cidade}/regioes', [DinamicaController::class, 'forcarRegiao'])->whereNumber('cidade')->middleware('throttle:30,1');
            Route::delete('dinamica/cidades/{cidade}/regioes/{regiao}', [DinamicaController::class, 'removerRegiao'])->whereNumber(['cidade', 'regiao']);
            Route::post('dinamica/cidades/{cidade}/recalcular', [DinamicaController::class, 'recalcular'])->whereNumber('cidade')->middleware('throttle:20,1');
            // simulação de pedidos em Porto Velho para testar dinâmico e demanda (fora de produção)
            Route::get('dinamica/simulacao/cenarios', [SimulacaoChamadasController::class, 'cenarios']);
            Route::post('dinamica/simulacao/iniciar', [SimulacaoChamadasController::class, 'iniciar']);
            Route::post('dinamica/simulacao/rodada', [SimulacaoChamadasController::class, 'rodada']);
            Route::post('dinamica/simulacao/parar', [SimulacaoChamadasController::class, 'parar']);
            Route::get('dinamica/simulacao/situacao', [SimulacaoChamadasController::class, 'situacao']);
            // cupons de desconto do passageiro: cadastro e entrega na carteira
            Route::get('cupons', [CuponsController::class, 'index']);
            Route::post('cupons/{cupom}/conceder', [CuponsController::class, 'conceder'])->whereNumber('cupom')->middleware('throttle:20,1');
            Route::apiResource('cupons', CuponsController::class)->only(['store', 'update', 'destroy'])->parameters(['cupons' => 'cupom']);
            Route::get('configuracoes', [ConfiguracoesController::class, 'index']);
            Route::put('configuracoes', [ConfiguracoesController::class, 'update'])->middleware('throttle:20,1');
            Route::get('restricoes-motoristas', [RestricoesMotoristasController::class, 'index']);
            Route::post('restricoes-motoristas', [RestricoesMotoristasController::class, 'store'])->middleware('throttle:20,1');
            Route::post('restricoes-motoristas/{restricao}/liberar', [RestricoesMotoristasController::class, 'liberar'])->whereNumber('restricao')->middleware('throttle:20,1');
            Route::get('restricoes-nota', [RestricoesNotaController::class, 'index']);
            Route::post('restricoes-nota', [RestricoesNotaController::class, 'store'])->middleware('throttle:20,1');
            Route::post('restricoes-nota/{restricao}/liberar', [RestricoesNotaController::class, 'liberar'])->whereNumber('restricao')->middleware('throttle:20,1');
            Route::get('selos-avaliacao', [SelosAvaliacaoController::class, 'index']);
            Route::post('selos-avaliacao', [SelosAvaliacaoController::class, 'store'])->middleware('throttle:30,1');
            Route::put('selos-avaliacao/{selo}', [SelosAvaliacaoController::class, 'update'])->whereNumber('selo')->middleware('throttle:60,1');
            Route::delete('selos-avaliacao/{selo}', [SelosAvaliacaoController::class, 'destroy'])->whereNumber('selo')->middleware('throttle:30,1');
            // avatares de perfil (passageiro e motorista escolhem no lugar da foto)
            Route::get('avatares', [AvataresController::class, 'index']);
            Route::post('avatares', [AvataresController::class, 'store'])->middleware('throttle:60,1');
            Route::put('avatares/{avatar}', [AvataresController::class, 'update'])->whereNumber('avatar')->middleware('throttle:60,1');
            Route::delete('avatares/{avatar}', [AvataresController::class, 'destroy'])->whereNumber('avatar')->middleware('throttle:30,1');
            Route::get('dicas-avaliacao', [DicasAvaliacaoController::class, 'index']);
            Route::post('dicas-avaliacao', [DicasAvaliacaoController::class, 'store'])->middleware('throttle:30,1');
            Route::put('dicas-avaliacao/{dica}', [DicasAvaliacaoController::class, 'update'])->whereNumber('dica')->middleware('throttle:60,1');
            Route::delete('dicas-avaliacao/{dica}', [DicasAvaliacaoController::class, 'destroy'])->whereNumber('dica')->middleware('throttle:30,1');
            Route::get('taxas-motoristas', [TaxasMotoristasController::class, 'index']);
            Route::get('taxas-motoristas/{motorista}/eventos', [TaxasMotoristasController::class, 'eventos'])->whereNumber('motorista');
            Route::get('administracao/regras', [RegrasAcessoController::class, 'index']);
            Route::get('administracao/regras/{perfil}/gestores', [RegrasAcessoController::class, 'gestores']);
            Route::put('administracao/regras/{perfil}/gestores', [RegrasAcessoController::class, 'atribuir']);

            foreach (['motorista' => 'motoristas', 'passageiro' => 'passageiros', 'gestor' => 'gestores'] as $tipo => $recurso) {
                Route::post($recurso.'/{perfil}/foto', [FotoPerfilController::class, 'atualizar'])
                    ->whereNumber('perfil')->defaults('tipo', $tipo);
            }

            Route::get('gestores/arquivados', [GestorController::class, 'arquivados']);
            Route::post('gestores/{gestor}/restaurar', [GestorController::class, 'restaurar'])->whereNumber('gestor');
            Route::apiResource('gestores', GestorController::class)->parameters(['gestores' => 'gestor']);

            Route::get('motorista-veiculos/{motoristaId}', [MotoristaController::class, 'motoristaVeiculos']);
            Route::apiResource('motoristas', MotoristaController::class);
            Route::post('adicionar-veiculo-ao-motorista', [MotoristaController::class, 'adicionarVeiculoAoMotorista']);
            Route::get('motorista-documentos/tipos', [MotoristaDocumentoController::class, 'tipos']);
            Route::get('motorista-documentos/fila', [MotoristaDocumentoController::class, 'fila']);
            Route::get('motorista-documentos/motivos-reprovacao', [MotoristaDocumentoController::class, 'motivosReprovacao']);
            Route::get('motorista-documentos/{motoristaId}/resumo', [MotoristaDocumentoController::class, 'resumo']);
            Route::get('motorista-documentos/{motoristaDocumentoId}/download', [MotoristaDocumentoController::class, 'baixar']);
            Route::apiResource('motorista-documentos', MotoristaDocumentoController::class);
            Route::put('mudar-status-documento/{motoristaDocumentoId}', [MotoristaDocumentoController::class, 'mudarStatusDocumento']);

            Route::get('marcas-veiculos/{marca}/modelos', [MarcaVeiculoController::class, 'modelos'])->whereNumber('marca');
            Route::apiResource('marcas-veiculos', MarcaVeiculoController::class)->parameters(['marcas-veiculos' => 'marca']);
            Route::apiResource('modelos-veiculos', ModeloVeiculoController::class)->except('index')->parameters(['modelos-veiculos' => 'modelo']);
            Route::apiResource('corridas', CorridasController::class)->only(['index', 'show']);

            Route::get('veiculos/{veiculoId}/usuarios', VeiculoUsuariosController::class)->whereNumber('veiculoId');

            require __DIR__.'/veiculo.php';
            require __DIR__.'/passageiro.php';
            require __DIR__.'/tarifa.php';
            require __DIR__.'/produto.php';
            require __DIR__.'/publicidade.php';
            require __DIR__.'/estimativa.php';
        });
    });
});
