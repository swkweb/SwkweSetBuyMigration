<?php declare(strict_types=1);

namespace SwkweSetBuyMigration\MigrationConnector\Repository;

use Doctrine\DBAL\Query\QueryBuilder;
use SwagMigrationConnector\Repository\AbstractRepository;
use SwagMigrationConnector\Util\TotalStruct;

class ProductSetRepository extends AbstractRepository
{
    private const ENTITY = 'swkweb_product_set';

    public function getTotal(): TotalStruct
    {
        $qb = $this->getQueryBuilder();
        $qb->select('COUNT(DISTINCT product.id)');

        $total = $qb->execute()->fetchOne();
        assert(is_scalar($total));

        return new TotalStruct(self::ENTITY, (int) $total);
    }

    public function requiredForCount(array $entities): bool
    {
        return !in_array(self::ENTITY, $entities, true);
    }

    public function fetch($offset = 0, $limit = 250): array
    {
        $qb = $this->getQueryBuilder();
        $qb
            ->select(
                'product.id as productId',
                'product.name as productName',
                'productAttribute.swkwe_is_set_article as isEnabled',
            )
            ->leftJoin(
                'product',
                's_articles_attributes',
                'productAttribute',
                $qb->expr()->eq('product.main_detail_id', 'productAttribute.articledetailsID'),
            )
            ->orderBy('product.id')
            ->groupBy('product.id')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
        ;

        return $qb->execute()->fetchAllAssociative();
    }

    private function getQueryBuilder(): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->from('s_articles', 'product')
            ->innerJoin(
                'product',
                'swkwe_set_buy_article_set',
                'setSlot',
                $qb->expr()->eq('product.id', 'setSlot.articleID'),
            )
            ->where($qb->expr()->eq('setSlot.active', 1))
        ;

        return $qb;
    }
}
