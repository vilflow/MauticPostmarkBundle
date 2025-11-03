<?php

namespace MauticPlugin\MauticPostmarkBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Idempotency log for entity-level Postmark sends.
 * Prevents duplicate sends for the same entity/event combination.
 *
 * @ORM\Entity(repositoryClass="MauticPlugin\MauticPostmarkBundle\Entity\PostmarkEntitySendLogRepository")
 * @ORM\Table(name="postmark_entity_send_log")
 */
class PostmarkEntitySendLog
{
    /**
     * @var int|null
     */
    private $id;

    /**
     * @var int Campaign event ID (action node ID)
     */
    private $campaignEventId;

    /**
     * @var int
     */
    private $campaignId;

    /**
     * @var int
     */
    private $contactId;

    /**
     * @var string Entity type: 'contact', 'event', 'opportunity', or 'note'
     */
    private $entityType;

    /**
     * @var int|null Entity ID (NULL for contact mode)
     */
    private $entityId;

    /**
     * @var string|null Postmark message ID
     */
    private $postmarkMessageId;

    /**
     * @var string Status: 'queued', 'sent', 'failed'
     */
    private $status;

    /**
     * @var string|null Error message if failed
     */
    private $error;

    /**
     * @var \DateTime|null When email was sent
     */
    private $sentAt;

    /**
     * @var \DateTime
     */
    private $createdAt;

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable('postmark_entity_send_log')
            ->setCustomRepositoryClass(PostmarkEntitySendLogRepository::class);

        $builder->addId();
        $builder->addField('campaignEventId', Types::INTEGER, ['columnName' => 'campaign_event_id']);
        $builder->addField('campaignId', Types::INTEGER, ['columnName' => 'campaign_id']);
        $builder->addField('contactId', Types::INTEGER, ['columnName' => 'contact_id']);
        $builder->addField('entityType', Types::STRING, ['columnName' => 'entity_type', 'length' => 32]);
        $builder->addField('entityId', Types::INTEGER, ['columnName' => 'entity_id', 'nullable' => true]);
        $builder->addField('postmarkMessageId', Types::STRING, ['columnName' => 'postmark_message_id', 'length' => 64, 'nullable' => true]);
        $builder->addField('status', Types::STRING, ['columnName' => 'status', 'length' => 32]);
        $builder->addField('error', Types::TEXT, ['columnName' => 'error', 'nullable' => true]);
        $builder->addField('sentAt', Types::DATETIME_MUTABLE, ['columnName' => 'sent_at', 'nullable' => true]);
        $builder->addField('createdAt', Types::DATETIME_MUTABLE, ['columnName' => 'created_at']);

        $builder->addUniqueConstraint(['campaign_event_id', 'entity_type', 'contact_id', 'entity_id'], 'idx_unique_send');
        $builder->addIndex(['campaign_id'], 'idx_campaign');
        $builder->addIndex(['contact_id'], 'idx_contact');
        $builder->addIndex(['status'], 'idx_status');
        $builder->addIndex(['entity_type'], 'idx_entity_type');
        $builder->addIndex(['postmark_message_id'], 'idx_postmark_message_id');
    }

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->status = 'queued';
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    public function setCampaignId(int $campaignId): self
    {
        $this->campaignId = $campaignId;
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
        if (!in_array($entityType, ['contact', 'event', 'opportunity', 'note'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid entity type "%s"', $entityType));
        }
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getPostmarkMessageId(): ?string
    {
        return $this->postmarkMessageId;
    }

    public function setPostmarkMessageId(?string $postmarkMessageId): self
    {
        $this->postmarkMessageId = $postmarkMessageId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, ['queued', 'sent', 'failed'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid status "%s"', $status));
        }
        $this->status = $status;
        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): self
    {
        $this->error = $error;
        return $this;
    }

    public function getSentAt(): ?\DateTime
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTime $sentAt): self
    {
        $this->sentAt = $sentAt;
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

    /**
     * Mark as sent with Postmark message ID
     */
    public function markAsSent(string $messageId): self
    {
        $this->status = 'sent';
        $this->postmarkMessageId = $messageId;
        $this->sentAt = new \DateTime();
        $this->error = null;
        return $this;
    }

    /**
     * Mark as failed with error message
     */
    public function markAsFailed(string $error): self
    {
        $this->status = 'failed';
        $this->error = $error;
        return $this;
    }
}
