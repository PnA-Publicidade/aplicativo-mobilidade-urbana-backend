<?php

return [
    // toda conta nova (motorista ou passageiro) começa com esta nota, que vale
    // como base até chegarem as primeiras avaliações reais; a nota 5 automática
    // do passageiro que não avalia não é avaliação real
    'nota_base' => (float) env('AVALIACOES_NOTA_BASE', 5.0),

    // até este número de corridas finalizadas o app mostra o selo "Novo usuário"
    'corridas_para_deixar_de_ser_novo' => (int) env('AVALIACOES_CORRIDAS_PARA_DEIXAR_DE_SER_NOVO', 20),

    // quantas das últimas avaliações reais entram na média é definido na
    // gestão (Configurações > Avaliações); o padrão está em
    // ConfiguracaoPlataformaService::AVALIACOES_MEDIA_ULTIMAS_PADRAO
];
