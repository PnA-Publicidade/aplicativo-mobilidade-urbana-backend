<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * As rotas dos apps aceitam os dois tokens (auth:motoristas,passageiros) e
     * conferem o motorista primeiro: quem entra num app sai do outro, senão o
     * passageiro logado depois do motorista no mesmo teste seria visto como ele.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        foreach (['motoristas', 'passageiros'] as $app) {
            if ($guard !== $app) {
                $this->app['auth']->guard($app)->forgetUser();
            }
        }

        return parent::be($user, $guard);
    }
}
