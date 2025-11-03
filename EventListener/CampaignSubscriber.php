<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\MauticEventsBundle\Entity\Event;
use MauticPlugin\MauticNotesBundle\Entity\Note;
use MauticPlugin\MauticOpportunitiesBundle\Entity\Opportunity;
use MauticPlugin\MauticPostmarkBundle\DTO\EntityFilterSpec;
use MauticPlugin\MauticPostmarkBundle\Entity\CampaignEntityConditionResultRepository;
use MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLog;
use MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLogRepository;
use MauticPlugin\MauticPostmarkBundle\Event\PostmarkEvents;
use MauticPlugin\MauticPostmarkBundle\Form\Type\PostmarkSendType;
use MauticPlugin\MauticPostmarkBundle\Service\EventCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\NoteCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\OpportunityCriteriaBuilder;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    private ?Connection $connection = null;
    private ?SuiteCRMService $suiteCRMService = null;
    private LoggerInterface $logger;
    private ?EntityManagerInterface $em = null;
    private ?EventCriteriaBuilder $eventCriteriaBuilder = null;
    private ?OpportunityCriteriaBuilder $opportunityCriteriaBuilder = null;
    private ?NoteCriteriaBuilder $noteCriteriaBuilder = null;

    public function __construct(
        ?Connection $connection = null,
        ?SuiteCRMService $suiteCRMService = null,
        ?LoggerInterface $logger = null,
        ?EntityManagerInterface $em = null,
        ?EventCriteriaBuilder $eventCriteriaBuilder = null,
        ?OpportunityCriteriaBuilder $opportunityCriteriaBuilder = null,
        ?NoteCriteriaBuilder $noteCriteriaBuilder = null
    ) {
        $this->connection                   = $connection;
        $this->suiteCRMService              = $suiteCRMService;
        $this->logger                       = $logger ?? new NullLogger();
        $this->em                           = $em;
        $this->eventCriteriaBuilder         = $eventCriteriaBuilder;
        $this->opportunityCriteriaBuilder   = $opportunityCriteriaBuilder;
        $this->noteCriteriaBuilder          = $noteCriteriaBuilder;
    }

    private function getConnection(): ?Connection
    {
        return $this->connection;
    }
    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD        => ['onCampaignBuild', 0],
            PostmarkEvents::ON_CAMPAIGN_BATCH_ACTION => ['onCampaignTriggerPostmark', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'postmark.send',
            [
                'label'            => 'mautic.postmark.campaign.event.send',
                'description'      => 'mautic.postmark.campaign.event.send_descr',
                'batchEventName'   => PostmarkEvents::ON_CAMPAIGN_BATCH_ACTION,
                'formType'         => PostmarkSendType::class,
                // Optional: declare channel so logs group under a channel name
                'channel'          => 'postmark',
            ]
        );

    }

    public function onCampaignTriggerPostmark(PendingEvent $event): void
    {

        if (!$event->checkContext('postmark.send')) {
            return;
        }

        $config = $event->getEvent()->getProperties();
        $mode = trim((string) ($config['mode'] ?? 'contact'));

        // Route to appropriate handler based on mode
        switch ($mode) {
            case 'event':
                $this->sendPerEvent($event, $config);
                return;
            case 'opportunity':
                $this->sendPerOpportunity($event, $config);
                return;
            case 'note':
                $this->sendPerNote($event, $config);
                return;
            case 'contact':
            default:
                $this->sendPerContact($event, $config);
                return;
        }
    }

    /**
     * Send one email per contact (original/default behavior)
     */
    private function sendPerContact(PendingEvent $event, array $config): void
    {
        $serverToken   = trim((string) ($config['server_token'] ?? ''));
        $fromEmail     = trim((string) ($config['from_email'] ?? ''));
        $toEmail       = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));

        // Build TemplateModel from SortableListType (like Webhook's additional_data)
        $templateModel = [];
        if (!empty($config['template_model']['list'])) {
            // parseList returns [value => label]; we want [label => value]
            $pairs         = \Mautic\CoreBundle\Helper\AbstractFormFieldHelper::parseList($config['template_model']['list']);
            $templateModel = array_flip($pairs);
        } elseif (!empty($config['template_model']) && is_array($config['template_model'])) {
            // Fallback if stored as flat key=>value pairs
            $templateModel = $config['template_model'];
        }
        // Also merge from generic 'data' bag if present (other UIs/patterns)
        if (!empty($config['data']) && is_array($config['data'])) {
            foreach ($config['data'] as $key => $value) {
                if (is_string($key)) {
                    $templateModel[$key] = $value;
                }
            }
        }

        // Channel for logs
        $event->setChannel('postmark');

        // Iterate all pending logs/contacts
        $pending  = $event->getPending();
        $contacts = $event->getContacts();

        foreach ($contacts as $logId => $contact) {
            $log = $pending->get($logId);

            // Resolve tokens in strings (basic token replacement for common contact fields)
            [$from, $to, $model] = $this->resolveTokens($fromEmail, $toEmail, $templateModel, $contact);

            // Validate required and report specifics
            $missing = [];
            if (!$serverToken) {
                $missing[] = 'server_token';
            }
            if (!$from) {
                $missing[] = 'from_email';
            }
            if (!$to) {
                $missing[] = 'to_email';
            }
            if (!$templateAlias) {
                $missing[] = 'template_alias';
            }
            if ($missing) {
                $event->fail($log, 'Missing Postmark fields: '.implode(', ', $missing));
                continue;
            }

            $payload = [
                'From'          => $from,
                'To'            => $to,
                'TemplateAlias' => $templateAlias,
                'TemplateModel' => $model,
            ];

            [$ok, $statusCode, $respBody, $err] = $this->sendPostmark($serverToken, $payload);

            if (!$ok) {
                $event->fail($log, sprintf('Postmark error (%s): %s', (string) $statusCode, $err ?: $respBody));
                continue;
            }

            // Append response metadata for timeline visibility
            $log->appendToMetadata([
                'postmark' => [
                    'status'     => 'success',
                    'http_code'  => $statusCode,
                    'to'         => $to,
                    'from'       => $from,
                    'template'   => $templateAlias,
                    'response'   => $respBody,
                ],
            ]);

            // Also persist MessageID to campaign_lead_event_log for webhook correlation
            $messageId = null;
            $decoded   = json_decode((string) $respBody, true);
            if (is_array($decoded) && !empty($decoded['MessageID'])) {
                $messageId = (string) $decoded['MessageID'];
            }
            try {
                $update = [
                    'postmark_delivery_status' => 'sent',
                ];
                if ($messageId) {
                    $update['postmark_message_id'] = $messageId;
                }
                if ($connection = $this->getConnection()) {
                    $connection->update(
                        MAUTIC_TABLE_PREFIX.'campaign_lead_event_log',
                        $update,
                        ['id' => $log->getId()]
                    );
                }
            } catch (\Exception $e) {
                $logFile = dirname(__DIR__) . '/postmark_error.log';
                $logLine = $e->getMessage();
                @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
                // Ignore if columns not present yet
            }

            // Create SuiteCRM Email record


            if (true) {
                $this->logger->info('SuiteCRM email record creation triggered.', [
                    'contact_id' => $contact->getId(),
                    'log_id'     => $log->getId(),
                ]);

                $this->createSuiteCRMEmailRecord($log, $from, $to, $contact, $messageId);
            } else {
                $this->logger->info('SuiteCRM email record creation not triggered.', [
                    'contact_id' => $contact->getId(),
                    'log_id'     => $log->getId(),
                ]);
            }

            $event->pass($log);
        }
    }


    /**
     * Resolve template placeholders for contact-only sends.
     *
     * @param string $from
     * @param string $to
     * @param array  $templateModel
     * @param Lead   $contact
     *
     * @return array [from, to, templateModel]
     */
    private function resolveTokens(string $from, string $to, array $templateModel, Lead $contact): array
    {
        $profileFields = $contact->getProfileFields();

        $from = $this->resolveTemplateValue($from, $contact, $profileFields, null);
        $to   = $this->resolveTemplateValue($to, $contact, $profileFields, null);

        $resolvedModel = [];
        foreach ($templateModel as $key => $value) {
            $resolvedModel[$key] = is_string($value)
                ? $this->resolveTemplateValue($value, $contact, $profileFields, null)
                : $value;
        }

        return [$from, $to, $resolvedModel];
    }

    /**
     * Create SuiteCRM Email record after sending email
     *
     * @param mixed  $log       Campaign log
     * @param string $from      From email address
     * @param string $to        To email address
     * @param mixed  $contact   Contact object
     * @param string|null $messageId Postmark message ID
     */
    private function createSuiteCRMEmailRecord($log, string $from, string $to, $contact, ?string $messageId): void
    {

        try {
            $profileFields = $contact->getProfileFields();
            $contactId     = $contact->getId();

            // Prepare email data for SuiteCRM
            // Note: SuiteCRM automatically sets date_entered and date_modified
            $campaign      = $log->getCampaign();
            $eventEntity   = $log->getEvent();
            $campaignName  = $campaign ? $campaign->getName() : null;
            $actionName    = $eventEntity ? $eventEntity->getName() : null;
            $emailNameParts = array_filter(
                [$campaignName, $actionName],
                static fn ($value) => null !== $value && '' !== trim((string) $value)
            );
            $emailName = $emailNameParts ? implode(' - ', $emailNameParts) : 'Postmark Email to ' . ($profileFields['lastname'] ?? $to);

            $emailData = [
                'name'        => $emailName,
                'status'      => 'sent',
                'from_addr'   => $from,
                'to_addrs'    => $to,
                'description' => 'Email sent via Mautic Postmark integration',
                'parent_type' => 'Contacts',
                'postmark_id_c' => $messageId,
                'parent_id'   => $profileFields['suitecrm_id'] ?? null, // SuiteCRM contact ID from Mautic contact field
            ];

            $this->logger->debug('Attempting to create SuiteCRM email record.', [
                'contact_id' => $contactId,
                'message_id' => $messageId,
                'email_data' => $emailData,
                'profile_fileds' => $profileFields,

            ]);

            [$success, $suitecrmEmailId, $error] = $this->suiteCRMService->createEmailRecord($emailData);
            
            if (!$success) {
                $this->logger->warning('SuiteCRM email record creation returned no success status.', [
                    'contact_id' => $contactId,
                    'message_id' => $messageId,
                    'error'      => $error,
                    'success'    => $success,
                    'email data' => $emailData   
                ]);
            }


            if ($success && $suitecrmEmailId) {
                $this->logger->info('SuiteCRM email record created successfully.', [
                    'contact_id'        => $contactId,
                    'suitecrm_email_id' => $suitecrmEmailId,
                    'message_id'        => $messageId,
                ]);

                // Store SuiteCRM email ID in log metadata for later updates
                $log->appendToMetadata([
                    'suitecrm' => [
                        'email_id'   => $suitecrmEmailId,
                        'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                        'description' => $emailData['description'] ?? null,
                    ],
                ]);



                // i want update email record in  

                // Also persist in database
                if ($connection = $this->getConnection()) {
                    $meta = $log->getMetadata();
                    $connection->update(
                        MAUTIC_TABLE_PREFIX.'campaign_lead_event_log',
                        ['metadata' => json_encode($meta)],
                        ['id' => $log->getId()]
                    );
                }
            }
        } catch (\Throwable $e) {
           


            $this->logger->error('SuiteCRM email record creation failed.', [
                'contact_id' => isset($contactId) ? $contactId : null,
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
                'exception'  => $e,
            ]);

            // Fail silently to not block email sending
            // You can log this error if needed
        }
    }

    /**
     * Sends a POST to Postmark with the given payload.
     *
     * @param string $serverToken
     * @param array  $payload
     *
     * @return array [ok(bool), statusCode(int), responseBody(string), error(string|null)]
     */
    private function sendPostmark(string $serverToken, array $payload): array
    {
        $url  = 'https://api.postmarkapp.com/email/withTemplate';
        $ch   = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Postmark-Server-Token: '.$serverToken,
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno      = curl_errno($ch);
        $error      = $errno ? curl_error($ch) : null;
        curl_close($ch);

        $ok = ($errno === 0) && $statusCode >= 200 && $statusCode < 300;
        return [$ok, $statusCode, (string) $response, $error];
    }

    /**
     * Send one email per Opportunity (per-entity mode)
     */
    private function sendPerOpportunity(PendingEvent $event, array $config): void
    {
        if (!$this->em) {
            $this->logger->error('EntityManager not available for per-opportunity sends');
            return;
        }

        $contacts = $event->getContacts();
        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();
        $event->setChannel('postmark');

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));

        // Parse template model
        $templateModel = $this->parseTemplateModel($config);

        foreach ($contacts as $logId => $contact) {
            $log = $event->getPending()->get($logId);
            $contactId = $contact->getId();

            // Get filtered opportunity IDs from upstream condition
            $opportunityIds = $this->getFilteredOpportunityIdsForContact($contactId, $event->getEvent());

            if (empty($opportunityIds)) {
                $event->fail($log, 'No matching opportunities found for this contact based on upstream conditions');
                continue;
            }

            $sentCount = 0;
            $failedCount = 0;

            // Send email for each opportunity
            foreach ($opportunityIds as $opportunityId) {
                // Check idempotency
                if ($this->alreadySent($actionEventId, 'opportunity', $contactId, $opportunityId)) {
                    $this->logger->debug('Skipping duplicate send', [
                        'action_event_id' => $actionEventId,
                        'contact_id' => $contactId,
                        'opportunity_id' => $opportunityId,
                    ]);
                    continue;
                }

                // Load opportunity entity
                $opportunity = $this->em->getRepository(Opportunity::class)->find($opportunityId);
                if (!$opportunity) {
                    $this->logger->warning('Opportunity not found', ['id' => $opportunityId]);
                    continue;
                }

                // Load related Event entity for token resolution
                $additionalEntities = [];
                if ($opportunity->getEvent()) {
                    $additionalEntities[] = $opportunity->getEvent();
                }

                // Resolve tokens with opportunity AND event context
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
                    $this->logEntitySend(
                        $actionEventId,
                        $campaignId,
                        $contactId,
                        'opportunity',
                        $opportunityId,
                        'failed',
                        null,
                        'Missing required fields (server_token, from, to, or template_alias)'
                    );
                    $failedCount++;
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
                    $contactId,
                    'opportunity',
                    $opportunityId,
                    $ok ? 'sent' : 'failed',
                    $messageId,
                    $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
                );

                if ($ok) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            // Append summary to log metadata
            $log->appendToMetadata([
                'postmark_opportunity_sends' => [
                    'total_opportunities' => count($opportunityIds),
                    'sent' => $sentCount,
                    'failed' => $failedCount,
                    'skipped' => count($opportunityIds) - $sentCount - $failedCount,
                ],
            ]);

            // Pass the contact log (even if some opportunities failed)
            $event->pass($log);
        }
    }

    /**
     * Send one email per Note (per-entity mode)
     */
    private function sendPerNote(PendingEvent $event, array $config): void
    {
        if (!$this->em) {
            $this->logger->error('EntityManager not available for per-note sends');
            return;
        }

        $contacts = $event->getContacts();
        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();
        $event->setChannel('postmark');

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));

        // Parse template model
        $templateModel = $this->parseTemplateModel($config);

        foreach ($contacts as $logId => $contact) {
            $log = $event->getPending()->get($logId);
            $contactId = $contact->getId();

            // Get filtered note IDs from upstream condition
            $noteIds = $this->getFilteredNoteIdsForContact($contactId, $event->getEvent());

            if (empty($noteIds)) {
                $event->fail($log, 'No matching notes found for this contact based on upstream conditions');
                continue;
            }

            $sentCount = 0;
            $failedCount = 0;

            // Send email for each note
            foreach ($noteIds as $noteId) {
                // Check idempotency
                if ($this->alreadySent($actionEventId, 'note', $contactId, $noteId)) {
                    $this->logger->debug('Skipping duplicate send', [
                        'action_event_id' => $actionEventId,
                        'contact_id' => $contactId,
                        'note_id' => $noteId,
                    ]);
                    continue;
                }

                // Load note entity
                $note = $this->em->getRepository(Note::class)->find($noteId);
                if (!$note) {
                    $this->logger->warning('Note not found', ['id' => $noteId]);
                    continue;
                }

                // Load related Event entity for token resolution
                $additionalEntities = [];
                if ($note->getEvent()) {
                    $additionalEntities[] = $note->getEvent();
                }

                // Resolve tokens with note AND event context
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
                    $this->logEntitySend(
                        $actionEventId,
                        $campaignId,
                        $contactId,
                        'note',
                        $noteId,
                        'failed',
                        null,
                        'Missing required fields (server_token, from, to, or template_alias)'
                    );
                    $failedCount++;
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
                    $contactId,
                    'note',
                    $noteId,
                    $ok ? 'sent' : 'failed',
                    $messageId,
                    $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
                );

                if ($ok) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            // Append summary to log metadata
            $log->appendToMetadata([
                'postmark_note_sends' => [
                    'total_notes' => count($noteIds),
                    'sent' => $sentCount,
                    'failed' => $failedCount,
                    'skipped' => count($noteIds) - $sentCount - $failedCount,
                ],
            ]);

            // Pass the contact log (even if some notes failed)
            $event->pass($log);
        }
    }

    /**
     * Send one email per Event (per-entity mode)
     */
    private function sendPerEvent(PendingEvent $event, array $config): void
    {
        error_log('[Postmark Debug] sendPerEvent called');

        $this->logger->error("sendPerEvent...");


        if (!$this->em) {
            $this->logger->error("sendPerEvent... 2");
            $this->logger->error('EntityManager not available for per-event sends');
            error_log('[Postmark Debug] EntityManager is NULL!');
            return;
        }

        $contacts = $event->getContacts();
        $actionEventId = $event->getEvent()->getId();
        $campaignId = $event->getEvent()->getCampaign()->getId();
        $event->setChannel('postmark');

        error_log(sprintf('[Postmark Debug] Processing %d contacts in sendPerEvent', count($contacts)));

        $serverToken = trim((string) ($config['server_token'] ?? ''));
        $fromEmail = trim((string) ($config['from_email'] ?? ''));
        $toEmail = trim((string) ($config['to_email'] ?? ''));
        $templateAlias = trim((string) ($config['template_alias'] ?? ''));

        // Parse template model
        $templateModel = $this->parseTemplateModel($config);

        $this->logger->error("config", [$config]);
        $this->logger->error("templateModel", [$templateModel]);

        $this->logger->error("sendPerEvent... 3");

        foreach ($contacts as $logId => $contact) {
            $log = $event->getPending()->get($logId);
            $contactId = $contact->getId();

            $this->logger->error("sendPerEvent... 4");

            // Get upstream condition to extract event filters
            $eventIds = $this->getFilteredEventIdsForContact($contactId, $event->getEvent());

            if (empty($eventIds)) {
                $event->fail($log, 'No matching events found for this contact based on upstream conditions');
                continue;
            }

            // Find EventContact entities for these filtered event IDs
            $eventContacts = $this->em->getRepository(\MauticPlugin\MauticEventsBundle\Entity\EventContact::class)
                ->createQueryBuilder('ec')
                ->where('ec.contact = :contactId')
                ->andWhere('ec.event IN (:eventIds)')
                ->setParameter('contactId', $contactId)
                ->setParameter('eventIds', $eventIds)
                ->getQuery()
                ->getResult();

            if (empty($eventContacts)) {
                $event->fail($log, 'No events found for this contact');
                continue;
            }

            $sentCount = 0;
            $failedCount = 0;

            // Send email for each event
            foreach ($eventContacts as $eventContact) {
                $this->logger->error("sendPerEvent... 5 ", [$eventContact]);

                $eventEntity = $eventContact->getEvent();
                if (!$eventEntity) {
                    $this->logger->warning('Event not found in EventContact');
                    continue;
                }

                $eventId = $eventEntity->getId();

                // Check idempotency
                if ($this->alreadySent($actionEventId, 'event', $contactId, $eventId)) {
                    $this->logger->error("sendPerEvent... 6 ");

                    $this->logger->debug('Skipping duplicate send', [
                        'action_event_id' => $actionEventId,
                        'contact_id' => $contactId,
                        'event_id' => $eventId,
                    ]);
                    continue;
                }

                // Resolve tokens with event context
                [$from, $to, $model] = $this->resolveTokensWithEntity(
                    $fromEmail,
                    $toEmail,
                    $templateModel,
                    $contact,
                    $eventEntity
                );

                // Validate required fields
                if (!$serverToken || !$from || !$to || !$templateAlias) {
                    $this->logger->error("sendPerEvent... 7 ");

                    $this->logEntitySend(
                        $actionEventId,
                        $campaignId,
                        $contactId,
                        'event',
                        $eventId,
                        'failed',
                        null,
                        'Missing required fields (server_token, from, to, or template_alias)'
                    );
                    $failedCount++;
                    continue;
                }

                // Send email
                $payload = [
                    'From' => $from,
                    'To' => $to,
                    'TemplateAlias' => $templateAlias,
                    'TemplateModel' => $model,
                ];

                
                $this->logger->error("sendPerEvent... 10", $payload);

                [$ok, $statusCode, $respBody, $err] = $this->sendPostmark($serverToken, $payload);

                $this->logger->error("sendPerEvent... 8", [$ok, $statusCode, $respBody, $err]);


                $messageId = $this->extractMessageId($respBody);

                // Log the send
                $this->logEntitySend(
                    $actionEventId,
                    $campaignId,
                    $contactId,
                    'event',
                    $eventId,
                    $ok ? 'sent' : 'failed',
                    $messageId,
                    $ok ? null : sprintf('Postmark error (%s): %s', $statusCode, $err ?: $respBody)
                );

                if ($ok) {
                    $sentCount++;
                } else {
                    $failedCount++;
                }
            }

            // Append summary to log metadata
            $log->appendToMetadata([
                'postmark_event_sends' => [
                    'total_events' => count($eventContacts),
                    'sent' => $sentCount,
                    'failed' => $failedCount,
                    'skipped' => count($eventContacts) - $sentCount - $failedCount,
                ],
            ]);

            // Pass the contact log (even if some events failed)
            $event->pass($log);
        }
    }

    /**
     * Parse template model from config
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
     * Find condition results for a contact and entity type
     *
     * @return array Array of condition results with entity_ids
     */
    private function findConditionResultsForContact(int $campaignId, int $contactId, string $entityType): array
    {
        if (!$this->em) {
            return [];
        }

        /** @var CampaignEntityConditionResultRepository $repo */
        $repo = $this->em->getRepository(\MauticPlugin\MauticPostmarkBundle\Entity\CampaignEntityConditionResult::class);

        return $repo->findByContactAndType($campaignId, $contactId, $entityType);
    }

    /**
     * Extract unique entity IDs from condition results
     *
     * @param array $conditionResults
     * @return int[]
     */
    private function extractEntityIds(array $conditionResults): array
    {
        $allIds = [];

        foreach ($conditionResults as $result) {
            $ids = $result->getEntityIds();
            $allIds = array_merge($allIds, $ids);
        }

        return array_unique($allIds);
    }

    /**
     * Check if entity send already exists (idempotency)
     */
    private function alreadySent(int $actionEventId, string $entityType, int $contactId, ?int $entityId): bool
    {
        if (!$this->em) {
            return false;
        }

        /** @var PostmarkEntitySendLogRepository $repo */
        $repo = $this->em->getRepository(\MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLog::class);

        return $repo->alreadySent($actionEventId, $entityType, $contactId, $entityId);
    }

    /**
     * Log entity send to database
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
     * Extract Postmark message ID from response body
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

    /**
     * Resolve template placeholders using contact and optional entity context.
     *
     * @param string      $from
     * @param string      $to
     * @param array       $templateModel
     * @param Lead        $contact
     * @param object|null $entity   Related entity (event/opportunity/note)
     *
     * @return array [from, to, templateModel]
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

        // Merge entity with additionalEntities
        $allEntities = [];
        if ($entity) {
            $allEntities[] = $entity;
        }
        $allEntities = array_merge($allEntities, $additionalEntities);

        $from = $this->resolveTemplateValue($from, $contact, $profileFields, $allEntities);
        $to   = $this->resolveTemplateValue($to, $contact, $profileFields, $allEntities);

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

        $resolved = $this->replaceAllEntityFieldTokens($resolved, $entities);

        return $this->resolveModuleReference($resolved, $contact, $profileFields, $entities);
    }

    private function replaceAllEntityFieldTokens(string $value, array $entities): string
    {
        // Replace tokens for each entity type
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

        if ($entity instanceof Event) {
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

    private function resolveModuleReference(
        string $value,
        Lead $contact,
        array $profileFields,
        array $entities = []
    ): string {
        $trimmed = trim($value);

        if (preg_match('/^(?<module>[a-z0-9_]+)\:(?<field>[a-z0-9_.]+)$/i', $trimmed, $matches)) {
            $module = strtolower($matches['module']);
            $field  = $matches['field'];

            $resolved = $this->resolveModuleFieldValue($module, $field, $contact, $profileFields, $entities);

            return $resolved;
        }

        return $value;
    }

    private function resolveModuleFieldValue(
        string $module,
        string $field,
        Lead $contact,
        array $profileFields,
        array $entities = []
    ): string {
        switch ($module) {
            case 'lead':
            case 'contact':
                return $this->stringify($profileFields[$field] ?? null);

            case 'company':
                $company = null;

                if (method_exists($contact, 'getPrimaryCompany')) {
                    $company = $contact->getPrimaryCompany();
                }

                if (!$company && method_exists($contact, 'getCompanies')) {
                    $companies = $contact->getCompanies();
                    if ($companies instanceof \Traversable) {
                        foreach ($companies as $candidate) {
                            $company = $candidate;
                            break;
                        }
                    } elseif (is_array($companies) && !empty($companies)) {
                        $company = reset($companies);
                    }
                }

                if ($company) {
                    return $this->extractPropertyValue($company, $field);
                }

                return '';

            case 'event':
                // Search for Event entity in entities array
                foreach ($entities as $entity) {
                    if ($entity instanceof Event) {
                        return $this->extractPropertyValue($entity, $field);
                    }
                }
                return '';

            case 'opportunity':
                // Search for Opportunity entity in entities array
                foreach ($entities as $entity) {
                    if ($entity instanceof Opportunity) {
                        return $this->extractPropertyValue($entity, $field);
                    }
                }
                return '';

            case 'note':
                // Search for Note entity in entities array
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
        $camel       = $this->camelize($field);
        $methodNames = [
            'get' . $camel,
            'is' . $camel,
            $field,
        ];

        foreach ($methodNames as $method) {
            if (method_exists($entity, $method)) {
                try {
                    $value = $entity->$method();
                    $this->logger->error("test extract: $method", [$value]);
                } catch (\Exception $e) {
                    $this->logger->error("error test extract: $method", [$value, $e->getMessage()]);
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
                static fn (string $part): string => ucfirst($part),
                array_filter($parts, static fn (string $part): bool => '' !== $part)
            );

            return implode('', $parts);
        }

        return ucfirst($field);
    }

    /**
     * @param mixed $value
     */
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

            $parts = array_filter($parts, static fn (string $part): bool => '' !== $part);

            return implode(', ', $parts);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Get filtered event IDs for a contact based on upstream campaign conditions
     *
     * @param int $contactId
     * @param \Mautic\CampaignBundle\Entity\Event $actionEvent
     * @return array Event IDs
     */
    private function getFilteredEventIdsForContact(int $contactId, $actionEvent): array
    {
        if (!$this->eventCriteriaBuilder) {
            $this->logger->warning('EventCriteriaBuilder not available, returning all events');
            // Fallback: return all event IDs for contact
            $eventContacts = $this->em->getRepository(\MauticPlugin\MauticEventsBundle\Entity\EventContact::class)
                ->findBy(['contact' => $contactId]);
            return array_map(fn($ec) => $ec->getEvent()->getId(), $eventContacts);
        }

        // Get parent condition
        $parent = $actionEvent->getParent();

        if (!$parent) {
            $this->logger->info('No parent condition found, returning all events for contact');
            // No upstream condition, return all events
            $eventContacts = $this->em->getRepository(\MauticPlugin\MauticEventsBundle\Entity\EventContact::class)
                ->findBy(['contact' => $contactId]);
            return array_map(fn($ec) => $ec->getEvent()->getId(), $eventContacts);
        }

        $properties = $parent->getProperties();
        $this->logger->error('Parent condition properties', $properties);

        // Extract filter criteria from condition properties
        if (empty($properties['field']) || !isset($properties['value'])) {
            $this->logger->warning('Parent condition has no field/value, returning all events');
            // No valid filter, return all events
            $eventContacts = $this->em->getRepository(\MauticPlugin\MauticEventsBundle\Entity\EventContact::class)
                ->findBy(['contact' => $contactId]);
            return array_map(fn($ec) => $ec->getEvent()->getId(), $eventContacts);
        }

        $field = $properties['field'];
        $operator = $properties['operator'] ?? '=';
        $value = $properties['value'];

        $this->logger->error('Extracted filter criteria', [
            'field' => $field,
            'operator' => $operator,
            'value' => $value
        ]);

        // Build EntityFilterSpec
        $criteria = [
            $field => [
                'operator' => $operator,
                'value' => $value
            ]
        ];

        $spec = EntityFilterSpec::fromArray('event', $criteria);

        // Use EventCriteriaBuilder to find matching events
        try {
            $eventIds = $this->eventCriteriaBuilder->findMatchingIdsForContact($contactId, $spec);

            $this->logger->error('Filtered event IDs', [
                'contactId' => $contactId,
                'eventIds' => $eventIds,
                'count' => count($eventIds)
            ]);

            return $eventIds;
        } catch (\Exception $e) {
            $this->logger->error('Error filtering events', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Fallback: return all events
            $eventContacts = $this->em->getRepository(\MauticPlugin\MauticEventsBundle\Entity\EventContact::class)
                ->findBy(['contact' => $contactId]);
            return array_map(fn($ec) => $ec->getEvent()->getId(), $eventContacts);
        }
    }

    /**
     * Get filtered opportunity IDs for a contact based on upstream campaign conditions
     * This respects relationships: if there's an Event condition upstream, only return
     * opportunities linked to those events.
     *
     * @param int $contactId
     * @param \Mautic\CampaignBundle\Entity\Event $actionEvent
     * @return array Opportunity IDs
     */
    private function getFilteredOpportunityIdsForContact(int $contactId, $actionEvent): array
    {
        if (!$this->opportunityCriteriaBuilder) {
            $this->logger->warning('OpportunityCriteriaBuilder not available, returning all opportunities');
            return $this->getAllOpportunityIds($contactId);
        }

        // Get all ancestor conditions
        $ancestors = $this->getAllAncestors($actionEvent);

        if (empty($ancestors)) {
            $this->logger->info('No ancestor conditions found, returning all opportunities for contact');
            return $this->getAllOpportunityIds($contactId);
        }

        // Separate filters by entity type
        $eventFilters = [];
        $opportunityFilters = [];

        foreach ($ancestors as $ancestor) {
            $properties = $ancestor->getProperties();
            $type = $ancestor->getType();

            $this->logger->error('Checking ancestor condition', [
                'type' => $type,
                'properties' => $properties
            ]);

            if (empty($properties['field']) || !isset($properties['value'])) {
                continue;
            }

            $field = $properties['field'];
            $operator = $properties['operator'] ?? '=';
            $value = $properties['value'];

            // Detect if this is an Event condition
            if (strpos($type, 'event') !== false || strpos(strtolower($field), 'event') !== false) {
                $eventFilters[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
            // Detect if this is an Opportunity condition
            elseif (strpos($type, 'opportunit') !== false) {
                $opportunityFilters[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
        }

        $this->logger->error('Separated filters', [
            'eventFilters' => $eventFilters,
            'opportunityFilters' => $opportunityFilters
        ]);

        // Start with base query
        $qb = $this->em->getRepository(Opportunity::class)->createQueryBuilder('o')
            ->select('o.id')
            ->where('o.contact = :contactId')
            ->andWhere('o.deleted = :deleted')
            ->setParameter('contactId', $contactId)
            ->setParameter('deleted', false);

        // Apply Event filters first (relationship filter)
        if (!empty($eventFilters) && $this->eventCriteriaBuilder) {
            $eventIds = $this->getFilteredEventIdsForContactDirect($contactId, $eventFilters);

            if (empty($eventIds)) {
                $this->logger->info('No events matched, so no opportunities can match');
                return [];
            }

            // Filter opportunities by event relationship
            $qb->andWhere('o.event IN (:eventIds)')
               ->setParameter('eventIds', $eventIds);

            $this->logger->error('Applied event relationship filter', [
                'eventIds' => $eventIds
            ]);
        }

        // Apply Opportunity filters
        foreach ($opportunityFilters as $filter) {
            $field = $filter['field'];
            $operator = $filter['operator'];
            $value = $filter['value'];

            $column = 'o.' . $field;
            $paramName = 'param_' . uniqid();

            $this->applyOperatorToQueryBuilder($qb, $column, $operator, $value, $paramName);
        }

        try {
            $result = $qb->getQuery()->getScalarResult();
            $opportunityIds = array_column($result, 'id');

            $this->logger->error('Filtered opportunity IDs with relationships', [
                'contactId' => $contactId,
                'opportunityIds' => $opportunityIds,
                'count' => count($opportunityIds)
            ]);

            return $opportunityIds;
        } catch (\Exception $e) {
            $this->logger->error('Error filtering opportunities', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->getAllOpportunityIds($contactId);
        }
    }

    /**
     * Get all opportunity IDs for a contact (fallback)
     */
    private function getAllOpportunityIds(int $contactId): array
    {
        $opportunities = $this->em->getRepository(Opportunity::class)
            ->findBy(['contact' => $contactId, 'deleted' => false]);
        return array_map(fn($o) => $o->getId(), $opportunities);
    }

    /**
     * Get filtered note IDs for a contact based on upstream campaign conditions
     * This respects relationships: if there's an Event condition upstream, only return
     * notes linked to those events.
     *
     * @param int $contactId
     * @param \Mautic\CampaignBundle\Entity\Event $actionEvent
     * @return array Note IDs
     */
    private function getFilteredNoteIdsForContact(int $contactId, $actionEvent): array
    {
        if (!$this->noteCriteriaBuilder) {
            $this->logger->warning('NoteCriteriaBuilder not available, returning all notes');
            return $this->getAllNoteIds($contactId);
        }

        // Get all ancestor conditions
        $ancestors = $this->getAllAncestors($actionEvent);

        if (empty($ancestors)) {
            $this->logger->info('No ancestor conditions found, returning all notes for contact');
            return $this->getAllNoteIds($contactId);
        }

        // Separate filters by entity type
        $eventFilters = [];
        $noteFilters = [];

        foreach ($ancestors as $ancestor) {
            $properties = $ancestor->getProperties();
            $type = $ancestor->getType();

            if (empty($properties['field']) || !isset($properties['value'])) {
                continue;
            }

            $field = $properties['field'];
            $operator = $properties['operator'] ?? '=';
            $value = $properties['value'];

            // Detect if this is an Event condition
            if (strpos($type, 'event') !== false || strpos(strtolower($field), 'event') !== false) {
                $eventFilters[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
            // Detect if this is a Note condition
            elseif (strpos($type, 'note') !== false) {
                $noteFilters[] = [
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value
                ];
            }
        }

        // Start with base query
        $qb = $this->em->getRepository(Note::class)->createQueryBuilder('n')
            ->select('n.id')
            ->where('n.contact = :contactId')
            ->andWhere('n.deleted = :deleted')
            ->setParameter('contactId', $contactId)
            ->setParameter('deleted', false);

        // Apply Event filters first (relationship filter)
        if (!empty($eventFilters) && $this->eventCriteriaBuilder) {
            $eventIds = $this->getFilteredEventIdsForContactDirect($contactId, $eventFilters);

            if (empty($eventIds)) {
                $this->logger->info('No events matched, so no notes can match');
                return [];
            }

            // Filter notes by event relationship
            $qb->andWhere('n.event IN (:eventIds)')
               ->setParameter('eventIds', $eventIds);
        }

        // Apply Note filters
        foreach ($noteFilters as $filter) {
            $field = $filter['field'];
            $operator = $filter['operator'];
            $value = $filter['value'];

            $column = 'n.' . $field;
            $paramName = 'param_' . uniqid();

            $this->applyOperatorToQueryBuilder($qb, $column, $operator, $value, $paramName);
        }

        try {
            $result = $qb->getQuery()->getScalarResult();
            $noteIds = array_column($result, 'id');

            $this->logger->error('Filtered note IDs with relationships', [
                'contactId' => $contactId,
                'noteIds' => $noteIds,
                'count' => count($noteIds)
            ]);

            return $noteIds;
        } catch (\Exception $e) {
            $this->logger->error('Error filtering notes', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->getAllNoteIds($contactId);
        }
    }

    /**
     * Get all note IDs for a contact (fallback)
     */
    private function getAllNoteIds(int $contactId): array
    {
        $notes = $this->em->getRepository(Note::class)
            ->findBy(['contact' => $contactId, 'deleted' => false]);
        return array_map(fn($n) => $n->getId(), $notes);
    }

    /**
     * Get all ancestor conditions (parents, grandparents, etc.) for an action event
     *
     * @param \Mautic\CampaignBundle\Entity\Event $event
     * @return array Array of ancestor events
     */
    private function getAllAncestors($event): array
    {
        $ancestors = [];
        $current = $event->getParent();

        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->getParent();
        }

        return $ancestors;
    }

    /**
     * Get filtered event IDs given an array of filters
     *
     * @param int $contactId
     * @param array $filters Array of ['field' => ..., 'operator' => ..., 'value' => ...]
     * @return array Event IDs
     */
    private function getFilteredEventIdsForContactDirect(int $contactId, array $filters): array
    {
        if (empty($filters)) {
            return [];
        }

        // Build criteria for EntityFilterSpec
        $criteria = [];
        foreach ($filters as $filter) {
            $criteria[$filter['field']] = [
                'operator' => $filter['operator'],
                'value' => $filter['value']
            ];
        }

        $spec = EntityFilterSpec::fromArray('event', $criteria);

        try {
            return $this->eventCriteriaBuilder->findMatchingIdsForContact($contactId, $spec);
        } catch (\Exception $e) {
            $this->logger->error('Error filtering events directly', [
                'message' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Apply an operator to a query builder
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     * @param string $column
     * @param string $operator
     * @param mixed $value
     * @param string $paramName
     */
    private function applyOperatorToQueryBuilder($qb, string $column, string $operator, $value, string $paramName): void
    {
        $trimmedValue = is_string($value) ? trim($value) : $value;

        switch ($operator) {
            case '=':
            case 'eq':
                $qb->andWhere($column . ' = :' . $paramName);
                $qb->setParameter($paramName, $trimmedValue);
                break;
            case '!=':
            case 'neq':
                $qb->andWhere('(' . $column . ' != :' . $paramName . ' OR ' . $column . ' IS NULL)');
                $qb->setParameter($paramName, $trimmedValue);
                break;
            case 'like':
            case 'contains':
                $qb->andWhere($column . ' LIKE :' . $paramName)
                    ->setParameter($paramName, '%' . $trimmedValue . '%');
                break;
            case '!like':
                $qb->andWhere($column . ' NOT LIKE :' . $paramName)
                    ->setParameter($paramName, '%' . $trimmedValue . '%');
                break;
            case 'gt':
                $qb->andWhere($column . ' > :' . $paramName)
                    ->setParameter($paramName, $trimmedValue);
                break;
            case 'gte':
                $qb->andWhere($column . ' >= :' . $paramName)
                    ->setParameter($paramName, $trimmedValue);
                break;
            case 'lt':
                $qb->andWhere($column . ' < :' . $paramName)
                    ->setParameter($paramName, $trimmedValue);
                break;
            case 'lte':
                $qb->andWhere($column . ' <= :' . $paramName)
                    ->setParameter($paramName, $trimmedValue);
                break;
            case 'in':
                $values = is_array($trimmedValue) ? $trimmedValue : array_map('trim', explode(',', (string) $trimmedValue));
                $values = array_values(array_filter($values));
                if (empty($values)) {
                    $qb->andWhere('1 = 0');
                    break;
                }
                $qb->andWhere($column . ' IN (:' . $paramName . ')')
                    ->setParameter($paramName, $values);
                break;
            case '!in':
                $values = is_array($trimmedValue) ? $trimmedValue : array_map('trim', explode(',', (string) $trimmedValue));
                $values = array_values(array_filter($values));
                if (empty($values)) {
                    break;
                }
                $qb->andWhere($column . ' NOT IN (:' . $paramName . ')')
                    ->setParameter($paramName, $values);
                break;
            case 'empty':
                $qb->andWhere('(' . $column . ' IS NULL OR ' . $column . " = '' )");
                break;
            case '!empty':
                $qb->andWhere($column . ' IS NOT NULL')
                    ->andWhere($column . " != ''");
                break;
            default:
                $qb->andWhere($column . ' = :' . $paramName)
                    ->setParameter($paramName, $trimmedValue);
        }
    }
}
