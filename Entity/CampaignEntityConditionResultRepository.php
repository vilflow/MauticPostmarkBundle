<?php

namespace MauticPlugin\MauticPostmarkBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for CampaignEntityConditionResult
 */
class CampaignEntityConditionResultRepository extends EntityRepository
{
    /**
     * Find all condition results for a contact and campaign
     *
     * @param int $campaignId
     * @param int $contactId
     * @param string $entityType
     * @return CampaignEntityConditionResult[]
     */
    public function findByContactAndType(int $campaignId, int $contactId, string $entityType): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.campaignId = :campaignId')
            ->andWhere('r.contactId = :contactId')
            ->andWhere('r.entityType = :entityType')
            ->setParameter('campaignId', $campaignId)
            ->setParameter('contactId', $contactId)
            ->setParameter('entityType', $entityType)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find result by specific condition event (node)
     *
     * @param int $campaignEventId
     * @param int $contactId
     * @return CampaignEntityConditionResult|null
     */
    public function findByEventAndContact(int $campaignEventId, int $contactId): ?CampaignEntityConditionResult
    {
        return $this->createQueryBuilder('r')
            ->where('r.campaignEventId = :eventId')
            ->andWhere('r.contactId = :contactId')
            ->setParameter('eventId', $campaignEventId)
            ->setParameter('contactId', $contactId)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Delete old results (cleanup)
     *
     * @param int $daysOld
     * @return int Number of deleted records
     */
    public function deleteOlderThan(int $daysOld): int
    {
        $cutoffDate = new \DateTime("-{$daysOld} days");

        return $this->createQueryBuilder('r')
            ->delete()
            ->where('r.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
