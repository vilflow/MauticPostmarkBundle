<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticPostmarkBundle\Service\EventCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\NoteCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\OpportunityCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
        'EventListener', // Exclude EventListeners from auto-loading (we register them manually)
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

    // Register Criteria Builders
    $services->set('mautic.postmark.criteria_builder.event', EventCriteriaBuilder::class)
        ->arg('$em', service('doctrine.orm.entity_manager'))
        ->public();

    $services->set('mautic.postmark.criteria_builder.opportunity', OpportunityCriteriaBuilder::class)
        ->arg('$em', service('doctrine.orm.entity_manager'))
        ->public();

    $services->set('mautic.postmark.criteria_builder.note', NoteCriteriaBuilder::class)
        ->arg('$em', service('doctrine.orm.entity_manager'))
        ->public();

    // Update CampaignSubscriber to receive EntityManager and CriteriaBuilders
    $services->set('mautic.postmark.campaign.subscriber')
        ->class(\MauticPlugin\MauticPostmarkBundle\EventListener\CampaignSubscriber::class)
        ->arg('$connection', service('database_connection'))
        ->arg('$suiteCRMService', service(SuiteCRMService::class))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->arg('$em', service('doctrine.orm.entity_manager'))
        ->arg('$eventCriteriaBuilder', service('mautic.postmark.criteria_builder.event'))
        ->arg('$opportunityCriteriaBuilder', service('mautic.postmark.criteria_builder.opportunity'))
        ->arg('$noteCriteriaBuilder', service('mautic.postmark.criteria_builder.note'))
        ->tag('kernel.event_subscriber');

    $services->set('mautic.postmark.opportunity.lifecycle_subscriber')
        ->class(\MauticPlugin\MauticPostmarkBundle\EventListener\OpportunityLifecycleSubscriber::class)
        ->arg('$campaignSubscriber', service('mautic.postmark.campaign.subscriber'))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->tag('doctrine.event_subscriber');

    // Register EntityCampaignSubscriber for Entity-Based Campaigns
    // This handles postmark.send actions in entity-based campaigns (opportunity, event, note)
    $services->set('mautic.postmark.entity_campaign.subscriber')
        ->class(\MauticPlugin\MauticPostmarkBundle\EventListener\EntityCampaignSubscriber::class)
        ->arg('$connection', service('database_connection'))
        ->arg('$suiteCRMService', service(SuiteCRMService::class))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->arg('$em', service('doctrine.orm.entity_manager'))
        ->tag('kernel.event_subscriber');

    // Register the reschedule entities command (handles opportunity, note, and event modes)
    $services->set('mautic.postmark.command.reschedule_entities')
        ->class(\MauticPlugin\MauticPostmarkBundle\Command\RescheduleEntityActionsCommand::class)
        ->arg('$connection', service('database_connection'))
        ->arg('$logger', service('monolog.logger.mautic'))
        ->tag('console.command');
};
