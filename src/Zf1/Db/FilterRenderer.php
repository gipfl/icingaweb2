<?php

namespace gipfl\IcingaWeb2\Zf1\Db;

use gipfl\ZfDb\Adapter\Adapter as Db;
use gipfl\ZfDb\Exception\SelectException;
use gipfl\ZfDb\Expr;
use gipfl\ZfDb\Select;
use Icinga\Data\Filter\Filter;
use Icinga\Data\Filter\FilterAnd;
use Icinga\Data\Filter\FilterChain;
use Icinga\Data\Filter\FilterExpression;
use Icinga\Data\Filter\FilterNot;
use Icinga\Data\Filter\FilterOr;
use Icinga\Data\SimpleQuery;
use InvalidArgumentException;
use RuntimeException;
use Zend_Db_Adapter_Abstract as DbAdapter;
use Zend_Db_Expr as DbExpr;
use Zend_Db_Select as DbSelect;
use Zend_Db_Select_Exception as DbSelectException;

class FilterRenderer
{
    private $db;

    /** @var Filter */
    private $filter;

    /** @var array */
    private $columnMap;

    /** @var string */
    private $dbExprClass;

    /**
     * FilterRenderer constructor.
     * @param Filter $filter
     * @param Db|DbAdapter $db
     */
    public function __construct(Filter $filter, $db)
    {
        $this->filter = $filter;
        if ($db instanceof Db) {
            $this->db = $db;
            $this->dbExprClass = Expr::class;
        } elseif ($db instanceof DbAdapter) {
            $this->db = $db;
            $this->dbExprClass = DbExpr::class;
        } else {
            throw new RuntimeException('Got no supported ZF1 DB adapter');
        }
    }

    /**
     * @return Expr|DbExpr
     */
    public function toDbExpression()
    {
        return $this->expr($this->render());
    }

    /**
     * @return Expr|DbExpr
     */
    protected function expr($content)
    {
        $class = $this->dbExprClass;
        return new $class($content);
    }

    /**
     * @param Filter $filter
     * @param Select|DbSelect|SimpleQuery $query
     * @return Select|DbSelect|SimpleQuery
     */
    public static function applyToQuery(Filter $filter, $query)
    {
        if ($query instanceof SimpleQuery) {
            $query->applyFilter($filter);
            return $query;
        }
        if (! ($query instanceof Select || $query instanceof DbSelect)) {
            throw new RuntimeException('Got no supported ZF1 Select object');
        }

        if (! $filter->isEmpty()) {
            $renderer = new static($filter, $query->getAdapter());
            $renderer->extractColumnMap($query);
            $query->where($renderer->toDbExpression());
        }

        return $query;
    }

    protected function lookupColumnAlias($column)
    {
        if (array_key_exists($column, $this->columnMap)) {
            return $this->columnMap[$column];
        } else {
            return $column;
        }
    }

    protected function extractColumnMap($query)
    {
        $map = [];
        try {
            $columns = $query->getPart(Select::COLUMNS);
        } catch (SelectException $e) {
            // Will not happen.
            throw new RuntimeException($e->getMessage());
        } catch (DbSelectException $e) {
            // Will not happen.
            throw new RuntimeException($e->getMessage());
        }

        foreach ($columns as $col) {
            if ($col[1] instanceof Expr || $col[1] instanceof DbExpr) {
                $map[$col[2]] = (string) $col[1];
                $map[$col[2]] = $col[1];
            } else {
                $map[$col[2]] = $col[0] . '.' . $col[1];
            }
        }

        $this->columnMap = $map;
    }

    /**
     * @return string
     */
    public function render()
    {
        return $this->renderFilter($this->filter);
    }

    protected function renderFilterChain(FilterChain $filter, $level = 0)
    {
        $prefix = '';

        if ($filter instanceof FilterAnd) {
            $op = ' AND ';
        } elseif ($filter instanceof FilterOr) {
            $op = ' OR ';
        } elseif ($filter instanceof FilterNot) {
            $op = ' AND ';
            $prefix = 'NOT ';
        } else {
            throw new InvalidArgumentException(
                'Cannot render a %s filter chain for Zf Db',
                get_class($filter)
            );
        }

        $parts = [];
        if ($filter->isEmpty()) {
            // Hint: we might want to fail here
            return '';
        } else {
            foreach ($filter->filters() as $f) {
                $part = $this->renderFilter($f, $level + 1);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
            if (empty($parts)) {
                // will not happen, as we are not empty
                return '';
            } else {
                if ($level > 0) {
                    return "$prefix (" . implode($op, $parts) . ')';
                } else {
                    return $prefix . implode($op, $parts);
                }
            }
        }
    }

    protected function renderFilterExpression(FilterExpression $filter)
    {
        $col = $this->lookupColumnAlias($filter->getColumn());
        if (! $col instanceof Expr && ! $col instanceof DbExpr && ! ctype_digit($col)) {
            $col = $this->db->quoteIdentifier($col);
        }
        $sign = $filter->getSign();
        $expression = $filter->getExpression();

        if (is_array($expression)) {
            return $this->renderArrayExpression($col, $sign, $expression);
        }

        if ($sign === '=') {
            if (strpos($expression, '*') === false) {
                return $this->renderAny($col, $sign, $expression);
            } else {
                return $this->renderLike($col, $expression);
            }
        }

        if ($sign === '!=') {
            if (strpos($expression, '*') === false) {
                return $this->renderAny($col, $sign, $expression);
            } else {
                return $this->renderNotLike($col, $expression);
            }
        }

        return $this->renderAny($col, $sign, $expression);
    }


    protected function renderLike($col, $expression)
    {
        if ($expression === '*') {
            return $this->expr('TRUE');
        }

        return $col . ' LIKE ' . $this->escape($this->escapeWildcards($expression));
    }

    protected function renderNotLike($col, $expression)
    {
        if ($expression === '*') {
            return $this->expr('FALSE');
        }

        return sprintf(
            '(%1$s NOT LIKE %2$s OR %1$s IS NULL)',
            $col,
            $this->escape($this->escapeWildcards($expression))
        );
    }

    protected function renderNotEqual($col, $expression)
    {
        return sprintf('(%1$s != %2$s OR %1$s IS NULL)', $col, $this->escape($expression));
    }

    protected function renderAny($col, $sign, $expression)
    {
        return sprintf('%s %s %s', $col, $sign, $this->escape($expression));
    }

    protected function renderArrayExpression($col, $sign, $expression)
    {
        if ($sign === '=') {
            return $col . ' IN (' . $this->escape($expression) . ')';
        } elseif ($sign === '!=') {
            return sprintf(
                '(%1$s NOT IN (%2$s) OR %1$s IS NULL)',
                $col,
                $this->escape($expression)
            );
        }

        throw new InvalidArgumentException(
            'Array expressions can only be rendered for = and !=, got %s',
            $sign
        );
    }

    /**
     * @param Filter $filter
     * @param int $level
     * @return string|Expr|DbExpr
     */
    protected function renderFilter(Filter $filter, $level = 0)
    {
        if ($filter instanceof FilterChain) {
            return $this->renderFilterChain($filter, $level);
        } elseif ($filter instanceof FilterExpression) {
            return $this->renderFilterExpression($filter);
        } else {
            throw new RuntimeException(sprintf(
                'Filter of type FilterChain or FilterExpression expected, got %s',
                get_class($filter)
            ));
        }
    }

    protected function escape($value)
    {
        // bindParam? bindValue?
        if (is_array($value)) {
            $ret = [];
            foreach ($value as $val) {
                $ret[] = $this->escape($val);
            }
            return implode(', ', $ret);
        } else {
            return $this->db->quote($value);
        }
    }

    protected function escapeWildcards($value)
    {
        return preg_replace('/\*/', '%', $value);
    }

    public function whereToSql($col, $sign, $expression)
    {
        if (is_array($expression)) {
            if ($sign === '=') {
                return $col . ' IN (' . $this->escape($expression) . ')';
            } elseif ($sign === '!=') {
                return sprintf('(%1$s NOT IN (%2$s) OR %1$s IS NULL)', $col, $this->escape($expression));
            }

            throw new InvalidArgumentException(
                'Unable to render array expressions with operators other than equal or not equal'
            );
        } elseif ($sign === '=' && strpos($expression, '*') !== false) {
            if ($expression === '*') {
                return $this->expr('TRUE');
            }

            return $col . ' LIKE ' . $this->escape($this->escapeWildcards($expression));
        } elseif ($sign === '!=' && strpos($expression, '*') !== false) {
            if ($expression === '*') {
                return $this->expr('FALSE');
            }

            return sprintf(
                '(%1$s NOT LIKE %2$s OR %1$s IS NULL)',
                $col,
                $this->escape($this->escapeWildcards($expression))
            );
        } elseif ($sign === '!=') {
            return sprintf('(%1$s %2$s %3$s OR %1$s IS NULL)', $col, $sign, $this->escape($expression));
        } else {
            return sprintf('%s %s %s', $col, $sign, $this->escape($expression));
        }
    }
}
