<?php

namespace SrcLibrary\Models;

use SrcLibrary\Base\BaseWithDB;
use \PDO;
use \PDOException;
use \Exception;

class SavedNoteModel extends BaseWithDB {

    /**
     * Creates a new saved note for a user.
     *
     * @param int $userId The ID of the user creating the note.
     * @param string $noteContent The content of the note.
     * @return int|false The ID of the newly created note, or false on failure.
     * @throws Exception
     */
    public function createNote(int $userId, string $noteContent): int|false {
        $sql = "INSERT INTO saved_notes (user_id, note, note_title) VALUES (:user_id, :note, :note_title)";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':note', $noteContent, PDO::PARAM_STR);
            $stmt->bindValue(':note_title', $this->generateTitle($noteContent), PDO::PARAM_STR);

            if ($stmt->execute()) {
                return (int)$this->db->dbh->lastInsertId();
            } else {
                throw new Exception("Failed to create saved note.", 500);
            }
        } catch (PDOException $e) {
            error_log("PDOException creating saved note: " . $e->getMessage());
            throw new Exception("Failed to create saved note due to database error.", 500, $e);
        }
    }

    /**
     * Retrieves a specific note by its ID and user ID.
     *
     * @param int $noteId The ID of the note to retrieve.
     * @param int $userId The ID of the user who owns the note.
     * @return array|false The note data as an associative array, or false if not found or not owned by the user.
     */
    public function getNoteById(int $noteId, int $userId): array|false {
        $sql = "SELECT id, user_id, note, note_title, created_at, updated_at
                FROM saved_notes
                WHERE id = :note_id AND user_id = :user_id";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':note_id', $noteId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PDOException fetching note by ID: " . $e->getMessage());
            return false; // Treat DB errors as not found for safety
        }
    }

    /**
     * Retrieves all notes for a specific user.
     *
     * @param int $userId The ID of the user whose notes to retrieve.
     * @return array An array of notes (each as an associative array).
     */
    public function getNotesByUserId(int $userId): array {
        $sql = "SELECT id, user_id, note, note_title, created_at, updated_at
                FROM saved_notes
                WHERE user_id = :user_id
                ORDER BY updated_at DESC"; // Or created_at, depending on desired order
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PDOException fetching notes by user ID: " . $e->getMessage());
            return []; // Return empty array on error
        }
    }

    /**
     * Updates the content of an existing note.
     *
     * @param int $noteId The ID of the note to update.
     * @param int $userId The ID of the user who owns the note.
     * @param string $newNoteContent The new content for the note.
     * @return bool True if the update was successful, false otherwise.
     */
    public function updateNote(int $noteId, int $userId, string $newNoteContent): bool {
        $sql = "UPDATE saved_notes SET note = :note, note_title = :note_title, updated_at = NOW()
                WHERE id = :note_id AND user_id = :user_id";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':note', $newNoteContent, PDO::PARAM_STR);
            $stmt->bindValue(':note_id', $noteId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':note_title', $this->generateTitle($newNoteContent), PDO::PARAM_STR);
            $stmt->execute();
            return $stmt->rowCount() > 0; // Check if any row was actually updated
        } catch (PDOException $e) {
            error_log("PDOException updating note: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a specific note owned by a user.
     * Note: Associated attachments will be deleted automatically due to FK constraint ON DELETE CASCADE.
     *
     * @param int $noteId The ID of the note to delete.
     * @param int $userId The ID of the user who owns the note.
     * @return bool True if a row was deleted, false otherwise.
     */
    public function deleteNote(int $noteId, int $userId): bool {
        // The ON DELETE CASCADE constraint on `fk_attachment_note_id`
        // should handle deleting related attachments automatically when a note is deleted.
        // If that constraint wasn't set, you'd need to delete attachments manually first.

        $sql = "DELETE FROM saved_notes WHERE id = :note_id AND user_id = :user_id";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':note_id', $noteId, PDO::PARAM_INT);
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("PDOException deleting note: " . $e->getMessage());
            return false;
        }
    }

    private function generateTitle(string $noteContent): string {
        $rn = strpos($noteContent, "\n");
        $end = 50;
        if (is_int($rn) && $rn < 50) {
            $end = $rn;
        }
        return strip_tags(substr($noteContent, 0, $end));
    }
}
