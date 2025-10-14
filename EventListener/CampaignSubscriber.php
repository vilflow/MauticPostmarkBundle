<?php

namespace MauticPlugin\MauticPostmarkBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\PendingEvent;
use MauticPlugin\MauticPostmarkBundle\Event\PostmarkEvents;
use MauticPlugin\MauticPostmarkBundle\Form\Type\PostmarkSendType;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    private ?Connection $connection = null;
    private ?SuiteCRMService $suiteCRMService = null;

    public function __construct(?Connection $connection = null, ?SuiteCRMService $suiteCRMService = null)
    {
        $this->connection       = $connection;
        $this->suiteCRMService  = $suiteCRMService;
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
            [$from, $to, $model] = $this->resolveTokens($fromEmail, $toEmail, $templateModel, $contact->getProfileFields());

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
            } catch (\Throwable) {
                // Ignore if columns not present yet
            }

            // Create SuiteCRM Email record
            if ($this->suiteCRMService && $this->suiteCRMService->isEnabled()) {
                $this->createSuiteCRMEmailRecord($log, $from, $to, $contact, $messageId);
            }

            $event->pass($log);
        }
    }


    /**
     * Very basic token replacement supporting patterns like {contactfield=email}, {contactfield=firstname}, etc.
     *
     * @param string $from
     * @param string $to
     * @param array  $templateModel
     * @param array  $profileFields
     *
     * @return array [from, to, templateModel]
     */
    private function resolveTokens(string $from, string $to, array $templateModel, array $profileFields): array
    {
        $replace = function (string $value) use ($profileFields): string {
            return preg_replace_callback('/\{contactfield=([^}]+)\}/i', function ($m) use ($profileFields) {
                $key = $m[1] ?? '';
                return (string) ($profileFields[$key] ?? '');
            }, $value) ?? $value;
        };

        $from = $replace($from);
        $to   = $replace($to);

        $resolvedModel = [];
        foreach ($templateModel as $k => $v) {
            $resolvedModel[$k] = is_string($v) ? $replace($v) : $v;
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
            $emailData = [
                'name'        => 'Postmark Email to ' . ($profileFields['firstname'] ?? $to),
                'status'      => 'sent',
                'from_addr'   => $from,
                'to_addrs'    => $to,
                'description' => 'Email sent via Mautic Postmark integration',
                'parent_type' => 'Contacts',
                'parent_id'   => $profileFields['suitecrm_id'] ?? null, // SuiteCRM contact ID from Mautic contact field
            ];

            // Add date_sent if available
            if (method_exists($log, 'getDateTriggered') && $log->getDateTriggered()) {
                $emailData['date_sent'] = $log->getDateTriggered()->format('Y-m-d\TH:i:s\Z');
            }

            [$success, $suitecrmEmailId, $error] = $this->suiteCRMService->createEmailRecord($emailData);

            if ($success && $suitecrmEmailId) {
                // Store SuiteCRM email ID in log metadata for later updates
                $log->appendToMetadata([
                    'suitecrm' => [
                        'email_id'   => $suitecrmEmailId,
                        'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    ],
                ]);

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
}
