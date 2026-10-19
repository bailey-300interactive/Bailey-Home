<?php
/**
 * DB - PDO wrapper (same API as the framework's DB class by Vivek Wicky Aswal)
 *
 * Changes for Home:
 *  - one shared PDO connection per request (every easyCRUD object used to open its own)
 *  - utf8mb4 and UTC on the connection
 *  - errors are logged to storage/logs and thrown, never echoed
 */
require_once(__DIR__ . '/Log.class.php');

class DB
{
    /** @var PDO|null shared connection */
    private static $sharedPdo = null;

    private $pdo;
    private $sQuery;
    private $parameters = array();
    private $log;

    public function __construct()
    {
        $this->log = new Log();
        $this->pdo = self::Pdo();
    }

    /**
     * The shared PDO connection
     * @return PDO
     */
    public static function Pdo()
    {
        if (self::$sharedPdo === null) {
            $settings = parse_ini_file(ROOT . 'settings/database.ini.php');
            if ($settings === false) {
                throw new RuntimeException('settings/database.ini.php is missing. Copy database.ini.php.sample and fill it in.');
            }
            $host = $settings['host'] ?? 'localhost';
            $port = !empty($settings['port']) ? ';port=' . $settings['port'] : '';
            $dsn = 'mysql:dbname=' . $settings['dbname'] . ';host=' . $host . $port . ';charset=utf8mb4';
            try {
                self::$sharedPdo = new PDO($dsn, $settings['user'], $settings['password'], array(
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '+00:00'"
                ));
            } catch (PDOException $e) {
                (new Log())->write('Database connection failed: ' . $e->getMessage());
                throw new RuntimeException('Could not connect to the database. Check settings/database.ini.php.');
            }
        }
        return self::$sharedPdo;
    }

    private function Init($query, $parameters = '')
    {
        try {
            $this->sQuery = $this->pdo->prepare($query);
            $this->bindMore($parameters);
            foreach ($this->parameters as $param => $value) {
                if (is_int($value[1])) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($value[1])) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($value[1])) {
                    $type = PDO::PARAM_NULL;
                } else {
                    $type = PDO::PARAM_STR;
                }
                $this->sQuery->bindValue($value[0], $value[1], $type);
            }
            $this->sQuery->execute();
        } catch (PDOException $e) {
            $this->parameters = array();
            $this->log->write($e->getMessage() . "\nSQL: " . $query);
            throw $e;
        }
        $this->parameters = array();
    }

    public function bind($para, $value)
    {
        $this->parameters[sizeof($this->parameters)] = [':' . ltrim($para, ':'), $value];
    }

    public function bindMore($parray)
    {
        if (empty($this->parameters) && is_array($parray)) {
            foreach ($parray as $column => $value) {
                if (is_int($column)) {
                    // positional "?" parameter
                    $this->parameters[sizeof($this->parameters)] = [$column + 1, $value];
                } else {
                    $this->bind($column, $value);
                }
            }
        }
    }

    /**
     * Run a query. SELECT/SHOW return all rows, INSERT/UPDATE/DELETE return the affected row count
     */
    public function query($query, $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $query = trim(str_replace("\r", ' ', $query));
        $this->Init($query, $params);
        $statement = strtolower(strtok(preg_replace('/\s+/', ' ', $query), ' '));

        if ($statement === 'select' || $statement === 'show' || $statement === 'with' || $statement === 'describe') {
            return $this->sQuery->fetchAll($fetchmode);
        } elseif ($statement === 'insert' || $statement === 'update' || $statement === 'delete' || $statement === 'replace') {
            return $this->sQuery->rowCount();
        }
        return null;
    }

    public function lastInsertId()
    {
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function executeTransaction()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false;
    }

    public function column($query, $params = null)
    {
        $this->Init($query, $params);
        $columns = $this->sQuery->fetchAll(PDO::FETCH_NUM);
        $column = array();
        foreach ($columns as $cells) {
            $column[] = $cells[0];
        }
        return $column;
    }

    public function row($query, $params = null, $fetchmode = PDO::FETCH_ASSOC)
    {
        $this->Init($query, $params);
        $result = $this->sQuery->fetch($fetchmode);
        $this->sQuery->closeCursor();
        return $result;
    }

    public function single($query, $params = null)
    {
        $this->Init($query, $params);
        $result = $this->sQuery->fetchColumn();
        $this->sQuery->closeCursor();
        return $result;
    }
}
