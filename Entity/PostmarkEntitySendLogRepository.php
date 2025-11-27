<?php

namespace MauticPlugin\MauticPostmarkBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for PostmarkEntitySendLog
 */
class PostmarkEntitySendLogRepository extends EntityRepository
{
    /**
     * Check if an entity send already exists (idempotency check)
     *
     * @param int $campaignEventId Action node ID
     * @param string $entityType
     * @param int $contactId
     * @param int|null $entityId
     * @return bool
     */
    public function alreadySent(int $campaignEventId, string $entityType, int $contactId, ?int $entityId): bool
    {
        $qb = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.campaignEventId = :eventId')
            ->andWhere('l.entityType = :entityType')
            ->andWhere('l.contactId = :contactId')
            ->setParameter('eventId', $campaignEventId)
            ->setParameter('entityType', $entityType)
            ->setParameter('contactId', $contactId);

        if (null === $entityId) {
            $qb->andWhere('l.entityId IS NULL');
        } else {
            $qb->andWhere('l.entityId = :entityId')
                ->setParameter('entityId', $entityId);
        }

        // Consider 'sent' or recent 'queued' (within 10 minutes) as already sent
        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->eq('l.status', ':statusSent'),
                $qb->expr()->andX(
                    $qb->expr()->eq('l.status', ':statusQueued'),
                    $qb->expr()->gt('l.createdAt', ':recentCutoff')
                )
            )
        )
        ->setParameter('statusSent', 'sent')
        ->setParameter('statusQueued', 'queued')
        ->setParameter('recentCutoff', new \DateTime('-10 minutes'));

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Find existing log entry for update
     *
     * @param int $campaignEventId
     * @param string $entityType
     * @param int $contactId
     * @param int|null $entityId
     * @return PostmarkEntitySendLog|null
     */
    public function findExisting(int $campaignEventId, string $entityType, int $contactId, ?int $entityId): ?PostmarkEntitySendLog
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.campaignEventId = :eventId')
            ->andWhere('l.entityType = :entityType')
            ->andWhere('l.contactId = :contactId')
            ->setParameter('eventId', $campaignEventId)
            ->setParameter('entityType', $entityType)
            ->setParameter('contactId', $contactId);

        if (null === $entityId) {
            $qb->andWhere('l.entityId IS NULL');
        } else {
            $qb->andWhere('l.entityId = :entityId')
                ->setParameter('entityId', $entityId);
        }

        return $qb->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all failed sends for retry
     *
     * @param int|null $limit
     * @return PostmarkEntitySendLog[]
     */
    public function findFailedForRetry(?int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.status = :status')
            ->andWhere('l.createdAt > :cutoff') // Only retry recent failures (last 24 hours)
            ->setParameter('status', 'failed')
            ->setParameter('cutoff', new \DateTime('-24 hours'))
            ->orderBy('l.createdAt', 'ASC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get send statistics for a campaign
     *
     * @param int $campaignId
     * @return array
     */
    public function getStatsByCampaign(int $campaignId): array
    {
        $result = $this->createQueryBuilder('l')
            ->select('l.status, l.entityType, COUNT(l.id) as count')
            ->where('l.campaignId = :campaignId')
            ->setParameter('campaignId', $campaignId)
            ->groupBy('l.status, l.entityType')
            ->getQuery()
            ->getResult();

        $stats = [
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'queued' => 0,
            'by_type' => [],
        ];

        foreach ($result as $row) {
            $status = $row['status'];
            $type = $row['entityType'];
            $count = (int) $row['count'];

            $stats['total'] += $count;
            $stats[$status] = ($stats[$status] ?? 0) + $count;

            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = ['total' => 0, 'sent' => 0, 'failed' => 0, 'queued' => 0];
            }
            $stats['by_type'][$type][$status] = $count;
            $stats['by_type'][$type]['total'] += $count;
        }

        return $stats;
    }

    /**
     * Delete old logs (cleanup)
     *
     * @param int $daysOld
     * @return int Number of deleted records
     */
    public function deleteOlderThan(int $daysOld): int
    {
        $cutoffDate = new \DateTime("-{$daysOld} days");

        return $this->createQueryBuilder('l')
            ->delete()
            ->where('l.createdAt < :cutoff')
            ->andWhere('l.status = :status') // Only delete successful sends
            ->setParameter('cutoff', $cutoffDate)
            ->setParameter('status', 'sent')
            ->getQuery()
            ->execute();
    }
}
