<?php

namespace gipfl\IcingaWeb2\Table;

use Countable;
use gipfl\Format\LocalDateFormat;
use gipfl\IcingaWeb2\Data\Paginatable;
use gipfl\IcingaWeb2\Zf1\Db\FilterRenderer;
use gipfl\IcingaWeb2\Table\Extension\QuickSearch;
use gipfl\IcingaWeb2\Url;
use gipfl\IcingaWeb2\Widget\ControlsAndContent;
use gipfl\IcingaWeb2\Widget\Paginator;
use gipfl\Translation\TranslationHelper;
use Icinga\Application\Benchmark;
use Icinga\Data\Filter\Filter;
use ipl\Html\Table;

abstract class QueryBasedTable extends Table implements Countable
{
    use TranslationHelper;
    use QuickSearch;

    protected $defaultAttributes = [
        'class' => ['common-table', 'table-row-selectable'],
        'data-base-target' => '_next',
    ];

    private $fetchedRows;

    private $firstRow;

    private $lastRow = false;

    private $rowNumber;

    private $rowNumberOnPage;

    protected $lastDay;

    /** @var Paginator|null Will usually be defined at rendering time */
    protected $paginator;

    private $isUsEnglish;

    private $dateFormatter;

    protected $searchColumns = [];

    /**
     * @return Paginatable
     */
    abstract protected function getPaginationAdapter();

    abstract public function getQuery();

    public function getPaginator(Url $url)
    {
        return new Paginator(
            $this->getPaginationAdapter(),
            $url
        );
    }

    public function count()
    {
        return $this->getPaginationAdapter()->count();
    }

    public function applyFilter(Filter $filter)
    {
        FilterRenderer::applyToQuery($filter, $this->getQuery());
        return $this;
    }

    protected function getSearchColumns()
    {
        return $this->searchColumns;
    }

    public function search($search)
    {
        if (! empty($search)) {
            $query = $this->getQuery();
            $columns = $this->getSearchColumns();
            if (strpos($search, ' ') === false) {
                $filter = Filter::matchAny();
                foreach ($columns as $column) {
                    $filter->addFilter(Filter::expression($column, '=', "*$search*"));
                }
            } else {
                $filter = Filter::matchAll();
                foreach (explode(' ', $search) as $s) {
                    $sub = Filter::matchAny();
                    foreach ($columns as $column) {
                        $sub->addFilter(Filter::expression($column, '=', "*$s*"));
                    }
                    $filter->addFilter($sub);
                }
            }

            FilterRenderer::applyToQuery($filter, $query);
        }

        return $this;
    }

    abstract protected function prepareQuery();

    public function renderContent()
    {
        $titleColumns = $this->renderTitleColumns();
        if ($titleColumns) {
            $this->getHeader()->add($titleColumns);
        }
        $this->fetchRows();

        return parent::renderContent();
    }

    protected function renderTitleColumns()
    {
        // TODO: drop this
        if (method_exists($this, 'getColumnsToBeRendered')) {
            $columns = $this->getColumnsToBeRendered();
            if (isset($columns) && count($columns)) {
                return static::row($columns, null, 'th');
            }
        }

        return null;
    }

    protected function splitByDay($timestamp)
    {
        $this->renderDayIfNew((int) $timestamp);
    }

    public function isOnFirstPage()
    {
        if ($this->paginator === null) {
            // No paginator? Then there should be only a single page
            return true;
        }

        return $this->paginator->getCurrentPage() === 1;
    }

    public function isOnFirstRow()
    {
        return $this->firstRow === true;
    }

    public function isOnLastRow()
    {
        return $this->lastRow === true;
    }

    protected function fetchRows()
    {
        $firstPage = $this->isOnFirstPage();
        $this->rowNumberOnPage = 0;
        $this->rowNumber = $this->getPaginationAdapter()->getOffset();
        $lastRow = count($this);
        foreach ($this->fetch() as $row) {
            $this->rowNumber++;
            $this->rowNumberOnPage++;
            if (null === $this->firstRow) {
                if ($firstPage) {
                    $this->firstRow = true;
                } else {
                    $this->firstRow = false;
                }
            } elseif (true === $this->firstRow) {
                $this->firstRow = false;
            }
            if ($lastRow === $this->rowNumber) {
                $this->lastRow = true;
            }
            // Hint: do not fetch the body first, the row might want to replace it
            $tr = $this->renderRow($row);
            if ($tr !== null) {
                $this->add($tr);
            }
        }
    }

    protected function renderRow($row)
    {
        return $this::row([$row]);
    }

    /**
     * @deprecated
     * @return bool
     */
    protected function isUsEnglish()
    {
        if ($this->isUsEnglish === null) {
            $this->isUsEnglish = in_array(setlocale(LC_ALL, 0), ['en_US', 'en_US.UTF-8', 'C']);
        }

        return $this->isUsEnglish;
    }

    /**
     * @param  int $timestamp
     */
    protected function renderDayIfNew($timestamp)
    {
        $day = $this->getDateFormatter()->getFullDay($timestamp);

        if ($this->lastDay !== $day) {
            $this->nextHeader()->add(
                $this::th($day, [
                    'colspan' => 2,
                    'class'   => 'table-header-day'
                ])
            );

            $this->lastDay = $day;
            $this->nextBody();
        }
    }

    abstract protected function fetchQueryRows();

    public function fetch()
    {
        $parts = explode('\\', get_class($this));
        $name = end($parts);
        Benchmark::measure("Fetching data for $name table");
        $rows = $this->fetchQueryRows();
        $this->fetchedRows = count($rows);
        Benchmark::measure("Fetched $this->fetchedRows rows for $name table");

        return $rows;
    }

    protected function initializeOptionalQuickSearch(ControlsAndContent $controller)
    {
        $columns = $this->getSearchColumns();
        if (! empty($columns)) {
            $this->search(
                $this->getQuickSearch(
                    $controller->controls(),
                    $controller->url()
                )
            );
        }
    }

    /**
     * @param ControlsAndContent $controller
     * @return $this
     */
    public function renderTo(ControlsAndContent $controller)
    {
        $url = $controller->url();
        $c = $controller->content();
        $this->paginator = $this->getPaginator($url);
        $this->initializeOptionalQuickSearch($controller);
        $controller->actions()->add($this->paginator);
        $c->add($this);

        // TODO: move elsewhere
        if (method_exists($this, 'dumpSqlQuery')) {
            if ($url->getParam('format') === 'sql') {
                $c->prepend($this->dumpSqlQuery($url));
            }
        }

        return $this;
    }

    protected function getDateFormatter()
    {
        if ($this->dateFormatter === null) {
            $this->dateFormatter = new LocalDateFormat();
        }

        return $this->dateFormatter;
    }
}
