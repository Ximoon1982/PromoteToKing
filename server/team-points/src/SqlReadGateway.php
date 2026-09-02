<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/** Shared read-only PDO boundary used by compatibility facades. */
final class SqlReadGateway
{
    public function one(PDO $pdo, string $sql, array $args = []): array
    {
        $query = $pdo->prepare($sql);
        $query->execute($args);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    public function all(PDO $pdo, string $sql, array $args = []): array
    {
        $query = $pdo->prepare($sql);
        $query->execute($args);
        return $query->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function optionalOne(PDO $pdo, string $sql, array $args = []): array
    {
        try {
            return $this->one($pdo, $sql, $args);
        } catch (\Throwable $error) {
            error_log('P2K member intelligence optional query: ' . $error->getMessage());
            return [];
        }
    }

    public function optionalAll(PDO $pdo, string $sql, array $args = []): array
    {
        try {
            return $this->all($pdo, $sql, $args);
        } catch (\Throwable $error) {
            error_log('P2K member intelligence optional query: ' . $error->getMessage());
            return [];
        }
    }
}
