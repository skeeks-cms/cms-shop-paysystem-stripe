<?php
return [
    'components' => [
        'shop' => [
            'paysystemHandlers' => [
                'stripe' => [
                    'class' => \skeeks\cms\shop\stripe\StripePaysystemHandler::class
                ]
            ],
        ],

        'log' => [
            'targets' => [
                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['info', 'warning', 'error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\stripe\controllers\StripeController::class, \skeeks\cms\shop\stripe\StripePaysystemHandler::class],
                    'logFile'    => '@runtime/logs/stripe-info.log',
                ],

                [
                    'class'      => 'yii\log\FileTarget',
                    'levels'     => ['error'],
                    'logVars'    => [],
                    'categories' => [\skeeks\cms\shop\stripe\controllers\StripeController::class, \skeeks\cms\shop\stripe\StripePaysystemHandler::class],
                    'logFile'    => '@runtime/logs/stripe-errors.log',
                ],
            ],
        ],
    ],
];