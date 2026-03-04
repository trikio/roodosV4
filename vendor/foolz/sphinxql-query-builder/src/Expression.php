<?php

namespace Foolz\SphinxQL;

/**
 * Wraps expressions so they aren't quoted or modified
 * when inserted into the query
 */
class Expression
{
    /**
     * The expression content
     *
     * @var string
     */
    protected string $string;

    /**
     * The constructor accepts the expression as string
     *
     * @param string $string The content to prevent being quoted
     */
    public function __construct(string $string = '')
    {
        $this->string = $string;
    }

    /**
     * Return the unmodified expression
     *
     * @return string The unaltered content of the expression
     */
    public function value(): string
    {
        return $this->string;
    }

    /**
     * Returns the unmodified expression
     *
     * @return string The unaltered content of the expression
     */
    public function __toString(): string
    {
        return $this->value();
    }
}
