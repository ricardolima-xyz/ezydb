<?php
/**
 * MIT License
 * 
 * Copyright (c) 2022 Luiz Ricardo de Lima
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

 
class DAOException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null) {
        parent::__construct($message, $code, $previous);
    }
}

class DAO
{
    protected $dbh;
    protected $entity;
    protected $properties;
    protected $key;
    protected $keyIsAutogenerated;
    private $delMethod;
    private $deactivateProperty;
    private $driver;
    
    protected const DEL_METHOD = 'del-method';
    protected const DEL_METHOD_DELETE = 'del-method-delete';
    protected const DEL_METHOD_DEACTIVATE = 'del-method-deactivate';

    protected const DEACTIVATE_PROPERTY = 'deactivate-property';

    // TODO: new constructor is under construction
    /** 
     * Class constructor.
     * 
     * @param PDO       $dbh The database connection object
     * @param string    $entity The name of the table this DAO represents
     * @param array     $properties An associative array whose keys are the column names and the
     *                  values are PDO::PARAM_* constants according to each data type
     *                  ({@link https://www.php.net/manual/en/pdo.constants.php}), for the correct
     *                  mapping in the internally created queries
     * @param array     $settings An associative array with dditional settings. Possible entries are
     *                  * 'automaticKey' => (boolean) indicates whether the key is autogenerated or not
     *                  * 'deleteMethod' => 'delete' or 'deactivate'
     *                  * 'deactivateProperty' => property which flags if an entry is deactivated or not
     *                  * 'key' => property that is the primary key for the entity
     * 
     * @link https://www.php.net/manual/en/pdo.constants.php 
     */
    function ___construct($dbh, $entity, $properties, $settings = null) {

        // Validation
        if ($dbh instanceof PDO === false) 
            throw new DAOException('The database connection is not valid.');
        if (empty($entity))
            throw new DAOException('Invalid name');

        // TODO #1 DAOs without keys should be possible
        // Setting id property and performing validation
        $this->key = isset($settings['key']) ? $settings['key'] : 'id';
        if(!isset($properties[$key]))
            throw new DAOException('The key property '.$key.' is not defined in $properties array.');

        $this->keyIsAutogenerated = isset($settings['automaticKey']) ? (bool) $settings['automaticKey'] : true;

        $this->dbh = $dbh;
        $this->entity = $entity;
        $this->properties = $properties;
        $this->driver = $dbh->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Loading default settiings
        $this->delMethod = self::DEL_METHOD_DELETE;
        $this->deactivateProperty = null;

        // Loading settings
        if (!empty($settings) && is_array($settings))
        {
            foreach($settings as $settingsKey => $settingsValue) switch ($settingsKey) 
            {
                case 'deleteMethod':
                    if ($settingsValue == 'delete')
                        $this->delMethod = self::DEL_METHOD_DELETE;
                    else if ($settingsValue == 'deactivate')
                        $this->delMethod = self::DEL_METHOD_DEACTIVATE;
                    else
                        throw new DAOException('Invalid deleteMethod specified on $settings.');
                    break;
                case 'deactivateProperty':
                    if(isset($properties[$settingsValue]))
                        $this->deactivateProperty = $settingsValue;
                    else
                        throw new DAOException('deactivateProperty specified on $settings does not exist in $properties.');
                    break;
                default :
                    throw new DAOException('Invalid settings key: '.$settingsKey); 
                    break;                  
            }
        }
    }

    function __construct($dbh, $entity, $properties, $key = 'id', $keyIsAutogenerated = true, $configuration = null) {
        
        // Validation - The properties array must have the $key element set
        if(!isset($properties[$key]))
            throw new DAOException('The key property '.$key.' was not defined on $properties param.');

        $this->dbh = $dbh;
        $this->entity = $entity;
        $this->properties = $properties;
        $this->key = $key;
        $this->keyIsAutogenerated = $keyIsAutogenerated;
        $this->driver = $dbh->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Loading default configuration
        $this->delMethod = self::DEL_METHOD_DELETE;
        $this->deactivateProperty = null;
        // Loading custom configuration, if set
        if ($configuration !== null && is_array($configuration))
        {
            foreach($configuration as $configurationKey => $configurationValue) switch ($configurationKey) 
            {
                case self::DEL_METHOD:
                    if (in_array($configurationValue, [self::DEL_METHOD_DELETE, self::DEL_METHOD_DEACTIVATE]))
                        $this->delMethod = $configurationValue;
                    else
                        throw new DAOException('Invalid DEL_METHOD specified on $configuration.');
                    break;
                case self::DEACTIVATE_PROPERTY:
                    if(isset($properties[$configurationValue]))
                        $this->deactivateProperty = $configurationValue;
                    else
                        throw new DAOException('DEACTIVATE_PROPERTY specified on $configuration is not set on $properties.');
                    break;
            }
        }
    }

    /**
     * @param array|null $filters - The filter for list. If null, no filtering will be performed.
     * If not null, needs to be an array of filter objects. The filter object is an associative
     * array with 3 keys: 'property', 'operator', 'value'. When $filters are set and the DAO class
     * deletes by deactivating, the deactivate property needs to be included in the filter, or all
     * the objects, included the deleted ones will be retrieved. If $filters is null, only the
     * not deleted ones will be retrieved. Implemented operators: =, <, >, <=, >=, <>, !=, LIKE.
     * Example: [['property' => 'id', 'operator'=>'=', 'value' => '42']]
     */ 
    public function count($filters = null)
    {
        // Discarding array key information and retrieving an ordered array
        if(is_array($filters)) $filters = array_values($filters);

        $query = 'SELECT count(*) FROM '.$this->esc($this->entity);
        $query.= $this->evalFilters($filters);

        $stmt = $this->dbh->prepare($query);
        if(is_array($filters)) foreach ($filters as $i => $filter) {
            $stmt->bindValue($i+1, $filter['value'], $this->properties[$filter['property']]);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function create($object) {
        if (!$this->keyIsAutogenerated && !isset($object[$this->key]))
            throw new DAOException('Missing key property '.$this->key.' on create. This property is not autogenerated.');
        
        // Exception: empty columns and values and sqlite
        if (empty($object) && $this->driver == 'sqlite') {
            $query =  'INSERT INTO '.$this->esc($this->entity).' DEFAULT VALUES';
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            return $this->dbh->lastInsertId();
        }

        $pos = 0;
        $names  = array();
        $types  = array();
        $values = array();
        $params = array();
        foreach ($this->properties as $propertyName => $propertyType) {
            // If keys are autogenerated, any input values for them will be ignored
            if ($propertyName == $this->key && $this->keyIsAutogenerated)
                continue;  
            if (array_key_exists($propertyName, $object)) {
                $names[$pos] = $this->esc($propertyName);
                $types[$pos] = $propertyType;
                $values[$pos] = $object[$propertyName];
                $params[$pos] = '?';
                $pos++;
            }
        }
        $query = 'INSERT INTO '.$this->esc($this->entity).' (';
        $query.= implode(', ', $names);
        $query.= ') VALUES (';
        $query.= implode(', ', $params);
        $query.= ')';

        $stmt = $this->dbh->prepare($query);
        for($i = 0; $i < $pos; $i++) {
            $stmt->bindValue($i+1, $values[$i], $types[$i]);
        }
        $stmt->execute();
        if ($this->keyIsAutogenerated)
            return $this->dbh->lastInsertId();
        else
            return $object[$this->key];
    }

    public function del($key) {
        switch ($this->delMethod) {
            case self::DEL_METHOD_DELETE:
                $stmt = $this->dbh->prepare(
                    'DELETE FROM '.$this->esc($this->entity).' WHERE '.$this->esc($this->key).' = ?');
                $stmt->bindValue(1, $key, $this->properties[$this->key]);
                $stmt->execute();
                return true;
                break;
            case self::DEL_METHOD_DEACTIVATE:
                $query = 'UPDATE '.$this->esc($this->entity);
                $query.= ' SET '.$this->esc($this->deactivateProperty).' = 0';
                $query.= ' WHERE '.$this->esc($this->key).' = ?';
                $stmt = $this->dbh->prepare($query);
                $stmt->bindValue(1, $key, $this->properties[$this->key]);
                $stmt->execute();
                return true;
                break;
        }
    }

    /** 
     * Escapes table names and column names in the pattern:
     *  table           => "table"
     *  column          => "column"
     *  table.column    => "table"."column"
     *  table.*         => "table".*
     */
    protected function esc($name) {
        $parts = explode('.', $name);
        foreach ($parts as $i => $value) {
            if ($value == '*') continue;
            
            switch ($this->driver) {
                case 'mysql':
                    $parts[$i] = "`$value`";
                    break;
                default:
                    $parts[$i] = "\"$value\"";
                    break;
            }
        }
        return implode('.', $parts);
    }

    public function exists($key) {
        $filters = array();
        $filters[] = ['property' => $this->key, 'operator'=>'=', 'value' => $key];
        if ($this->delMethod == self::DEL_METHOD_DEACTIVATE)
            $filters[] = ['property' => $this->deactivateProperty, 'operator'=>'=', 'value' => 1];

        return (boolean) $this->count($filters);
    }

    /** $filters is a numeric-indexed array */
    private function evalFilters($filters) {
        $filterQuery = array();
        if ($filters === null && $this->delMethod == self::DEL_METHOD_DEACTIVATE) {
            $true = ($this->properties[$this->deactivateProperty] == PDO::PARAM_STR) ? "'1'" : "1";
            return ' WHERE '.$this->esc($this->deactivateProperty).' = '.$true;
        }
        if (is_array($filters)) foreach ($filters as $key => $filter) {
            if (!isset($filter['operator']))
                throw new DAOException('Operator not specified for filter in position '.$key.'.');
            else if (!in_array($filter['operator'], ['=','<','>','<=','>=','<>','!=','LIKE']))
                throw new DAOException('Invalid or unimplemented operator '.$filter['operator'].' for filter in position '.$key.'.');
            else if (!isset($filter['property']))
                throw new DAOException('Missing \'property\' for filter in position '.$key.'.');
            else if(!isset($this->properties[$filter['property']]))
                throw new DAOException('The property '.$filter['property'].' for filter in position '.$key.' is not defined in the class.');
            else if (!isset($filter['value']))
                throw new DAOException('Missing \'value\' for filter in position '.$key.'.');
            else { // all good!
                $filterQuery[] = $this->esc($filter['property']).' '.$filter['operator'].' ?';
            }
        }
        if (!empty($filterQuery)) return ' WHERE '.implode(' AND ', $filterQuery);
        else return '';
    }

    /** $orderBy is a numeric-indexed array */
    private function evalOrderBy($orderBy) {
        $orderByQuery = array();
        if (is_array($orderBy)) foreach ($orderBy as $key => $ob) {
            if (!isset($ob['property']))
                throw new DAOException('Missing \'property\' for orderBy in position '.$key.'.');
            else if(!isset($this->properties[$ob['property']]))
                throw new DAOException('The property '.$ob['property'].' for orderBy in position '.$key.' is not defined in the class.');
            else if (isset($ob['direction']) && !in_array(strtolower($ob['direction']), ['asc', 'desc']))
                throw new DAOException('Invalid \'direction\' for orderBy in position '.$key.'.');
            else { // all good!
                $direction = isset($ob['direction']) ? strtoupper($ob['direction']) : 'ASC';
                $orderByQuery[] = $this->esc($ob['property']).' '.$direction;
            }
        }
        if (!empty($orderByQuery)) return ' ORDER BY '.implode(', ', $orderByQuery);
        else return '';  
    }

    public function get($key) {
        $stmt = $this->dbh->prepare(
            'SELECT * FROM '.$this->esc($this->entity).' WHERE '.$this->esc($this->key).' = ?');
        $stmt->bindValue(1, $key, $this->properties[$this->key]);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result : null;
    }

    /**
     * @param array|null $filters - The filter for list. If null, no filtering will be performed.
     * If not null, needs to be an array of filter objects. The filter object is an associative
     * array with 3 keys: 'property', 'operator', 'value'. When $filters are set and the DAO class
     * deletes by deactivating, the deactivate property needs to be included in the filter, or all
     * the objects, included the deleted ones will be retrieved. If $filters is null, only the
     * not deleted ones will be retrieved. Implemented operators: =, <, >, <=, >=, <>, !=, LIKE.
     * Example: [['property' => 'id', 'operator'=>'=', 'value' => '42']]
     * 
     * @param array|null $orderBy - The order by information, if any. If null, no ordering will be
     * performed. If not null, it needs to be an indexed array (simple array) of orderby objects.
     * The orderby object is an associative array with 2 keys: 'property', 'direction'. 
     * If 'direction' is not set, it will default to 'ASC'. 'direction' is case-insensitive.
     * Example: [['property' => 'id', 'direction' => 'desc']]
     */ 
    public function list($elementKeyAsArrayKey = true, $filters = null, $orderBy = null)
    {
        // Discarding array key information and retrieving an ordered array
        if(is_array($filters)) $filters = array_values($filters);

        $query = 'SELECT * FROM '.$this->esc($this->entity).' ';
        $query.= $this->evalFilters($filters);
        $query.= $this->evalOrderBy($orderBy);

        $stmt = $this->dbh->prepare($query);
        if(is_array($filters)) foreach ($filters as $i => $filter) {
            $stmt->bindValue($i+1, $filter['value'], $this->properties[$filter['property']]);
        }
        
        $stmt->execute();
        $result = array();
        while ($object = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if($elementKeyAsArrayKey)
                $result[$object[$this->key]] = $object;
            else
                $result[] = $object;
        }
        return $result;
    }

    public function update($object) {
        if (!isset($object[$this->key]))
            throw new DAOException('Missing key property '.$this->key.' on update.');
        
        $pos = 0;
        $types  = array();
        $values = array();
        $params = array();
        foreach ($this->properties as $propertyName => $propertyType) {
            if ($propertyName == $this->key) continue;  
            if (array_key_exists($propertyName, $object)) {
                $types[$pos] = $propertyType;
                $values[$pos] = $object[$propertyName];
                $params[$pos] = $this->esc($propertyName).' = ?';
                $pos++;
            }
        }

        $query = 'UPDATE '.$this->esc($this->entity).' SET ';
        $query.= implode(', ', $params);
        $query.= ' WHERE '.$this->esc($this->key).' = ?';

        $stmt = $this->dbh->prepare($query);
        for($i = 0; $i < $pos; $i++) {
            $stmt->bindValue($i+1, $values[$i], $types[$i]);
        }
        $stmt->bindValue($pos+1, $object[$this->key], $this->properties[$this->key]);
        $stmt->execute();
        return true;
    }

    public function updateKey($oldkey, $newkey) {
        $query = 'UPDATE '.$this->esc($this->entity);
        $query.= ' SET   '.$this->esc($this->key).' = ?';
        $query.= ' WHERE '.$this->esc($this->key).' = ?';
        $stmt = $this->dbh->prepare($query);
        $stmt->bindValue(1, $newkey, $this->properties[$this->key]);
        $stmt->bindValue(2, $oldkey, $this->properties[$this->key]);
        $stmt->execute();
        return true;
    }
}