<?php

namespace SrcLibrary\Models;

use SrcLibrary\Base\BaseWithDB;
use \PDO;
use \PDOException;
use \Exception;

class AttachmentModel extends BaseWithDB {

    /**
     * Creates a new attachment record linked to a saved note.
     *
     * @param int $savedNoteId The ID of the saved note this attachment belongs to.
     * @param string $fileUuid The unique identifier (e.g., UUID) for the stored file.
     * @param string $fileName The original name of the uploaded file.
     * @return int|false The ID of the newly created attachment record, or false on failure.
     * @throws Exception
     */
    public function createAttachment(int $savedNoteId, string $fileUuid, string $fileName): int|false {
        $sql = "INSERT INTO attachments (saved_note_id, file_uuid, file_name) VALUES (:saved_note_id, :file_uuid, :file_name)";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':saved_note_id', $savedNoteId, PDO::PARAM_INT);
            $stmt->bindValue(':file_uuid', $fileUuid, PDO::PARAM_STR);
            $stmt->bindValue(':file_name', $fileName, PDO::PARAM_STR);

             if ($stmt->execute()) {
                return (int)$this->db->dbh->lastInsertId();
            } else {
                throw new Exception("Failed to create attachment record.", 500);
            }
        } catch (PDOException $e) {
            error_log("PDOException creating attachment: " . $e->getMessage());
            // Check for foreign key constraint violation (e.g., note doesn't exist)
            if ($e->getCode() == '23000') { // Integrity constraint violation
                 throw new Exception("Failed to create attachment: Invalid saved note ID.", 400, $e);
            }
            throw new Exception("Failed to create attachment due to database error.", 500, $e);
        }
    }

    /**
     * Retrieves all attachments associated with a specific saved note.
     *
     * @param int $savedNoteId The ID of the saved note.
     * @return array An array of attachments (each as an associative array including 'file_name').
     */
    public function getAttachmentsByNoteId(int $savedNoteId): array {
        $sql = "SELECT id, file_name, file_uuid, created_at, updated_at, saved_note_id
                 FROM attachments
                WHERE saved_note_id = :saved_note_id
                ORDER BY created_at ASC";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':saved_note_id', $savedNoteId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PDOException fetching attachments by note ID: " . $e->getMessage());
            return []; // Return empty array on error
        }
    }

    /**
     * Retrieves a specific attachment by its ID.
     *
     * @param int $attachmentId The ID of the attachment to retrieve.
     * @return array|false The attachment data as an associative array (including 'file_name'), or false if not found.
     */
    public function getAttachmentById(int $attachmentId): array|false {
        $sql = "SELECT id, file_name, file_uuid, created_at, updated_at, saved_note_id
                 FROM attachments
                WHERE id = :attachment_id";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':attachment_id', $attachmentId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("PDOException fetching attachment by ID: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Deletes a specific attachment record by its ID.
     * Note: This only deletes the database record, not the actual file. File deletion logic should be handled elsewhere.
     *
     * @param int $attachmentId The ID of the attachment record to delete.
     * @return bool True if a row was deleted, false otherwise.
     */
    public function deleteAttachment(int $attachmentId): bool {
        $sql = "DELETE FROM attachments WHERE id = :attachment_id";
        try {
            $stmt = $this->db->dbh->prepare($sql);
            $stmt->bindValue(':attachment_id', $attachmentId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("PDOException deleting attachment: " . $e->getMessage());
            return false;
        }
    }

    // Note: A method like `deleteAttachmentsByNoteId` is generally not needed
    // if the foreign key constraint `ON DELETE CASCADE` is set, as deleting the note
    // will automatically delete its associated attachments in the database.
    // If the constraint is not set or you need more control (e.g., deleting files first),
    // you would add such a method here.
}