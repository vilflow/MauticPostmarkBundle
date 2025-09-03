<?php

return [
    'name'        => 'Postmark Campaign Action',
    'description' => 'Adds a campaign action to send emails via Postmark API with templates.',
    'version'     => '1.0.0',
    'author'      => 'Your Team',
    'routes'      => [
        'public' => [
            'mautic_postmark_webhook' => [
                'path'       => '/postmark/webhook',
                'controller' => 'MauticPlugin\\MauticPostmarkBundle\\Controller\\WebhookController::handleAction',
                'method'     => 'POST',
            ],
        ],
        'main' => [
            'mautic_postmark_ajax' => [
                'path'       => '/postmark/ajax/{objectAction}',
                'controller' => 'MauticPlugin\\MauticPostmarkBundle\\Controller\\AjaxController::executeAjaxAction',
                'method'     => 'POST',
            ],
        ],
    ],
    'assets'      => [
        'js' => [
            'plugins/MauticPostmarkBundle/Assets/js/postmark.js',
        ],
    ],
];
