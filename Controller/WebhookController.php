<?php

namespace MauticPlugin\MauticPostmarkBundle\Controller;

use Doctrine\DBAL\Connection;
use MauticPlugin\MauticPostmarkBundle\Service\SuiteCRMService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController
{
    private ?SuiteCRMService $suiteCRMService = null;
    private LoggerInterface $logger;

    public function __construct(private Connection $connection, ?SuiteCRMService $suiteCRMService = null, ?LoggerInterface $logger = null)
    {
        $this->suiteCRMService = $suiteCRMService;
        $this->logger          = $logger ?? new NullLogger();
    }

    public function handleAction(Request $request): JsonResponse
    {
        $this->logger->info('aaaaaaa');

        $rawBody = (string) $request->getContent();

        // Optional signature validation if POSTMARK_WEBHOOK_SECRET is set
        $secret = (string) (getenv('POSTMARK_WEBHOOK_SECRET') ?: '');
        if ($secret !== '') {
            $valid = $this->isValidSignature($request, $rawBody, $secret);
            if (!$valid) {
                return new JsonResponse(['ok' => false, 'error' => 'invalid_signature'], 401);
            }
        }

        $payload = json_decode($rawBody, true) ?: [];

        $records = isset($payload[0]) ? $payload : [$payload];
        foreach ($records as $event) {
            $this->handleEvent($event);
        }

        return new JsonResponse(['ok' => true]);
    }

    private function handleEvent(array $e): void
    {
        $recordType = strtolower((string) ($e['RecordType'] ?? $e['Type'] ?? ''));
        $messageId  = $e['MessageID'] ?? $e['MessageID'] ?? null;
        $tsRaw      = $e['DeliveredAt'] ?? $e['DeliveredAt'] ?? $e['BouncedAt'] ?? $e['ChangedAt'] ?? $e['ClickedAt'] ?? $e['OpenedAt'] ?? null;
        $ts         = $tsRaw ? (new \DateTime($tsRaw))->format('Y-m-d H:i:s') : (new \DateTime())->format('Y-m-d H:i:s');

        if ($messageId) {
            $logId = $this->connection->createQueryBuilder()
                ->select('id')
                ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'l')
                ->where('l.postmark_message_id = :mid')
                ->setParameter('mid', $messageId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if ($logId) {
                $this->applyUpdate($messageId, (int) $logId, $recordType, $ts, $e);
            }

            return;
        }

        // Fallback correlation by recipient (if no MessageID)
        $recipient = $e['Recipient'] ?? $e['Email'] ?? null;
        if (!$recipient) {
            return;
        }

        $logId = $this->connection->createQueryBuilder()
            ->select('id')
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', 'l')
            ->where("l.channel = 'postmark'")
            ->andWhere('l.date_triggered >= DATE_SUB(NOW(), INTERVAL 14 DAY)')
            ->andWhere('JSON_EXTRACT(l.metadata, "$.postmark.to") = :to')
            ->orderBy('l.date_triggered', 'DESC')
            ->setParameter('to', $recipient)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();


        if ($logId) {
            $this->applyUpdate($messageId, (int) $logId, $recordType, $ts, $e);
        }
    }

    private function applyUpdate($messageId, int $logId, string $type, string $ts, array $e): void
    {

        $updates = ['postmark_delivery_status' => null];
        switch ($type) {
            case 'delivery':
                $updates += [
                    'postmark_delivered'        => 1,
                    'postmark_delivered_at'     => $ts,
                    'postmark_delivery_status'  => 'delivered',
                ];
                $this->appendEventMetadata($logId, 'delivery', $ts, [
                    'MessageID'  => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'  => $e['Recipient'] ?? $e['Email'] ?? null,
                    'DeliveredAt'=> $e['DeliveredAt'] ?? null,
                    'Tag'        => $e['Tag'] ?? null,
                    'Metadata'   => $e['Metadata'] ?? null,
                ]);
                break;

            case 'open':
                $count = $this->incCol($logId, 'postmark_opened_count');
                // Track only first open as boolean; counts and last timestamp are recorded too
                $openedFlag = $count > 0 ? 1 : 1; // always ends up 1, but flag first open via metadata
                $updates += [
                    'postmark_opened'           => $openedFlag,
                    'postmark_opened_count'     => $count,
                    'postmark_last_opened_at'   => $ts,
                    'postmark_delivery_status'  => 'opened',
                ];
                $this->appendEventMetadata($logId, 'open', $ts, [
                    'MessageID'  => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'  => $e['Recipient'] ?? $e['Email'] ?? null,
                    'ReceivedAt' => $e['ReceivedAt'] ?? $e['OpenedAt'] ?? null,
                    'FirstOpen'  => $e['FirstOpen'] ?? ($count === 1),
                    'Platform'   => $e['Platform'] ?? null,
                    'UserAgent'  => $e['UserAgent'] ?? null,
                    'OS'         => $e['OS'] ?? ($e['OperatingSystem'] ?? null),
                    'Client'     => $e['Client'] ?? null,
                    'Geo'        => $e['Geo'] ?? [
                        'City'    => $e['City']    ?? null,
                        'Region'  => $e['Region']  ?? null,
                        'Country' => $e['Country'] ?? null,
                        'IP'      => $e['IPAddress'] ?? null,
                    ],
                    'Metadata'   => $e['Metadata'] ?? null,
                ]);
                break;

            case 'click':
                $clickCount = $this->incCol($logId, 'postmark_clicked_count');
                $updates += [
                    'postmark_clicked'          => 1,
                    'postmark_clicked_count'    => $clickCount,
                    'postmark_last_clicked_at'  => $ts,
                    'postmark_delivery_status'  => 'clicked',
                ];
                $this->appendEventMetadata($logId, 'click', $ts, [
                    'MessageID'     => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'     => $e['Recipient'] ?? $e['Email'] ?? null,
                    'ReceivedAt'    => $e['ReceivedAt'] ?? $e['ClickedAt'] ?? null,
                    'ClickLocation' => $e['ClickLocation'] ?? null,
                    'OriginalLink'  => $e['OriginalLink'] ?? $e['OriginalLinkUrl'] ?? null,
                    'Platform'      => $e['Platform'] ?? null,
                    'UserAgent'     => $e['UserAgent'] ?? null,
                    'OS'            => $e['OS'] ?? ($e['OperatingSystem'] ?? null),
                    'Client'        => $e['Client'] ?? null,
                    'Geo'           => $e['Geo'] ?? [
                        'City'    => $e['City']    ?? null,
                        'Region'  => $e['Region']  ?? null,
                        'Country' => $e['Country'] ?? null,
                        'IP'      => $e['IPAddress'] ?? null,
                    ],
                    'Metadata'      => $e['Metadata'] ?? null,
                ]);
                break;

            case 'bounce':
                $updates += [
                    'postmark_bounced'          => 1,
                    'postmark_bounced_at'       => $ts,
                    'postmark_bounce_type'      => (string)($e['Type'] ?? ''),
                    'postmark_bounce_detail'    => (string)($e['Description'] ?? ''),
                    'postmark_delivery_status'  => 'bounced',
                ];
                $this->appendEventMetadata($logId, 'bounce', $ts, [
                    'MessageID'  => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'  => $e['Recipient'] ?? $e['Email'] ?? null,
                    'BouncedAt'  => $e['BouncedAt'] ?? null,
                    'Type'       => $e['Type'] ?? null,
                    'Description'=> $e['Description'] ?? null,
                    'Content'    => $e['Content'] ?? $e['Details'] ?? null,
                    'Metadata'   => $e['Metadata'] ?? null,
                ]);
                break;

            case 'spamcomplaint':
                $updates += [
                    'postmark_spam_complaint'    => 1,
                    'postmark_spam_complaint_at' => $ts,
                    'postmark_delivery_status'   => 'complained',
                ];
                $this->appendEventMetadata($logId, 'spam_complaint', $ts, [
                    'MessageID'   => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'   => $e['Recipient'] ?? $e['Email'] ?? null,
                    'BouncedAt'   => $e['BouncedAt'] ?? null,
                    'Description' => $e['Description'] ?? null,
                    'Content'     => $e['Content'] ?? $e['Details'] ?? null,
                    'Metadata'    => $e['Metadata'] ?? null,
                ]);
                break;

            case 'subscriptionchange':
                // Postmark Subscription Change event
                $suppress = (bool) ($e['SuppressSending'] ?? false);
                $status   = $suppress ? 'suppressed' : ($e['SuppressionReason'] ?? 'subscription_changed');
                $updates += [
                    'postmark_delivery_status' => $status,
                ];
                $this->appendEventMetadata($logId, 'subscription_change', $ts, [
                    'MessageID'        => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient'        => $e['Recipient'] ?? $e['Email'] ?? null,
                    'ChangedAt'        => $e['ChangedAt'] ?? null,
                    'Origin'           => $e['Origin'] ?? null,
                    'SuppressSending'  => $suppress,
                    'SuppressionReason'=> $e['SuppressionReason'] ?? null,
                    'Metadata'         => $e['Metadata'] ?? null,
                ]);
                break;

            case 'transient':
                $updates += [
                    'postmark_deferred_count'    => $this->incCol($logId, 'postmark_deferred_count'),
                    'postmark_last_deferred_at'  => $ts,
                    'postmark_delivery_status'   => 'deferred',
                ];
                $this->appendEventMetadata($logId, 'deferred', $ts, [
                    'MessageID' => $e['MessageID'] ?? $e['MessageId'] ?? null,
                    'Recipient' => $e['Recipient'] ?? $e['Email'] ?? null,
                    'ReceivedAt'=> $e['ReceivedAt'] ?? null,
                    'Metadata'  => $e['Metadata'] ?? null,
                ]);
                break;

            default:
                if ($type) {
                    $updates['postmark_delivery_status'] = $type;
                }
                $this->appendEventMetadata($logId, $type ?: 'unknown', $ts, [
                    'raw' => $e,
                ]);
        }

        $updates = array_filter($updates, fn ($v) => null !== $v);
        if ($updates) {
            $this->connection->update(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log', $updates, ['id' => $logId]);
        }

//        die(dump($this->suiteCRMService->isEnabled(), 1));
        // Update SuiteCRM Email record if integration is enabled
        if ($this->suiteCRMService && $this->suiteCRMService->isEnabled()) {
            $this->logger->info('Updating SuiteCRM email record from Postmark webhook.', [
                'log_id'     => $logId,
                'event_type' => $type,
                'timestamp'  => $ts,
                'event'      => $e,
            ]);
            $this->updateSuiteCRMEmailRecord($messageId, $logId, $type, $ts, $e);
        } else {
            $this->logger->debug('SuiteCRM email record update skipped; integration disabled or unavailable.', [
                'log_id'     => $logId,
                'event_type' => $type,
                'timestamp'  => $ts,
            ]);
        }
    }

    private function incCol(int $logId, string $col): int
    {
        $current = (int) $this->connection->createQueryBuilder()
            ->select($col)
            ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log')
            ->where('id = :id')
            ->setParameter('id', $logId)
            ->executeQuery()
            ->fetchOne();

        return $current + 1;
    }

    private function appendEventMetadata(int $logId, string $eventType, string $ts, array $data): void
    {
        try {
            $row = $this->connection->createQueryBuilder()
                ->select('metadata')
                ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log')
                ->where('id = :id')
                ->setParameter('id', $logId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            $meta = [];
            $hadExisting = is_string($row) && $row !== '';
            if ($hadExisting) {
                $decoded = json_decode($row, true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                } else {
                    // Existing metadata is not JSON; do not risk overriding
                    return;
                }
            }

            if (!isset($meta['postmark']) || !is_array($meta['postmark'])) {
                $meta['postmark'] = [];
            }
            if (!isset($meta['postmark']['events']) || !is_array($meta['postmark']['events'])) {
                $meta['postmark']['events'] = [];
            }

            $meta['postmark']['events'][] = [
                'type' => $eventType,
                'ts'   => $ts,
                'data' => $data,
            ];

            $this->connection->update(
                MAUTIC_TABLE_PREFIX.'campaign_lead_event_log',
                ['metadata' => json_encode($meta)],
                ['id' => $logId]
            );
        } catch (\Throwable) {
            // fail silently; metadata is optional
        }
    }

    /**
     * Update SuiteCRM Email record when webhook event is received
     *
     * @param int    $logId Mautic campaign log ID
     * @param string $type  Event type (delivery, open, click, bounce, etc.)
     * @param string $ts    Timestamp
     * @param array  $e     Event data from Postmark
     */
    private function updateSuiteCRMEmailRecord($messageId, int $logId, string $type, string $ts, array $e): void
    {
        try {

            // Get SuiteCRM email ID from log metadata
            $row = $this->connection->createQueryBuilder()
                ->select('metadata')
                ->from(MAUTIC_TABLE_PREFIX.'campaign_lead_event_log')
                ->where('id = :id')
                ->setParameter('id', $logId)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();

            if (!$row) {
                return;
            }

//            $meta = json_decode($row, true);
//            if (JSON_ERROR_NONE !== json_last_error() || !is_array($meta)) {
//                // Fall back to PHP serialized metadata if JSON decoding failed
//                $meta = @unserialize($row, ['allowed_classes' => false]);
//
//                $this->logger->info(
//                    'my meta',
//                    ['meta' => $meta],
//                );
//
//
//                if ($meta === false && $row !== 'b:0;') {
//                    $this->logger->warning(
//                        'Postmark webhook metadata could not be decoded.',
//                        ['logId' => $logId, 'type' => $type]
//                    );
//                    return;
//                }
//            }

//            if (!is_array($meta) || empty($meta['suitecrm']['email_id'])) {
//                return; // No SuiteCRM email ID found
//            }

//            $suitecrmEmailId = $meta['suitecrm']['email_id'];

            // Prepare update data based on event type
            $updateData = [];
            $description = '';




            switch ($type) {
                case 'delivery':
                    $updateData['status'] = 'delivered';
                    $description = sprintf(
                        'Delivered at %s to %s',
                        $ts,
                        $e['Recipient'] ?? $e['Email'] ?? 'unknown'
                    );
                    break;

                case 'open':
                    $updateData['status'] = 'opened';
                    $description = sprintf(
                        'Opened at %s from %s (Platform: %s, Client: %s)',
                        $ts,
                        $e['City'] ?? 'unknown location',
                        $e['Platform'] ?? 'unknown',
                        $e['Client'] ?? 'unknown'
                    );
                    break;

                case 'click':
                    $updateData['status'] = 'clicked';
                    $description = sprintf(
                        'Clicked at %s. Link: %s',
                        $ts,
                        $e['OriginalLink'] ?? $e['OriginalLinkUrl'] ?? 'unknown'
                    );
                    break;

                case 'bounce':
                    $updateData['status'] = 'bounced';
                    $description = sprintf(
                        'Bounced at %s. Type: %s, Reason: %s',
                        $ts,
                        $e['Type'] ?? 'unknown',
                        $e['Description'] ?? 'unknown'
                    );
                    break;

                case 'spamcomplaint':
                    $updateData['status'] = 'spam_complaint';
                    $description = sprintf(
                        'Spam complaint at %s. %s',
                        $ts,
                        $e['Description'] ?? ''
                    );
                    break;

                default:
                    // For other event types, just add to description
                    $description = sprintf('%s event at %s', ucfirst($type), $ts);
                    break;
            }

            // Append to description instead of replacing
            if (!empty($description)) {
                $updateData['description'] = $description;
            }

            $this->logger->info('my sample test: ',[
               'message id' => $messageId,
                'update data' => $updateData
            ]);

            if (!empty($updateData)) {
                $this->suiteCRMService->updateEmailRecordByPostmarkId($messageId, $updateData);
            }
        } catch (\Throwable $ex) {
            // Fail silently to not break webhook processing
            // You can log this error if needed
        }
    }

    private function isValidSignature(Request $request, string $rawBody, string $secret): bool
    {
        $signature = (string) $request->headers->get('X-Postmark-Signature', '');
        if ($signature === '') {
            return false;
        }
        // HMAC-SHA256 of the request body with the webhook secret, base64-encoded
        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));
        // constant-time comparison
        if (function_exists('hash_equals')) {
            return hash_equals($signature, $computed);
        }
        return $signature === $computed;
    }
}
