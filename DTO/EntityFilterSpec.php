<?php

namespace MauticPlugin\MauticPostmarkBundle\DTO;

/**
 * Data Transfer Object for entity filter specifications.
 * Used by both campaign condition nodes and action nodes to ensure consistent filtering.
 */
class EntityFilterSpec
{
    /**
     * @var string Entity type: 'event', 'opportunity', or 'note'
     */
    private string $type;

    /**
     * @var array<string, mixed> Filter criteria as associative array
     */
    private array $criteria;

    /**
     * @param string $type Entity type ('event', 'opportunity', or 'note')
     * @param array<string, mixed> $criteria Filter criteria
     */
    public function __construct(string $type, array $criteria = [])
    {
        if (!in_array($type, ['event', 'opportunity', 'note'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid entity type "%s". Must be "event", "opportunity", or "note".', $type));
        }

        $this->type = $type;
        $this->criteria = $criteria;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getCriteria(): array
    {
        return $this->criteria;
    }

    /**
     * Serialize to JSON for storage
     */
    public function toJson(): string
    {
        return json_encode([
            'type' => $this->type,
            'criteria' => $this->criteria,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Deserialize from JSON
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (!isset($data['type'])) {
            throw new \InvalidArgumentException('Missing "type" field in EntityFilterSpec JSON');
        }

        return new self(
            $data['type'],
            $data['criteria'] ?? []
        );
    }

    /**
     * Create from array (e.g., from campaign event config)
     */
    public static function fromArray(string $type, array $criteria): self
    {
        return new self($type, $criteria);
    }

    /**
     * Normalize criteria to ensure consistent comparison
     */
    public function normalize(): self
    {
        $normalized = $this->criteria;
        ksort($normalized);

        return new self($this->type, $normalized);
    }

    /**
     * Generate a hash for this spec (useful for caching/comparison)
     */
    public function hash(): string
    {
        return md5($this->normalize()->toJson());
    }
}
