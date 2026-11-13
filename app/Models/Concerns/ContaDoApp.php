<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Conta do motorista e do passageiro, como a do gestor: cada app tem a sua
 * tabela, o seu guard JWT e um token que não vale no outro app.
 */
trait ContaDoApp
{
    abstract public function perfilDoApp(): string;

    protected function initializeContaDoApp(): void
    {
        $this->mergeFillable(['name', 'telefone', 'cpf', 'data_nascimento', 'email']);
        $this->makeHidden(['password', 'remember_token']);
        $this->mergeCasts([
            'email_verified_at' => 'datetime',
            'data_nascimento' => 'date:Y-m-d',
            'password' => 'hashed',
        ]);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /** @return array<string, string> */
    public function getJWTCustomClaims(): array
    {
        return ['uid' => (string) Str::uuid(), 'perfil' => $this->perfilDoApp()];
    }

    /**
     * O que os apps recebem do perfil logado: o mesmo formato de antes, mas com
     * o id do perfil no lugar do id da conta.
     *
     * @return array<string, mixed>
     */
    public function dadosDoApp(): array
    {
        return [
            'id' => $this->getKey(),
            'name' => $this->getAttribute('name'),
            'email' => $this->getAttribute('email'),
            'telefone' => $this->getAttribute('telefone'),
            'cpf' => $this->getAttribute('cpf'),
            'data_nascimento' => $this->getAttribute('data_nascimento')?->format('Y-m-d'),
            'foto' => $this->getAttribute('foto'),
            'status' => $this->getAttribute('status'),
            'perfil' => $this->perfilDoApp(),
            'motorista' => $this->perfilDoApp() === 'motorista',
            'created_at' => $this->getAttribute('created_at'),
        ];
    }

    /** arquivado, bloqueado ou inativo: não entra */
    public function indisponivelNoApp(): bool
    {
        return $this->getAttribute('deleted_at') !== null
            || in_array($this->getAttribute('status'), ['bloqueado', 'inativo'], true);
    }
}
