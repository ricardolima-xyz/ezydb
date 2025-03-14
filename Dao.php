<?php
/**
 * MIT License
 * 
 * Copyright (c) 2025 Luiz Ricardo de Lima
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
    private $dbh;
    private $table;
    private $columns;
    private $key;
    private $keyIsAutogenerated;
    private $delMethod;
    private $deactivateProperty;
    private $driver;
    
    public const DEL_METHOD = 'deleteMethod';
    public const DEL_METHOD_DELETE = 'deleteMethodDelete';
    public const DEL_METHOD_DEACTIVATE = 'deleteMethodDeactivate';
    public const DEACTIVATE_COLUMN = 'deactivateColumn';
    public const KEY = 'key';
    public const KEY_IS_AUTOGENERATED = 'keyIsAutogenerated';

    function __construct($dbh, $table, $columns, $configuration)
    {
        $this->dbh = $dbh;
        $this->table = $table;
        $this->columns = $columns;
        $this->key = null;
        $this->driver = $dbh->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Loading configuration values
        $this->keyIsAutogenerated = true;
        $this->delMethod = self::DEL_METHOD_DELETE;
        $this->deactivateProperty = null;
        if (is_array($configuration))
        {
            foreach($configuration as $configurationKey => $configurationValue) switch ($configurationKey) 
            {
                case self::KEY:
                    if (empty($configurationValue)) {
                        throw new DAOException('Invalid value for '.self::KEY.'. It cannot be empty.');
                    }
                    else if(!isset($columns[$configurationValue])) {
                        throw new DAOException(self::KEY.' in $configuration is not set in $columns.');
                    }
                    else {
                        $this->key = $configurationValue;
                    }
                    break;
                case self::KEY_IS_AUTOGENERATED:
                    if (is_bool($configurationValue)) {
                        $this->keyIsAutogenerated = $configurationValue;
                    }
                    else {
                        throw new DAOException('Invalid '.self::KEY_IS_AUTOGENERATED.' in $configuration. It must be a boolean.');
                    }
                    break;
                case self::DEL_METHOD:
                    if (in_array($configurationValue, [self::DEL_METHOD_DELETE, self::DEL_METHOD_DEACTIVATE]))
                        $this->delMethod = $configurationValue;
                    else
                        throw new DAOException('Invalid '.self::DEL_METHOD.' in $configuration.');
                    break;
                case self::DEACTIVATE_COLUMN:
                    if(isset($columns[$configurationValue]))
                        $this->deactivateProperty = $configurationValue;
                    else
                        throw new DAOException('The column '.$configurationValue.' used as '.self::DEACTIVATE_COLUMN.' in $configuration is not set in $columns.');
                    break;
            }
        }
        else {
            throw new DAOException('Invalid $configuration. It must be an array.');
        }

        // Validation after reading all configuration
        if($this->key === null) {
            throw new DAOException(self::KEY.' in $configuration is mandatory.');
        }
    }

    /**
     * @param array|null $filters - The filter for list. If null, no filtering will be performed.
     * If not null, needs to be an array of filter objects. The filter object is an associative
     * array with 3 keys: 'property', 'operator', 'value'. When $filters are set and the DAO class
     * deletes by deactivating, the deactivate property needs to be included in the filter, or all
     * the objects, included the deleted ones will be retrieved. If $filters is null, only the
     * not deleted ones will be retrieved. Implemented operators: =, <, >, <=, >=, <>, !=, LIKE, 
     * IS NULL, IS NOT NULL. Do not use 'value' when operatior is 'IS NULL' or 'IS NOT NULL'.
     * Example: [['property' => 'id', 'operator'=>'=', 'value' => '42']]
     */ 
    public function count($filters = null)
    {
        // Discarding array key information and retrieving an ordered array
        if(is_array($filters)) $filters = array_values($filters);

        $query = 'SELECT count(*) FROM '.$this->esc($this->table);
        $query.= $this->evalFilters($filters);

        $stmt = $this->dbh->prepare($query);
        $i = 1;
        if(is_array($filters)) foreach ($filters as $filter) {
            if (isset($filter['value'])) {
                $stmt->bindValue($i, $filter['value'], $this->columns[$filter['property']]);
                $i++;
            }
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }

    public function create($object) {
        if (!$this->keyIsAutogenerated && !isset($object[$this->key]))
            throw new DAOException('Missing key property '.$this->key.' on create. This property is not autogenerated.');
        
        // Exception: empty columns and values and sqlite
        if (empty($object) && $this->driver == 'sqlite') {
            $query =  'INSERT INTO '.$this->esc($this->table).' DEFAULT VALUES';
            $stmt = $this->dbh->prepare($query);
            $stmt->execute();
            return $this->dbh->lastInsertId();
        }

        $pos = 0;
        $names  = array();
        $types  = array();
        $values = array();
        $params = array();
        foreach ($this->columns as $propertyName => $propertyType) {
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
        $query = 'INSERT INTO '.$this->esc($this->table).' (';
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
                    'DELETE FROM '.$this->esc($this->table).' WHERE '.$this->esc($this->key).' = ?');
                $stmt->bindValue(1, $key, $this->columns[$this->key]);
                $stmt->execute();
                return true;
                break;
            case self::DEL_METHOD_DEACTIVATE:
                $deactivated = ($this->columns[$this->deactivateProperty] == PDO::PARAM_STR) ? "'0'" : "0";
                $query = 'UPDATE '.$this->esc($this->table);
                $query.= ' SET '.$this->esc($this->deactivateProperty).' = '.$deactivated;
                $query.= ' WHERE '.$this->esc($this->key).' = ?';
                $stmt = $this->dbh->prepare($query);
                $stmt->bindValue(1, $key, $this->columns[$this->key]);
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
    public function esc($name) {
        $parts = explode('.', $name);
        foreach ($parts as $i => $value) {
            if ($value == '*') continue;
            $parts[$i] = "\"$value\"";
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
            $true = ($this->columns[$this->deactivateProperty] == PDO::PARAM_STR) ? "'1'" : "1";
            return ' WHERE '.$this->esc($this->deactivateProperty).' = '.$true;
        }
        if (is_array($filters)) foreach ($filters as $key => $filter) {
            if (!isset($filter['operator']))
                throw new DAOException('Operator not specified for filter in position '.$key.'.');
            else if (!in_array($filter['operator'], ['=','<','>','<=','>=','<>','!=','LIKE','IS NULL','IS NOT NULL']))
                throw new DAOException('Invalid or unimplemented operator '.$filter['operator'].' for filter in position '.$key.'.');
            else if (!isset($filter['property']))
                throw new DAOException('Missing \'property\' for filter in position '.$key.'.');
            else if(!isset($this->columns[$filter['property']]))
                throw new DAOException('The property '.$filter['property'].' for filter in position '.$key.' is not defined in the class.');
            else if (!in_array($filter['operator'], ['IS NULL','IS NOT NULL']) && !isset($filter['value']))
                throw new DAOException('Missing \'value\' for filter in position '.$key.'.');
            else { // all good!

                $filterQuery[] = in_array($filter['operator'], ['IS NULL','IS NOT NULL']) ?
                     $this->esc($filter['property']).' '.$filter['operator']:
                     $this->esc($filter['property']).' '.$filter['operator'].' ?';
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
            else if(!isset($this->columns[$ob['property']]))
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
            'SELECT * FROM '.$this->esc($this->table).' WHERE '.$this->esc($this->key).' = ?');
        $stmt->bindValue(1, $key, $this->columns[$this->key]);
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
     * not deleted ones will be retrieved. Implemented operators: =, <, >, <=, >=, <>, !=, LIKE,
     * IS NULL, IS NOT NULL. Do not use 'value' when operatior is 'IS NULL' or 'IS NOT NULL'.
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

        $query = 'SELECT * FROM '.$this->esc($this->table).' ';
        $query.= $this->evalFilters($filters);
        $query.= $this->evalOrderBy($orderBy);

        $stmt = $this->dbh->prepare($query);
        $i = 1;
        if(is_array($filters)) foreach ($filters as $filter) {
            if (isset($filter['value'])) {
                $stmt->bindValue($i, $filter['value'], $this->columns[$filter['property']]);
                $i++;
            }
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
        foreach ($this->columns as $propertyName => $propertyType) {
            if ($propertyName == $this->key) continue;  
            if (array_key_exists($propertyName, $object)) {
                $types[$pos] = $propertyType;
                $values[$pos] = $object[$propertyName];
                $params[$pos] = $this->esc($propertyName).' = ?';
                $pos++;
            }
        }

        $query = 'UPDATE '.$this->esc($this->table).' SET ';
        $query.= implode(', ', $params);
        $query.= ' WHERE '.$this->esc($this->key).' = ?';

        $stmt = $this->dbh->prepare($query);
        for($i = 0; $i < $pos; $i++) {
            $stmt->bindValue($i+1, $values[$i], $types[$i]);
        }
        $stmt->bindValue($pos+1, $object[$this->key], $this->columns[$this->key]);
        $stmt->execute();
        return true;
    }

    public function updateKey($oldkey, $newkey) {
        $query = 'UPDATE '.$this->esc($this->table);
        $query.= ' SET   '.$this->esc($this->key).' = ?';
        $query.= ' WHERE '.$this->esc($this->key).' = ?';
        $stmt = $this->dbh->prepare($query);
        $stmt->bindValue(1, $newkey, $this->columns[$this->key]);
        $stmt->bindValue(2, $oldkey, $this->columns[$this->key]);
        $stmt->execute();
        return true;
    }
}