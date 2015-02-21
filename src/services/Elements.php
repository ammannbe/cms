<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\services;

use Craft;
use craft\app\db\Command;
use craft\app\db\FixedOrderExpression;
use craft\app\db\Query;
use craft\app\elementactions\ElementActionInterface;
use craft\app\elements\ElementRelationParamParser;
use craft\app\elementtypes\ElementTypeInterface;
use craft\app\enums\ComponentType;
use craft\app\errors\Exception;
use craft\app\events\DeleteElementsEvent;
use craft\app\events\ElementEvent;
use craft\app\events\MergeElementsEvent;
use craft\app\helpers\ArrayHelper;
use craft\app\helpers\DbHelper;
use craft\app\helpers\ElementHelper;
use craft\app\helpers\StringHelper;
use craft\app\models\BaseElementModel;
use craft\app\models\ElementCriteria as ElementCriteriaModel;
use craft\app\records\Element as ElementRecord;
use craft\app\records\ElementLocale as ElementLocaleRecord;
use craft\app\records\StructureElement as StructureElementRecord;
use yii\base\Component;

/**
 * The Elements service provides APIs for managing elements.
 *
 * An instance of the Elements service is globally accessible in Craft via [[Application::elements `Craft::$app->elements`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Elements extends Component
{
	// Constants
	// =========================================================================

	/**
     * @event ElementEvent The event that is triggered after an element is populated.
     */
    const EVENT_AFTER_POPULATE_ELEMENT = 'afterPopulateElement';

	/**
     * @event MergeElementsEvent The event that is triggered after two elements are merged together.
     */
    const EVENT_AFTER_MERGE_ELEMENTS = 'afterMergeElements';

	/**
     * @event DeleteElementsEvent The event that is triggered before one or more elements are deleted.
     */
    const EVENT_BEFORE_DELETE_ELEMENTS = 'beforeDeleteElements';

	/**
     * @event ElementEvent The event that is triggered before an element is saved.
     *
     * You may set [[ElementEvent::performAction]] to `false` to prevent the element from getting saved.
     */
    const EVENT_BEFORE_SAVE_ELEMENT = 'beforeSaveElement';

	/**
     * @event ElementEvent The event that is triggered after an element is saved.
     */
    const EVENT_AFTER_SAVE_ELEMENT = 'afterSaveElement';

	// Properties
	// =========================================================================

	/**
	 * @var array
	 */
	private $_placeholderElements;

	// Public Methods
	// =========================================================================

	// Finding Elements
	// -------------------------------------------------------------------------

	/**
	 * Returns an element criteria model for a given element type.
	 *
	 * This should be the starting point any time you want to fetch elements in Craft.
	 *
	 * ```php
	 * $criteria = Craft::$app->elements->getCriteria(ElementType::Entry);
	 * $criteria->section = 'news';
	 * $entries = $criteria->find();
	 * ```
	 *
	 * @param string $type       The element type class handle (e.g. one of the values in the [[ElementType]] enum).
	 * @param mixed  $attributes Any criteria attribute values that should be pre-populated on the criteria model.
	 *
	 * @throws Exception
	 * @return ElementCriteriaModel An element criteria model, wired to fetch elements of the given $type.
	 */
	public function getCriteria($type, $attributes = null)
	{
		$elementType = $this->getElementType($type);

		if (!$elementType)
		{
			throw new Exception(Craft::t('app', 'No element type exists by the type “{type}”.', ['type' => $type]));
		}

		return new ElementCriteriaModel($attributes, $elementType);
	}

	/**
	 * Returns an element by its ID.
	 *
	 * If no element type is provided, the method will first have to run a DB query to determine what type of element
	 * the $elementId is, so you should definitely pass it if it’s known.
	 *
	 * The element’s status will not be a factor when usisng this method.
	 *
	 * @param int    $elementId   The element’s ID.
	 * @param null   $elementType The element type’s class handle.
	 * @param string $localeId    The locale to fetch the element in.
	 *                            Defaults to [[\craft\app\web\Application::getLanguage() `Craft::$app->getLanguage`]].
	 *
	 * @return BaseElementModel|null The matching element, or `null`.
	 */
	public function getElementById($elementId, $elementType = null, $localeId = null)
	{
		if (!$elementId)
		{
			return null;
		}

		if (!$elementType)
		{
			$elementType = $this->getElementTypeById($elementId);

			if (!$elementType)
			{
				return null;
			}
		}

		$criteria = $this->getCriteria($elementType);
		$criteria->id = $elementId;
		$criteria->locale = $localeId;
		$criteria->status = null;
		$criteria->localeEnabled = null;
		return $criteria->first();
	}

	/**
	 * Returns an element by its URI.
	 *
	 * @param string      $uri         The element’s URI.
	 * @param string|null $localeId    The locale to look for the URI in, and to return the element in.
	 *                                 Defaults to [[\craft\app\web\Application::getLanguage() `Craft::$app->getLanguage()`]].
	 * @param bool        $enabledOnly Whether to only look for an enabled element. Defaults to `false`.
	 *
	 * @return BaseElementModel|null The matching element, or `null`.
	 */
	public function getElementByUri($uri, $localeId = null, $enabledOnly = false)
	{
		if ($uri === '')
		{
			$uri = '__home__';
		}

		if (!$localeId)
		{
			$localeId = Craft::$app->language;
		}

		// First get the element ID and type

		$conditions = ['and',
			'elements_i18n.uri = :uri',
			'elements_i18n.locale = :locale'
		];

		$params = [
			':uri'    => $uri,
			':locale' => $localeId
		];

		if ($enabledOnly)
		{
			$conditions[] = 'elements_i18n.enabled = 1';
			$conditions[] = 'elements.enabled = 1';
			$conditions[] = 'elements.archived = 0';
		}

		$result = (new Query())
			->select('elements.id, elements.type')
			->from('{{%elements}} elements')
			->innerJoin('{{%elements_i18n}} elements_i18n', 'elements_i18n.elementId = elements.id')
			->where($conditions, $params)
			->one();

		if ($result)
		{
			// Return the actual element
			return $this->getElementById($result['id'], $result['type'], $localeId);
		}
	}

	/**
	 * Returns the element type(s) used by the element of a given ID(s).
	 *
	 * If a single ID is passed in (an int), then a single element type will be returned (a string), or `null` if
	 * no element exists by that ID.
	 *
	 * If an array is passed in, then an array will be returned.
	 *
	 * @param int|array $elementId An element’s ID, or an array of elements’ IDs.
	 *
	 * @return string|array|null The element type(s).
	 */
	public function getElementTypeById($elementId)
	{
		if (is_array($elementId))
		{
			return (new Query())
				->select('type')
				->distinct(true)
				->from('{{%elements}}')
				->where(['in', 'id', $elementId])
				->column();
		}
		else
		{
			return (new Query())
				->select('type')
				->from('{{%elements}}')
				->where(['id' => $elementId])
				->scalar();
		}
	}

	/**
	 * Finds elements.
	 *
	 * @param ElementCriteriaModel $criteria An element criteria model that defines the parameters for the elements
	 *                                       we should be looking for.
	 * @param bool                 $justIds  Whether the method should only return an array of the IDs of the matched
	 *                                       elements. Defaults to `false`.
	 *
	 * @return array The matched elements, or their IDs, depending on $justIds.
	 */
	public function findElements($criteria = null, $justIds = false)
	{
		$elements = [];
		$query = $this->buildElementsQuery($criteria, $contentTable, $fieldColumns);

		if ($query)
		{
			if ($justIds)
			{
				$query->select(['elements.id']);
			}

			if ($criteria->fixedOrder)
			{
				$ids = ArrayHelper::toArray($criteria->id);

				if (!$ids)
				{
					return [];
				}

				$query->orderBy(new FixedOrderExpression('elements.id', $ids));
			}
			else if ($criteria->order && $criteria->order != 'score')
			{
				$order = $criteria->order;

				if (is_array($fieldColumns))
				{
					// Add the field column prefixes
					foreach ($fieldColumns as $column)
					{
						// Avoid matching fields named "asc" or "desc" in the string "column_name asc" or
						// "column_name desc"
						$order = preg_replace('/(?<!\s)\b'.$column['handle'].'\b/', $column['column'].'$1', $order);
					}
				}

				$query->orderBy($order);
			}

			if ($criteria->offset)
			{
				$query->offset($criteria->offset);
			}

			if ($criteria->limit)
			{
				$query->limit($criteria->limit);
			}

			$results = $query->all();

			if ($results)
			{
				if ($justIds)
				{
					foreach ($results as $result)
					{
						$elements[] = $result['id'];
					}
				}
				else
				{
					$locale = $criteria->locale;
					$elementType = $criteria->getElementType();
					$indexBy = $criteria->indexBy;
					$lastElement = null;

					foreach ($results as $result)
					{
						// Do we have a placeholder for this elmeent?
						if (isset($this->_placeholderElements[$result['id']][$locale]))
						{
							$element = $this->_placeholderElements[$result['id']][$locale];
						}
						else
						{
							// Make a copy to pass to the onPopulateElement event
							$originalResult = array_merge($result);

							if ($contentTable)
							{
								// Separate the content values from the main element attributes
								$content = [
									'id'        => (isset($result['contentId']) ? $result['contentId'] : null),
									'elementId' => $result['id'],
									'locale'    => $locale,
									'title'     => (isset($result['title']) ? $result['title'] : null)
								];

								unset($result['title']);

								if ($fieldColumns)
								{
									foreach ($fieldColumns as $column)
									{
										// Account for results where multiple fields have the same handle, but from
										// different columns e.g. two Matrix block types that each have a field with the
										// same handle

										$colName = $column['column'];
										$fieldHandle = $column['handle'];

										if (!isset($content[$fieldHandle]) || (empty($content[$fieldHandle]) && !empty($result[$colName])))
										{
											$content[$fieldHandle] = $result[$colName];
										}

										unset($result[$colName]);
									}
								}
							}

							$result['locale'] = $locale;
							$element = $elementType->populateElementModel($result);

							// Was an element returned?
							if (!$element || !($element instanceof BaseElementModel))
							{
								continue;
							}

							if ($contentTable)
							{
								$element->setContent($content);
							}

							// Fire an 'afterPopulateElement' event
							$this->trigger(static::EVENT_AFTER_POPULATE_ELEMENT, new ElementEvent([
								'element' => $element,
								'result'  => $originalResult
							]));
						}

						if ($indexBy)
						{
							$elements[$element->$indexBy] = $element;
						}
						else
						{
							$elements[] = $element;
						}

						if ($lastElement)
						{
							$lastElement->setNext($element);
							$element->setPrev($lastElement);
						}
						else
						{
							$element->setPrev(false);
						}

						$lastElement = $element;
					}

					$lastElement->setNext(false);
				}
			}
		}

		return $elements;
	}

	/**
	 * Returns the total number of elements that match a given criteria.
	 *
	 * @param ElementCriteriaModel $criteria An element criteria model that defines the parameters for the elements
	 *                                       we should be counting.
	 *
	 * @return int The total number of elements that match the criteria.
	 */
	public function getTotalElements($criteria = null)
	{
		$query = $this->buildElementsQuery($criteria);

		if ($query)
		{
			$elementIds = $this->_getElementIdsFromQuery($query);

			if ($criteria->search)
			{
				$elementIds = Craft::$app->search->filterElementIdsByQuery($elementIds, $criteria->search, false);
			}

			return count($elementIds);
		}
		else
		{
			return 0;
		}
	}

	/**
	 * Preps a [[Command]] object for querying for elements, based on a given element criteria.
	 *
	 * @param ElementCriteriaModel &$criteria     The element criteria model
	 * @param string               &$contentTable The content table that should be joined in. (This variable will
	 *                                            actually get defined by buildElementsQuery(), and is passed by
	 *                                            reference so whatever’s calling the method will have access to its
	 *                                            value.)
	 * @param array                &$fieldColumns Info about the content field columns being selected. (This variable
	 *                                            will actually get defined by buildElementsQuery(), and is passed by
	 *                                            reference so whatever’s calling the method will have access to its
	 *                                            value.)
	 *
	 * @return Query|false The query object, or `false` if the method was able to determine ahead of time that
	 *                         there’s no chance any elements are going to be found with the given parameters.
	 */
	public function buildElementsQuery(&$criteria = null, &$contentTable = null, &$fieldColumns = null)
	{
		if (!($criteria instanceof ElementCriteriaModel))
		{
			$criteria = $this->getCriteria('Entry', $criteria);
		}

		$elementType = $criteria->getElementType();

		if (!$elementType->isLocalized())
		{
			// The criteria *must* be set to the primary locale
			$criteria->locale = Craft::$app->getI18n()->getPrimarySiteLocaleId();
		}
		else if (!$criteria->locale)
		{
			// Default to the current app locale
			$criteria->locale = Craft::$app->language;
		}

		// Set up the query
		// ---------------------------------------------------------------------

		$query = (new Query())
			->select('elements.id, elements.type, elements.enabled, elements.archived, elements.dateCreated, elements.dateUpdated, elements_i18n.slug, elements_i18n.uri, elements_i18n.enabled AS localeEnabled')
			->from('{{%elements}} elements')
			->innerJoin('{{%elements_i18n}} elements_i18n', 'elements_i18n.elementId = elements.id')
			->where('elements_i18n.locale = :locale', [':locale' => $criteria->locale])
			->groupBy('elements.id');

		if ($elementType->hasContent())
		{
			$contentTable = $elementType->getContentTableForElementsQuery($criteria);

			if ($contentTable)
			{
				$contentCols = 'content.id AS contentId';

				if ($elementType->hasTitles())
				{
					$contentCols .= ', content.title';
				}

				$fields = $elementType->getFieldsForElementsQuery($criteria);

				foreach ($fields as $field)
				{
					if ($field->hasContentColumn())
					{
						$columnPrefix = $field->columnPrefix ? $field->columnPrefix : 'field_';
						$contentCols .= ', content.'.$columnPrefix.$field->handle;
					}
				}

				$query->addSelect($contentCols);
				$query->innerJoin($contentTable.' content', 'content.elementId = elements.id');
				$query->andWhere('content.locale = :locale');
			}
		}

		// Basic element params
		// ---------------------------------------------------------------------

		// If the 'id' parameter is set to any empty value besides `null`, don't return anything
		if ($criteria->id !== null && empty($criteria->id))
		{
			return false;
		}

		if ($criteria->id)
		{
			$query->andWhere(DbHelper::parseParam('elements.id', $criteria->id, $query->params));
		}

		if ($criteria->archived)
		{
			$query->andWhere('elements.archived = 1');
		}
		else
		{
			$query->andWhere('elements.archived = 0');

			if ($criteria->status)
			{
				$statusConditions = [];
				$statuses = ArrayHelper::toArray($criteria->status);

				foreach ($statuses as $status)
				{
					$status = StringHelper::toLowerCase($status);

					// Is this a supported status?
					if (in_array($status, array_keys($elementType->getStatuses())))
					{
						if ($status == BaseElementModel::ENABLED)
						{
							$statusConditions[] = 'elements.enabled = 1';
						}
						else if ($status == BaseElementModel::DISABLED)
						{
							$statusConditions[] = 'elements.enabled = 0';
						}
						else
						{
							$elementStatusCondition = $elementType->getElementQueryStatusCondition($query, $status);

							if ($elementStatusCondition)
							{
								$statusConditions[] = $elementStatusCondition;
							}
							else if ($elementStatusCondition === false)
							{
								return false;
							}
						}
					}
				}

				if ($statusConditions)
				{
					if (count($statusConditions) == 1)
					{
						$statusCondition = $statusConditions[0];
					}
					else
					{
						array_unshift($statusConditions, 'or');
						$statusCondition = $statusConditions;
					}

					$query->andWhere($statusCondition);
				}
			}
		}

		if ($criteria->dateCreated)
		{
			$query->andWhere(DbHelper::parseDateParam('elements.dateCreated', $criteria->dateCreated, $query->params));
		}

		if ($criteria->dateUpdated)
		{
			$query->andWhere(DbHelper::parseDateParam('elements.dateUpdated', $criteria->dateUpdated, $query->params));
		}

		if ($elementType->hasTitles() && $criteria->title)
		{
			$query->andWhere(DbHelper::parseParam('content.title', $criteria->title, $query->params));
		}

		// i18n params
		// ---------------------------------------------------------------------

		if ($criteria->slug)
		{
			$query->andWhere(DbHelper::parseParam('elements_i18n.slug', $criteria->slug, $query->params));
		}

		if ($criteria->uri)
		{
			$query->andWhere(DbHelper::parseParam('elements_i18n.uri', $criteria->uri, $query->params));
		}

		if ($criteria->localeEnabled)
		{
			$query->andWhere('elements_i18n.enabled = 1');
		}

		// Relational params
		// ---------------------------------------------------------------------

		// Convert the old childOf and parentOf params to the relatedTo param
		// childOf(element)  => relatedTo({ source: element })
		// parentOf(element) => relatedTo({ target: element })

		// TODO: Remove this code in Craft 4
		if (!$criteria->relatedTo && ($criteria->childOf || $criteria->parentOf))
		{
			$relatedTo = ['and'];

			if ($criteria->childOf)
			{
				$relatedTo[] = ['sourceElement' => $criteria->childOf, 'field' => $criteria->childField];
			}

			if ($criteria->parentOf)
			{
				$relatedTo[] = ['targetElement' => $criteria->parentOf, 'field' => $criteria->parentField];
			}

			$criteria->relatedTo = $relatedTo;

			Craft::$app->deprecator->log('element_old_relation_params', 'The ‘childOf’, ‘childField’, ‘parentOf’, and ‘parentField’ element params have been deprecated. Use ‘relatedTo’ instead.');
		}

		if ($criteria->relatedTo)
		{
			$relationParamParser = new ElementRelationParamParser();
			$relConditions = $relationParamParser->parseRelationParam($criteria->relatedTo, $query);

			if ($relConditions === false)
			{
				return false;
			}

			$query->andWhere($relConditions);

			// If there's only one relation criteria and it's specifically for grabbing target elements, allow the query
			// to order by the relation sort order
			if ($relationParamParser->isRelationFieldQuery())
			{
				$query->addSelect('sources1.sortOrder');
			}
		}

		// Give field types a chance to make changes
		// ---------------------------------------------------------------------

		if ($elementType->hasContent() && $contentTable)
		{
			$contentService = Craft::$app->content;
			$originalFieldColumnPrefix = $contentService->fieldColumnPrefix;
			$extraCriteriaAttributes = $criteria->getExtraAttributeNames();

			foreach ($fields as $field)
			{
				$fieldType = $field->getFieldType();

				if ($fieldType)
				{
					// Was this field's parameter set on the criteria model?
					if (in_array($field->handle, $extraCriteriaAttributes))
					{
						$fieldCriteria = $criteria->{$field->handle};
					}
					else
					{
						$fieldCriteria = null;
					}

					// Set the field's column prefix on the Content service.
					if ($field->columnPrefix)
					{
						$contentService->fieldColumnPrefix = $field->columnPrefix;
					}

					$fieldTypeResponse = $fieldType->modifyElementsQuery($query, $fieldCriteria);

					// Set it back
					$contentService->fieldColumnPrefix = $originalFieldColumnPrefix;

					// Need to bail early?
					if ($fieldTypeResponse === false)
					{
						return false;
					}
				}
			}
		}

		// Give the element type a chance to make changes
		// ---------------------------------------------------------------------

		if ($elementType->modifyElementsQuery($query, $criteria) === false)
		{
			return false;
		}

		// Structure params
		// ---------------------------------------------------------------------

		if ($query->isJoined('structureelements'))
		{
			$query->addSelect('structureelements.root, structureelements.lft, structureelements.rgt, structureelements.level');

			if ($criteria->ancestorOf)
			{
				if (!$criteria->ancestorOf instanceof BaseElementModel)
				{
					$criteria->ancestorOf = Craft::$app->elements->getElementById($criteria->ancestorOf, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->ancestorOf)
					{
						return false;
					}
				}

				if ($criteria->ancestorOf)
				{
					$query->andWhere(
						['and',
							'structureelements.lft < :ancestorOf_lft',
							'structureelements.rgt > :ancestorOf_rgt',
							'structureelements.root = :ancestorOf_root'
						],
						[
							':ancestorOf_lft'  => $criteria->ancestorOf->lft,
							':ancestorOf_rgt'  => $criteria->ancestorOf->rgt,
							':ancestorOf_root' => $criteria->ancestorOf->root
						]
					);

					if ($criteria->ancestorDist)
					{
						$query->andWhere('structureelements.level >= :ancestorOf_level',
							[':ancestorOf_level' => $criteria->ancestorOf->level - $criteria->ancestorDist]
						);
					}
				}
			}

			if ($criteria->descendantOf)
			{
				if (!$criteria->descendantOf instanceof BaseElementModel)
				{
					$criteria->descendantOf = Craft::$app->elements->getElementById($criteria->descendantOf, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->descendantOf)
					{
						return false;
					}
				}

				if ($criteria->descendantOf)
				{
					$query->andWhere(
						['and',
							'structureelements.lft > :descendantOf_lft',
							'structureelements.rgt < :descendantOf_rgt',
							'structureelements.root = :descendantOf_root'
						],
						[
							':descendantOf_lft'  => $criteria->descendantOf->lft,
							':descendantOf_rgt'  => $criteria->descendantOf->rgt,
							':descendantOf_root' => $criteria->descendantOf->root
						]
					);

					if ($criteria->descendantDist)
					{
						$query->andWhere('structureelements.level <= :descendantOf_level',
							[':descendantOf_level' => $criteria->descendantOf->level + $criteria->descendantDist]
						);
					}
				}
			}

			if ($criteria->siblingOf)
			{
				if (!$criteria->siblingOf instanceof BaseElementModel)
				{
					$criteria->siblingOf = Craft::$app->elements->getElementById($criteria->siblingOf, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->siblingOf)
					{
						return false;
					}
				}

				if ($criteria->siblingOf)
				{
					$query->andWhere(
						['and',
							'structureelements.level = :siblingOf_level',
							'structureelements.root = :siblingOf_root',
							'structureelements.elementId != :siblingOf_elementId'
						],
						[
							':siblingOf_level'     => $criteria->siblingOf->level,
							':siblingOf_root'      => $criteria->siblingOf->root,
							':siblingOf_elementId' => $criteria->siblingOf->id
						]
					);

					if ($criteria->siblingOf->level != 1)
					{
						$parent = $criteria->siblingOf->getParent();

						if ($parent)
						{
							$query->andWhere(
								['and',
									'structureelements.lft > :siblingOf_lft',
									'structureelements.rgt < :siblingOf_rgt'
								],
								[
									':siblingOf_lft'  => $parent->lft,
									':siblingOf_rgt'  => $parent->rgt
								]
							);
						}
						else
						{
							return false;
						}
					}
				}
			}

			if ($criteria->prevSiblingOf)
			{
				if (!$criteria->prevSiblingOf instanceof BaseElementModel)
				{
					$criteria->prevSiblingOf = Craft::$app->elements->getElementById($criteria->prevSiblingOf, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->prevSiblingOf)
					{
						return false;
					}
				}

				if ($criteria->prevSiblingOf)
				{
					$query->andWhere(
						['and',
							'structureelements.level = :prevSiblingOf_level',
							'structureelements.rgt = :prevSiblingOf_rgt',
							'structureelements.root = :prevSiblingOf_root'
						],
						[
							':prevSiblingOf_level' => $criteria->prevSiblingOf->level,
							':prevSiblingOf_rgt'   => $criteria->prevSiblingOf->lft - 1,
							':prevSiblingOf_root'  => $criteria->prevSiblingOf->root
						]
					);
				}
			}

			if ($criteria->nextSiblingOf)
			{
				if (!$criteria->nextSiblingOf instanceof BaseElementModel)
				{
					$criteria->nextSiblingOf = Craft::$app->elements->getElementById($criteria->nextSiblingOf, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->nextSiblingOf)
					{
						return false;
					}
				}

				if ($criteria->nextSiblingOf)
				{
					$query->andWhere(
						['and',
							'structureelements.level = :nextSiblingOf_level',
							'structureelements.lft = :nextSiblingOf_lft',
							'structureelements.root = :nextSiblingOf_root'
						],
						[
							':nextSiblingOf_level' => $criteria->nextSiblingOf->level,
							':nextSiblingOf_lft'   => $criteria->nextSiblingOf->rgt + 1,
							':nextSiblingOf_root'  => $criteria->nextSiblingOf->root
						]
					);
				}
			}

			if ($criteria->positionedBefore)
			{
				if (!$criteria->positionedBefore instanceof BaseElementModel)
				{
					$criteria->positionedBefore = Craft::$app->elements->getElementById($criteria->positionedBefore, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->positionedBefore)
					{
						return false;
					}
				}

				if ($criteria->positionedBefore)
				{
					$query->andWhere(
						['and',
							'structureelements.rgt < :positionedBefore_rgt',
							'structureelements.root = :positionedBefore_root'
						],
						[
							':positionedBefore_rgt'   => $criteria->positionedBefore->lft,
							':positionedBefore_root'  => $criteria->positionedBefore->root
						]
					);
				}
			}

			if ($criteria->positionedAfter)
			{
				if (!$criteria->positionedAfter instanceof BaseElementModel)
				{
					$criteria->positionedAfter = Craft::$app->elements->getElementById($criteria->positionedAfter, $elementType->getClassHandle(), $criteria->locale);

					if (!$criteria->positionedAfter)
					{
						return false;
					}
				}

				if ($criteria->positionedAfter)
				{
					$query->andWhere(
						['and',
							'structureelements.lft > :positionedAfter_lft',
							'structureelements.root = :positionedAfter_root'
						],
						[
							':positionedAfter_lft'   => $criteria->positionedAfter->rgt,
							':positionedAfter_root'  => $criteria->positionedAfter->root
						]
					);
				}
			}

			// TODO: Remove this code in Craft 4
			if (!$criteria->level && $criteria->depth)
			{
				$criteria->level = $criteria->depth;
				$criteria->depth = null;
				Craft::$app->deprecator->log('element_depth_param', 'The ‘depth’ element param has been deprecated. Use ‘level’ instead.');
			}

			if ($criteria->level)
			{
				$query->andWhere(DbHelper::parseParam('structureelements.level', $criteria->level, $query->params));
			}
		}

		// Search
		// ---------------------------------------------------------------------

		if ($criteria->search)
		{
			$elementIds = $this->_getElementIdsFromQuery($query);
			$scoredSearchResults = ($criteria->order == 'score');
			$filteredElementIds = Craft::$app->search->filterElementIdsByQuery($elementIds, $criteria->search, $scoredSearchResults);

			// No results?
			if (!$filteredElementIds)
			{
				return [];
			}

			$query->andWhere(['in', 'elements.id', $filteredElementIds]);

			if ($scoredSearchResults)
			{
				// Order the elements in the exact order that the Search service returned them in
				$query->orderBy(new FixedOrderExpression('elements.id', $filteredElementIds));
			}
		}

		return $query;
	}

	/**
	 * Returns an element’s URI for a given locale.
	 *
	 * @param int    $elementId The element’s ID.
	 * @param string $localeId  The locale to search for the element’s URI in.
	 *
	 * @return string|null The element’s URI, or `null`.
	 */
	public function getElementUriForLocale($elementId, $localeId)
	{
		return (new Query())
			->select('uri')
			->from('{{%elements_i18n}}')
			->where(['elementId' => $elementId, 'locale' => $localeId])
			->scalar();
	}

	/**
	 * Returns the locales that a given element is enabled in.
	 *
	 * @param int $elementId The element’s ID.
	 *
	 * @return array The locales that the element is enabled in. If the element could not be found, an empty array
	 *               will be returned.
	 */
	public function getEnabledLocalesForElement($elementId)
	{
		return (new Query())
			->select('locale')
			->from('{{%elements_i18n}}')
			->where(['elementId' => $elementId, 'enabled' => 1])
			->column();
	}

	// Saving Elements
	// -------------------------------------------------------------------------

	/**
	 * Handles all of the routine tasks that go along with saving elements.
	 *
	 * Those tasks include:
	 *
	 * - Validating its content (if $validateContent is `true`, or it’s left as `null` and the element is enabled)
	 * - Ensuring the element has a title if its type [[BaseElementType::hasTitles() has titles]], and giving it a
	 *   default title in the event that $validateContent is set to `false`
	 * - Saving a row in the `elements` table
	 * - Assigning the element’s ID on the element model, if it’s a new element
	 * - Assigning the element’s ID on the element’s content model, if there is one and it’s a new set of content
	 * - Updating the search index with new keywords from the element’s content
	 * - Setting a unique URI on the element, if it’s supposed to have one.
	 * - Saving the element’s row(s) in the `elements_i18n` and `content` tables
	 * - Deleting any rows in the `elements_i18n` and `content` tables that no longer need to be there
	 * - Calling the field types’ [[BaseFieldType::onAfterElementSave() onAfterElementSave()]] methods
	 * - Cleaing any template caches that the element was involved in
	 *
	 * This method should be called by a service’s “saveX()” method, _after_ it is done validating any attributes on
	 * the element that are of particular concern to its element type. For example, if the element were an entry,
	 * saveElement() should be called only after the entry’s sectionId and typeId attributes had been validated to
	 * ensure that they point to valid section and entry type IDs.
	 *
	 * @param BaseElementModel $element         The element that is being saved
	 * @param bool|null        $validateContent Whether the element's content should be validated. If left 'null', it
	 *                                          will depend on whether the element is enabled or not.
	 *
	 * @throws Exception|\Exception
	 * @return bool
	 */
	public function saveElement(BaseElementModel $element, $validateContent = null)
	{
		$elementType = $this->getElementType($element->getElementType());

		$isNewElement = !$element->id;

		// Validate the content first
		if ($elementType->hasContent())
		{
			if ($validateContent === null)
			{
				$validateContent = (bool) $element->enabled;
			}

			if ($validateContent && !Craft::$app->content->validateContent($element))
			{
				$element->addErrors($element->getContent()->getErrors());
				return false;
			}
			else
			{
				// Make sure there's a title
				if ($elementType->hasTitles())
				{
					$fields = ['title'];
					$content = $element->getContent();
					$content->setRequiredFields($fields);

					if (!$content->validate($fields) && $content->hasErrors('title'))
					{
						// Just set *something* on it
						if ($isNewElement)
						{
							$content->title = 'New '.$element->getClassHandle();
						}
						else
						{
							$content->title = $element->getClassHandle().' '.$element->id;
						}
					}
				}
			}
		}

		// Get the element record
		if (!$isNewElement)
		{
			$elementRecord = ElementRecord::findOne([
				'id'   => $element->id,
				'type' => $element->getElementType()
			]);

			if (!$elementRecord)
			{
				throw new Exception(Craft::t('app', 'No element exists with the ID “{id}”.', ['id' => $element->id]));
			}
		}
		else
		{
			$elementRecord = new ElementRecord();
			$elementRecord->type = $element->getElementType();
		}

		// Set the attributes
		$elementRecord->enabled = (bool) $element->enabled;
		$elementRecord->archived = (bool) $element->archived;

		$transaction = Craft::$app->getDb()->getTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;

		try
		{
			// Fire a 'beforeSaveElement' event
			$event = new ElementEvent([
				'element' => $element
			]);

			$this->trigger(static::EVENT_BEFORE_SAVE_ELEMENT, $event);

			// Is the event giving us the go-ahead?
			if ($event->performAction)
			{
				// Save the element record first
				$success = $elementRecord->save(false);

				if ($success)
				{
					if ($isNewElement)
					{
						// Save the element id on the element model, in case {id} is in the URL format
						$element->id = $elementRecord->id;

						if ($elementType->hasContent())
						{
							$element->getContent()->elementId = $element->id;
						}
					}

					// Save the content
					if ($elementType->hasContent())
					{
						Craft::$app->content->saveContent($element, false, (bool)$element->id);
					}

					// Update the search index
					Craft::$app->search->indexElementAttributes($element);

					// Update the locale records and content

					// We're saving all of the element's locales here to ensure that they all exist and to update the URI in
					// the event that the URL format includes some value that just changed

					$localeRecords = [];

					if (!$isNewElement)
					{
						$existingLocaleRecords = ElementLocaleRecord::findAll([
							'elementId' => $element->id
						]);

						foreach ($existingLocaleRecords as $record)
						{
							$localeRecords[$record->locale] = $record;
						}
					}

					$mainLocaleId = $element->locale;

					$locales = $element->getLocales();
					$localeIds = [];

					if (!$locales)
					{
						throw new Exception('All elements must have at least one locale associated with them.');
					}

					foreach ($locales as $localeId => $localeInfo)
					{
						if (is_numeric($localeId) && is_string($localeInfo))
						{
							$localeId = $localeInfo;
							$localeInfo = [];
						}

						$localeIds[] = $localeId;

						if (!isset($localeInfo['enabledByDefault']))
						{
							$localeInfo['enabledByDefault'] = true;
						}

						if (isset($localeRecords[$localeId]))
						{
							$localeRecord = $localeRecords[$localeId];
						}
						else
						{
							$localeRecord = new ElementLocaleRecord();

							$localeRecord->elementId = $element->id;
							$localeRecord->locale = $localeId;
							$localeRecord->enabled = $localeInfo['enabledByDefault'];
						}

						// Is this the main locale?
						$isMainLocale = ($localeId == $mainLocaleId);

						if ($isMainLocale)
						{
							$localizedElement = $element;
						}
						else
						{
							// Copy the element for this locale
							$localizedElement = $element->copy();
							$localizedElement->locale = $localeId;

							if ($localeRecord->id)
							{
								// Keep the original slug
								$localizedElement->slug = $localeRecord->slug;
							}
							else
							{
								// Default to the main locale's slug
								$localizedElement->slug = $element->slug;
							}
						}

						if ($elementType->hasContent())
						{
							if (!$isMainLocale)
							{
								$content = null;

								if (!$isNewElement)
								{
									// Do we already have a content row for this locale?
									$content = Craft::$app->content->getContent($localizedElement);
								}

								if (!$content)
								{
									$content = Craft::$app->content->createContent($localizedElement);
									$content->setAttributes($element->getContent()->getAttributes());
									$content->id = null;
									$content->locale = $localeId;
								}

								$localizedElement->setContent($content);
							}

							if (!$localizedElement->getContent()->id)
							{
								Craft::$app->content->saveContent($localizedElement, false, false);
							}
						}

						// Capture the original slug, in case it's entirely composed of invalid characters
						$originalSlug = $localizedElement->slug;

						// Clean up the slug
						ElementHelper::setValidSlug($localizedElement);

						// If the slug was entirely composed of invalid characters, it will be blank now.
						if ($originalSlug && !$localizedElement->slug)
						{
							$localizedElement->slug = $originalSlug;
							$element->addError('slug', Craft::t('app', '{attribute} is invalid.', ['attribute' => Craft::t('app', 'Slug')]));

							// Don't bother with any of the other locales
							$success = false;
							break;
						}

						ElementHelper::setUniqueUri($localizedElement);

						$localeRecord->slug = $localizedElement->slug;
						$localeRecord->uri = $localizedElement->uri;

						if ($isMainLocale)
						{
							$localeRecord->enabled = (bool)$element->localeEnabled;
						}

						$success = $localeRecord->save();

						if (!$success)
						{
							// Pass any validation errors on to the element
							$element->addErrors($localeRecord->getErrors());

							// Don't bother with any of the other locales
							break;
						}
					}

					if ($success)
					{
						if (!$isNewElement)
						{
							// Delete the rows that don't need to be there anymore

							Craft::$app->getDb()->createCommand()->delete('{{%elements_i18n}}', ['and',
								'elementId = :elementId',
								['not in', 'locale', $localeIds]
							], [
								':elementId' => $element->id
							])->execute();

							if ($elementType->hasContent())
							{
								Craft::$app->getDb()->createCommand()->delete($element->getContentTable(), ['and',
									'elementId = :elementId',
									['not in', 'locale', $localeIds]
								], [
									':elementId' => $element->id
								])->execute();
							}
						}

						// Call the field types' onAfterElementSave() methods
						$fieldLayout = $element->getFieldLayout();

						if ($fieldLayout)
						{
							foreach ($fieldLayout->getFields() as $fieldLayoutField)
							{
								$field = $fieldLayoutField->getField();

								if ($field)
								{
									$fieldType = $field->getFieldType();

									if ($fieldType)
									{
										$fieldType->element = $element;
										$fieldType->onAfterElementSave();
									}
								}
							}
						}

						// Finally, delete any caches involving this element. (Even do this for new elements, since they
						// might pop up in a cached criteria.)
						Craft::$app->templateCache->deleteCachesByElement($element);
					}
				}
			}
			else
			{
				$success = false;
			}

			// Commit the transaction regardless of whether we saved the user, in case something changed
			// in onBeforeSaveElement
			if ($transaction !== null)
			{
				$transaction->commit();
			}
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}

		if ($success)
		{
			// Fire an 'afterSaveElement' event
			$this->trigger(static::EVENT_AFTER_SAVE_ELEMENT, new ElementEvent([
				'element'      => $element,
				'isNewElement' => $isNewElement
			]));
		}
		else
		{
			if ($isNewElement)
			{
				$element->id = null;

				if ($elementType->hasContent())
				{
					$element->getContent()->id = null;
					$element->getContent()->elementId = null;
				}
			}
		}

		return $success;
	}

	/**
	 * Updates an element’s slug and URI, along with any descendants.
	 *
	 * @param BaseElementModel $element            The element to update.
	 * @param bool             $updateOtherLocales Whether the element’s other locales should also be updated.
	 * @param bool             $updateDescendants  Whether the element’s descendants should also be updated.
	 *
	 * @return null
	 */
	public function updateElementSlugAndUri(BaseElementModel $element, $updateOtherLocales = true, $updateDescendants = true)
	{
		ElementHelper::setUniqueUri($element);

		Craft::$app->getDb()->createCommand()->update('{{%elements_i18n}}', [
			'slug' => $element->slug,
			'uri'  => $element->uri
		], [
			'elementId' => $element->id,
			'locale'    => $element->locale
		])->execute();

		// Delete any caches involving this element
		Craft::$app->templateCache->deleteCachesByElement($element);

		if ($updateOtherLocales)
		{
			$this->updateElementSlugAndUriInOtherLocales($element);
		}

		if ($updateDescendants)
		{
			$this->updateDescendantSlugsAndUris($element);
		}
	}

	/**
	 * Updates an element’s slug and URI, for any locales besides the given one.
	 *
	 * @param BaseElementModel $element The element to update.
	 *
	 * @return null
	 */
	public function updateElementSlugAndUriInOtherLocales(BaseElementModel $element)
	{
		foreach (Craft::$app->getI18n()->getSiteLocaleIds() as $localeId)
		{
			if ($localeId == $element->locale)
			{
				continue;
			}

			$elementInOtherLocale = $this->getElementById($element->id, $element->getElementType(), $localeId);

			if ($elementInOtherLocale)
			{
				$this->updateElementSlugAndUri($elementInOtherLocale, false, false);
			}
		}
	}

	/**
	 * Updates an element’s descendants’ slugs and URIs.
	 *
	 * @param BaseElementModel $element The element whose descendants should be updated.
	 *
	 * @return null
	 */
	public function updateDescendantSlugsAndUris(BaseElementModel $element)
	{
		$criteria = $this->getCriteria($element->getElementType());
		$criteria->descendantOf = $element;
		$criteria->descendantDist = 1;
		$criteria->status = null;
		$criteria->localeEnabled = null;
		$children = $criteria->find();

		foreach ($children as $child)
		{
			$this->updateElementSlugAndUri($child);
		}
	}

	/**
	 * Merges two elements together.
	 *
	 * This method will update the following:
	 *
	 * - Any relations involving the merged element
	 * - Any structures that contain the merged element
	 * - Any reference tags in textual custom fields referencing the merged element
	 *
	 * @param int $mergedElementId     The ID of the element that is going away.
	 * @param int $prevailingElementId The ID of the element that is sticking around.
	 *
	 * @throws \Exception
	 * @return bool Whether the elements were merged successfully.
	 */
	public function mergeElementsByIds($mergedElementId, $prevailingElementId)
	{
		$transaction = Craft::$app->getDb()->getTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;
		try
		{
			// Update any relations that point to the merged element
			$relations = (new Query())
				->select(['id', 'fieldId', 'sourceId', 'sourceLocale'])
				->from('{{%relations}}')
				->where(['targetId' => $mergedElementId])
				->all();

			foreach ($relations as $relation)
			{
				// Make sure the persisting element isn't already selected in the same field
				$persistingElementIsRelatedToo = (new Query())
					->from('{{%relations}}')
					->where([
						'fieldId'      => $relation['fieldId'],
						'sourceId'     => $relation['sourceId'],
						'sourceLocale' => $relation['sourceLocale'],
						'targetId'     => $prevailingElementId
					])
					->exists();

				if (!$persistingElementIsRelatedToo)
				{
					Craft::$app->getDb()->createCommand()->update('{{%relations}}', [
						'targetId' => $prevailingElementId
					], [
						'id' => $relation['id']
					])->execute();
				}
			}

			// Update any structures that the merged element is in
			$structureElements = (new Query())
				->select(['id', 'structureId'])
				->from('{{%structureelements}}')
				->where(['elementId' => $mergedElementId])
				->all();

			foreach ($structureElements as $structureElement)
			{
				// Make sure the persisting element isn't already a part of that structure
				$persistingElementIsInStructureToo = (new Query())
					->from('{{%structureElements}}')
					->where([
						'structureId' => $structureElement['structureId'],
						'elementId' => $prevailingElementId
					])
					->exists();

				if (!$persistingElementIsInStructureToo)
				{
					Craft::$app->getDb()->createCommand()->update('{{%relations}}', [
						'elementId' => $prevailingElementId
					], [
						'id' => $structureElement['id']
					])->execute();
				}
			}

			// Update any reference tags
			$elementType = $this->getElementTypeById($prevailingElementId);

			if ($elementType)
			{
				$refTagPrefix = '{'.lcfirst($elementType).':';

				Craft::$app->tasks->createTask('FindAndReplace', Craft::t('app', 'Updating element references'), [
					'find'    => $refTagPrefix.$mergedElementId.':',
					'replace' => $refTagPrefix.$prevailingElementId.':',
				]);

				Craft::$app->tasks->createTask('FindAndReplace', Craft::t('app', 'Updating element references'), [
					'find'    => $refTagPrefix.$mergedElementId.'}',
					'replace' => $refTagPrefix.$prevailingElementId.'}',
				]);
			}

			// Fire an 'afterMergeElements' event
			$this->trigger(static::EVENT_AFTER_MERGE_ELEMENTS, new MergeElementEvent([
				'mergedElementId'     => $mergedElementId,
				'prevailingElementId' => $prevailingElementId
			]));

			// Now delete the merged element
			$success = $this->deleteElementById($mergedElementId);

			if ($transaction !== null)
			{
				$transaction->commit();
			}

			return $success;
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Deletes an element(s) by its ID(s).
	 *
	 * @param int|array $elementIds The element’s ID, or an array of elements’ IDs.
	 *
	 * @throws \Exception
	 * @return bool Whether the element(s) were deleted successfully.
	 */
	public function deleteElementById($elementIds)
	{
		if (!$elementIds)
		{
			return false;
		}

		if (!is_array($elementIds))
		{
			$elementIds = [$elementIds];
		}

		$transaction = Craft::$app->getDb()->getTransaction() === null ? Craft::$app->getDb()->beginTransaction() : null;

		try
		{
			// Fire a 'beforeDeleteElements' event
			$this->trigger(static::EVENT_BEFORE_DELETE_ELEMENTS, new DeleteElementsEvent([
				'elementIds' => $elementIds
			]));

			// First delete any structure nodes with these elements, so NestedSetBehavior can do its thing. We need to
			// go one-by-one in case one of theme deletes the record of another in the process.
			foreach ($elementIds as $elementId)
			{
				$records = StructureElementRecord::findAll([
					'elementId' => $elementId
				]);

				foreach ($records as $record)
				{
					// If this element still has any children, move them up before the one getting deleted.
					$children = $record->children()->findAll();

					foreach ($children as $child)
					{
						$child->moveBefore($record);
					}

					// Delete this element's node
					$record->deleteNode();
				}
			}

			// Delete the caches before they drop their elementId relations (passing `false` because there's no chance
			// this element is suddenly going to show up in a new query)
			Craft::$app->templateCache->deleteCachesByElementId($elementIds, false);

			// Now delete the rows in the elements table
			if (count($elementIds) == 1)
			{
				$condition = ['id' => $elementIds[0]];
				$matrixBlockCondition = ['ownerId' => $elementIds[0]];
				$searchIndexCondition = ['elementId' => $elementIds[0]];
			}
			else
			{
				$condition = ['in', 'id', $elementIds];
				$matrixBlockCondition = ['in', 'ownerId', $elementIds];
				$searchIndexCondition = ['in', 'elementId', $elementIds];
			}

			// First delete any Matrix blocks that belong to this element(s)
			$matrixBlockIds = (new Query())
				->select('id')
				->from('{{%matrixblocks}}')
				->where($matrixBlockCondition)
				->column();

			if ($matrixBlockIds)
			{
				Craft::$app->matrix->deleteBlockById($matrixBlockIds);
			}

			// Delete the elements table rows, which will cascade across all other InnoDB tables
			$affectedRows = Craft::$app->getDb()->createCommand()->delete('{{%elements}}', $condition)->execute();

			// The searchindex table is MyISAM, though
			Craft::$app->getDb()->createCommand()->delete('{{%searchindex}}', $searchIndexCondition)->execute();

			if ($transaction !== null)
			{
				$transaction->commit();
			}

			return (bool) $affectedRows;
		}
		catch (\Exception $e)
		{
			if ($transaction !== null)
			{
				$transaction->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Deletes elements by a given type.
	 *
	 * @param string $type The element type class handle.
	 *
	 * @return bool Whether the elements were deleted successfully.
	 */
	public function deleteElementsByType($type)
	{
		// Get the IDs and let deleteElementById() take care of the actual deletion
		$elementIds = (new Query())
			->select('id')
			->from('{{%elements}}')
			->where('type = :type', [':type' => $type])
			->column();

		if ($elementIds)
		{
			$this->deleteElementById($elementIds);

			// Delete the template caches
			Craft::$app->templateCache->deleteCachesByElementType($type);
		}
	}

	// Element types
	// -------------------------------------------------------------------------

	/**
	 * Returns all installed element types.
	 *
	 * @return ElementTypeInterface[] The installed element types.
	 */
	public function getAllElementTypes()
	{
		return Craft::$app->components->getComponentsByType(ComponentType::Element);
	}

	/**
	 * Returns an element type by its class handle.
	 *
	 * @param string $class The element type class handle.
	 *
	 * @return ElementTypeInterface|null The element type, or `null`.
	 */
	public function getElementType($class)
	{
		return Craft::$app->components->getComponentByTypeAndClass(ComponentType::Element, $class);
	}

	// Element Actions
	// -------------------------------------------------------------------------

	/**
	 * Returns all installed element actions.
	 *
	 * @return ElementActionInterface[] The installed element actions.
	 */
	public function getAllActions()
	{
		return Craft::$app->components->getComponentsByType(ComponentType::ElementAction);
	}

	/**
	 * Returns an element action by its class handle.
	 *
	 * @param string $class The element action class handle.
	 *
	 * @return ElementTypeInterface|null The element action, or `null`.
	 */
	public function getAction($class)
	{
		return Craft::$app->components->getComponentByTypeAndClass(ComponentType::ElementAction, $class);
	}

	// Misc
	// -------------------------------------------------------------------------

	/**
	 * Parses a string for element [reference tags](http://buildwithcraft.com/docs/reference-tags).
	 *
	 * @param string $str The string to parse.
	 *
	 * @return string The parsed string.
	 */
	public function parseRefs($str)
	{
		if (StringHelper::contains($str, '{'))
		{
			global $refTagsByElementType;
			$refTagsByElementType = [];

			$str = preg_replace_callback('/\{(\w+)\:([^\:\}]+)(?:\:([^\:\}]+))?\}/', function($matches)
			{
				global $refTagsByElementType;

				$elementTypeHandle = ucfirst($matches[1]);
				$token = '{'.StringHelper::randomString(9).'}';

				$refTagsByElementType[$elementTypeHandle][] = ['token' => $token, 'matches' => $matches];

				return $token;
			}, $str);

			if ($refTagsByElementType)
			{
				$search = [];
				$replace = [];

				$things = ['id', 'ref'];

				foreach ($refTagsByElementType as $elementTypeHandle => $refTags)
				{
					$elementType = Craft::$app->elements->getElementType($elementTypeHandle);

					if (!$elementType)
					{
						// Just put the ref tags back the way they were
						foreach ($refTags as $refTag)
						{
							$search[] = $refTag['token'];
							$replace[] = $refTag['matches'][0];
						}
					}
					else
					{
						$refTagsById  = [];
						$refTagsByRef = [];

						foreach ($refTags as $refTag)
						{
							// Searching by ID?
							if (is_numeric($refTag['matches'][2]))
							{
								$refTagsById[$refTag['matches'][2]][] = $refTag;
							}
							else
							{
								$refTagsByRef[$refTag['matches'][2]][] = $refTag;
							}
						}

						// Things are about to get silly...
						foreach ($things as $thing)
						{
							$refTagsByThing = ${'refTagsBy'.ucfirst($thing)};

							if ($refTagsByThing)
							{
								$criteria = Craft::$app->elements->getCriteria($elementTypeHandle);
								$criteria->status = null;
								$criteria->$thing = array_keys($refTagsByThing);
								$elements = $criteria->find();

								$elementsByThing = [];

								foreach ($elements as $element)
								{
									$elementsByThing[$element->$thing] = $element;
								}

								foreach ($refTagsByThing as $thingVal => $refTags)
								{
									if (isset($elementsByThing[$thingVal]))
									{
										$element = $elementsByThing[$thingVal];
									}
									else
									{
										$element = false;
									}

									foreach($refTags as $refTag)
									{
										$search[] = $refTag['token'];

										if ($element)
										{
											if (!empty($refTag['matches'][3]) && isset($element->{$refTag['matches'][3]}))
											{
												$value = (string) $element->{$refTag['matches'][3]};
												$replace[] = $this->parseRefs($value);
											}
											else
											{
												// Default to the URL
												$replace[] = $element->getUrl();
											}
										}
										else
										{
											$replace[] = $refTag['matches'][0];
										}
									}
								}
							}
						}
					}
				}

				$str = str_replace($search, $replace, $str);
			}

			unset ($refTagsByElementType);
		}

		return $str;
	}

	/**
	 * Stores a placeholder element that [[findElements()]] should use instead of populating a new element with a
	 * matching ID and locale.
	 *
	 * This is used by Live Preview and Sharing features.
	 *
	 * @param BaseElementModel $element The element currently being edited by Live Preview.
	 *
	 * @return null
	 */
	public function setPlaceholderElement(BaseElementModel $element)
	{
		// Won't be able to do anything with this if it doesn't have an ID or locale
		if (!$element->id || !$element->locale)
		{
			return;
		}

		$this->_placeholderElements[$element->id][$element->locale] = $element;
	}

	// Private Methods
	// =========================================================================

	/**
	 * Returns the unique element IDs that match a given element query.
	 *
	 * @param Query $query
	 *
	 * @return array
	 */
	private function _getElementIdsFromQuery(Query $query)
	{
		// Get the matched element IDs, and then have the Search service filter them.
		$elementIdsQuery = (new Query())
			->select(['elements.id'])
			->from('{{%elements}} elements')
			->groupBy('elements.id');

		$elementIdsQuery->where = $query->where;
		$elementIdsQuery->join = $query->join;

		$elementIdsQuery->params = $query->params;
		return $elementIdsQuery->column();
	}
}
