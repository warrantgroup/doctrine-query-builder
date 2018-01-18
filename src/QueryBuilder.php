<?php

namespace Warrant\Doctrine\QueryBuilder;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Warrant\ApiBundle\Handler\Decorator\PaginatorDecorator;

/**
 * @author Emil Kilhage
 */
class QueryBuilder
{
    /**
     * @param EntityRepository $repo
     * @param Request $request
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function buildFromQuery(EntityRepository $repo, Request $request)
    {
        return $this->build($repo, [
            Param::LIMIT => $request->get(Param::LIMIT, 20),
            Param::OFFSET => $request->get(Param::OFFSET, 0),
            Param::ORDER_BY => $request->get(Param::ORDER_BY, null),
            Param::GROUP_BY => $request->get(Param::GROUP_BY, null),
            Param::WHERE => $request->get(Param::WHERE, null),
            Param::PARAMS => $request->get(Param::PARAMS, null),
            Param::ALIAS => $request->get(Param::ALIAS, null),
        ]);
    }

    /**
     * array $criteria, array $orderBy = null, $limit = null, $offset = null
     *
     * @param EntityRepository $repo
     * @param array $params
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function build(EntityRepository $repo, array $params, Request $request)
    {
        $alias = isset($params[Param::ALIAS]) ? $params[Param::ALIAS] : 'xxxx'.random_int(1, 200);

        $query = $repo->createQueryBuilder($alias);

        if (!empty($params[Param::SELECT])) {
            $query->select($params[Param::SELECT]);
        }

        if (!empty($params[Param::DISTINCT])) {
            $query->distinct($params[Param::DISTINCT]);
        }

        if (isset($params[Param::JOIN])) {
            $this->addJoin($query, $alias, $params);
        }

        if (!empty($params[Param::WHERE])) {
            $this->buildFilters($query, $alias, $params);
        }

        if (!empty($params[Param::LIMIT]) && $params[Param::LIMIT] != -1) {
            $query->setMaxResults($params[Param::LIMIT]);
        }

        if (!empty($params[Param::OFFSET])) {
            $query->setFirstResult($params[Param::OFFSET]);
        }

        if (!empty($params[Param::ORDER_BY])) {
            $this->buildOrderBy($query, $params[Param::ORDER_BY], $alias);
        }

        if (!empty($params[Param::GROUP_BY])) {
            $this->buildGroupBy($query, $params[Param::GROUP_BY], $alias);
        }

        if (!empty($params[Param::PARAMS])) {
            $this->setParams($query, $params);
        }

        $paginator = new Paginator($query);
        $paginator->setUseOutputWalkers(false);

        $paginatorParams = array(
            'pageSize' => $request->get('pageSize') ?? 50,
            'currentPage' => $request->get('page') ?? 0,
            'totalPages' => 0,
            'count' => 0
        );

        $paginatorParams['count'] = count($paginator);
        $paginatorParams['totalPages'] = ceil($paginatorParams['count']/$paginatorParams['pageSize']);

        $paginator->getQuery()
            ->setFirstResult($paginatorParams['pageSize'] * ($paginatorParams['currentPage'] - 1))
            ->setMaxResults($paginatorParams['pageSize']);

        $paginatorDecorator = new PaginatorDecorator($paginator);

        return $paginatorDecorator->paginate();
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $query
     * @param string|array $groupBy
     * @param string $alias
     */
    private function buildGroupBy(\Doctrine\ORM\QueryBuilder $query, $groupBy, $alias)
    {
        if (!empty($groupBy)) {
            if (is_string($groupBy)) {
                if (false === strpos($groupBy, '.')) {
                    $groupBy = "$alias.$groupBy";
                }

                $query->addGroupBy($groupBy);
            } elseif (is_array($groupBy)) {
                foreach ($groupBy as $column) {
                    if (false === strpos($column, '.')) {
                        $column = "$alias.$column";
                    }

                    $query->addGroupBy($column);
                }
            }
        }
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $query
     * @param string|array $orderBy
     */
    private function buildOrderBy(\Doctrine\ORM\QueryBuilder $query, $orderBy, $alias)
    {
        if (!empty($orderBy)) {
            if (is_string($orderBy)) {
                if (false === strpos($orderBy, '.')) {
                    $orderBy = "$alias.$orderBy";
                }

                $query->orderBy($orderBy);
            } elseif (is_array($orderBy)) {
                foreach ($orderBy as $sort => $order) {
                    if (is_int($sort)) {
                        if (false === strpos($sort, '.')) {
                            $sort = "$alias.$sort";
                        }

                        $query->addOrderBy($sort);
                    } else {
                        if (false === strpos($sort, '.')) {
                            $sort = "$alias.$sort";
                        }

                        $query->addOrderBy($sort, $order);
                    }
                }
            }
        }
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $query
     * @param \Doctrine\ORM\Query\Expr $expr
     * @param array $filters
     * @param string $alias
     *
     * @return array
     */
    private function addFilters(
        \Doctrine\ORM\QueryBuilder $query,
        \Doctrine\ORM\Query\Expr $expr,
        array $filters,
        $alias
    ) {
        $predicts = [];

        foreach ($filters as $field => $filter) {
            if ($filter instanceof Expr\Base) {
                $predicts[] = $filter;
            } elseif (is_int($field)) {
                $predicts = array_merge(
                    $predicts,
                    $this->addFilters($query, $expr, $filter, $alias)
                );
            } elseif ($field === Filter::_OR) {
                $or = $this->addFilters($query, $expr, $filter, $alias);
                $predicts[] = call_user_func_array([$expr, 'orX'], $or);
            } elseif ($field === Filter::_AND) {
                $and = $this->addFilters($query, $expr, $filter, $alias);
                $predicts[] = call_user_func_array([$expr, 'andX'], $and);
            } else {
                if (!is_array($filter)) {
                    $value = $filter;
                    $filter = [];

                    if (in_array($value, [Filter::NOT_NULL, Filter::IS_NULL], true)) {
                        $filter[$value] = null;
                    } else {
                        $filter[Filter::EQUALS] = $value;
                    }
                }

                if (false === strpos($field, '.')) {
                    $field = "$alias.$field";
                }

                foreach ($filter as $op => $value) {
                    switch ($op) {
                        case Filter::EQUALS:
                            $value = $expr->literal($value);
                            $predicts[] = $expr->eq($field, $value);
                            break;
                        case Filter::SAME:
                            $predicts[] = $expr->eq($field, $value);
                            break;
                        case Filter::NOT_SAME:
                            $predicts[] = $expr->neq($field, $value);
                            break;
                        case Filter::NOT_EQUALS:
                            $value = $expr->literal($value);
                            $predicts[] = $expr->neq($field, $value);
                            break;
                        case Filter::STARTS:
                            $value = $expr->literal("$value%");
                            $predicts[] = $expr->like($field, $value);
                            break;
                        case Filter::ENDS:
                            $value = $expr->literal("%$value");
                            $predicts[] = $expr->like($field, $value);
                            break;
                        case Filter::CONTAINS:
                            $value = $expr->literal("%$value%");
                            $predicts[] = $expr->like($field, "$value");
                            break;
                        case Filter::NOT_CONTAINS:
                            $value = $expr->literal("%$value%");
                            $predicts[] = $expr->notLike($field, $value);
                            break;
                        case Filter::IN:
                            if (!is_array($value)) {
                                throw new BadRequestHttpException(Filter::IN.' requires an array');
                            }
                            $predicts[] = $expr->in($field, $value);
                            break;
                        case Filter::NOT_IN:
                            if (!is_array($value)) {
                                throw new BadRequestHttpException(Filter::NOT_IN.' requires an array');
                            }
                            $predicts[] = $expr->notIn($field, $value);
                            break;
                        case Filter::BETWEEN:
                            if (!is_array($value) || count($value) != 2) {
                                throw new BadRequestHttpException(Filter::BETWEEN.' requires an array with two values.');
                            }
                            $predicts[] = $expr->between($field, $value[0], $value[1]);
                            break;
                        case Filter::IS_NULL:
                            $predicts[] = $expr->isNull($field);
                            break;
                        case Filter::NOT_NULL:
                            $predicts[] = $expr->isNotNull($field);
                            break;
                        case Filter::LT:
                            $predicts[] = $expr->lt($field, $value);
                            break;
                        case Filter::LTE:
                            $predicts[] = $expr->lte($field, $value);
                            break;
                        case Filter::GT:
                            $predicts[] = $expr->gt($field, $value);
                            break;
                        case Filter::GTE:
                            $predicts[] = $expr->gte($field, $value);
                            break;
                        default:
                            throw new BadRequestHttpException("Did not recognize the operand: " . $op);
                    }
                }
            }
        }

        return $predicts;
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder$query
     * @param string $alias
     * @param array $params
     */
    private function buildFilters(\Doctrine\ORM\QueryBuilder $query, $alias, array $params)
    {
        if (is_array($params[Param::WHERE])) {
            $expr = $query->expr();
            $and = $this->addFilters($query, $expr, $params[Param::WHERE], $alias);
            call_user_func_array([$query, 'where'], $and);
        } else {
            throw new BadRequestHttpException();
        }
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $query
     * @param array $params
     */
    private function setParams(\Doctrine\ORM\QueryBuilder $query, array $params)
    {
        foreach ($params[Param::PARAMS] as $key => $value) {
            $query->setParameter($key, $value);
        }
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $query
     * @param string $alias
     * @param array $params
     */
    private function addJoin(\Doctrine\ORM\QueryBuilder $query, $alias, array $params)
    {
        foreach ($params[Param::JOIN] as $join => $a) {
            if (false === strpos($join, '.')) {
                $join = "$alias.$join";
            }

            if (is_string($a)) {
                $a = [
                    'alias' => $a,
                ];
            }

            switch (strtolower(isset($a['type']) ? $a['type'] : 'inner')) {
                case 'inner':
                    $query->innerJoin(
                        $join,
                        $a['alias'],
                        isset($a['conditionType']) ? $a['conditionType'] : Join::WITH,
                        isset($a['condition']) ? $a['condition'] : null,
                        isset($a['indexBy']) ? $a['indexBy'] : null
                    );
                    break;
                case 'left':
                    $query->leftJoin(
                        $join,
                        $a['alias'],
                        isset($a['conditionType']) ? $a['conditionType'] : Join::WITH,
                        isset($a['condition']) ? $a['condition'] : null,
                        isset($a['indexBy']) ? $a['indexBy'] : null
                    );
                    break;
            }
        }
    }
}
