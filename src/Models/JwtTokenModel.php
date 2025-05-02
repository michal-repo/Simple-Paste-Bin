<?php

namespace SrcLibrary\Models;

use SrcLibrary\Base\BaseWithDB;
use \PDO;
use \PDOException;
use \Exception;

class JwtTokenModel extends BaseWithDB {

    /**
     * Removes expired JWTs from the database.
     * Intended for periodic cleanup.
     *
     * @return void
     */
    public function removeExpiredTokens(): void {
        // --- Performance Consideration ---
        // Running this DELETE on every APIAuth instantiation might be inefficient
        // on high-traffic sites, especially if the jwt_tokens table grows large.
        // Consider running it probabilistically (e.g., 1% of the time) or,
        // preferably, using a dedicated cron job/scheduled task for cleanup.
        /*
        // Example: Probabilistic execution (run ~1% of the time)
        if (random_int(1, 100) !== 1) {
           return;
        }
        */
        // --- End Performance Consideration ---

        $sql = "DELETE FROM jwt_tokens WHERE expires_at <= NOW()";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->execute();
            // Optional: Log if rows were deleted
            // $rowCount = $stmt->rowCount();
            // if ($rowCount > 0) { error_log("Cleaned up $rowCount expired JWT tokens."); }
        } catch (PDOException $e) {
            // Log the error but don't necessarily stop execution,
            // as cleanup failure shouldn't prevent core auth functionality.
            error_log("PDOException during JWT cleanup: " . $e->getMessage());
            // Depending on requirements, you might re-throw or handle differently
            // throw new Exception("Failed JWT cleanup.", 500, $e);
        }
    }

    /**
     * Stores the generated JWT in the database.
     * @param int $userId
     * @param string $jwt
     * @param int $expiresAtTimestamp
     * @param string|null $userAgent
     * @return void
     * @throws Exception
     */
    public function storeJwt(int $userId, string $jwt, int $expiresAtTimestamp, ?string $userAgent): void {
        $sql = "INSERT INTO jwt_tokens (user_id, token, expires_at, user_agent, token_type)
                VALUES (:user_id, :token, :expires_at, :user_agent, :token_type)";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->bindValue(':expires_at', date('Y-m-d H:i:s', $expiresAtTimestamp), PDO::PARAM_STR);
            $stmt->bindValue(':user_agent', $userAgent, PDO::PARAM_STR);
            $stmt->bindValue(':token_type', 'access', PDO::PARAM_STR); // Assuming 'access' type

            if (!$stmt->execute()) {
                throw new Exception("Failed to store JWT token.", 500);
            }
        } catch (PDOException $e) {
            // error_log("PDOException storing JWT: " . $e->getMessage());
            throw new Exception("Failed to store JWT token due to database error.", 500, $e);
        }
    }

    /**
     * Checks if a JWT exists in the database and hasn't expired according to DB time.
     * @param string $jwt
     * @return bool
     */
    public function isJwtStoredAndValid(string $jwt): bool {
        $sql = "SELECT 1 FROM jwt_tokens WHERE token = :token AND expires_at > NOW()";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->fetchColumn() !== false;
        } catch (PDOException $e) {
            // error_log("PDOException checking JWT validity: " . $e->getMessage());
            return false; // Treat DB errors as invalid token for safety
        }
    }

    /**
     * Updates the last_used_at timestamp for a given token.
     * @param string $jwt
     * @return void
     */
    public function updateJwtLastUsed(string $jwt): void {
        $sql = "UPDATE jwt_tokens SET last_used_at = NOW() WHERE token = :token";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->execute();
        } catch (PDOException $e) {
            // Log error but don't necessarily halt execution
            // error_log("PDOException updating JWT last_used: " . $e->getMessage());
        }
    }

    /**
     * Deletes a specific JWT from the database.
     * @param string $jwt
     * @return bool Returns true if a row was deleted, false otherwise.
     */
    public function deleteJwt(string $jwt): bool {
        try {
            $sql = "DELETE FROM jwt_tokens WHERE token = :token";
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':token', $jwt, PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // error_log("PDOException deleting JWT: " . $e->getMessage());
            // Depending on requirements, you might re-throw or just return false
            // throw new Exception("Failed to delete JWT token.", 500, $e);
            return false;
        }
    }
}