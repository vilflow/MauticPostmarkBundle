<?php

namespace MauticPlugin\MauticPostmarkBundle\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class WebhookController
{
    public function __construct(private Connection $connection)
    {
    }

    public function handleAction(Request $request): JsonResponse
    {
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
                $this->applyUpdate((int) $logId, $recordType, $ts, $e);
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
            $this->applyUpdate((int) $logId, $recordType, $ts, $e);
        }
    }

    private function applyUpdate(int $logId, string $type, string $ts, array $e): void
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
                    'postmark_subscription_change'    => 1,
                    'postmark_subscription_change_at' => $ts,
                    'postmark_delivery_status'   => $status,
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
