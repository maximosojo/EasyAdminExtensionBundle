<?php

namespace AlterPHP\EasyAdminExtensionBundle\EventListener;

use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Apply filters on list/search queryBuilder
 */
class PostQueryBuilderSubscriber implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            EasyAdminEvents::POST_LIST_QUERY_BUILDER => array('onPostListQueryBuilder'),
            EasyAdminEvents::POST_SEARCH_QUERY_BUILDER => array('onPostSearchQueryBuilder'),
        );
    }

    /**
     * Called on POST_LIST_QUERY_BUILDER event.
     *
     * @param  GenericEvent $event
     */
    public function onPostListQueryBuilder(GenericEvent $event)
    {
        $queryBuilder = $event->getArgument('query_builder');

        if ($event->hasArgument('request')) {
            $this->applyRequestFilters($queryBuilder, $event->getArgument('request')->get('filters', array()));
        }
    }

    /**
     * Called on POST_SEARCH_QUERY_BUILDER event.
     *
     * @param  GenericEvent $event
     */
    public function onPostSearchQueryBuilder(GenericEvent $event)
    {
        $queryBuilder = $event->getArgument('query_builder');

        if ($event->hasArgument('request')) {
            $this->applyRequestFilters($queryBuilder, $event->getArgument('request')->get('filters', array()));
        }
    }

    /**
     * Applies filters on queryBuilder.
     *
     * @param  QueryBuilder $queryBuilder
     * @param  array        $filters
     */
    protected function applyRequestFilters(QueryBuilder $queryBuilder, array $filters = array())
    {
        foreach ($filters as $field => $value) {
            // Empty string in considered as "not applied filter" (it's supposed to be set from GET parameter)
            if ('' === $value) {
                continue;
            }

            // Add root entity alias if none provided
            $field = false === strpos($field, '.') ? $queryBuilder->getRootAlias().'.'.$field : $field;

            // Checks if filter is directly appliable on queryBuilder
            if (!$this->isFilterAppliable($queryBuilder, $field)) {
                continue;
            }

            // Sanitize parameter name
            $parameter = 'filter_'.str_replace('.', '_', $field);

            $this->filterQueryBuilder($queryBuilder, $field, $parameter, $value);
        }
    }

    /**
     * Filters queryBuilder.
     *
     * @param  QueryBuilder $queryBuilder
     * @param  string       $field
     * @param  string       $parameter
     * @param  mixed        $value
     */
    protected function filterQueryBuilder(QueryBuilder $queryBuilder, string $field, string $parameter, $value)
    {
        // For multiple value, use an IN clause, equality otherwise
        if (is_array($value)) {
            $filterDqlPart = $field.' IN (:'.$parameter.')';
        } else {
            $filterDqlPart = $field.' = :'.$parameter;
        }

        $queryBuilder
            ->andWhere($filterDqlPart)
            ->setParameter($parameter, $value)
        ;
    }

    /**
     * Checks if filter is directly appliable on queryBuilder.
     *
     * @param  QueryBuilder $queryBuilder
     * @param  string       $field
     *
     * @return boolean
     */
    protected function isFilterAppliable(QueryBuilder $queryBuilder, string $field): bool
    {
        // TODO: if not directly appliable on queryBuilder (not existing field/association on entity)
        // => return false

        return true;
    }
}