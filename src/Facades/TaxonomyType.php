<?php

namespace JobMetric\Taxonomy\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \JobMetric\Taxonomy\TaxonomyType
 *
 * @method static \JobMetric\Taxonomy\TaxonomyType define(string $type)
 * @method static \JobMetric\Taxonomy\TaxonomyType type(string $type)
 */
class TaxonomyType extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return \JobMetric\Taxonomy\TaxonomyType::class;
    }
}