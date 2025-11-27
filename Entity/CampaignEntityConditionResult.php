<?php

namespace MauticPlugin\MauticPostmarkBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Stores filter results from campaign condition nodes.
 * Enables action nodes to inherit exact filter matches for per-entity sends.
 *
 * @ORM\Entity(repositoryClass="MauticPlugin\MauticPostmarkBundle\Entity\CampaignEntityConditionResultRepository")
 * @ORM\Table(name="campaign_entity_condition_result")
 */
class CampaignEntityConditionResult
{
    /**
     * @var int|null
     */
    private $id;

    /**
     * @var int
     */
    private $campaignId;

    /**
     * @var int Campaign event ID (the condition node ID)
     */
    private $campaignEventId;

    /**
     * @var int
     */
    private $contactId;

    /**
     * @var string Entity type: 'opportunity' or 'note'
     */
    private $entityType;

    /**
     * @var string Normalized filter spec as JSON
     */
    private $specJson;

    /**
     * @var string|null Compact JSON array of matched IDs, or null if large
     */
    private $entityIdsJson;

    /**
     * @var \DateTime
     */
    private $createdAt;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('campaign_entity_condition_result')
            ->setCustomRepositoryClass(CampaignEntityConditionResultRepository::class);

        $builder->addId();
        $builder->addField('campaignId', Types::INTEGER, ['columnName' => 'campaign_id']);
        $builder->addField('campaignEventId', Types::INTEGER, ['columnName' => 'campaign_event_id']);
        $builder->addField('contactId', Types::INTEGER, ['columnName' => 'contact_id']);
        $builder->addField('entityType', Types::STRING, ['columnName' => 'entity_type', 'length' => 32]);
        $builder->addField('specJson', Types::TEXT, ['columnName' => 'spec_json']);
        $builder->addField('entityIdsJson', Types::TEXT, ['columnName' => 'entity_ids_json', 'nullable' => true, 'length' => 16777215]); // LONGTEXT
        $builder->addField('createdAt', Types::DATETIME_MUTABLE, ['columnName' => 'created_at']);

        $builder->addIndex(['campaign_event_id', 'contact_id'], 'idx_campaign_event_contact');
        $builder->addIndex(['campaign_id', 'contact_id'], 'idx_campaign_contact');
        $builder->addIndex(['entity_type'], 'idx_entity_type');
    }

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    public function setCampaignId(int $campaignId): self
    {
        $this->campaignId = $campaignId;
        return $this;
    }

    public function getCampaignEventId(): int
    {
        return $this->campaignEventId;
    }

    public function setCampaignEventId(int $campaignEventId): self
    {
        $this->campaignEventId = $campaignEventId;
        return $this;
    }

    public function getContactId(): int
    {
        return $this->contactId;
    }

    public function setContactId(int $contactId): self
    {
        $this->contactId = $contactId;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        if (!in_array($entityType, ['opportunity', 'note'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid entity type "%s"', $entityType));
        }
        $this->entityType = $entityType;
        return $this;
    }

    public function getSpecJson(): string
    {
        return $this->specJson;
    }

    public function setSpecJson(string $specJson): self
    {
        $this->specJson = $specJson;
        return $this;
    }

    public function getEntityIdsJson(): ?string
    {
        return $this->entityIdsJson;
    }

    public function setEntityIdsJson(?string $entityIdsJson): self
    {
        $this->entityIdsJson = $entityIdsJson;
        return $this;
    }

    /**
     * Get entity IDs as array
     *
     * @return int[]
     */
    public function getEntityIds(): array
    {
        if (null === $this->entityIdsJson) {
            return [];
        }

        try {
            $ids = json_decode($this->entityIdsJson, true, 512, JSON_THROW_ON_ERROR);
            return is_array($ids) ? $ids : [];
        } catch (\JsonException $e) {
            return [];
        }
    }

    /**
     * Set entity IDs from array
     *
     * @param int[] $entityIds
     */
    public function setEntityIds(array $entityIds): self
    {
        $this->entityIdsJson = json_encode($entityIds, JSON_THROW_ON_ERROR);
        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
