<?php

namespace MauticPlugin\MauticPostmarkBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\MauticEventsBundle\Entity\Event;
use MauticPlugin\MauticEventsBundle\Entity\EventRepository;
use MauticPlugin\MauticPostmarkBundle\DTO\EntityFilterSpec;

/**
 * Builds query criteria for Event filtering based on EntityFilterSpec.
 * Uses the same logic as EventRepository for consistency.
 */
class EventCriteriaBuilder
{
    private EntityManagerInterface $em;
    private ?EventRepository $repository = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Lazy-load the repository to avoid EntityManager being null during service initialization
     */
    private function getRepository(): EventRepository
    {
        if (null === $this->repository) {
            $this->repository = $this->em->getRepository(Event::class);
        }
        return $this->repository;
    }

    /**
     * Build a QueryBuilder from EntityFilterSpec
     *
     * @param EntityFilterSpec $spec Filter specification
     * @return QueryBuilder
     */
    public function fromSpec(EntityFilterSpec $spec): QueryBuilder
    {
        if ($spec->getType() !== 'event') {
            throw new \InvalidArgumentException('Spec type must be "event"');
        }

        $qb = $this->getRepository()->createQueryBuilder('e')
            ->where('e.deleted = :deleted')
            ->setParameter('deleted', false);

        $criteria = $spec->getCriteria();

        // Apply each filter criterion
        foreach ($criteria as $field => $filterConfig) {
            if (!isset($filterConfig['operator'], $filterConfig['value'])) {
                continue;
            }

            $operator = $filterConfig['operator'];
            $value = $filterConfig['value'];

            $this->applyFilter($qb, $field, $operator, $value);
        }

        return $qb;
    }

    /**
     * Find matching Event IDs for a specific contact
     *
     * @param int $contactId Contact ID
     * @param EntityFilterSpec $spec Filter specification
     * @param int|null $limit Optional limit
     * @return int[] Array of Event IDs
     */
    public function findMatchingIdsForContact(int $contactId, EntityFilterSpec $spec, ?int $limit = null): array
    {
        $qb = $this->fromSpec($spec);
        $qb->select('e.id')
            ->join('MauticPlugin\MauticEventsBundle\Entity\EventContact', 'ec', 'WITH', 'ec.event = e.id')
            ->andWhere('ec.contact = :contactId')
            ->setParameter('contactId', $contactId);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $results = $qb->getQuery()->getScalarResult();

        return array_column($results, 'id');
    }

    /**
     * Count matching Events for a specific contact
     *
     * @param int $contactId Contact ID
     * @param EntityFilterSpec $spec Filter specification
     * @return int Count of matching events
     */
    public function countMatchingForContact(int $contactId, EntityFilterSpec $spec): int
    {
        $qb = $this->fromSpec($spec);
        $qb->select('COUNT(e.id)')
            ->join('MauticPlugin\MauticEventsBundle\Entity\EventContact', 'ec', 'WITH', 'ec.event = e.id')
            ->andWhere('ec.contact = :contactId')
            ->setParameter('contactId', $contactId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Apply a single filter to the query builder
     *
     * @param QueryBuilder $qb Query builder
     * @param string $field Field name
     * @param string $operator Operator
     * @param mixed $value Value
     */
    private function applyFilter(QueryBuilder $qb, string $field, string $operator, mixed $value): void
    {
        // Convert label to key for select fields
        $value = $this->convertLabelToKeyForSelectFields($field, $value);

        $column = 'e.' . $field;
        $parameterName = 'param_' . $field . '_' . uniqid();

        $trimmedValue = is_string($value) ? trim($value) : $value;

        switch ($operator) {
            case '=':
            case 'eq':
                $qb->andWhere($column . ' = :' . $parameterName);
                $qb->setParameter($parameterName, $trimmedValue);
                break;
            case '!=':
            case 'neq':
                $qb->andWhere('(' . $column . ' != :' . $parameterName . ' OR ' . $column . ' IS NULL)');
                $qb->setParameter($parameterName, $trimmedValue);
                break;
            case 'like':
            case 'contains':
                $qb->andWhere($column . ' LIKE :' . $parameterName)
                    ->setParameter($parameterName, '%' . $trimmedValue . '%');
                break;
            case '!like':
                $qb->andWhere($column . ' NOT LIKE :' . $parameterName)
                    ->setParameter($parameterName, '%' . $trimmedValue . '%');
                break;
            case 'startsWith':
                $qb->andWhere($column . ' LIKE :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue . '%');
                break;
            case 'endsWith':
                $qb->andWhere($column . ' LIKE :' . $parameterName)
                    ->setParameter($parameterName, '%' . $trimmedValue);
                break;
            case 'gt':
                $qb->andWhere($column . ' > :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
                break;
            case 'gte':
                $qb->andWhere($column . ' >= :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
                break;
            case 'lt':
                $qb->andWhere($column . ' < :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
                break;
            case 'lte':
                $qb->andWhere($column . ' <= :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
                break;
            case 'in':
                $values = is_array($trimmedValue) ? $trimmedValue : array_map('trim', explode(',', (string) $trimmedValue));
                $values = array_values(array_filter($values, static fn($item) => $item !== '' && $item !== null));
                if (empty($values)) {
                    $qb->andWhere('1 = 0');
                    break;
                }
                $qb->andWhere($column . ' IN (:' . $parameterName . ')')
                    ->setParameter($parameterName, $values);
                break;
            case '!in':
                $values = is_array($trimmedValue) ? $trimmedValue : array_map('trim', explode(',', (string) $trimmedValue));
                $values = array_values(array_filter($values, static fn($item) => $item !== '' && $item !== null));
                if (empty($values)) {
                    break;
                }
                $qb->andWhere($column . ' NOT IN (:' . $parameterName . ')')
                    ->setParameter($parameterName, $values);
                break;
            case 'empty':
                $qb->andWhere('(' . $column . ' IS NULL OR ' . $column . " = '' )");
                break;
            case '!empty':
                $qb->andWhere($column . ' IS NOT NULL')
                    ->andWhere($column . " != ''");
                break;
            case 'date':
                $this->applyDateFilter($qb, $column, $trimmedValue, $parameterName, $field);
                break;
            default:
                $qb->andWhere($column . ' = :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
        }
    }

    /**
     * Apply date filter with relative date support
     */
    private function applyDateFilter(QueryBuilder $qb, string $column, string $value, string $parameterName, string $field): void
    {
        // Handle anniversary special case
        if ('anniversary' === $value) {
            $dateValue = $this->convertRelativeDateToActual($value);
            $qb->andWhere('SUBSTRING(' . $column . ', 6, 5) = SUBSTRING(:' . $parameterName . ', 6, 5)')
                ->setParameter($parameterName, $dateValue);
            return;
        }

        // Check if this is a relative interval
        $isRelativeInterval = $this->isRelativeInterval($value);

        if ($isRelativeInterval) {
            // For relative intervals: create a RANGE filter
            $rangeParams = $this->calculateDateRange($value);
            $startParam = $parameterName . '_start';
            $endParam = $parameterName . '_end';

            // Get field metadata to check if it's a datetime or date field
            $metadata = $this->em->getClassMetadata(Event::class);
            $fieldName = str_replace('e.', '', $column);

            $useSubstring = false;
            if ($metadata->hasField($fieldName)) {
                $fieldMapping = $metadata->getFieldMapping($fieldName);
                $fieldType = $fieldMapping['type'] ?? 'string';
                $useSubstring = in_array($fieldType, ['datetime', 'datetimetz', 'datetime_immutable']);
            }

            if ($useSubstring) {
                // For datetime fields, extract date and compare
                $qb->andWhere('DATE(' . $column . ') >= :' . $startParam)
                    ->andWhere('DATE(' . $column . ') <= :' . $endParam)
                    ->setParameter($startParam, $rangeParams['start'])
                    ->setParameter($endParam, $rangeParams['end']);
            } else {
                // For date fields, compare directly
                $qb->andWhere($column . ' >= :' . $startParam)
                    ->andWhere($column . ' <= :' . $endParam)
                    ->setParameter($startParam, $rangeParams['start'])
                    ->setParameter($endParam, $rangeParams['end']);
            }
        } else {
            // Absolute date
            $dateValue = $this->convertRelativeDateToActual($value);
            $qb->andWhere($column . ' = :' . $parameterName)
                ->setParameter($parameterName, $dateValue);
        }
    }

    /**
     * Check if value is a relative date interval (like -P30D, +P1M)
     */
    private function isRelativeInterval(string $value): bool
    {
        return preg_match('/^[+-]?P\d+[DWMY]$/i', $value) === 1;
    }

    /**
     * Calculate date range for relative interval
     */
    private function calculateDateRange(string $interval): array
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));
        $start = clone $now;
        $end = clone $now;

        // Parse interval (e.g., "-P30D", "+P1M")
        $sign = $interval[0] === '-' ? '-' : '+';
        $intervalString = ltrim($interval, '+-');

        if ($sign === '-') {
            // Looking back: start is (now - interval), end is now
            $start->sub(new \DateInterval($intervalString));
        } else {
            // Looking forward: start is now, end is (now + interval)
            $end->add(new \DateInterval($intervalString));
        }

        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];
    }

    /**
     * Convert relative date keywords to actual dates
     */
    private function convertRelativeDateToActual(string $value): string
    {
        $now = new \DateTime('now', new \DateTimeZone('UTC'));

        switch (strtolower($value)) {
            case 'today':
                return $now->format('Y-m-d');
            case 'yesterday':
                $now->modify('-1 day');
                return $now->format('Y-m-d');
            case 'tomorrow':
                $now->modify('+1 day');
                return $now->format('Y-m-d');
            case 'anniversary':
                return $now->format('Y-m-d');
            default:
                // If it's an interval like "-P30D", calculate it
                if ($this->isRelativeInterval($value)) {
                    $sign = $value[0] === '-' ? '-' : '+';
                    $intervalString = ltrim($value, '+-');

                    if ($sign === '-') {
                        $now->sub(new \DateInterval($intervalString));
                    } else {
                        $now->add(new \DateInterval($intervalString));
                    }

                    return $now->format('Y-m-d');
                }

                // Otherwise, assume it's already a valid date string
                return $value;
        }
    }

    /**
     * Convert human-readable labels to internal keys for select fields
     *
     * Examples:
     * - activityStatusType: "Active" -> "active"
     * - eventRoundC: "1st Round" -> "1st"
     */
    private function convertLabelToKeyForSelectFields(string $field, $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Map of field names to their label-key mappings
        $selectFieldMappings = [
            'activityStatusType' => [
                'Active' => 'active',
                'Planned' => 'planned',
                'Held' => 'held',
                'Not Held' => 'notheld',
            ],
            'eventRoundC' => [
                '1st Round' => '1st',
                '2nd Round' => '2nd',
                '3rd Round' => '3rd',
                'Final' => 'final',
            ],
        ];

        // If this field has mappings and the value matches a label, convert it
        if (isset($selectFieldMappings[$field][$value])) {
            return $selectFieldMappings[$field][$value];
        }

        return $value;
    }
}
