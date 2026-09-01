<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Corrida\AvaliacoesCorridaController;
use App\Http\Controllers\Corrida\CorridaController;
use App\Http\Controllers\Corrida\CorridaDescontoController;
use App\Http\Controllers\Corrida\CorridaDestinoController;
use App\Http\Controllers\Corrida\CorridaFinaceiroController;
use App\Http\Controllers\Corrida\CorridaNegociacoesController;
use App\Http\Controllers\Corrida\StatusBuscaController;
use App\Http\Controllers\Estimativa\EstimativasController;
use App\Http\Controllers\Motorista\MotoristaController;
use App\Http\Controllers\Motorista\MotoristaDocumentoController;
use App\Http\Controllers\Passageiro\PassageiroController;
use App\Http\Controllers\Produto\ProdutoCategoriaController;
use App\Http\Controllers\Produto\ProdutosCorridaController;
use App\Http\Controllers\Tarifa\TarifaController;
use App\Http\Controllers\Usuario\UsuarioController;
use App\Http\Controllers\Veiculo\VeiculosController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

Route::post('auth/login', LoginController::class)->name('auth.login');
Route::post('auth/register', [UsuarioController::class, 'register']);
Route::post('/auth/verificar-codigo', [LoginController::class, 'verificarCodigo']);
Route::post('auth/enviar-codigo', [LoginController::class, 'enviarCodigo']);
Route::get('auth/verifica-se-conta-existe', [LoginController::class, 'verificaSeContaExiste']);

Route::middleware('auth:jwt')->group(function () {
    Route::get('buscar-endereco', [CorridaController::class, 'buscarEndereco']);
    Route::post('auth/logout', LogoutController::class)->name('auth.logout');

    Route::apiResource('users', UsuarioController::class);
    Route::get('usuario-logado', [UsuarioController::class, 'usuarioLogado']);
    Route::delete('usuario-remover-foto-perfil/{id}', [UsuarioController::class, 'removerFotoPerfil']);
    Route::post('usuario-arquivar', [UsuarioController::class, 'usuarioArquivar']);
    Route::post('usuario-deletar', [UsuarioController::class, 'usuarioDeletar']);
    Route::post('usuario-restaurar', [UsuarioController::class, 'usuarioRestaurar']);
    Route::get('usuarios-arquivados', [UsuarioController::class, 'usuariosArquivados']);
    Route::put('usuario-alterar-foto-perfil/{id}', [UsuarioController::class, 'alterarFotoPerfil']);

    Route::apiResource('veiculos', VeiculosController::class);
    Route::get('veiculo-por-placa/{placa}', [VeiculosController::class, 'veiculoPorPlaca']);

    Route::get('motorista-veiculos/{motoristaId}', [MotoristaController::class, 'motoristaVeiculos']);
    Route::apiResource('motoristas', MotoristaController::class);
    Route::post('adicionar-veiculo-ao-motorista', [MotoristaController::class, 'adicionarVeiculoAoMotorista']);

    Route::apiResource('motorista-documentos', MotoristaDocumentoController::class);
    Route::put('mudar-status-documento/{motoristaDocumentoId}', [MotoristaDocumentoController::class, 'mudarStatusDocumento']);

    Route::apiResource('passageiros', PassageiroController::class);
    Route::get('passageiros-arquivados', [PassageiroController::class, 'passageirosArquivados']);

    Route::apiResource('corridas', CorridaController::class);
    Route::get('corridas-negociada', [CorridaController::class, 'simularCorridaNegociada']);
    Route::apiResource('corridas-negociacoes', CorridaNegociacoesController::class);
    Route::apiResource('corrida-destinos', CorridaDestinoController::class);
    Route::apiResource('corrida-descontos', CorridaDescontoController::class);
    Route::apiResource('corrida-financeiros', CorridaFinaceiroController::class);
    Route::apiResource('avaliacoes-corridas', AvaliacoesCorridaController::class);
    Route::apiResource('status-buscas', StatusBuscaController::class);

    Route::apiResource('tarifas', TarifaController::class);
    Route::get('calculos-entre-endereco', [CorridaController::class, 'calculoEntreEnderecos']);
    Route::apiResource('produtos-corridas', ProdutosCorridaController::class);
    Route::apiResource('produto-categorias', ProdutoCategoriaController::class);

    Route::prefix('estimativa')->group(function () {
        Route::get('rota/distancia', [EstimativasController::class, 'estimarRota']);
    });

    Route::get('/user', function (Request $request) {
        /** @var JWTGuard */
        $guard = auth('jwt');
        $uid = $guard->payload()->get('uid');

        return [
            'user' => $request->user(),
            'uid' => $uid,
        ];
    });
});
