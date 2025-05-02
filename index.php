<?php

namespace Router;

// Session cookie path setting is no longer relevant for JWT auth state
// $cookiePath = empty($_ENV['domain_path']) ? '/' : $_ENV['domain_path'];
// \ini_set('session.cookie_path', $cookiePath);

require_once 'vendor/autoload.php';

use SrcLibrary\Auth\APIAuth;
use SrcLibrary\Controllers\SavedNoteController;
use SrcLibrary\Controllers\AttachmentController;
use \Bramus\Router\Router as BRouter;
use Dotenv\Dotenv as Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();


$router = new BRouter();

// --- Enhanced Error Handler ---
// (Keep the existing handleErr function as is)
function handleErr(\Throwable $th) {
    header('Content-Type: application/json; charset=utf-8');
    $code = $th->getCode();
    $message = $th->getMessage();
    $httpStatusCode = 500;
    $jsonMessage = 'Internal Server Error';

    switch ($code) {
        case 400:
            $httpStatusCode = 400;
            $jsonMessage = $message ?: 'Bad Request';
            break;
        case 401:
            $httpStatusCode = 401;
            $jsonMessage = $message ?: 'Unauthorized';
            break; // Used for invalid/missing JWT
        case 404:
            $httpStatusCode = 404;
            $jsonMessage = $message ?: 'Not Found';
            break;
        case 409:
            $httpStatusCode = 409;
            $jsonMessage = $message ?: 'Conflict';
            break;
        case 503:
            $httpStatusCode = 503;
            $jsonMessage = $message ?: 'Service Unavailable';
            break;
        case 429:
            $httpStatusCode = 429;
            $jsonMessage = $message ?: 'Too Many Requests';
            break;
        // Add case for 500 if you want a specific message for generic 500s thrown by your code
        case 500:
            $httpStatusCode = 500;
            $jsonMessage = $message ?: 'Internal Server Error';
            break;
        default:
            // If the code is not one of the specific ones, treat it as a generic 500
            if ($code < 100 || $code >= 600) { // Basic check for valid HTTP status code range
                $httpStatusCode = 500;
                $jsonMessage = 'Internal Server Error';
            } else {
                $httpStatusCode = $code;
                $jsonMessage = $message ?: 'Server Error'; // Use the message if it's a valid HTTP code
            }
            break;
    }


    http_response_code($httpStatusCode);

    if (isset($_ENV['debug']) && $_ENV['debug'] === "true") {
        // Detailed error in debug mode
        echo json_encode([
            'status' => ['code' => $httpStatusCode, 'message' => $message], // Use the original message for debug
            'error_details' => [
                'message' => $th->getMessage(),
                'code' => $th->getCode(),
                'file' => $th->getFile(),
                'line' => $th->getLine(),
                'trace' => $th->getTraceAsString()
            ]
        ], JSON_PRETTY_PRINT); // Added JSON_PRETTY_PRINT for debug readability
    } else {
        // Generic error for production
        if ($httpStatusCode >= 500) { // Log only server-side errors (5xx)
            $logEntry = sprintf(
                "### %s ###\nCode: %d\nMessage: %s\nFile: %s\nLine: %d\nTrace:\n%s\n",
                date('Y-m-d H:i:s'),
                $th->getCode(),
                $th->getMessage(),
                $th->getFile(),
                $th->getLine(),
                $th->getTraceAsString()
            );
            // Ensure logs directory exists and is writable
            @mkdir('logs', 0775, true);
            @file_put_contents('logs/errors.log', $logEntry . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
        echo json_encode(['status' => ['code' => $httpStatusCode, 'message' => $jsonMessage]]); // Use the generic message for production
    }
    die();
}


// --- CORS OPTIONS Request Handler ---
// (Keep the existing handleOptionsRequest function as is)
function handleOptionsRequest(string $allowedMethods) {
    $allowedOrigin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? '*';
    $allowedHeaders = $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type, Authorization, X-Requested-With';
    $maxAge = $_ENV['CORS_MAX_AGE'] ?? '86400';

    header("Access-Control-Allow-Origin: {$allowedOrigin}");
    header("Access-Control-Allow-Methods: {$allowedMethods}, OPTIONS");
    header("Access-Control-Allow-Headers: {$allowedHeaders}");
    header("Access-Control-Max-Age: {$maxAge}");

    http_response_code(204);
    die();
}

// --- Routes ---

$router->set404('/api(/.*)?', function () {
    handleErr(new \Exception("API endpoint not found", 404));
});

// --- Root ---
$router->options('/', function () {
    handleOptionsRequest('GET');
});
$router->get('/', function () {
    $viewPath = __DIR__ . '/src/Views/view.html';
    if (file_exists($viewPath)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($viewPath); // Efficient way to output file content
    } else {
        // Use the existing error handler if the file is missing
        handleErr(new \Exception("View file not found.", 500));
    }
});

// --- Auth Check (Checks JWT Validity) ---
$router->options('/check', function () {
    handleOptionsRequest('GET');
});
$router->get('/check', function () {
    header('Content-Type: application/json; charset=utf-8');
    // Use the helper function which now checks JWT validity
    ensureAuthenticated();
    // If ensureAuthenticated passes, we are logged in
    echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Authenticated"]);
});

// --- Registration (Unaffected by JWT login state) ---
$router->options('/register', function () {
    handleOptionsRequest('GET, POST');
});
$router->get('/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = new APIAuth();
        if (!method_exists($api, 'isRegisterEnabled')) {
            handleErr(new \Exception("Registration check feature not available.", 501));
        }
        if ($api->isRegisterEnabled()) {
            echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Available"]);
        } else {
            throw new \Exception("Registration is disabled.", 503);
        }
    } catch (\Throwable $th) {
        handleErr($th);
    }
});
$router->post('/register', function () {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) {
        handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400));
    }
    if (empty($j["email"]) || empty($j["password"]) || empty($j["username"])) {
        handleErr(new \InvalidArgumentException("Missing required fields: email, password, username.", 400));
    }

    try {
        $api = new APIAuth();
        if (!method_exists($api, 'register')) {
            handleErr(new \Exception("Registration feature not available.", 501));
        }
        $result = $api->register($j["email"], $j["password"], $j["username"]);
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => $result]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

// --- Login (Returns JWT) ---
$router->options('/log-in', function () {
    handleOptionsRequest('POST');
});
$router->post('/log-in', function () {
    header('Content-Type: application/json; charset=utf-8');
    $j = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || is_null($j)) {
        handleErr(new \InvalidArgumentException("Invalid JSON provided.", 400));
    }
    if (empty($j["email"]) || empty($j["password"])) {
        handleErr(new \InvalidArgumentException("Missing required fields: email, password.", 400));
    }

    try {
        $api = new APIAuth();
        $jwtToken = $api->log_in($j["email"], $j["password"]);

        echo json_encode([
            'status' => ['code' => 200, 'message' => 'ok'],
            'data' => ['token' => $jwtToken]
        ]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
});

// --- Logout (Invalidates JWT via Header) ---
function handleLogOut() {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $api = new APIAuth();
        $api->logOut();
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], "data" => "Logged out"]);
    } catch (\Throwable $th) {
        handleErr($th);
    }
}
$router->options('/log-out', function () {
    handleOptionsRequest('GET, POST');
});
$router->get('/log-out', function () {
    handleLogOut();
});
$router->post('/log-out', function () {
    handleLogOut();
});


// --- Helper Functions ---
// (Keep the existing checkGetParam function as is)
function checkGetParam(string $param, $default, int $filter = FILTER_DEFAULT, $options = null) {
    // Ensure default filter is used if none specified that makes sense for general use
    if ($filter !== FILTER_VALIDATE_INT && $filter !== FILTER_VALIDATE_FLOAT && $filter !== FILTER_VALIDATE_BOOLEAN && $filter !== FILTER_VALIDATE_EMAIL && $filter !== FILTER_VALIDATE_URL) {
        $filter = FILTER_DEFAULT; // Sanitize string by default
    }

    // For FILTER_DEFAULT, add STRIP_LOW and STRIP_HIGH flags to prevent potential XSS or control character issues
    if ($filter === FILTER_DEFAULT && $options === null) {
        $options = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    } elseif ($filter === FILTER_DEFAULT && is_array($options) && !isset($options['flags'])) {
        $options['flags'] = FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    } elseif ($filter === FILTER_DEFAULT && is_int($options)) { // If options is just flags
        $options |= FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH;
    }


    $value = filter_input(INPUT_GET, $param, $filter, $options);

    // Check if filter_input failed or returned null
    // For boolean, false is a valid value, so we check specifically for null/false failure
    if ($filter === FILTER_VALIDATE_BOOLEAN) {
        if ($value === null) { // Parameter not present or invalid boolean string
            // Check if parameter exists but is invalid, return default. If not present, also return default.
            if (filter_has_var(INPUT_GET, $param)) {
                // Parameter exists but wasn't a valid boolean representation
                return $default;
            }
            return $default; // Parameter not present
        }
        // filter_input returns true for "1", "true", "on", "yes". Returns false for "0", "false", "off", "no", "". Null otherwise.
        return (bool) $value;
    } elseif ($value === null || $value === false) {
        // For other filters, null or false indicates failure or parameter not set
        // Check if the parameter was actually present but failed validation
        if (filter_has_var(INPUT_GET, $param) && $value === false) {
            // Parameter was present but invalid according to the filter
            return $default; // Return default if validation failed
        }
        // Parameter was not present or filter returned null for other reasons
        return $default;
    }

    // Return the filtered value
    return $value;
}


/**
 * Checks if the user is authenticated via JWT. Throws 401 Exception if not.
 */
function ensureAuthenticated(): void {
    try {
        $api = new APIAuth();
        if (!$api->isLoggedIn()) {
            throw new \Exception('Unauthorized', 401);
        }
    } catch (\Throwable $th) {
        // Re-throw with appropriate code if needed, or let handleErr manage it
        if ($th->getCode() !== 401) { // If the auth check itself failed unexpectedly
            handleErr(new \Exception('Authentication check failed.', 500, $th));
        } else {
            handleErr($th); // Pass the original 401 exception
        }
    }
}

// --- Saved Notes Routes ---
$router->mount('/notes', function () use ($router) {

    // OPTIONS /notes
    $router->options('/', function () {
        handleOptionsRequest('GET, POST');
    });

    // GET /notes - List all notes for the user
    $router->get('/', function () {
        try {
            ensureAuthenticated();
            $controller = new SavedNoteController();
            $controller->getAllNotes();
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });

    // POST /notes - Create a new note
    $router->post('/', function () {
        try {
            ensureAuthenticated();
            $controller = new SavedNoteController();
            $controller->createNote();
        } catch (\Throwable $th) {
            handleErr($th);
        }
    });


    // --- Attachments for this Note ---
    $router->mount('/{noteId:\d+}/attachments', function () use ($router) {

        // OPTIONS /notes/{noteId}/attachments
        $router->options('/', function () {
            handleOptionsRequest('GET, POST');
        });

        // GET /notes/{noteId}/attachments - List attachments for the note
        $router->get('/', function ($noteId) {
            try {
                ensureAuthenticated();
                $controller = new AttachmentController();
                $controller->getAttachmentsForNote((int)$noteId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });

        // POST /notes/{noteId}/attachments - Create an attachment record for the note
        $router->post('/', function ($noteId) {
            try {
                ensureAuthenticated();
                $controller = new AttachmentController();
                $controller->createAttachment((int)$noteId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });

        // OPTIONS /notes/{noteId}/attachments/{attachmentId}
        $router->options('/{attachmentId:\d+}', function () {
            handleOptionsRequest('DELETE'); // Add GET if you implement getAttachmentById
        });

        // DELETE /notes/{noteId}/attachments/{attachmentId} - Delete an attachment record
        $router->delete('/{attachmentId:\d+}', function ($noteId, $attachmentId) {
            try {
                ensureAuthenticated();
                $controller = new AttachmentController();
                $controller->deleteAttachment((int)$noteId, (int)$attachmentId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });
    });

    // --- Specific Note Routes ---
    $router->mount('/{noteId:\d+}', function () use ($router) {

        // OPTIONS /notes/{noteId}
        $router->options('/', function () {
            handleOptionsRequest('GET, PUT, DELETE');
        });

        // GET /notes/{noteId} - Get a specific note
        $router->get('/', function ($noteId) {
            try {
                ensureAuthenticated();
                $controller = new SavedNoteController();
                $controller->getNoteById((int)$noteId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });

        // PUT /notes/{noteId} - Update a specific note
        $router->put('/', function ($noteId) {
            try {
                ensureAuthenticated();
                $controller = new SavedNoteController();
                $controller->updateNote((int)$noteId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });

        // DELETE /notes/{noteId} - Delete a specific note
        $router->delete('/', function ($noteId) {
            try {
                ensureAuthenticated();
                $controller = new SavedNoteController();
                $controller->deleteNote((int)$noteId);
            } catch (\Throwable $th) {
                handleErr($th);
            }
        });
    }); // End /notes/{noteId} mount


}); // End /notes mount

// --- Run the router ---

// --- Attachment Download Route (Top Level) ---
$router->mount('/attachments', function () use ($router) {

    // OPTIONS /attachments/download/{attachmentId}
    $router->options('/download/{attachmentId:\d+}', function () {
        handleOptionsRequest('GET');
    });

    // GET /attachments/download/{attachmentId} - Serve the attachment file for download
    $router->get('/download/{attachmentId:\d+}', function ($attachmentId) {
        try {
            ensureAuthenticated(); // Ensure user is logged in
            $controller = new AttachmentController();
            $controller->serveAttachment((int)$attachmentId);
        } catch (\Throwable $th) {
            handleErr($th); // Let the error handler manage file not found, auth errors, etc.
        }
    });
});

$router->run();
