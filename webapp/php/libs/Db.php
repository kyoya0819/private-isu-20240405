<?php

namespace Libs;

use PDO;

class Db extends PDO
{
    /**
     * @param array|null $config
     */
    public function __construct(?array $config = [])
    {
        $config = array_merge(
            [
                'host' => $_SERVER['ISUCONP_DB_HOST'] ?? 'localhost',
                'port' => $_SERVER['ISUCONP_DB_PORT'] ?? 3306,
                'username' => $_SERVER['ISUCONP_DB_USER'] ?? 'root',
                'password' => $_SERVER['ISUCONP_DB_PASSWORD'] ?? null,
                'database' => $_SERVER['ISUCONP_DB_NAME'] ?? 'isuconp',
            ],
            $config
        );

        parent::__construct(
            "mysql:dbname={$config["database"]};host={$config["host"]};port={$config["port"]};charset=utf8mb4",
            $config["username"],
            $config["password"]
        );
    }

    /**
     * @return void
     */
    public function init(): void
    {
        $sql = [];
        $sql[] = 'DELETE FROM users WHERE id > 1000';
        $sql[] = 'DELETE FROM posts WHERE id > 10000';
        $sql[] = 'DELETE FROM comments WHERE id > 100000';
        $sql[] = 'UPDATE users SET del_flg = 0';
        $sql[] = 'UPDATE users SET del_flg = 1 WHERE id % 50 = 0';
        foreach($sql as $s) {
            $this->query($s);
        }
    }

    /**
     * @param $query
     * @param ...$params
     * @return mixed
     */
    public function fetchFirst($query, ...$params)
    {
        $ps = $this->prepare($query);
        $ps->execute($params);
        $result = $ps->fetch();
        $ps->closeCursor();
        return $result;
    }
}