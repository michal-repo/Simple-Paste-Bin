<?php

namespace SrcLibrary\Auth;

use SrcLibrary\Base\BaseWithDB;
use SrcLibrary\Models\JwtTokenModel;
use \Exception;
use \PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class APIAuth extends BaseWithDB {

    private string $jwtSecret;
    private int $jwtExpirySeconds;
    private string $jwtIssuer;
    private string $jwtAudience;
    private string $jwtAlgorithm = 'HS256';
    private JwtTokenModel $jwtTokenModel;

    public function __construct() {
        parent::__construct();

        // Load JWT configuration
        $this->jwtSecret = $_ENV['JWT_SECRET_KEY'] ?? null;
        $this->jwtExpirySeconds = (int)($_ENV['JWT_EXPIRY_SECONDS'] ?? 3600);
        $this->jwtIssuer = $_ENV['JWT_ISSUER'] ?? 'DefaultIssuer';
        $this->jwtAudience = $_ENV['JWT_AUDIENCE'] ?? 'DefaultAudience';

        if (empty($this->jwtSecret)) {
            throw new Exception("JWT_SECRET_KEY is not configured in .env", 500);
        }

        $this->jwtTokenModel = new JwtTokenModel();

        // --- Automatically clean up expired tokens ---
        $this->jwtTokenModel->removeExpiredTokens();
        // --- End cleanup ---
    }

    /**
     * Authenticates user with email/password and returns a JWT upon success.
     *
     * @param string $email
     * @param string $password
     * @return string The generated JWT.
     * @throws Exception On authentication failure or JWT generation/storage error.
     */
    public function log_in($email, $password): string {
        // Temporarily instantiate Delight\Auth just for credential validation
        $delightAuth = new \Delight\Auth\Auth($this->db->dbh);

        try {
            // Validate credentials using Delight\Auth
            $rememberDuration = null; // Not using Delight's remember me with JWT
            $delightAuth->login($email, $password, $rememberDuration);

            // If login succeeds, get the user ID
            $userId = $delightAuth->getUserId();
            if ($userId === null) {
                throw new Exception('Authentication succeeded but failed to get user ID.', 500);
            }

            // --- Generate JWT ---
            $issuedAt = time();
            $notBefore = $issuedAt;
            $expire = $issuedAt + $this->jwtExpirySeconds;

            $payload = [
                'iss' => $this->jwtIssuer,
                'aud' => $this->jwtAudience,
                'iat' => $issuedAt,
                'nbf' => $notBefore,
                'exp' => $expire,
                'sub' => $userId,
            ];

            $jwt = JWT::encode($payload, $this->jwtSecret, $this->jwtAlgorithm);

            // --- Store JWT in Database ---
            $this->jwtTokenModel->storeJwt($userId, $jwt, $expire, $_SERVER['HTTP_USER_AGENT'] ?? null);

            return $jwt;
        } catch (\Delight\Auth\InvalidEmailException | \Delight\Auth\InvalidPasswordException | \Delight\Auth\EmailNotVerifiedException $e) {
            throw new Exception($e->getMessage(), 401, $e);
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            throw new Exception('Too many login requests', 429, $e);
        } catch (\Throwable $th) {
            // error_log("Login/JWT Error: " . $th->getMessage());
            throw new Exception('Login failed due to an unexpected error.', 500, $th);
        }
    }

    /**
     * Invalidates a JWT by removing it from the database.
     *
     * @return void
     * @throws Exception If user is not logged in (no valid token found) or DB error occurs.
     */
    public function logOut(): void {
        $jwt = $this->getJwtFromHeader();
        if ($jwt === null) {
            throw new Exception("No token provided for logout.", 400);
        }

        try {
            if (!$this->jwtTokenModel->deleteJwt($jwt)) {
                // Optionally handle the case where the token wasn't found/deleted
            }
        } catch (\Exception $e) { // Catch potential exceptions from the model
            // error_log("PDOException during logout: " . $e->getMessage());
            throw new Exception('Logout failed due to a database error.', 500, $e);
        }
    }

    /**
     * Checks if a valid, non-expired, database-stored JWT is present in the request.
     *
     * @return bool
     */
    public function isLoggedIn(): bool {
        try {
            return $this->getUserId() !== null;
        } catch (\Throwable $th) {
            return false;
        }
    }

    /**
     * Validates the JWT from the request header and returns the user ID if valid.
     * Returns null if the token is missing, invalid, expired, or not found in the database.
     *
     * @return int|null
     */
    public function getUserId(): ?int {
        $token = $this->getJwtFromHeader();
        if ($token === null) {
            return null;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));

            if (!$this->jwtTokenModel->isJwtStoredAndValid($token)) {
                return null;
            }

            if (isset($decoded->sub) && is_numeric($decoded->sub) && $decoded->sub > 0) {
                $this->updateJwtLastUsed($token);
                return (int)$decoded->sub;
            }

            return null;
        } catch (ExpiredException $e) {
            // Token expired according to JWT payload
            $this->jwtTokenModel->deleteJwt($token);
            return null;
        } catch (SignatureInvalidException | BeforeValidException | \DomainException | \InvalidArgumentException | \UnexpectedValueException $e) {
            // Catch specific JWT validation errors
            return null;
        } catch (\Throwable $th) {
            // error_log("Unexpected error during JWT validation: " . $th->getMessage());
            return null;
        }
    }

    // --- Helper Methods ---

    /**
     * Extracts the JWT from the Authorization header.
     * @return string|null
     */
    private function getJwtFromHeader(): ?string {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader === null && function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        if ($authHeader !== null && stripos($authHeader, 'Bearer ') === 0) {
            return trim(substr($authHeader, 7));
        }
        return null;
    }

    /**
     * Updates the last_used_at timestamp for a given token.
     * @param string $jwt
     * @return void
     */
    private function updateJwtLastUsed(string $jwt): void {
        $this->jwtTokenModel->updateJwtLastUsed($jwt);
    }

    // --- Registration methods (uncommented as they don't rely on JWT state) ---

    public function isRegisterEnabled(): bool {
        return isset($_ENV["register_enabled"]) && $_ENV["register_enabled"] === "true";
    }

    public function register($email, $password, $username) {
        if (!$this->isRegisterEnabled()) {
            throw new Exception("Registration is disabled.", 503);
        }
        if (empty($username) || preg_match('/[\x00-\x1f\x7f\/:\\\\]/', $username)) {
            throw new Exception("Invalid characters in username or username empty.", 400);
        }

        $delightAuth = new \Delight\Auth\Auth($this->db->dbh);
        try {
            $userId = $delightAuth->registerWithUniqueUsername($email, $password, $username);
            // Return a more informative message or just the ID
            return 'We have signed up a new user with the ID ' . $userId;
        } catch (\Delight\Auth\InvalidEmailException $e) {
            throw new Exception("Invalid email address!", 400);
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            throw new Exception("Invalid password!", 400);
        } catch (\Delight\Auth\UserAlreadyExistsException $e) {
            throw new Exception("Email address already exists!", 409);
        } catch (\Delight\Auth\DuplicateUsernameException $e) {
            throw new Exception("Username already exists!", 409);
        } catch (\Delight\Auth\TooManyRequestsException $e) {
            throw new Exception("Too many registration requests!", 429);
        } catch (\Throwable $th) {
            throw new Exception('Registration failed due to an unexpected error.', 500, $th);
        }
    }
}
