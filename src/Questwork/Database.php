<?php
/**
 * Database Class
 */

namespace Questwork;

use PDO;
use PDOException;

class Database
{
    protected $pdo;

    public function __construct($config = [])
    {
        if (empty($config) || is_numeric($config)) {
            trigger_error($message = 'Missing database connect configuration');
            throw new Exception($message);
        } else if (is_string($config)) {
            $config = ['connection' => 'sqlite:' . $config];
        } else if (is_null($config['connection'])) {
            $config['connection'] = 'mysql:host=' . $config['hostname'] . ';dbname=' . $config['database'];
        }
        try {
            $this->pdo = new PDO($config['connection'], $config['username'], $config['password']);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $error) {
            $message = 'Cannot connect database, ' . $error->getMessage();
            trigger_error($message);
            throw new Exception($message, $error->getCode());
        }
    }

    public function query($command, $params = NULL)
    {
        if (!is_null($params)) {
            return $this->prepare($command, $params);
        }
        try {
            return $this->pdo->query($command);
        } catch (PDOException $error) {
            trigger_error($error->getMessage());
            throw new Exception($error->getMessage(), $error->getCode());
        }
    }

    public function exec($command)
    {
        try {
            return $this->pdo->exec($command);
        } catch (PDOException $error) {
            trigger_error($error->getMessage());
            throw new Exception($error->getMessage(), $error->getCode());
        }
    }

    public function prepare($command, $params)
    {
        try {
            $stmt = $this->pdo->prepare($command);
            $stmt->execute($params);
        } catch (PDOException $error) {
            trigger_error($error->getMessage());
            throw new Exception($error->getMessage(), $error->getCode());
        }
        return $stmt;
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function pdo()
    {
        return $this->pdo;
    }

    public function primary($table)
    {
        $data = $this->query("SHOW KEYS FROM $table WHERE Key_name = 'PRIMARY'")->fetch();
        return $data['Column_name'];
    }

    public function columns($table)
    {
        $data = $this->query('SHOW COLUMNS FROM ' . $table)->fetchAll();
        return $data;
    }

    public function buildSelect($fields = '*', $from = '', $where = NULL, $order_by = NULL, $limit = NULL)
    {
        if (is_array($fields)) {
            $fields = implode(', ', $fields);
        }
        if (is_array($from)) {
            extract($from);
        }

        $command = "SELECT $fields";
        $command .= "\nFROM $from";
        if (isset($join)) {
            foreach ($join as $key => $value) {
                $value = implode(" = ", preg_split('/\s*=\s*/', trim($value)));
                $join[$key] = "JOIN $key ON $value";
            }
            $command .= "\n" . implode("\n", $join);
        }
        $command .= $this->parseCondition($where);
        if (isset($group_by)) {
            if (is_array($group_by)) {
                $group_by = implode(", ", $group_by);
            }
            $command .= "\nGROUP BY $group_by";
        }
        if (isset($order_by)) {
            if (is_array($order_by)) {
                $order_by[1] = ($order_by[1] || $order_by[1] === NULL) ? 'ASC' : 'DESC';
                $order_by = implode(' ', $order_by);
            }
            $command .= "\nORDER BY $order_by";
        }
        if ($limit) {
            if (is_array($limit)) {
                $limit = implode(', ', $limit);
            }
            $command .= "\nLIMIT $limit";
        }
        return $this->query($command, $where ?: []);
    }

    public function parseCondition(&$where)
    {
        $result = '';
        if (!empty($where)) {
            if (!is_array($where)) {
                $where = [$where];
            }
            $conditions = [];
            $result = [];
            foreach ($where as $key => $value) {
                if (is_string($key)) {
                    $newKey = str_replace('.', '_', $key);
                    $hasSpace = ($spacePos = strpos($key, ' ')) !== FALSE;
                    if (!$hasSpace) {
                        $condition = "$key = :$newKey";
                    } else {
                        $newKey = substr($newKey, 0, $spacePos);
                        $condition = "$key :$newKey";
                    }
                    if (is_array($value)) {
                        $condition = [];
                        foreach ($value as $subkey => $subvalue) {
                            $subkey = $newKey . '_' . $subkey;
                            array_push($condition, "$key = :$subkey");
                            $conditions[$subkey] = $subvalue;
                        }
                        $condition = '(' . implode(' OR ', $condition) . ')';
                    } else {
                        $conditions[$newKey] = $value;
                    }
                    array_push($result, $condition);
                } else {
                    array_push($result, $value);
                }
            }
            $where = $conditions;
            $result = "\nWHERE " . implode("\nAND ", $result);
        }
        return $result;
    }

    public function select($fields = '*', $from = '', $where = NULL, $order = NULL, $limit = NULL)
    {
        return $this->buildSelect($fields, $from, $where, $order, $limit)->fetchAll();
    }

    public function selectOne($fields = '*', $from = '', $where = NULL, $order = NULL)
    {
        return $this->buildSelect($fields, $from, $where, $order, [0, 1])->fetch();
    }

    public function count($table = '', $where = NULL)
    {
        $command = "SELECT COUNT(*) AS count FROM $table" . $this->parseCondition($where);
        $result = $this->query($command, $where)->fetch();
        return $result ? $result['count'] : '0';
    }

    public function insert($table = '', $fields = [], $batch = NULL)
    {
        $command = "INSERT INTO $table ";
        if ($batch) {
            $keys = $fields;
            foreach ($batch as $key => $value) {
                if (is_string($value)) {
                    $value = preg_split('/[\ \,]+/', $value);
                }
                $batch[$key] = "('" . implode("', '", $value) . "')";
            }
            $values = "\n" . implode(",\n", $batch);
            $fields = [];
        } else {
            $keys = array_keys($fields);
            $values = "(:" . implode(", :", $keys) . ")";
        }
        $command .= "(" . implode(", ", $keys) . ") \nVALUES $values";
        return $this->query($command, $fields)->rowCount();
    }

    public function update($table = '', $fields = [], $where = NULL)
    {
        $command = "UPDATE $table \nSET ";
        $params = array_merge($fields, $where);
        foreach ($fields as $key => $value) {
            $fields[$key] = "$key = :$key";
        }
        $command .= implode(', ', $fields) . $this->parseCondition($where);
        return $this->query($command, $params)->rowCount();
    }

    public function delete($table = '', $where = NULL)
    {
        $command = "DELETE FROM $table" . $this->parseCondition($where);
        return $this->query($command, $where)->rowCount();
    }
}
