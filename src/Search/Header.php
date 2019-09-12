<?php


namespace Ddeboer\Imap\Search;

/**
 * Note: it doesn't work with common c-client
 * Class Header
 * @package Ddeboer\Imap\Search
 */
class Header extends AbstractCondition
{
    protected $keyword = 'HEADER';
    /**
     * @var string
     */
    protected $name;
    /**
     * @var string
     */
    protected $value;

    public function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    protected function getKeyword()
    {
        return $this->keyword;
    }

    public function __toString()
    {
        return parent::__toString() . sprintf(" %s %s ", $this->name, $this->value);
    }


}