<?php

namespace Icinga\Module\Director\Objects;

use Icinga\Exception\ProgrammingError;
use Iterator;
use Countable;
use Icinga\Module\Director\IcingaConfig\IcingaConfigRenderer;
use Icinga\Module\Director\IcingaConfig\IcingaConfigHelper as c;

class IcingaObjectMultiRelations implements Iterator, Countable, IcingaConfigRenderer
{
    protected $stored = array();

    protected $relations = array();

    protected $modified = false;

    protected $object;

    protected $propertyName;

    protected $relatedObjectClass;

    protected $relatedTableName;

    protected $relationIdColumn;

    private $position = 0;

    private $db;

    protected $idx = array();

    public function __construct(IcingaObject $object, $propertyName, $relatedObjectClass)
    {
        $this->object = $object;
        $this->propertyName = $propertyName;
        $this->relatedObjectClass = $relatedObjectClass;
    }

    public function count()
    {
        return count($this->relations);
    }

    public function rewind()
    {
        $this->position = 0;
    }

    public function hasBeenModified()
    {
        return $this->modified;
    }

    public function current()
    {
        if (! $this->valid()) {
            return null;
        }

        return $this->relations[$this->idx[$this->position]];
    }

    public function key()
    {
        return $this->idx[$this->position];
    }

    public function next()
    {
        ++$this->position;
    }

    public function valid()
    {
        return array_key_exists($this->position, $this->idx);
    }

    public function get($key)
    {
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        return null;
    }

    public function set($relation)
    {
        if (! is_array($relation)) {
            $relation = array($relation);
        }

        $existing = array_keys($this->relations);
        $new = array();
        $class = $this->getRelatedClassName();
        $unset = array();

        foreach ($relation as $k => $g) {

            if ($g instanceof $class) {
                $new[] = $g->object_name;
            } else {
                if (empty($g)) {
                    $unset[] = $k;
                    continue;
                }

                $new[] = $g;
            }
        }

        foreach ($unset as $k) {
            unset($relation[$k]);
        }

        sort($existing);
        sort($new);
        if ($existing === $new) {
            return $this;
        }

        $this->relations = array();
        if (empty($relation)) {
            $this->modified = true;
            $this->refreshIndex();
            return $this;
        }

        return $this->add($relation);
    }

    /**
     * Magic isset check
     *
     * @return boolean
     */
    public function __isset($relation)
    {
        return array_key_exists($relation, $this->relations);
    }

    public function remove($relation)
    {
        if (array_key_exists($relation, $this->relations)) {
            unset($this->relations[$relation]);
        }

        $this->modified = true;
        $this->refreshIndex();
    }

    protected function refreshIndex()
    {
        ksort($this->relations);
        $this->idx = array_keys($this->relations);
    }

    public function add($relation, $onError = 'fail')
    {
        // TODO: only one query when adding array
        if (is_array($relation)) {
            foreach ($relation as $r) {
                $this->add($r, $onError);
            }
            return $this;
        }

        if (array_key_exists($relation, $this->relations)) {
            return $this;
        }

        $class = $this->getRelatedClassName();

        if ($relation instanceof $class) {
            $this->relations[$relation->object_name] = $relation;

        } elseif (is_string($relation)) {

            $connection = $this->object->getConnection();
            // TODO: fix this, prefetch or whatever - this is expensive
            $query = $this->getDb()->select()->from(
                $this->getRelatedTableName()
            )->where('object_name = ?', $relation);
            $relations = $class::loadAll($connection, $query, 'object_name');

            if (! array_key_exists($relation, $relations)) {
                switch ($onError) {
                    case 'autocreate':
                        $relations[$relation] = $class::create(array(
                            'object_type' => 'object',
                            'object_name' => $relation
                        ));
                        $relations[$relation]->store($connection);
                        // TODO
                    case 'fail':
                        throw new ProgrammingError(
                            'The related %s "%s" doesn\'t exists.',
                            $this->getRelatedTableName(),
                            $relation
                        );
                        break;
                    case 'ignore':
                        return $this;
                }
            }
        } else {
            throw new ProgrammingError(
                'Invalid related object: %s',
                var_export($relation, 1)
            );
        }

        $this->relations[$relation] = $relations[$relation];
        $this->modified = true;
        $this->refreshIndex();

        return $this;
    }

    protected function getPropertyName()
    {
        return $this->propertyName;
    }

    protected function getTableName()
    {
        $class = $this->getRelatedClassName();
        return $this->object->getTableName() . '_' . $class::create()->getShortTableName();
    }

    protected function getRelatedTableName()
    {
        if ($this->relatedTableName === null) {
            $class = $this->getRelatedClassName();
            $this->relatedTableName = $class::create()->getTableName();
        }

        return $this->relatedTableName;
    }

    protected function getRelationIdColumn()
    {
        if ($this->relationIdColumn === null) {
            $class = $this->getRelatedClassName();
            $this->relationIdColumn = $class::create()->getShortTableName();
        }

        return $this->relationIdColumn;
    }

    public function listRelatedNames()
    {
        return array_keys($this->relations);
    }

    public function listOriginalNames()
    {
        return array_keys($this->stored);
    }

    public function getType()
    {
        return $this->object->getShortTableName();
    }

    protected function loadFromDb()
    {
        $db = $this->getDb();
        $connection = $this->object->getConnection();

        $type = $this->getType();
        $objectIdCol = $type . '_id';
        $relationIdCol = $this->getRelationIdColumn() . '_id';

        $query = $db->select()->from(
            array('r' => $this->getTableName()),
            array()
        )->join(
            array('ro' => $this->getRelatedTableName()),
            sprintf('r.%s = ro.id', $relationIdCol),
            '*'
        )->where(
            sprintf('r.%s = ?', $objectIdCol),
            (int) $this->object->id
        )->order('ro.object_name');

        $class = $this->getRelatedClassName();
        $this->relations = $class::loadAll($connection, $query, 'object_name');
        $this->cloneStored();

        return $this;
    }

    public function store()
    {
        $db = $this->getDb();

        $stored = array_keys($this->stored);
        $relations = array_keys($this->relations);

        $objectId = $this->object->id;
        $type = $this->getType();

        $type = $this->getType();
        $objectCol = $type . '_id';
        $relationCol = $this->getRelationIdColumn() . '_id';

        $toDelete = array_diff($stored, $relations);
        foreach ($toDelete as $relation) {
            $where = sprintf(
                $objectCol . ' = %d AND ' . $relationCol . ' = %d',
                $objectId,
                $this->stored[$relation]->id
            );

            $db->delete(
                $this->getTableName(),
                $where
            );
        }

        $toAdd = array_diff($relations, $stored);
        foreach ($toAdd as $related) {
            $db->insert(
                $this->getTableName(),
                array(
                    $objectCol => $objectId,
                    $relationCol => $this->relations[$related]->id
                )
            );
        }
        $this->cloneStored();

        return true;
    }

    protected function cloneStored()
    {
        $this->stored = array();
        foreach ($this->relations as $k => $v) {
            $this->stored[$k] = clone($v);
        }
    }

    protected function getRelatedClassName()
    {
        return __NAMESPACE__ . '\\' . $this->relatedObjectClass;
    }

    protected function getDb()
    {
        if ($this->db === null) {
            $this->db = $this->object->getDb();
        }

        return $this->db;
    }

    public static function loadForStoredObject(IcingaObject $object, $propertyName, $relatedObjectClass)
    {
        $relations = new static($object, $propertyName, $relatedObjectClass);
        return $relations->loadFromDb();
    }

    public function toConfigString()
    {
        $relations = array_keys($this->relations);

        if (empty($relations)) {
            return '';
        }

        return c::renderKeyValue($this->propertyName, c::renderArray($relations));
    }

    public function __toString()
    {
        try {
            return $this->toConfigString();
        } catch (Exception $e) {
            trigger_error($e);
            $previousHandler = set_exception_handler(
                function () {
                }
            );
            restore_error_handler();
            if ($previousHandler !== null) {
                call_user_func($previousHandler, $e);
                die();
            } else {
                die($e->getMessage());
            }
        }
    }
}
