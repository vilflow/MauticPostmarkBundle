<?php

namespace MauticPlugin\MauticPostmarkBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use MauticPlugin\MauticNotesBundle\Entity\Note;
use MauticPlugin\MauticNotesBundle\Entity\NoteRepository;
use MauticPlugin\MauticPostmarkBundle\DTO\EntityFilterSpec;

/**
 * Builds query criteria for Note filtering based on EntityFilterSpec.
 * Uses the same logic as NoteRepository for consistency.
 */
class NoteCriteriaBuilder
{
    private EntityManagerInterface $em;
    private ?NoteRepository $repository = null;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Lazy-load the repository to avoid EntityManager being null during service initialization
     */
    private function getRepository(): NoteRepository
    {
        if (null === $this->repository) {
            $this->repository = $this->em->getRepository(Note::class);
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
        if ($spec->getType() !== 'note') {
            throw new \InvalidArgumentException('Spec type must be "note"');
        }

        $qb = $this->getRepository()->createQueryBuilder('n')
            ->where('n.deleted = :deleted')
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
     * Find matching Note IDs for a specific contact
     *
     * @param int $contactId Contact ID
     * @param EntityFilterSpec $spec Filter specification
     * @param int|null $limit Optional limit
     * @return int[] Array of Note IDs
     */
    public function findMatchingIdsForContact(int $contactId, EntityFilterSpec $spec, ?int $limit = null): array
    {
        $qb = $this->fromSpec($spec);
        $qb->select('n.id')
            ->andWhere('n.contact = :contactId')
            ->setParameter('contactId', $contactId);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        $results = $qb->getQuery()->getScalarResult();

        return array_column($results, 'id');
    }

    /**
     * Count matching Notes for a specific contact
     *
     * @param int $contactId Contact ID
     * @param EntityFilterSpec $spec Filter specification
     * @return int Count of matching notes
     */
    public function countMatchingForContact(int $contactId, EntityFilterSpec $spec): int
    {
        $qb = $this->fromSpec($spec);
        $qb->select('COUNT(n.id)')
            ->andWhere('n.contact = :contactId')
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
        $column = 'n.' . $field;
        $parameterName = 'param_' . $field . '_' . uniqid();

        $trimmedValue = is_string($value) ? trim($value) : $value;

        // Check if this is a boolean field
        $isBooleanField = in_array($field, ['deleted', 'embedFlag', 'newsletterFormC', 'popupFormC', 'portalFlag', 'visaFormC'], true);
        if ($isBooleanField && in_array($trimmedValue, ['0', '1', 0, 1], true)) {
            $trimmedValue = (int) $trimmedValue;
        }

        // Check if this is a date field
        $isDateField = in_array($field, ['dateEntered', 'dateModified', 'createdAt', 'updatedAt'], true);

        switch ($operator) {
            case '=':
            case 'eq':
                if ($isDateField) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) = :' . $parameterName);
                    $qb->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere($column . ' = :' . $parameterName);
                    $qb->setParameter($parameterName, $trimmedValue);
                }
                break;
            case '!=':
            case 'neq':
                if ($isDateField) {
                    $qb->andWhere('(SUBSTRING(' . $column . ', 1, 10) != :' . $parameterName . ' OR ' . $column . ' IS NULL)');
                    $qb->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere('(' . $column . ' != :' . $parameterName . ' OR ' . $column . ' IS NULL)');
                    $qb->setParameter($parameterName, $trimmedValue);
                }
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
                if ($isDateField) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) > :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere($column . ' > :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                }
                break;
            case 'gte':
                if ($isDateField) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) >= :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere($column . ' >= :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                }
                break;
            case 'lt':
                if ($isDateField) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) < :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere($column . ' < :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                }
                break;
            case 'lte':
                if ($isDateField) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) <= :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                } else {
                    $qb->andWhere($column . ' <= :' . $parameterName)
                        ->setParameter($parameterName, $trimmedValue);
                }
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
            case 'regexp':
                $qb->andWhere($column . ' REGEXP :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
                break;
            case '!regexp':
                $qb->andWhere($column . ' NOT REGEXP :' . $parameterName)
                    ->setParameter($parameterName, $trimmedValue);
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
            $metadata = $this->em->getClassMetadata(Note::class);
            $fieldName = str_replace('n.', '', $column);

            $useSubstring = false;
            if ($metadata->hasField($fieldName)) {
                $fieldMapping = $metadata->getFieldMapping($fieldName);
                $fieldType = $fieldMapping['type'] ?? 'string';
                $useSubstring = in_array($fieldType, ['datetime', 'datetimetz', 'datetime_immutable']);
            }

            if ($useSubstring) {
                $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) BETWEEN :' . $startParam . ' AND :' . $endParam)
                    ->setParameter($startParam, $rangeParams['start'])
                    ->setParameter($endParam, $rangeParams['end']);
            } else {
                $qb->andWhere($column . ' BETWEEN :' . $startParam . ' AND :' . $endParam)
                    ->setParameter($startParam, $rangeParams['start'])
                    ->setParameter($endParam, $rangeParams['end']);
            }
        } else {
            // For absolute dates: exact match
            $dateValue = $this->convertRelativeDateToActual($value);

            $metadata = $this->em->getClassMetadata(Note::class);
            $fieldName = str_replace('n.', '', $column);

            if ($metadata->hasField($fieldName)) {
                $fieldMapping = $metadata->getFieldMapping($fieldName);
                $fieldType = $fieldMapping['type'] ?? 'string';

                if (in_array($fieldType, ['datetime', 'datetimetz', 'datetime_immutable'])) {
                    $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) = :' . $parameterName)
                        ->setParameter($parameterName, $dateValue);
                } else {
                    $qb->andWhere($column . ' = :' . $parameterName)
                        ->setParameter($parameterName, $dateValue);
                }
            } else {
                $qb->andWhere('SUBSTRING(' . $column . ', 1, 10) = :' . $parameterName)
                    ->setParameter($parameterName, $dateValue);
            }
        }
    }

    private function isRelativeInterval(string $value): bool
    {
        if (preg_match('/^([+-])(PT?)(\d+)([DIMHWY])$/i', $value, $matches)) {
            $amount = (int)$matches[3];
            if ($amount === 0) {
                return false;
            }
            if ($amount === 1 && strtoupper($matches[4]) === 'D') {
                return false;
            }
            return true;
        }
        return false;
    }

    private function calculateDateRange(string $value): array
    {
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        $today->setTime(0, 0, 0);

        if (preg_match('/^([+-])(PT?)(\d+)([DIMHWY])$/i', $value, $matches)) {
            $sign = $matches[1];
            $timePrefix = strtoupper($matches[2]);
            $amount = $matches[3];
            $unit = strtoupper($matches[4]);

            $isTimeInterval = ($timePrefix === 'PT' && in_array($unit, ['H', 'M']));

            $unitMap = [
                'D' => 'day',
                'W' => 'week',
                'M' => $isTimeInterval ? 'minute' : 'month',
                'Y' => 'year',
                'H' => 'hour',
                'I' => 'minute',
            ];

            $modifier = $amount . ' ' . ($unitMap[$unit] ?? 'day');
            if ((int)$amount !== 1) {
                $modifier .= 's';
            }

            if ($sign === '-') {
                $startDate = clone $today;
                $startDate->modify('-' . $modifier);
                $endDate = clone $today;

                return [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ];
            } else {
                $startDate = clone $today;
                $endDate = clone $today;
                $endDate->modify('+' . $modifier);

                return [
                    'start' => $startDate->format('Y-m-d'),
                    'end' => $endDate->format('Y-m-d'),
                ];
            }
        }

        return [
            'start' => $today->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
        ];
    }

    private function convertRelativeDateToActual(string $value): string
    {
        if (preg_match('/^([+-])(PT?)(\d+)([DIMHWY])$/i', $value, $matches)) {
            $sign = $matches[1];
            $timePrefix = strtoupper($matches[2]);
            $amount = $matches[3];
            $unit = strtoupper($matches[4]);

            $isTimeInterval = ($timePrefix === 'PT' && in_array($unit, ['H', 'M']));

            $unitMap = [
                'D' => 'day',
                'W' => 'week',
                'M' => $isTimeInterval ? 'minute' : 'month',
                'Y' => 'year',
                'H' => 'hour',
                'I' => 'minute',
            ];

            $modifier = $sign . $amount . ' ' . ($unitMap[$unit] ?? 'day');
            if ((int)$amount !== 1) {
                $modifier .= 's';
            }

            $date = new \DateTime('now', new \DateTimeZone('UTC'));
            $date->modify($modifier);

            return $date->format('Y-m-d');
        }

        switch (strtolower($value)) {
            case 'today':
                return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
            case 'yesterday':
                return (new \DateTime('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d');
            case 'tomorrow':
                return (new \DateTime('tomorrow', new \DateTimeZone('UTC')))->format('Y-m-d');
            case 'anniversary':
                return (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d');
            default:
                try {
                    $date = new \DateTime($value, new \DateTimeZone('UTC'));
                    return $date->format('Y-m-d');
                } catch (\Exception $e) {
                    return $value;
                }
        }
    }
}
