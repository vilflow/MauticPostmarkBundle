<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
    ];

    $services->load('MauticPlugin\\MauticPostmarkBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Explicitly configure SuiteCRMService with environment variables
    // Read from $_ENV which is populated by bootstrap.php
    $suitecrm_base_url = $_ENV['SUITECRM_BASE_URL'] ?? $_SERVER['SUITECRM_BASE_URL'] ?? '';
    $suitecrm_client_id = $_ENV['SUITECRM_CLIENT_ID'] ?? $_SERVER['SUITECRM_CLIENT_ID'] ?? '';
    $suitecrm_client_secret = $_ENV['SUITECRM_CLIENT_SECRET'] ?? $_SERVER['SUITECRM_CLIENT_SECRET'] ?? '';
    $suitecrm_username = $_ENV['SUITECRM_USERNAME'] ?? $_SERVER['SUITECRM_USERNAME'] ?? '';
    $suitecrm_password = $_ENV['SUITECRM_PASSWORD'] ?? $_SERVER['SUITECRM_PASSWORD'] ?? '';

    $services->set(SuiteCRMService::class)
        ->arg('$suitecrm_base_url', $suitecrm_base_url)
        ->arg('$suitecrm_client_id', $suitecrm_client_id)
        ->arg('$suitecrm_client_secret', $suitecrm_client_secret)
        ->arg('$suitecrm_username', $suitecrm_username)
        ->arg('$suitecrm_password', $suitecrm_password)
        ->public();
};
