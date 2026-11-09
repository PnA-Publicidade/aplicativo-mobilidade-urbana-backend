<?php

namespace App\Services;

use Illuminate\Http\Request;

class PermissoesGestaoService
{
    public function permissaoDaRota(Request $request): ?string
    {
        $caminho = substr($request->path(), strlen('api/gestao/'));
        $recurso = explode('/', $caminho)[0];
        $consulta = $request->isMethod('GET') || $request->isMethod('HEAD');
        if ($recurso === 'administracao') {
            return 'regras.gerenciar';
        }
        if ($recurso === 'gestores') {
            return 'gestores.gerenciar';
        }
        if ($recurso === 'motorista-documentos' || $recurso === 'mudar-status-documento') {
            return $consulta ? 'documentos.consultar' : 'documentos.analisar';
        }
        if (in_array($recurso, ['veiculos', 'veiculo-por-placa', 'motorista-veiculos', 'adicionar-veiculo-ao-motorista', 'marcas-veiculos', 'modelos-veiculos'], true)) {
            return $consulta ? 'veiculos.consultar' : 'veiculos.gerenciar';
        }
        if ($recurso === 'plataforma-lucros') {
            return $consulta || str_ends_with($request->path(), '/sincronizar') ? 'financeiro.consultar' : 'financeiro.conciliar';
        }
        if (in_array($recurso, ['motoristas-saldo', 'motoristas-ganhos'], true)) {
            return $consulta ? 'financeiro.consultar' : null;
        }
        if ($recurso === 'corridas') {
            return $consulta ? 'corridas.consultar' : null;
        }
        if ($recurso === 'ajuda') {
            return 'ajuda.gerenciar';
        }
        if (in_array($recurso, ['users', 'motoristas', 'motoristas-arquivados', 'passageiros', 'passageiros-arquivados', 'usuarios-arquivados'], true) || str_starts_with($recurso, 'usuario-')) {
            return $consulta ? 'usuarios.consultar' : 'usuarios.gerenciar';
        }
        if (in_array($recurso, ['tarifas', 'produtos-corridas', 'produto-categorias', 'publicidades', 'cidades', 'estimativa'], true)) {
            return 'configuracoes.gerenciar';
        }

        // Rotas futuras precisam entrar explicitamente no catálogo de acesso.
        return null;
    }
}
