<?php

declare(strict_types=1);

namespace Monadial\Nexus\Example\Fulfillment\Platform\Http;

use Monadial\Nexus\Example\Fulfillment\Platform\Boot\DbConfig;
use PDO;
use PDOException;

/**
 * Readiness = the database answers. Liveness (/healthz) is process-only.
 */
final readonly class ReadinessProbe
{
    public function __construct(private DbConfig $db) {}

    /**
     * @return string|null null when ready, otherwise a short reason
     */
    public function check(): ?string
    {
        try {
            $pdo = new PDO($this->db->pdoDsn(), $this->db->user, $this->db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 2,
            ]);
            $pdo->query('SELECT 1');

            return null;
        } catch (PDOException) {
            return 'database unreachable';
        }
    }
}
