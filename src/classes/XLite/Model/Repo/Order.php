<?php
// vim: set ts=4 sw=4 sts=4 et:

/**
 * Copyright (c) 2011-present Qualiteam software Ltd. All rights reserved.
 * See https://www.x-cart.com/license-agreement.html for license details.
 */

namespace XLite\Model\Repo;

/**
 * Order repository
 *
 * @Api\Operation\Create(modelClass="XLite\Model\Order", summary="Add new order")
 * @Api\Operation\Read(modelClass="XLite\Model\Order", summary="Retrieve order by id")
 * @Api\Operation\ReadAll(modelClass="XLite\Model\Order", summary="Retrieve orders by conditions")
 * @Api\Operation\Update(modelClass="XLite\Model\Order", summary="Update order by id")
 * @Api\Operation\Delete(modelClass="XLite\Model\Order", summary="Delete order by id")
 */
class Order extends \XLite\Model\Repo\ARepo
{
    /**
     * Additional search mode
     */
    const SEARCH_MODE_TOTALS  = 'totals';

    /**
     * Cart TTL (in seconds)
     */
    const ORDER_TTL = 86400;

    /**
     * In progress orders TTL (in seconds)
     */
    const IN_PROGRESS_ORDER_TTL = 3600;

    /**
     * Allowable search params
     */
    const P_ORDER_ID          = 'orderId';
    const P_PROFILE_ID        = 'profileId';
    const P_PROFILE           = 'profile';
    const P_EMAIL             = 'email';
    const P_PAYMENT_STATUS    = 'paymentStatus';
    const P_SHIPPING_STATUS   = 'shippingStatus';
    const P_DATE              = 'date';
    const P_CURRENCY          = 'currency';
    
    const P_ORDER_NUMBER      = 'orderNumber';
    const P_SHIPPING_METHOD_NAME = 'shippingMethodName';
    const P_PAYMENT_METHOD_NAME  = 'paymentMethodName';
    const P_RECENT            = 'recent';
    const SEARCH_DATE_RANGE   = 'dateRange';
    const SEARCH_SUBSTRING    = 'substring';
    const SEARCH_ACCESS_LEVEL = 'accessLevel';
    const SEARCH_ZIPCODE      = 'zipcode';
    const SEARCH_CUSTOMER_NAME = 'customerName';
    const SEARCH_TRANS_ID     = 'transactionID';
    const SEARCH_SKU          = 'sku';

    /**
     * Alternative record identifiers
     *
     * @var array
     */
    protected $alternativeIdentifier = array(
        array('orderNumber'),
    );

    /**
     * Get condition to search recent orders
     *
     * @return \XLite\Core\CommonCell
     */
    public function getRecentOrdersCondition()
    {
        $cnd = new \XLite\Core\CommonCell();
        $cnd->{\XLite\Model\Repo\Order::P_RECENT} = 1;

        return $cnd;
    }

    /**
     * Search for recent orders and return its number or a list
     *
     * @param \XLite\Core\CommonCell $cnd   Search condition
     * @param boolean                $count Flag: return number of recent orders
     *
     * @return integer|\Doctrine\Common\Collections\ArrayCollection
     */
    public function searchRecentOrders($cnd = null, $count = false)
    {
        if (!$cnd) {
            $cnd = $this->getRecentOrdersCondition();
        }

        return $this->search($cnd, $count);
    }

    /**
     * Find all expired temporary orders
     *
     * @return null | \Iterator
     */
    public function findAllExpiredTemporaryOrders()
    {
        return $this->getOrderTTL()
            ? $this->defineAllExpiredTemporaryOrdersQuery()->iterate()
            : null;
    }

    /**
     * Get orders statistics data: count and sum of orders
     *
     * @param integer $startDate Start date timestamp
     * @param integer $endDate   End date timestamp OPTIONAL
     *
     * @return array
     */
    public function getOrderStats($startDate, $endDate = 0)
    {
        $result = $this->defineGetOrderStatsQuery($startDate, $endDate)->getSingleResult();

        return $result;
    }

    /**
     * Get first order date
     *
     * @return integer
     */
    public function getFistOpenOrderDate()
    {
        $result = $this->defineGetFistOpenOrderDateQuery()->getSingleScalarResult();

        return $result;
    }

    /**
     * Create a new QueryBuilder instance that is prepopulated for this entity name
     *
     * @param string  $alias      Table alias OPTIONAL
     * @param string  $indexBy    The index for the from. OPTIONAL
     * @param boolean $placedOnly Use only orders or orders + carts OPTIONAL
     *
     * @return \XLite\Model\QueryBuilder\AQueryBuilder
     */
    public function createQueryBuilder($alias = null, $indexBy = null, $placedOnly = true)
    {
        $result = parent::createQueryBuilder($alias, $indexBy);

        if ($placedOnly) {
            $result->andWhere($result->getMainAlias() . ' INSTANCE OF XLite\Model\Order');
        }

        return $result;
    }

    /**
     * Create a new QueryBuilder instance that is prepopulated for this entity name
     * NOTE: without any relative subqueries!
     *
     * @param string  $alias      Table alias OPTIONAL
     * @param boolean $placedOnly Use only orders or orders + carts OPTIONAL
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function createPureQueryBuilder($alias = null, $placedOnly = true)
    {
        $result = parent::createPureQueryBuilder($alias);

        if ($placedOnly) {
            $result->andWhere('o INSTANCE OF XLite\Model\Order');
        }

        return $result;
    }

    /**
     * Orders collect garbage
     *
     * @return void
     */
    public function collectGarbage()
    {
        // Remove old temporary orders
        $result = $this->findAllExpiredTemporaryOrders();
        if ($result) {
            $count = 0;

            foreach ($result as $item) {
                $order = $item[0];

                \XLite\Core\Database::getEM()->remove($order);
                $count++;

                if (!($count % 100)) {
                    \XLite\Core\Database::getEM()->flush();
                }
            }

            if ($count > 0) {
                \XLite\Core\Database::getEM()->flush();

                // Log operation only in debug mode
                \XLite\Logger::getInstance()->log(
                    \XLite\Core\Translation::getInstance()->translate(
                        'X expired shopping cart(s) have been successfully removed',
                        array('count' => $count)
                    )
                );
            }
        }
    }

    /**
     * Correct search conditions
     *
     * @param \XLite\Core\CommonCell $cnd Search conditions
     *
     * @return \XLite\Core\CommonCell
     */
    public function correctSearchConditions($cnd)
    {
        if (
            isset($cnd->{static::P_PROFILE_ID})
            && is_numeric($cnd->{static::P_PROFILE_ID})
        ) {
            unset($cnd->{static::SEARCH_CUSTOMER_NAME});

        } else {
            unset($cnd->{static::P_PROFILE_ID});
        }

        return $cnd;
    }

    // {{{ Search totals

    /**
     * Get search modes handlers
     *
     * @return array
     */
    protected function getSearchModes()
    {
        return array_merge(
            parent::getSearchModes(),
            array(
                static::SEARCH_MODE_TOTALS     => 'searchTotals',
            )
        );
    }

    /**
     * Search result routine.
     *
     * @return \Doctrine\ORM\PersistentCollection|integer
     */
    protected function searchTotals()
    {
        $queryBuilder = $this->searchState['queryBuilder'];

        return $queryBuilder->getResult();
    }

    /**
     * Prepare conditions for search
     *
     * @return void
     */
    protected function processConditions()
    {
        $this->searchState['currentSearchCnd'] = $this->correctSearchConditions(
            $this->searchState['currentSearchCnd']
        );

        parent::processConditions();
    }

    /**
     * Common search
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function getQueryBuilderForSearch()
    {
        $queryBuilder = parent::getQueryBuilderForSearch();

        if ($this->searchState['searchMode'] === static::SEARCH_MODE_TOTALS) {
            $queryBuilder = $queryBuilder
                ->select('SUM(o.total) as orders_total')
                ->addSelect('c.currency_id as currency_id')
                ->linkInner('o.profile', 'p')
                ->linkInner('o.currency', 'c')
                ->linkLeft('o.orig_profile', 'op')
                ->addGroupBy('c.currency_id')
                ->addOrderBy('orders_total', 'DESC');
        }

        return $queryBuilder;
    }

    /**
     * Returns search totals
     * N.B. Left for backward compatibility
     *
     * @param \XLite\Core\CommonCell $cnd Search condition
     *
     * @return array
     */
    public function getSearchTotal(\XLite\Core\CommonCell $cnd)
    {
        return $this->search($cnd, static::SEARCH_MODE_TOTALS);
    }

    /**
     * Create a QueryBuilder instance for getSearchTotals()
     * N.B. Left for backward compatibility
     *
     * @param \XLite\Core\CommonCell $cnd Search condition
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineGetSearchTotalQuery(\XLite\Core\CommonCell $cnd)
    {
        $this->searchState['queryBuilder'] = $this->createQueryBuilder()
            ->select('SUM(o.total) as orders_total')
            ->addSelect('c.currency_id as currency_id')
            ->linkInner('o.profile', 'p')
            ->linkInner('o.currency', 'c')
            ->linkLeft('o.orig_profile', 'op')
            ->addGroupBy('c.currency_id')
            ->addOrderBy('orders_total', 'DESC');

        foreach ($cnd as $key => $value) {
            if (self::P_LIMIT !== $key && self::P_ORDER_BY !== $key) {
                $this->callSearchConditionHandler($value, $key);
            }
        }

        return $this->searchState['queryBuilder'];
    }

    // }}}

    /**
     * Next order number is initialized with the maximum order number
     *
     * @return void
     */
    public function initializeNextOrderNumber()
    {
        \XLite\Core\Database::getRepo('XLite\Model\Config')->createOption(
            array(
                'category'  => 'General',
                'name'      => 'order_number_counter',
                'value'     => $this->getMaxOrderNumber() + 1,
            )
        );
    }

    /**
     * The maximum order number
     *
     * @return integer
     */
    public function getMaxOrderNumber()
    {
        $result = $this->defineMaxOrderNumberQuery()->getSingleResult();

        return empty($result) ? 1 : array_pop($result);
    }

    /**
     * The next order number is used only for orders.
     * This generator checks the  field for independent ID for orders only
     *
     * @return integer
     */
    public function findNextOrderNumber()
    {
        if (!\XLite\Core\Config::getInstance()->General->order_number_counter) {
            $this->initializeNextOrderNumber();
        }

        $em   = \XLite\Core\Database::getEM();
        $conn = $em->getConnection();

        $conn->beginTransaction();

        try {
            $orderNumber = $em->createQueryBuilder()
                ->select(['c.config_id', 'c.value'])
                ->from('XLite\Model\Config', 'c')
                ->where('c.name = :name')
                ->andWhere('c.category = :category')
                ->setParameter('name', 'order_number_counter')
                ->setParameter('category', 'General')
                ->getQuery()
                ->setLockMode(\Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE)
                ->getSingleResult();

            $value = max($orderNumber['value'], $this->getMaxOrderNumber() + 1);

            $qb = $em->createQueryBuilder();

            $qb
                ->update('XLite\Model\Config', 'c')
                ->set('c.value', $qb->expr()->literal($value + 1))
                ->where('c.config_id = :config_id')
                ->setParameter('config_id', $orderNumber['config_id'])
                ->getQuery()
                ->execute();

            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollback();

            throw $e;
        }

        return $value;
    }

    /**
     * Selects the last maximum order number field.
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function defineMaxOrderNumberQuery()
    {
        $qb = $this->createQueryBuilder('o', null, true)
            ->select('o.orderNumber')
            ->andWhere('o.orderNumber IS NOT NULL')
            ->setMaxResults(1);

        $this->prepareCndOrderBy($qb, ['o.orderNumber', 'DESC']);

        return $qb;
    }

    /**
     * @param \XLite\Model\Order $order
     *
     * @return \XLite\Model\AEntity|\XLite\Model\Order|null
     */
    public function findNextOrder($order)
    {
        return $this->defineFindNextOrder($order)->getSingleResult();
    }

    /**
     * @param \XLite\Model\Order $order
     *
     * @return \Doctrine\ORM\QueryBuilder|\XLite\Model\QueryBuilder\AQueryBuilder
     */
    protected function defineFindNextOrder($order)
    {
        return $this->createQueryBuilder()
            ->andWhere('o.order_id > :orderId')
            ->andWhere('o.orderNumber IS NOT NULL')
            ->setParameter('orderId', (int) $order->getOrderId())
            ->orderBy('o.order_id', 'ASC');
    }

    /**
     * @param \XLite\Model\Order $order
     *
     * @return \XLite\Model\AEntity|\XLite\Model\Order|null
     */
    public function findPreviousOrder($order)
    {
        return $this->defineFindPreviousOrder($order)->getSingleResult();
    }

    /**
     * @param \XLite\Model\Order $order
     *
     * @return \Doctrine\ORM\QueryBuilder|\XLite\Model\QueryBuilder\AQueryBuilder
     */
    protected function defineFindPreviousOrder($order)
    {
        return $this->createQueryBuilder()
            ->andWhere('o.order_id < :orderId')
            ->andWhere('o.orderNumber IS NOT NULL')
            ->setParameter('orderId', (int) $order->getOrderId())
            ->orderBy('o.order_id', 'DESC');
    }

    /**
     * Create a QueryBuilder instance for getOrderStats()
     *
     * @param integer $startDate Start date timestamp
     * @param integer $endDate   End date timestamp
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineGetOrderStatsQuery($startDate, $endDate)
    {
        $qb = $this->createQueryBuilder()
            ->select('COUNT(o.order_id) as orders_count')
            ->addSelect('SUM(o.total) as orders_total')
            ->andWhere('o.currency = :currency')
            ->setParameter('currency', \XLite::getInstance()->getCurrency());

        $this->prepareCndDate($qb, array($startDate, $endDate));
        $this->prepareCndPaymentStatus($qb, \XLite\Model\Order\Status\Payment::getOpenStatuses());

        return $qb;
    }

    /**
     * Create a QueryBuilder instance for getFistOpenOrderDate()
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineGetFistOpenOrderDateQuery()
    {
        $qb = $this->createQueryBuilder()
            ->select('MIN(o.date) as order_date');

        $this->prepareCndPaymentStatus($qb, \XLite\Model\Order\Status\Payment::getOpenStatuses());

        return $qb;
    }

    /**
     * Prepare certain search condition
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndOrderId(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->andWhere('o.order_id = :order_id')
                ->setParameter('order_id', $value);
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve order by its number", type="integer")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndOrderNumber(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->andWhere('o.orderNumber = :orderNumber')
                ->setParameter('orderNumber', $value);
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve order by user access level", type="string", enum={"anonymous", "registered"})
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndAccessLevel(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            switch ($value) {
                case \XLite\View\FormField\Select\Order\CustomerAccessLevel::ACCESS_LEVEL_ANONYMOUS:
                    $anonymous = 1;

                    break;

                case \XLite\View\FormField\Select\Order\CustomerAccessLevel::ACCESS_LEVEL_REGISTERED:
                    $anonymous = 0;

                    break;

                default:
                    $anonymous = '';

                    break;
            }

            if ('' !== $anonymous) {
                $cnd = new \Doctrine\ORM\Query\Expr\Orx();

                $anonymousCnd = new \Doctrine\ORM\Query\Expr\Andx();

                $anonymousCnd->add('op.profile_id IS NULL');
                $anonymousCnd->add('p.anonymous = :accessLevel');

                $cnd->add('op.anonymous = :accessLevel');
                $cnd->add($anonymousCnd);

                $queryBuilder->linkInner('o.profile', 'p')
                    ->linkLeft('o.orig_profile', 'op');

                $queryBuilder->andWhere($cnd)
                    ->setParameter('accessLevel', $anonymous);
            }

        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters order by date (timestamp) arranged in two-value array [start, end]", type="array", collectionFormat="multi", @Swg\Items(type="integer"))
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndDateRange(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if ($value && is_array($value)) {
            list($start, $end) = $value;

            if ($start) {
                $queryBuilder->andWhere('o.date >= :start')
                    ->setParameter('start', $start);
            }

            if ($end) {
                $queryBuilder->andWhere('o.date <= :end')
                    ->setParameter('end', $end);
            }
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve order by user login or order number substring", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndSubstring(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        $value = trim($value);

        if (!empty($value)) {
            $queryBuilder->linkInner('o.profile', 'p');

            $orCnd = $queryBuilder->expr()->orX();;
            $orCnd->add('p.login LIKE :substringLike');

            if (preg_match('/^\d+\s*?\-\s*?\d+$/S', $value)) {
                list($min, $max) = explode('-', $value);

                $orCnd->add('o.orderNumber BETWEEN :orderNumMin and :orderNumMax');

                $queryBuilder->setParameter('orderNumMin', (int) trim($min));
                $queryBuilder->setParameter('orderNumMax', (int) trim($max));

            } elseif (preg_match('/^\d+$/S', $value)) {
                $orCnd->add('o.orderNumber = :substring');
                $queryBuilder->setParameter('substring', (int) $value);
            }

            $queryBuilder->andWhere($orCnd)
                ->setParameter('substringLike', '%' . $value . '%');
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve order by product sku", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndSku(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        $value = trim($value);

        if (!empty($value)) {

            $multiple = array_filter(array_map('trim', explode(',', $value)), 'strlen');

            if (0 < count($multiple)) {

                $queryBuilder->linkLeft('o.items', 'oi');

                if (1 < count($multiple)) {
                    // Detectd several values separated with comma: search for exact match
                    $queryBuilder->andWhere($queryBuilder->expr()->in('oi.sku', $multiple));

                } else {
                    // Detected single SKU value
                    $queryBuilder->andWhere('oi.sku LIKE :sku')
                       ->setParameter('sku', '%' . $value . '%');
                }
            }
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve only recent (unprocessed) orders", type="boolean")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndRecent(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if ($value) {
            $queryBuilder->andWhere('o.recent = :recent')
                ->setParameter('recent', true);
        }
    }

    /**
     * Prepare certain search condition
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param \XLite\Model\Profile       $value        Profile
     *
     * @return void
     */
    protected function prepareCndProfile(\Doctrine\ORM\QueryBuilder $queryBuilder, \XLite\Model\Profile $value)
    {
        if (!empty($value)) {
            $queryBuilder->linkInner('o.profile', 'p')
                ->linkLeft('o.orig_profile', 'op');

            $queryBuilder->andWhere('op.profile_id = :opid')
                ->setParameter('opid', $value->getProfileId());
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve orders by profile id", type="integer")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndProfileId(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $profile = \XLite\Core\Database::getRepo('XLite\Model\Profile')->find($value);
            $queryBuilder->andWhere('o.orig_profile = :orig_profile')
                ->setParameter('orig_profile', $profile);
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Retrieve orders by user login", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndEmail(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->linkInner('o.profile', 'p')
                ->linkLeft('o.orig_profile', 'op');

            $queryBuilder->andWhere('p.login LIKE :email')
                ->setParameter('email', '%' . $value . '%');
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(
     *     description="Retrieve orders in certain payment status",
     *     type="string", enum={"A", "PP", "P", "D", "C", "Q", "R"})
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndPaymentStatus(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        $this->prepareStatusCnd($queryBuilder, $value, 'paymentStatus');
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(
     *     description="Retrieve orders in certain shipping status",
     *     type="string", enum={"N", "P", "S", "D", "WND", "R", "WFA"})
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndShippingStatus(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        $this->prepareStatusCnd($queryBuilder, $value, 'shippingStatus');
    }

    /**
     * Prepare certain search condition
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param mixed                      $value        Condition data
     * @param string                     $status       Order status
     *
     * @return void
     */
    protected function prepareStatusCnd(\Doctrine\ORM\QueryBuilder $queryBuilder, $value, $status)
    {
        if (!empty($value)) {
            $alias = $status . 'Alias';

            if (is_array($value)) {
                if (1 !== count($value)
                    || !isset($value[0])
                    || '' !== $value[0]
                ) {
                    $field = 'id';
                    foreach ($value as $val) {
                        if (!is_numeric($val)) {
                            $field = 'code';
                            break;
                        }
                    }
                    $queryBuilder->innerJoin('o.' . $status, $alias)
                        ->andWhere($queryBuilder->expr()->in($alias . '.' . $field, $value));
                }

            } elseif (is_object($value)) {
                $queryBuilder->andWhere('o.' . $status . ' = :' . $status)
                    ->setParameter($status, $value);

            } elseif (is_int($value)
                || (is_string($value)
                    && preg_match('/^[\d]+$/', $value)
                )
            ) {
                $queryBuilder->innerJoin('o.' . $status, $alias)
                    ->andWhere($alias . '.id = :' . $status)
                    ->setParameter($status, $value);

            } elseif (is_string($value)) {
                $queryBuilder->innerJoin('o.' . $status, $alias)
                    ->andWhere($alias . '.code = :' . $status)
                    ->setParameter($status, $value);
            }
        }
    }

    /**
     * Prepare certain search condition
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param array                      $value        Condition data OPTIONAL
     *
     * @return void
     */
    protected function prepareCndDate(\Doctrine\ORM\QueryBuilder $queryBuilder, $value = null)
    {
        if (is_array($value) && !empty($value)) {
            $value = array_values($value);
            $start = empty($value[0]) ? null : (int) $value[0];
            $end = empty($value[1]) ? null : (int) $value[1];

            if ($start) {
                $queryBuilder->andWhere('o.date >= :start')
                    ->setParameter('start', $start);
            }

            if ($end) {
                $queryBuilder->andWhere('o.date <= :end')
                    ->setParameter('end', $end);
            }
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters order by currency id", type="integer")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data OPTIONAL
     *
     * @return void
     */
    protected function prepareCndCurrency(\Doctrine\ORM\QueryBuilder $queryBuilder, $value = null)
    {
        if ($value) {
            $queryBuilder->innerJoin('o.currency', 'currency', 'WITH', 'currency.currency_id = :currency_id')
                ->setParameter('currency_id', $value);
        }
    }

    /**
     * Return cart TTL
     *
     * @return integer
     */
    protected function getOrderTTL()
    {
        return ((int) \XLite\Core\Config::getInstance()->General->cart_ttl) * 86400;
    }

    /**
     * Define query for findAllExpiredTemporaryOrders() method
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineAllExpiredTemporaryOrdersQuery()
    {
        return $this->createQueryBuilder(null, null, false)
            ->leftJoin('o.orig_profile', 'op')
            ->andWhere('o INSTANCE OF XLite\Model\Cart')
            ->andWhere('op.profile_id IS NULL')
            ->andWhere('o.date < :time')
            ->setParameter('time', \XLite\Core\Converter::time() - $this->getOrderTTL());
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters order by payment transaction public_id", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndTransactionID(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->linkLeft('o.payment_transactions', 'payment_transactions')
                ->andWhere('payment_transactions.public_id LIKE :transactionID')
                ->setParameter('transactionID', '%' . $value . '%');
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters orders by user zipcode", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndZipcode(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->linkInner('o.profile', 'p')
                ->linkLeft('o.orig_profile', 'op');

            $queryBuilder->linkLeft('p.addresses', 'addresses');

            $this->prepareOrderByAddressField($queryBuilder, 'zipcode');

            $queryBuilder->andWhere('address_field_value_zipcode.value LIKE :zipcodeValue')
                ->setParameter('zipcodeValue', '%' . $value . '%');
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters orders by customer name", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param integer                    $value        Condition data
     *
     * @return void
     */
    protected function prepareCndCustomerName(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if (!empty($value)) {
            $queryBuilder->linkInner('o.profile', 'p')
                ->linkLeft('o.orig_profile', 'op');

            $queryBuilder
                ->andWhere('p.searchFakeField LIKE :customerName')
                ->setParameter('customerName', '%' . $value . '%');
        }
    }

    /**
     * Generate fullname by firstname and lastname values
     * @Api\Condition(description="Filters orders by customer firstname-lastname pair", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder QueryBuilder instance
     *
     * @return void
     */
    protected function prepareCndOrderByFullname(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $queryBuilder->linkInner('o.profile', 'p')
            ->linkLeft('o.orig_profile', 'op');
        $queryBuilder->linkLeft('p.addresses', 'addresses');

        $this->prepareOrderByAddressField($queryBuilder, 'firstname');
        $this->prepareOrderByAddressField($queryBuilder, 'lastname');

        $queryBuilder->addSelect(
            'CONCAT(CONCAT(address_field_value_firstname.value, \' \'),
            address_field_value_lastname.value) as fullname'
        );
    }

    /**
     * Prepare fields for fullname value (for 'order by')
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder object
     * @param string                     $fieldName    Field name
     *
     * @return void
     */
    protected function prepareOrderByAddressField(\Doctrine\ORM\QueryBuilder $queryBuilder, $fieldName)
    {
        $addressFieldName = 'address_field_value_' . $fieldName;

        $addressField = \XLite\Core\Database::getRepo('XLite\Model\AddressField')
            ->findOneBy(array('serviceName' => $fieldName));

        $queryBuilder->linkLeft(
            'addresses.addressFields',
            $addressFieldName,
            \Doctrine\ORM\Query\Expr\Join::WITH,
            $addressFieldName . '.addressField = :' . $fieldName
        )->setParameter($fieldName, $addressField);
    }

    /**
     * Prepare certain search condition
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param array                      $value        Condition data
     *
     * @return void
     */
    protected function prepareCndOrderBy(\Doctrine\ORM\QueryBuilder $queryBuilder, array $value)
    {
        list($sort, $order) = $this->getSortOrderValue($value);
        if (!is_array($sort)) {
            $sort = array($sort);
            $order = array($order);
        }
        $queryBuilder->addSelect('INTVAL(o.orderNumber) AS HIDDEN int_order_number');

        foreach ($sort as $key => $sortItem) {
            if (\XLite\View\ItemsList\Model\Order\Admin\Search::SORT_BY_MODE_ID === $sortItem) {
                $sortItem = 'int_order_number';

            } elseif (\XLite\View\ItemsList\Model\Order\Admin\Search::SORT_BY_MODE_CUSTOMER === $sortItem) {
                $this->prepareCndOrderByFullname($queryBuilder);
                $queryBuilder->addOrderBy('fullname', $order[$key]);
            }

            $queryBuilder->addOrderBy($sortItem, $order[$key]);
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters orders by shipping method name", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndShippingMethodName(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if ($value) {
            $queryBuilder->andWhere('o.shipping_method_name = :shippingMethodName')
                ->setParameter('shippingMethodName', $value);
        }
    }

    /**
     * Prepare certain search condition
     * @Api\Condition(description="Filters orders by payment method name", type="string")
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder Query builder to prepare
     * @param string                     $value        Condition data
     *
     * @return void
     */
    protected function prepareCndPaymentMethodName(\Doctrine\ORM\QueryBuilder $queryBuilder, $value)
    {
        if ($value) {
            $queryBuilder->andWhere('o.payment_method_name = :paymentMethodName')
                ->setParameter('paymentMethodName', $value);
        }
    }

    // {{{ Export routines

    /**
     * Define export iterator query builder
     *
     * @param integer $position Position
     *
     * @return \XLite\Model\QueryBuilder\AQueryBuilder
     */
    protected function defineExportIteratorQueryBuilder($position)
    {
        return parent::defineExportIteratorQueryBuilder($position)
            ->orderBy('o.date', 'desc');
    }

    // }}}

    // {{{ Mark order as cart

    /**
     * Mark order as cart
     *
     * @param integer $orderId Order id
     *
     * @return boolean
     */
    public function markAsCart($orderId)
    {
        $stmt = $this->defineMarkAsCartQuery($orderId);

        return $stmt && $stmt->execute() && 0 < $stmt->rowCount();
    }

    /**
     * Define query for markAsCart() method
     *
     * @param integer $orderId Order id
     *
     * @return \Doctrine\DBAL\Statement|void
     */
    protected function defineMarkAsCartQuery($orderId)
    {
        $stmt = $this->_em->getConnection()->prepare(
            'UPDATE ' . $this->_class->getTableName() . ' '
            . 'SET is_order = :flag '
            . 'WHERE order_id = :id'
        );

        if ($stmt) {
            $stmt->bindValue(':flag', 0);
            $stmt->bindValue(':id', $orderId);

        } else {
            $stmt = null;
        }

        return $stmt;
    }

    // }}}

    // {{{ Statistic

    /**
     * Returns count statistics
     *
     * @param \XLite\Core\CommonCell $condition Condition
     *
     * @return mixed
     */
    public function getStatisticCount($condition)
    {
        return $this->defineStatisticCountQuery($condition)->getResult();
    }

    /**
     * Returns total statistics
     *
     * @param \XLite\Core\CommonCell $condition Condition
     *
     * @return mixed
     */
    public function getStatisticTotal($condition)
    {
        return $this->defineStatisticTotalQuery($condition)->getResult();
    }

    /**
     * Returns query builder for count statistics
     *
     * @param \XLite\Core\CommonCell $condition Condition
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineStatisticCountQuery($condition)
    {
        $queryBuilder = $this->createQueryBuilder();

        $queryBuilder->select('COUNT(o)')
            ->linkInner('o.paymentStatus', 'ps')
            ->addSelect('ps.code')
            ->groupBy('o.paymentStatus');

        if ($condition->currency) {
            $queryBuilder->innerJoin('o.currency', 'currency', 'WITH', 'currency.currency_id = :currency_id')
                ->setParameter('currency_id', $condition->currency);
        }

        if ($condition->date) {
            $this->prepareCndDate($queryBuilder, $condition->date);
        }

        return $queryBuilder;
    }

    /**
     * Returns query builder for total statistics
     *
     * @param \XLite\Core\CommonCell $condition Condition
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    protected function defineStatisticTotalQuery($condition)
    {
        $queryBuilder = $this->createQueryBuilder();

        $queryBuilder->select('SUM(o.total)')
            ->linkInner('o.paymentStatus', 'ps')
            ->addSelect('ps.code')
            ->groupBy('o.paymentStatus');

        if ($condition->currency) {
            $queryBuilder->andWhere('o.currency = :currency_id')
                ->setParameter('currency_id', $condition->currency);
        }

        if ($condition->date) {
            $this->prepareCndDate($queryBuilder, $condition->date);
        }

        return $queryBuilder;
    }

    // }}}

    /**
     * Assemble regular fields from record
     *
     * @param array $record  Record
     * @param array $regular Regular fields info OPTIONAL
     *
     * @return array
     */
    protected function assembleRegularFieldsFromRecord(array $record, array $regular = array())
    {
        $shippingMethod = null;

        if (isset($record['shipping']['processor'])
            && isset($record['shipping']['code'])
        ) {
            $shippingMethod = \XLite\Core\Database::getRepo('XLite\Model\Shipping\Method')->findOneBy(
                array(
                    'processor' => $record['shipping']['processor'],
                    'code'      => $record['shipping']['code']
                )
            );
        }

        if ($shippingMethod) {
            $record['shipping_id']              = $shippingMethod->getMethodId();
            $record['shipping_method_name']     = $shippingMethod->getName();
        }
        if (isset($record['shipping'])) {
            unset($record['shipping']);
        }

        return parent::assembleRegularFieldsFromRecord($record, $regular);
    }
}
