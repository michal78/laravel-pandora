<?php

declare(strict_types=1);

namespace Pandora\Support\Concerns;

use Illuminate\Database\QueryException;

/**
 * Telling "somebody got there first" apart from "the database is broken".
 *
 * Pandora uses a unique INSERT as a claim in two places -- the automation
 * occurrence and the webhook delivery nonce -- because a check-then-write pair
 * has a race window and an insert does not. Both therefore have to catch a
 * constraint violation as a NORMAL outcome.
 *
 * Catching `QueryException` wholesale is the trap. On MySQL a deadlock, a
 * lock-wait timeout or a value too long for a column all arrive as the same
 * class, and treating one of those as "already claimed" makes Pandora answer
 * "this webhook was already processed" to a delivery it in fact dropped on the
 * floor. Silent data loss, reported months later as "some webhooks don't
 * arrive". So the detection is narrow, shared, and used in both places.
 */
trait DetectsUniqueViolations
{
    /**
     * SQLSTATE 23000 (MySQL/MariaDB/SQLite) and 23505 (PostgreSQL) are the
     * integrity-constraint classes. 23000 covers more than uniqueness on
     * MySQL -- a foreign key failure is 23000 too -- so the driver message is
     * checked as well rather than instead.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach ([
            'unique constraint',   // SQLite, PostgreSQL
            'unique violation',    // PostgreSQL
            'duplicate entry',     // MySQL, MariaDB
            'duplicate key',       // SQL Server dialects and some proxies
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
