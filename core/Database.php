<?php
/**
 * Core Database Wrapper
 * Menggunakan PDO dengan Desain Singleton Pattern
 * Mendukung Prepared Statements dan Transaction Control (TCL: BEGIN, COMMIT, ROLLBACK)
 */

class Database
{
    private static ?Database $instance = null;
    private ?PDO $pdo = null;

    private function __construct()
    {
        $config = require dirname(__DIR__) . '/config/database.php';

        $dsn = sprintf(
            "%s:host=%s;port=%d;dbname=%s;charset=%s",
            $config['driver'],
            $config['host'],
            $config['port'],
            $config['dbname'],
            $config['charset']
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );
        } catch (PDOException $e) {
            // Catat log error teknis secara aman
            error_log("[Database Error] Koneksi gagal: " . $e->getMessage());
            
            if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
                die("Gagal terhubung ke database db_tokokita: " . $e->getMessage());
            } else {
                die("Terjadi gangguan pada sistem database. Silakan hubungi administrator.");
            }
        }
    }

    // Mencegah kloning instance (Singleton)
    private function __clone() {}

    // Mencegah unserialize instance (Singleton)
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Mengambil instance tunggal Database
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Mengambil objek PDO asli
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Menjalankan kueri SQL dengan Prepared Statement
     * @param string $sql
     * @param array $params
     * @return PDOStatement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("[Database Query Error] SQL: {$sql} | Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mengambil seluruh baris hasil kueri (array asosiatif)
     * @param string $sql
     * @param array $params
     * @return array
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Mengambil satu baris pertama hasil kueri
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }

    /**
     * Menjalankan kueri INSERT/UPDATE/DELETE dan mengembalikan jumlah baris terdampak
     * @param string $sql
     * @param array $params
     * @return int
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Mengambil ID terakhir dari baris yang baru di-insert (AUTO_INCREMENT)
     * @return string
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Memulai transaksi (BEGIN TRANSACTION)
     */
    public function beginTransaction(): bool
    {
        if (!$this->pdo->inTransaction()) {
            return $this->pdo->beginTransaction();
        }
        return false;
    }

    /**
     * Menyimpan seluruh perubahan transaksi ke database (COMMIT)
     */
    public function commit(): bool
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->commit();
        }
        return false;
    }

    /**
     * Membatalkan seluruh perubahan transaksi (ROLLBACK)
     */
    public function rollBack(): bool
    {
        if ($this->pdo->inTransaction()) {
            return $this->pdo->rollBack();
        }
        return false;
    }

    /**
     * Memeriksa apakah saat ini sedang dalam status transaksi aktif
     */
    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
