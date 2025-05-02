<?php

namespace SrcLibrary\Controllers;

use SrcLibrary\Base\BaseWithDB;
use SrcLibrary\Models\AttachmentModel;
use SrcLibrary\Models\SavedNoteModel; // Needed to verify note ownership
use SrcLibrary\Auth\APIAuth;
use \Exception;
use Ramsey\Uuid\Uuid; // Added for UUID generation


class AttachmentController extends BaseWithDB {

    private AttachmentModel $attachmentModel;
    private SavedNoteModel $noteModel;
    private APIAuth $apiAuth;
    private string $uploadDir;

    public function __construct() {
        parent::__construct();
        $this->attachmentModel = new AttachmentModel();
        $this->noteModel = new SavedNoteModel();
        $this->apiAuth = new APIAuth();
    }

    // Helper to get the absolute path to the upload directory
    private function getUploadDir(): string {
        return $_ENV["upload_path"];
    }

    /**
     * Verify if the current user owns the note. Throws exception if not.
     * @param int $noteId
     * @param int $userId
     * @throws Exception
     */
    private function verifyNoteOwnership(int $noteId, int $userId): void {
        $note = $this->noteModel->getNoteById($noteId, $userId);
        if (!$note) {
            throw new Exception("Note not found or access denied.", 404);
        }
    }

    /**
     * Get all attachments for a specific note owned by the authenticated user.
     * @param int $noteId
     */
    public function getAttachmentsForNote(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        // Ensure the user owns the note they are trying to get attachments for
        $this->verifyNoteOwnership($noteId, $userId);

        $attachments = $this->attachmentModel->getAttachmentsByNoteId($noteId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], 'data' => $attachments]);
    }

    /**
     * Create a new attachment record for a note owned by the authenticated user.
     * Handles file upload via multipart/form-data. Expects file under key 'attachment_file'.
     * Saves the file with a UUID name and stores original name + UUID in DB.
     * @param int $noteId
     */
    public function createAttachment(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        // Ensure the user owns the note they are attaching to
        $this->verifyNoteOwnership($noteId, $userId);

        // --- File Upload Handling ---
        if (!isset($_FILES['attachment_file'])) {
            throw new Exception("No file uploaded. Ensure the file is sent via multipart/form-data with the key 'attachment_file'.", 400);
        }

        $file = $_FILES['attachment_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            // Handle various upload errors
            throw new Exception("File upload error: " . $this->uploadErrorMessage($file['error']), 500);
        }

        $originalFileName = basename($file['name']); // Sanitize original name
        $tempFilePath = $file['tmp_name'];

        // Generate UUID for the stored filename
        $fileUuid = Uuid::uuid4()->toString();

        // Define and ensure upload directory exists
        $uploadPath = $this->getUploadDir();
        if (!is_dir($uploadPath)) {
            if (!@mkdir($uploadPath, 0775, true)) {
                throw new Exception("Failed to create upload directory: " . $uploadPath, 500);
            }
        }
        if (!is_writable($uploadPath)) {
            throw new Exception("Upload directory is not writable: " . $uploadPath, 500);
        }

        $targetPath = $uploadPath . DIRECTORY_SEPARATOR . $fileUuid;

        // Move the uploaded file
        if (!move_uploaded_file($tempFilePath, $targetPath)) {
            throw new Exception("Failed to move uploaded file.", 500);
        }

        // --- Database Record Creation ---
        // Assumes createAttachment model method now accepts ($noteId, $originalFileName, $fileUuid)
        $newAttachmentId = $this->attachmentModel->createAttachment($noteId, $fileUuid, $originalFileName);


        if ($newAttachmentId === false) {
            // Clean up the saved file if DB insertion fails
            @unlink($targetPath);
            throw new Exception("Failed to create attachment record.", 500);
        }

        // Fetch the newly created attachment record to return it
        $newAttachment = $this->attachmentModel->getAttachmentById($newAttachmentId);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 201, 'message' => 'created'], 'data' => $newAttachment]);
    }

    /**
     * Delete an attachment record.
     * Note: This ALSO deletes the actual file from storage.
     * @param int $noteId // Included to verify ownership easily
     * @param int $attachmentId
     */
    public function deleteAttachment(int $noteId, int $attachmentId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        // Ensure the user owns the note associated with the attachment
        $this->verifyNoteOwnership($noteId, $userId);

        // Get attachment details to find the file UUID for deletion
        $attachment = $this->attachmentModel->getAttachmentById($attachmentId);
        if (!$attachment) {
            throw new Exception("Attachment not found.", 404);
        }
        // Extra check: Ensure the attachment belongs to the specified note (belt and suspenders)
        if ($attachment['saved_note_id'] != $noteId) {
            throw new Exception("Attachment does not belong to the specified note.", 403); // Forbidden
        }

        // Delete the database record first
        $success = $this->attachmentModel->deleteAttachment($attachmentId);

        if (!$success) {
            // Could be not found or DB error.
            // If it wasn't found here but was found above, it's likely a race condition or DB error.
            throw new Exception("Failed to delete attachment record.", 500);
        }

        // Delete the actual file from storage
        $filePath = $this->getUploadDir() . DIRECTORY_SEPARATOR . $attachment['file_uuid'];
        if (file_exists($filePath)) {
            if (!@unlink($filePath)) {
                // Log this error, but don't necessarily fail the request,
                // as the DB record is already gone.
                error_log("Failed to delete attachment file: " . $filePath);
            }
        }

        http_response_code(204); // No Content
    }

    /**
     * Serves an attachment file for download.
     * Verifies ownership before serving.
     * @param int $attachmentId
     */
    public function serveAttachment(int $attachmentId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        $attachment = $this->attachmentModel->getAttachmentById($attachmentId);
        if (!$attachment) {
            throw new Exception("Attachment not found.", 404);
        }

        // Verify ownership of the note this attachment belongs to
        $this->verifyNoteOwnership((int)$attachment['saved_note_id'], $userId);

        $filePath = $this->getUploadDir() . DIRECTORY_SEPARATOR . $attachment['file_uuid'];
        $originalFileName = $attachment['file_name'];

        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("Attachment file not found or is not readable on server.", 404);
        }

        // Set headers for download
        header('Content-Description: File Transfer');
        // Try to determine mime type, fallback to octet-stream
        $mimeType = function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream';
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . addslashes($originalFileName) . '"'); // Use original name
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));

        // Clear output buffer and read the file
        ob_clean(); // Clean (erase) the output buffer
        flush(); // Flush the system write buffers
        readfile($filePath);
        exit; // Important to prevent any further output
    }

    /**
     * Helper function to convert UPLOAD_ERR_* constants to readable messages.
     * @param int $errorCode
     * @return string
     */
    private function uploadErrorMessage(int $errorCode): string {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
            case UPLOAD_ERR_FORM_SIZE:
                return "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.";
            case UPLOAD_ERR_PARTIAL:
                return "The uploaded file was only partially uploaded.";
            case UPLOAD_ERR_NO_FILE:
                return "No file was uploaded.";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "Missing a temporary folder.";
            case UPLOAD_ERR_CANT_WRITE:
                return "Failed to write file to disk.";
            case UPLOAD_ERR_EXTENSION:
                return "A PHP extension stopped the file upload.";
            default:
                return "Unknown upload error.";
        }
    }
}
