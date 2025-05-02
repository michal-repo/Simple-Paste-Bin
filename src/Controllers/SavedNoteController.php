<?php

namespace SrcLibrary\Controllers;

use SrcLibrary\Base\BaseWithDB;
use SrcLibrary\Models\AttachmentModel; // Added to fetch attachments
use SrcLibrary\Models\SavedNoteModel;
use SrcLibrary\Auth\APIAuth;
use \Exception;

// Added to use the deleteAttachment method which handles file deletion too
use SrcLibrary\Controllers\AttachmentController;

class SavedNoteController extends BaseWithDB {

    private SavedNoteModel $noteModel;
    private APIAuth $apiAuth;
    private AttachmentModel $attachmentModel; // Added
    public function __construct() {
        parent::__construct();
        $this->noteModel = new SavedNoteModel();
        $this->attachmentModel = new AttachmentModel();
        $this->apiAuth = new APIAuth(); // Used to get the authenticated user ID
    }

    /**
     * Get all notes for the authenticated user.
     */
    public function getAllNotes(): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401); // Should be caught by ensureAuthenticated generally
        }

        $notes = $this->noteModel->getNotesByUserId($userId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], 'data' => $notes]);
    }

    /**
     * Get a specific note by ID for the authenticated user.
     * @param int $noteId
     */
    public function getNoteById(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        $note = $this->noteModel->getNoteById($noteId, $userId);

        if (!$note) {
            throw new Exception("Note not found or access denied.", 404);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], 'data' => $note]);
    }

    /**
     * Create a new note for the authenticated user.
     */
    public function createNote(): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || !isset($input['note']) || !is_string($input['note'])) {
            throw new Exception("Missing or invalid 'note' content in JSON body.", 400);
        }

        $noteContent = $input['note'];
        $newNoteId = $this->noteModel->createNote($userId, $noteContent);

        if ($newNoteId === false) {
            throw new Exception("Failed to create note.", 500);
        }

        // Fetch the newly created note to return it
        $newNote = $this->noteModel->getNoteById($newNoteId, $userId);

        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 201, 'message' => 'created'], 'data' => $newNote]);
    }

    /**
     * Update an existing note for the authenticated user.
     * @param int $noteId
     */
    public function updateNote(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || !isset($input['note']) || !is_string($input['note'])) {
            throw new Exception("Missing or invalid 'note' content in JSON body.", 400);
        }

        $newNoteContent = $input['note'];
        $success = $this->noteModel->updateNote($noteId, $userId, $newNoteContent);

        if (!$success) {
            // Could be not found, not owned, or DB error. Check existence first for better error.
            if (!$this->noteModel->getNoteById($noteId, $userId)) {
                throw new Exception("Note not found or access denied.", 404);
            }
            throw new Exception("Failed to update note.", 500);
        }

        // Fetch the updated note to return it
        $updatedNote = $this->noteModel->getNoteById($noteId, $userId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], 'data' => $updatedNote]);
    }

    /**
     * Delete a note for the authenticated user.
     * @param int $noteId
     */
    public function deleteNote(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        // --- BEGIN Attachment Deletion ---

        // First, verify the user owns the note they are trying to delete.
        // This also implicitly verifies the note exists before we try deleting attachments.
        $note = $this->noteModel->getNoteById($noteId, $userId);
        if (!$note) {
            throw new Exception("Note not found or access denied.", 404);
        }

        // Instantiate AttachmentController to use its delete method
        // It handles both DB record AND file deletion.
        $attachmentController = new AttachmentController();
        $attachments = $this->attachmentModel->getAttachmentsByNoteId($noteId);

        foreach ($attachments as $attachment) {
            try {
                // Call the deleteAttachment method from AttachmentController
                // It requires noteId for an internal ownership check (though redundant here)
                // and the specific attachment ID.
                $attachmentController->deleteAttachment($noteId, (int)$attachment['id']);
            } catch (Exception $e) {
                // If deleting an attachment fails, stop the whole process to avoid partial deletion.
                // Log the original error for debugging.
                error_log("Failed to delete attachment ID {$attachment['id']} during note deletion (Note ID: {$noteId}): " . $e->getMessage());
                throw new Exception("Failed to delete an associated attachment (ID: {$attachment['id']}). Note deletion aborted.", 500, $e);
            }
        }
        // --- END Attachment Deletion ---

        // Now, delete the note itself
        $success = $this->noteModel->deleteNote($noteId, $userId);

        if (!$success) {
            // If attachments were deleted but note deletion fails now, it's likely a DB issue.
            throw new Exception("Failed to delete note after successfully deleting attachments.", 500);
        }

        http_response_code(204); // No Content is appropriate for successful DELETE
        // No body needed for 204 response
    }

    /**
     * Pin or unpin a note for the authenticated user.
     * @param int $noteId
     */
    public function setPinnedStatus(int $noteId): void {
        $userId = $this->apiAuth->getUserId();
        if ($userId === null) {
            throw new Exception("User ID not found from token.", 401);
        }

        $input = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE || is_null($input) || !isset($input['pinned']) || !is_bool($input['pinned'])) {
            throw new Exception("Missing or invalid 'pinned' status in JSON body.", 400);
        }

        $pinned = $input['pinned'];
        $success = $this->noteModel->setNotePinnedStatus($noteId, $userId, $pinned);

        if (!$success) {
            // Check if the note exists and is owned by the user
            if (!$this->noteModel->getNoteById($noteId, $userId)) {
                throw new Exception("Note not found or access denied.", 404);
            }
            throw new Exception("Failed to update pinned status.", 500);
        }

        // Fetch the updated note to return it
        $updatedNote = $this->noteModel->getNoteById($noteId, $userId);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => ['code' => 200, 'message' => 'ok'], 'data' => $updatedNote]);
    }
}
