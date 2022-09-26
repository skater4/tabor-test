<?php

class Controller
{
    private object $redis;
    private string $redis_host = '127.0.0.1';
    private const ALLOWED_PROCESSES = 1;
    private const REDIS_LIFETIME = 3600;
    private static $table = 'tabor'; //(int) id autoincrement, (int) number

    private object $mysqli;
    private string $db_host = '127.0.0.1';
    private string $db_user = 'db_user';
    private string $db_pass = 'db_pass';
    private string $db_name = 'db_name';

    public function __construct()
    {
        $redis = new Redis();
        $redis->connect($this->redis_host);
        $this->redis = $redis;

        $mysqli = new mysqli($this->db_host, $this->db_user, $this->db_pass, $this->db_name);
        $mysqli->set_charset("utf8");
        $this->mysqli = $mysqli;
    }

    public function getSum(string $idempotency_key = '', int $number = 0) : int
    {
        if (empty($idempotency_key)) {
            $idempotency_key = uniqid();
        }

        if ($this->preventRaceCondition($idempotency_key) && $this->checkIdempotency($idempotency_key, $number)) {
            $this->mysqli->query('INSERT INTO `' . self::$table . '` (`number`) VALUES (' . $number . ')');
        }
        $this->releaseRace($idempotency_key);

        return $this->mysqli->query('select sum(number) as sum from ' . self::$table)->fetch_assoc()['sum'];
    }

    private function checkIdempotency($idempotency_key, $value) : bool
    {
        if (!$this->redis->exists($idempotency_key)) {
            $this->redis->set($idempotency_key, serialize([$value]), ['ex' => self::REDIS_LIFETIME]);
            return true;
        } else {
            $values = unserialize($this->redis->get($idempotency_key));
            if (in_array($value, $values)) {
                return false;
            } else {
                $values []= $value;
                $this->redis->set($idempotency_key, serialize(array_unique($values)));
                return true;
            }
        }
    }

    private function preventRaceCondition($idempotency_key) : bool
    {
        if (!$this->redis->exists('race_' . $idempotency_key)) {
            $allowed_processes = self::ALLOWED_PROCESSES;
            $this->redis->set('race_' . $idempotency_key, self::ALLOWED_PROCESSES, ['ex' => self::REDIS_LIFETIME]);
        } else {
            $allowed_processes = $this->redis->get('race_' . $idempotency_key);
        }

        if ($allowed_processes > 0) {
            $this->redis->set('race_' . $idempotency_key, $allowed_processes--);
            return true;
        } else return false;
    }

    private function releaseRace($idempotency_key) {
        $this->redis->del('race_' . $idempotency_key);
    }
}

$controller = new Controller();
echo $controller->getSum('1346', 5);