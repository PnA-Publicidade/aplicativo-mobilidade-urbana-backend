<?php

use App\Http\Controllers\Ajuda\TicketsController;
use App\Http\Controllers\Auth\GestorAutenticacaoController;
use App\Http\Controllers\Corrida\ChatCorridaController;
use App\Http\Controllers\Gestao\CorridasController;
use App\Http\Controllers\Gestao\FotoPerfilController;
use App\Http\Controllers\Gestao\GanhosMotoristasController;
use App\Http\Controllers\Gestao\HistoricoCorridaSaldoController;
use App\Http\Controllers\Gestao\PlataformaLucrosController;
use App\Http\Controllers\Gestao\RegrasAcessoController;
use App\Http\Controllers\Gestao\SaldoMotoristasController;
use App\Http\Controllers\Gestao\VeiculoUsuariosController;
use App\Http\Controllers\Gestor\GestorController;
use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\MotoristaDocumentoController;
use App\Http\Controllers\Usuario\UsuarioController;
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
            Route::apiResource('users', UsuarioController::class)->only(['index', 'store', 'show', 'update']);
            Route::delete('usuario-remover-foto-perfil/{id}', [UsuarioController::class, 'removerFotoPerfil']);
            Route::post('usuario-arquivar', [UsuarioController::class, 'usuarioArquivar']);
            Route::post('usuario-deletar', [UsuarioController::class, 'usuarioDeletar']);
            Route::post('usuario-restaurar', [UsuarioController::class, 'usuarioRestaurar']);
            Route::get('usuarios-arquivados', [UsuarioController::class, 'usuariosArquivados']);
            Route::put('usuario-alterar-foto-perfil/{id}', [UsuarioController::class, 'alterarFotoPerfil']);

            Route::get('motorista-veiculos/{motoristaId}', [MotoristaController::class, 'motoristaVeiculos']);
            Route::apiResource('motoristas', MotoristaController::class);
            Route::post('adicionar-veiculo-ao-motorista', [MotoristaController::class, 'adicionarVeiculoAoMotorista']);
            Route::get('motorista-documentos/tipos', [MotoristaDocumentoController::class, 'tipos']);
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
