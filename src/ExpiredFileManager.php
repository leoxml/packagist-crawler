<?php
/**
 * @license CC0-1.0
 */
namespace hirak\PackagistCrawler;

use PDO;
use DateTime;

class ExpiredFileManager
{
    /** @type PDO $pdo */
    private $pdo;

    /** @type int $expire */
    private $expire;

    function __construct($dbpath, $expire)
    {
        if (!is_string($dbpath)) {
            throw new \InvalidArgumentException('expect string but passed ' . gettype($dbpath));
        }

        if (file_exists($dbpath) && !is_writable($dbpath)) {
            throw new \RuntimeException($dbpath . ' is not writable');
        }

        $this->expire = $expire;

        // PDO::__construct ( string $dsn [, string $username [, string $password [, array $driver_options ]]] )
        $this->pdo = $pdo = new PDO("sqlite:$dbpath", null, null, array(
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,   // 设置默认的提取模式 => 关联数组形式
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,    // 错误提示 => 抛出异常
        ));
        $pdo->beginTransaction();   // 开始事务
        $pdo->exec( // 建表
            'CREATE TABLE IF NOT EXISTS expired ('
            .'path TEXT PRIMARY KEY, expiredAt INTEGER'
            .')'
        );
        $pdo->exec( // 建索引
            'CREATE INDEX IF NOT EXISTS expiredAtIndex'
            .' ON expired (expiredAt)'
        );
    }

    function __destruct()
    {
        $this->pdo->commit();   // 执行事务
        /**
         * VACUUM 命令通过复制主数据库中的内容到一个临时数据库文件，然后清空主数据库，并从副本中重新载入原始的数据库文件。这消除了空闲页，把表中的数据排列为连续的，另外会清理数据库文件结构。
         * 如果表中没有明确的整型主键（INTEGER PRIMARY KEY），VACUUM 命令可能会改变表中条目的行 ID（ROWID）。VACUUM 命令只适用于主数据库，附加的数据库文件是不可能使用 VACUUM 命令。
         * 如果有一个活动的事务，VACUUM 命令就会失败。VACUUM 命令是一个用于内存数据库的任何操作。由于 VACUUM 命令从头开始重新创建数据库文件，所以 VACUUM 也可以用于修改许多数据库特定的配置参数。
         */
        $this->pdo->exec('VACUUM'); // 执行SQL语句
    }

    /**
     * add record into expired.db
     * @param string $fullpath expired json file path
     * @param integer $now timestamp (optional)
     * @return void
     */
    function add($fullpath, $now=null)
    {
        static $insert, $path, $expiredAt;
        empty($now) or $now = $_SERVER['REQUEST_TIME'];

        if (empty($insert)) {
            $insert = $this->pdo->prepare(
                'INSERT OR IGNORE INTO expired(path,expiredAt)'
                .' VALUES(:path, :expiredAt)'
            );
            $insert->bindParam(':path', $path, PDO::PARAM_STR);
            $insert->bindParam(':expiredAt', $expiredAt, PDO::PARAM_INT);
        }

        $path = $fullpath;
        $expiredAt = $now;
        $insert->execute(); // 执行预处理语句
    }

    /**
     * delete record from expired.db
     * @param string $fullpath expired json file path
     * @return void
     */
    function delete($fullpath)
    {
        static $delete, $path;

        if (empty($delete)) {
            $delete = $this->pdo->prepare(
                'DELETE FROM expired WHERE path = :path'
            );
            $delete->bindParam(':path', $path, PDO::PARAM_STR);
        }

        $path = $fullpath;
        $delete->execute();
    }

    /**
     * get file list from expired.db
     * @param integer $from timestamp
     * @return Traversable (List<string>)
     */
    function getExpiredFileList($until=null)
    {
        isset($until) or $until = $_SERVER['REQUEST_TIME'] - $this->expire * 60;

        $stmt = $this->pdo->prepare(
            'SELECT path FROM expired WHERE expiredAt <= :expiredAt'    // 过期时间 + 1天 <= 请求时间
        );
        $stmt->bindValue(':expiredAt', $until, PDO::PARAM_INT);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_COLUMN, 0);  // bool PDOStatement::setFetchMode ( int $PDO::FETCH_COLUMN , int $colno ) 列号
        $list = array();

        foreach ($stmt as $file){
            $list[] = $file;
        }

        return $list;
    }
}
