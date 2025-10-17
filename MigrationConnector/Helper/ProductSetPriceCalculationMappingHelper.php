<?php declare(strict_types=1);

namespace SwkweSetBuyMigration\MigrationConnector\Helper;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

class ProductSetPriceCalculationMappingHelper
{
    private Connection $connection;

    public function __construct(
        Connection $connection
    ) {
        $this->connection = $connection;
    }

    /**
     * @return array<string, string>
     */
    public function getDistinctPriceCalculations(): array
    {
        $priceCalculations = $this->getPriceCalculations(null);

        return array_combine(
            array_column($priceCalculations, 'hash'),
            array_column($priceCalculations, 'label'),
        );
    }

    /**
     * @param int[] $optionIds
     *
     * @return array<int, string>
     */
    public function getOptionPriceCalculationHashes(array $optionIds): array
    {
        $priceCalculations = $this->getPriceCalculations($optionIds);

        return array_combine(
            array_keys($priceCalculations),
            array_column($priceCalculations, 'hash'),
        );
    }

    /**
     * @param int[]|null $optionIds
     *
     * @return array<int, array{hash: string, label: string}>
     */
    private function getPriceCalculations(?array $optionIds): array
    {
        $priceCalculations = [];

        foreach ($this->fetchPriceCalculations($optionIds) as $optionId => $optionPriceCalculations) {
            ksort($optionPriceCalculations);

            $hash = md5(json_encode($optionPriceCalculations, \JSON_THROW_ON_ERROR));

            $label = '';

            if (array_key_first($optionPriceCalculations) === 0) {
                $label .= '"' . array_shift($optionPriceCalculations) . '"';
            }

            if (count($optionPriceCalculations) > 0) {
                $segments = array_map(
                    function (int $languageId, string $priceCalculation): string {
                        return  "{$languageId}: \"{$priceCalculation}\"";
                    },
                    array_keys($optionPriceCalculations),
                    array_values($optionPriceCalculations),
                );

                $label .= ($label === '' ? '' : ' ') . '(' . implode(', ', $segments) . ')';
            }

            $priceCalculations[$optionId] = [
                'hash' => $hash,
                'label' => $label,
            ];
        }

        return $priceCalculations;
    }

    /**
     * @param int[]|null $optionIds
     *
     * @return array<int, array<int, string>>
     */
    private function fetchPriceCalculations(?array $optionIds): array
    {
        $priceCalculations = [];
        $result = $this->getQueryBuilder($optionIds)->execute();

        foreach ($result->iterateAssociativeIndexed() as $optionId => $row) {
            assert(is_string($optionId));

            $optionId = (int) $optionId;
            $optionPriceCalculations = $priceCalculations[$optionId] ?? [];

            $priceCalculation = $row['priceCalculation'] ?? null;
            $languageId = $row['languageId'] ?? null;
            $serializedObjectData = $row['objectData'] ?? null;

            if (is_string($priceCalculation) && $priceCalculation !== '') {
                $optionPriceCalculations[0] = trim($priceCalculation);
            }

            if (is_string($serializedObjectData) && is_string($languageId)) {
                try {
                    $objectData = unserialize($serializedObjectData, ['allowed_classes' => false]);
                } catch (\Throwable $e) {
                    $objectData = null;
                }

                if (is_array($objectData)) {
                    $translatedPriceCalculation = $objectData['priceCalculation'] ?? null;

                    if (is_string($translatedPriceCalculation) && $translatedPriceCalculation !== '') {
                        $optionPriceCalculations[(int) $languageId] = trim($translatedPriceCalculation);
                    }
                }
            }

            if (count($optionPriceCalculations) > 0) {
                $priceCalculations[$optionId] = $optionPriceCalculations;
            }
        }

        return $priceCalculations;
    }

    /**
     * @param int[]|null $optionIds
     */
    private function getQueryBuilder(?array $optionIds): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'setOption.id AS id',
                'setOptionTranslation.objectlanguage AS languageId',
                'setOption.price_calculation AS priceCalculation',
                'setOptionTranslation.objectdata AS objectData',
            )
            ->from('swkwe_set_buy_article_set_option', 'setOption')
            ->leftJoin(
                'setOption',
                's_core_translations',
                'setOptionTranslation',
                (string) $qb->expr()->and(
                    $qb->expr()->eq('setOptionTranslation.objectkey', 'setOption.id'),
                    $qb->expr()->eq(
                        'setOptionTranslation.objecttype',
                        $qb->createPositionalParameter('swkweSetBuyOption'),
                    ),
                ),
            )
            ->where($qb->expr()->or(
                $qb->expr()->isNotNull('setOption.price_calculation'),
                $qb->expr()->isNotNull('setOptionTranslation.objectdata'),
            ))
        ;

        if ($optionIds !== null) {
            $qb->andWhere($qb->expr()->in(
                'setOption.id',
                $qb->createPositionalParameter($optionIds, Connection::PARAM_INT_ARRAY),
            ));
        }

        return $qb;
    }
}
