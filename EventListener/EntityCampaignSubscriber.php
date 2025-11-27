<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticEntityBasedCampaignBundle\Event\EntityPendingEvent;
use MauticPlugin\MauticEntityBasedCampaignBundle\MauticEntityBasedCampaignEvents;
use MauticPlugin\MauticEventsBundle\Entity\Event as CustomEvent;
use MauticPlugin\MauticEventsBundle\Entity\EventContact;
use MauticPlugin\MauticNotesBundle\Entity\Note;
use MauticPlugin\MauticOpportunitiesBundle\Entity\Opportunity;
use MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLog;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles Postmark email sending for Entity-Based Campaigns.
 *
 * This subscriber listens to entity campaign events and sends emails
 * per entity (opportunity, event, note) rather than per contact.
 */
class EntityCampaignSubscriber implements EventSubscriberInterface
{
    private ?Connection $connection = null;
    private ?SuiteCRMService $suiteCRMService = null;
    private LoggerInterface $logger;
    private ?EntityManagerInterface $em = null;

    public function __construct(
        ?Connection $connection = null,
        ?SuiteCRMService $suiteCRMService = null,
        ?LoggerInterface $logger = null,
        ?EntityManagerInterface $em = null
    ) {
        $this->connection = $connection;
        $this->suiteCRMService = $suiteCRMService;
        $this->logger = $logger ?? new NullLogger();
        $this->em = $em;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MauticEntityBasedCampaignEvents::ON_ENTITY_CAMPAIGN_TRIGGER_ACTION => ['onEntityCampaignAction', 0],
        ];
    }

    /**
     * Handle entity campaign action (send email per entity).
     */
    public function onEntityCampaignAction(EntityPendingEvent $event): void
    {
        // Only handle postmark.send actions
        if (!$event->checkContext('postmark.send')) {
            return;
        }

        $this->logger->info('EntityCampaignSubscriber: Processing postmark.send action for entity campaign');

        $config = $event->getEvent()->getProperties();
        $entityType = $event->getEntityType();
        $entityIds = $event->getEntityIds();
        $logs = $event->getLogs();

        $this->logger->info('Entity campaign postmark action', [
            'entity_type' => $entityType,
            'entity_count' => count($entityIds),
            'config' => array_keys($config),
        ]);

        // Route to appropriate handler based on entity type
        switch ($entityType) {
            case 'event':
                $this->sendPerEventEntity($event, $config, $entityIds);
                break;
            case 'opportunity':
                $this->sendPerOpportunityEntity($event, $config, $entityIds);
                break;
            case 'note':
                $this->sendPerNoteEntity($event, $config, $entityIds);
                break;
            default:
                $this->logger->warning('Unknown entity type for postmark send', [
                    'entity_type' => $entityType,
                ]);
                $event->failAll();
                return;
        }
    }

    /**
     * Send one email per Event entity.
     */
    private function sendPerEventEntity(EntityPendingEvent $event, array $config, array $entityIds): void
    {
        if (!$this->em) {
            $this->logger->error('EntityManager not available');
            $event->failAll();
            return;
        }

        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));
        $templateModel = $this->parseTemplateModel($config);

        $passedEntityIds = [];
        $failedEntityIds = [];

        foreach ($entityIds as $entityId) {
            // Load Event entity
            $eventEntity = $this->em->getRepository(CustomEvent::class)->find($entityId);
            if (!$eventEntity) {
                $this->logger->warning('Event entity not found', ['id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Get contact through EventContact relationship
            $eventContact = $this->em->getRepository(EventContact::class)->findOneBy(['event' => $entityId]);
            if (!$eventContact) {
                $this->logger->warning('No EventContact found for event', ['event_id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            $contact = $eventContact->getContact();
            if (!$contact) {
                $this->logger->warning('Contact not found in EventContact', ['event_id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Check idempotency
            if ($this->alreadySent($actionEventId, 'event', $contact->getId(), $entityId)) {
                $this->logger->debug('Skipping duplicate send', [
                    'action_event_id' => $actionEventId,
                    'contact_id' => $contact->getId(),
                    'event_id' => $entityId,
                ]);
                $passedEntityIds[] = $entityId; // Already sent, mark as passed
                continue;
            }

            // Resolve tokens
            [$from, $to, $model] = $this->resolveTokensWithEntity(
                $fromEmail,
                $toEmail,
                $templateModel,
                $contact,
                $eventEntity
            );

            // Validate required fields
            if (!$serverToken || !$from || !$to || !$templateAlias) {
                $this->logEntitySend($actionEventId, $campaignId, $contact->getId(), 'event', $entityId, 'failed', null, 'Missing required fields');
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Send email
            $payload = [
                'From' => $from,
                'To' => $to,
                'TemplateAlias' => $templateAlias,
                'TemplateModel' => $model,
            ];

            [$ok, $statusCode, $respBody, $err] = $this->sendPostmark($serverToken, $payload);
            $messageId = $this->extractMessageId($respBody);

            // Log the send
            $this->logEntitySend(
                $actionEventId,
                $campaignId,
                $contact->getId(),
                'event',
                $entityId,
                $ok ? 'sent' : 'failed',
                $messageId,
                $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
            );

            if ($ok) {
                $passedEntityIds[] = $entityId;
                $this->logger->info('Email sent successfully for event entity', [
                    'event_id' => $entityId,
                    'contact_email' => $to,
                    'message_id' => $messageId,
                ]);
            } else {
                $failedEntityIds[] = $entityId;
                $this->logger->error('Failed to send email for event entity', [
                    'event_id' => $entityId,
                    'error' => $err ?: $respBody,
                ]);
            }
        }

        // Mark logs as passed/failed
        if (!empty($passedEntityIds)) {
            $event->pass($passedEntityIds);
        }
        if (!empty($failedEntityIds)) {
            $event->fail($failedEntityIds);
        }
    }

    /**
     * Send one email per Opportunity entity.
     */
    private function sendPerOpportunityEntity(EntityPendingEvent $event, array $config, array $entityIds): void
    {
        if (!$this->em) {
            $this->logger->error('EntityManager not available');
            $event->failAll();
            return;
        }

        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));
        $templateModel = $this->parseTemplateModel($config);

        $passedEntityIds = [];
        $failedEntityIds = [];

        foreach ($entityIds as $entityId) {
            // Load Opportunity entity
            $opportunity = $this->em->getRepository(Opportunity::class)->find($entityId);
            if (!$opportunity) {
                $this->logger->warning('Opportunity entity not found', ['id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Get contact from opportunity
            $contact = $opportunity->getContact();
            if (!$contact) {
                $this->logger->warning('Contact not found in Opportunity', ['opportunity_id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Check idempotency
            if ($this->alreadySent($actionEventId, 'opportunity', $contact->getId(), $entityId)) {
                $this->logger->debug('Skipping duplicate send', [
                    'action_event_id' => $actionEventId,
                    'contact_id' => $contact->getId(),
                    'opportunity_id' => $entityId,
                ]);
                $passedEntityIds[] = $entityId;
                continue;
            }

            // Load related Event entity for token resolution
            $additionalEntities = [];
            if ($opportunity->getEvent()) {
                $additionalEntities[] = $opportunity->getEvent();
            }

            // Resolve tokens
            [$from, $to, $model] = $this->resolveTokensWithEntity(
                $fromEmail,
                $toEmail,
                $templateModel,
                $contact,
                $opportunity,
                $additionalEntities
            );

            // Validate required fields
            if (!$serverToken || !$from || !$to || !$templateAlias) {
                $this->logEntitySend($actionEventId, $campaignId, $contact->getId(), 'opportunity', $entityId, 'failed', null, 'Missing required fields');
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Send email
            $payload = [
                'From' => $from,
                'To' => $to,
                'TemplateAlias' => $templateAlias,
                'TemplateModel' => $model,
            ];

            [$ok, $statusCode, $respBody, $err] = $this->sendPostmark($serverToken, $payload);
            $messageId = $this->extractMessageId($respBody);

            // Log the send
            $this->logEntitySend(
                $actionEventId,
                $campaignId,
                $contact->getId(),
                'opportunity',
                $entityId,
                $ok ? 'sent' : 'failed',
                $messageId,
                $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
            );

            if ($ok) {
                $passedEntityIds[] = $entityId;
                $this->logger->info('Email sent successfully for opportunity entity', [
                    'opportunity_id' => $entityId,
                    'contact_email' => $to,
                    'message_id' => $messageId,
                ]);
            } else {
                $failedEntityIds[] = $entityId;
                $this->logger->error('Failed to send email for opportunity entity', [
                    'opportunity_id' => $entityId,
                    'error' => $err ?: $respBody,
                ]);
            }
        }

        // Mark logs as passed/failed
        if (!empty($passedEntityIds)) {
            $event->pass($passedEntityIds);
        }
        if (!empty($failedEntityIds)) {
            $event->fail($failedEntityIds);
        }
    }

    /**
     * Send one email per Note entity.
     */
    private function sendPerNoteEntity(EntityPendingEvent $event, array $config, array $entityIds): void
    {
        if (!$this->em) {
            $this->logger->error('EntityManager not available');
            $event->failAll();
            return;
        }

        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));
        $templateModel = $this->parseTemplateModel($config);

        $passedEntityIds = [];
        $failedEntityIds = [];

        foreach ($entityIds as $entityId) {
            // Load Note entity
            $note = $this->em->getRepository(Note::class)->find($entityId);
            if (!$note) {
                $this->logger->warning('Note entity not found', ['id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Get contact from note
            $contact = $note->getContact();
            if (!$contact) {
                $this->logger->warning('Contact not found in Note', ['note_id' => $entityId]);
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Check idempotency
            if ($this->alreadySent($actionEventId, 'note', $contact->getId(), $entityId)) {
                $this->logger->debug('Skipping duplicate send', [
                    'action_event_id' => $actionEventId,
                    'contact_id' => $contact->getId(),
                    'note_id' => $entityId,
                ]);
                $passedEntityIds[] = $entityId;
                continue;
            }

            // Load related Event entity for token resolution
            $additionalEntities = [];
            if ($note->getEvent()) {
                $additionalEntities[] = $note->getEvent();
            }

            // Resolve tokens
            [$from, $to, $model] = $this->resolveTokensWithEntity(
                $fromEmail,
                $toEmail,
                $templateModel,
                $contact,
                $note,
                $additionalEntities
            );

            // Validate required fields
            if (!$serverToken || !$from || !$to || !$templateAlias) {
                $this->logEntitySend($actionEventId, $campaignId, $contact->getId(), 'note', $entityId, 'failed', null, 'Missing required fields');
                $failedEntityIds[] = $entityId;
                continue;
            }

            // Send email
            $payload = [
                'From' => $from,
                'To' => $to,
                'TemplateAlias' => $templateAlias,
                'TemplateModel' => $model,
            ];

            [$ok, $statusCode, $respBody, $err] = $this->sendPostmark($serverToken, $payload);
            $messageId = $this->extractMessageId($respBody);

            // Log the send
            $this->logEntitySend(
                $actionEventId,
                $campaignId,
                $contact->getId(),
                'note',
                $entityId,
                $ok ? 'sent' : 'failed',
                $messageId,
                $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
            );

            if ($ok) {
                $passedEntityIds[] = $entityId;
                $this->logger->info('Email sent successfully for note entity', [
                    'note_id' => $entityId,
                    'contact_email' => $to,
                    'message_id' => $messageId,
                ]);
            } else {
                $failedEntityIds[] = $entityId;
                $this->logger->error('Failed to send email for note entity', [
                    'note_id' => $entityId,
                    'error' => $err ?: $respBody,
                ]);
            }
        }

        // Mark logs as passed/failed
        if (!empty($passedEntityIds)) {
            $event->pass($passedEntityIds);
        }
        if (!empty($failedEntityIds)) {
            $event->fail($failedEntityIds);
        }
    }

    /**
     * Parse template model from config.
     */
    private function parseTemplateModel(array $config): array
    {
        $templateModel = [];
        if (!empty($config['template_model']['list'])) {
            $pairs = \Mautic\CoreBundle\Helper\AbstractFormFieldHelper::parseList($config['template_model']['list']);
            $templateModel = array_flip($pairs);
        } elseif (!empty($config['template_model']) && is_array($config['template_model'])) {
            $templateModel = $config['template_model'];
        }
        if (!empty($config['data']) && is_array($config['data'])) {
            foreach ($config['data'] as $key => $value) {
                if (is_string($key)) {
                    $templateModel[$key] = $value;
                }
            }
        }
        return $templateModel;
    }

    /**
     * Resolve template placeholders using contact and optional entity context.
     */
    private function resolveTokensWithEntity(
        string $from,
        string $to,
        array $templateModel,
        Lead $contact,
        ?object $entity = null,
        array $additionalEntities = []
    ): array {
        $profileFields = $contact->getProfileFields();

        // Fallback: if profile fields are empty (e.g., when Lead is loaded via relationship),
        // populate basic fields from the Lead entity's direct getters
        if (empty($profileFields) || !isset($profileFields['email'])) {
            $profileFields = array_merge($profileFields ?: [], [
                'email' => $contact->getEmail(),
                'firstname' => $contact->getFirstname(),
                'lastname' => $contact->getLastname(),
                'company' => $contact->getCompany(),
                'position' => $contact->getPosition(),
                'phone' => $contact->getPhone(),
                'mobile' => $contact->getMobile(),
                'city' => $contact->getCity(),
                'state' => $contact->getState(),
                'country' => $contact->getCountry(),
                'zipcode' => $contact->getZipcode(),
                'address1' => $contact->getAddress1(),
                'address2' => $contact->getAddress2(),
            ]);
        }

        $allEntities = [];
        if ($entity) {
            $allEntities[] = $entity;
        }
        $allEntities = array_merge($allEntities, $additionalEntities);

        $from = $this->resolveTemplateValue($from, $contact, $profileFields, $allEntities);
        $to = $this->resolveTemplateValue($to, $contact, $profileFields, $allEntities);

        $resolvedModel = [];
        foreach ($templateModel as $key => $value) {
            $resolvedModel[$key] = is_string($value)
                ? $this->resolveTemplateValue($value, $contact, $profileFields, $allEntities)
                : $value;
        }

        return [$from, $to, $resolvedModel];
    }

    private function resolveTemplateValue(string $value, Lead $contact, array $profileFields, array $entities = []): string
    {
        // Replace contact field tokens
        $resolved = preg_replace_callback(
            '/\{contactfield=([^}]+)\}/i',
            function (array $matches) use ($profileFields): string {
                $key = $matches[1] ?? '';
                return $this->stringify($profileFields[$key] ?? null);
            },
            $value
        );

        if (null === $resolved) {
            $resolved = $value;
        }

        // Replace entity field tokens
        $resolved = $this->replaceAllEntityFieldTokens($resolved, $entities);

        // Resolve module references (contact:field, event:field, etc.)
        return $this->resolveModuleReference($resolved, $contact, $profileFields, $entities);
    }

    private function replaceAllEntityFieldTokens(string $value, array $entities): string
    {
        foreach ($entities as $entity) {
            $value = $this->replaceEntityFieldTokens($value, $entity);
        }
        return $value;
    }

    private function replaceEntityFieldTokens(string $value, ?object $entity): string
    {
        $entityType = $this->determineEntityType($entity);

        if (!$entityType) {
            return $value;
        }

        $pattern = sprintf('/\{%sfield=([^}]+)\}/i', $entityType);
        $replaced = preg_replace_callback(
            $pattern,
            function (array $matches) use ($entity): string {
                $field = $matches[1] ?? '';
                if (!$entity) {
                    return '';
                }
                return $this->extractPropertyValue($entity, $field);
            },
            $value
        );

        return $replaced ?? $value;
    }

    private function determineEntityType(?object $entity): ?string
    {
        if (!$entity) {
            return null;
        }

        if ($entity instanceof CustomEvent) {
            return 'event';
        }

        if ($entity instanceof Opportunity) {
            return 'opportunity';
        }

        if ($entity instanceof Note) {
            return 'note';
        }

        return null;
    }

    private function resolveModuleReference(string $value, Lead $contact, array $profileFields, array $entities = []): string
    {
        $trimmed = trim($value);

        if (preg_match('/^(?<module>[a-z0-9_]+)\:(?<field>[a-z0-9_.]+)$/i', $trimmed, $matches)) {
            $module = strtolower($matches['module']);
            $field = $matches['field'];

            return $this->resolveModuleFieldValue($module, $field, $contact, $profileFields, $entities);
        }

        return $value;
    }

    private function resolveModuleFieldValue(string $module, string $field, Lead $contact, array $profileFields, array $entities = []): string
    {
        switch ($module) {
            case 'static':
                // Static value - return the field as-is (it's actually the static value)
                return $field;

            case 'lead':
            case 'contact':
                return $this->stringify($profileFields[$field] ?? null);

            case 'event':
                foreach ($entities as $entity) {
                    if ($entity instanceof CustomEvent) {
                        return $this->extractPropertyValue($entity, $field);
                    }
                }
                return '';

            case 'opportunity':
                foreach ($entities as $entity) {
                    if ($entity instanceof Opportunity) {
                        return $this->extractPropertyValue($entity, $field);
                    }
                }
                return '';

            case 'note':
                foreach ($entities as $entity) {
                    if ($entity instanceof Note) {
                        return $this->extractPropertyValue($entity, $field);
                    }
                }
                return '';

            default:
                return '';
        }
    }

    private function extractPropertyValue(object $entity, string $field): string
    {
        $camel = $this->camelize($field);
        $methodNames = [
            'get' . $camel,
            'is' . $camel,
            $field,
        ];

        foreach ($methodNames as $method) {
            if (method_exists($entity, $method)) {
                try {
                    $value = $entity->$method();
                } catch (\Exception $e) {
                    continue;
                }
                return $this->stringify($value);
            }
        }

        return '';
    }

    private function camelize(string $field): string
    {
        if (preg_match('/[_\-\s]/', $field)) {
            $parts = preg_split('/[_\-\s]+/', $field) ?: [];
            $parts = array_map(
                static fn(string $part): string => ucfirst($part),
                array_filter($parts, static fn(string $part): bool => '' !== $part)
            );
            return implode('', $parts);
        }

        return ucfirst($field);
    }

    private function stringify($value): string
    {
        if (null === $value) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $this->stringify($item);
            }
            $parts = array_filter($parts, static fn(string $part): bool => '' !== $part);
            return implode(', ', $parts);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Check if entity send already exists (idempotency).
     */
    private function alreadySent(int $actionEventId, string $entityType, int $contactId, ?int $entityId): bool
    {
        if (!$this->em) {
            return false;
        }

        try {
            $repo = $this->em->getRepository(PostmarkEntitySendLog::class);
            return $repo->alreadySent($actionEventId, $entityType, $contactId, $entityId);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Log entity send to database.
     */
    private function logEntitySend(
        int $actionEventId,
        int $campaignId,
        int $contactId,
        string $entityType,
        ?int $entityId,
        string $status,
        ?string $messageId,
        ?string $error
    ): void {
        if (!$this->em) {
            return;
        }

        try {
            $log = new PostmarkEntitySendLog();
            $log->setCampaignEventId($actionEventId);
            $log->setCampaignId($campaignId);
            $log->setContactId($contactId);
            $log->setEntityType($entityType);
            $log->setEntityId($entityId);
            $log->setStatus($status);

            if ($messageId) {
                $log->setPostmarkMessageId($messageId);
            }

            if ($status === 'sent') {
                $log->setSentAt(new \DateTime());
            }

            if ($error) {
                $log->setError($error);
            }

            $this->em->persist($log);
            $this->em->flush();
        } catch (\Exception $e) {
            $this->logger->error('Failed to log entity send', [
                'error' => $e->getMessage(),
                'action_event_id' => $actionEventId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }
    }

    /**
     * Sends a POST to Postmark with the given payload.
     */
    private function sendPostmark(string $serverToken, array $payload): array
    {
        $url = 'https://api.postmarkapp.com/email/withTemplate';
        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: ' . $serverToken,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        curl_close($ch);

        $ok = ($errno === 0) && $statusCode >= 200 && $statusCode < 300;
        return [$ok, $statusCode, (string) $response, $error];
    }

    /**
     * Extract Postmark message ID from response body.
     */
    private function extractMessageId(?string $respBody): ?string
    {
        if (!$respBody) {
            return null;
        }

        try {
            $decoded = json_decode($respBody, true, 512, JSON_THROW_ON_ERROR);
            return $decoded['MessageID'] ?? null;
        } catch (\JsonException $e) {
            return null;
        }
    }
}
