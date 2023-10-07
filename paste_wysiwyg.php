<?php
$db_location = ".";
?>

<html>

<head>
	<meta name="viewport" content="width=device-width, initial-scale=1">

	<script type="text/javascript" src="include/jquery-3.3.1.js"></script>

	<script src="ckeditor/ckeditor.js"></script>

	<title>Paste Bin</title>

	<link rel="icon" href="paste.ico" />
	<link rel="stylesheet" href="bootstrap-5.2.2-dist/css/bootstrap.min.css">
	<script src="bootstrap-5.2.2-dist/js/bootstrap.min.js"></script>
</head>
<?php

// SQLITE
try {
	$initDB = false;
	if (!is_file((isset($db_location) ? $db_location : __DIR__) . "/pastebin.db")) {
		$connection = new PDO("sqlite:" . $db_location . "/pastebin.db");
		$stmt = $connection->prepare("CREATE TABLE saved_notes (id integer NOT NULL PRIMARY KEY AUTOINCREMENT,  note text NOT NULL,  date timestamp NOT NULL DEFAULT current_timestamp,  filename varchar(255) DEFAULT NULL, pinned INTEGER DEFAULT 0 NOT NULL)");
		$stmt->execute();
	}
} catch (Exception $e) {
	echo "Unable to connect to local database.<br>" . $e->getMessage();
}

//DATABASE
try {
	$connection = new PDO("sqlite:" . $db_location . "/pastebin.db");
} catch (Exception $e) {
	echo 'Caught exception: ',  $e->getMessage(), "\n";
	die;
}
/////////////////////

function getNoteFromDB($id)
{
	global $connection;
	$sth = $connection->prepare("SELECT * FROM saved_notes where id=:id");
	$sth->bindValue(':id', $id);
	$sth->execute();
	return $sth->fetch(PDO::FETCH_ASSOC);
}

function removeFile($name)
{
	if (isset($name)) {
		$p = realpath($name);
		if ($p !== false) {
			unlink($p);
			return true;
		}
	}
	return false;
}

if (isset($_POST['note']) && isset($_POST['id'])) {
	$stmt = $connection->prepare('update saved_notes set note=:note where id=:id');
	$stmt->bindValue(':note', $_POST['note']);
	$stmt->bindValue(':id', $_POST['id']);
	$stmt->execute();
	$redirect = $_POST['id'];
} elseif (isset($_POST['note'])) {
	$stmt = $connection->prepare('insert into saved_notes (note) values (:note)');
	$stmt->bindValue(':note', $_POST['note']);
	$stmt->execute();
	$_POST['id'] = $connection->lastInsertId();
	$redirect = $_POST['id'];
} elseif (isset($_POST['remove'])) {
	$note = getNoteFromDB($_POST['remove']);
	removeFile($note['filename']);
	$stmt = $connection->prepare('delete from saved_notes where id=:id');
	$stmt->bindValue(':id', $_POST['remove']);
	$stmt->execute();
	header('Location: paste.php');
	exit;
} elseif (isset($_POST['pin_note']) && isset($_POST['pin_note_val'])) {
	$stmt = $connection->prepare('update saved_notes set pinned=:pinned where id=:id');
	$stmt->bindValue(':pinned', $_POST['pin_note_val']);
	$stmt->bindValue(':id', $_POST['pin_note']);
	$stmt->execute();
	$redirect = $_POST['id'];
}
$file_upload_result = NULL;
if (isset($_FILES['fileToUpload']['tmp_name']) && $_FILES['fileToUpload']['tmp_name'] !== '') {
	$file_upload_result = "";
	$target_dir = "paste_uploads/";
	$target_file = $target_dir . basename($_FILES["fileToUpload"]["name"]);
	$uploadOk = 1;
	$imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

	// Check if file already exists
	if (file_exists($target_file)) {
		$file_upload_result .= "Sorry, file already exists.<br>";
		$uploadOk = 0;
	}

	// Check file size
	$MAX_SIZE  = 20 * 1024 * 1024;
	if ($_FILES["fileToUpload"]["size"] > $MAX_SIZE) {
		$file_upload_result .= "Sorry, your file is too large.<br>";
		$uploadOk = 0;
	}

	if ($uploadOk == 0) {
		$file_upload_result .= "Sorry, your file was not uploaded.";
		// if everything is ok, try to upload file
	} else {
		if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
			$note = getNoteFromDB($_POST['id']);
			$r = removeFile($note['filename']);
			$file_upload_result .= "The file " . htmlspecialchars(basename($_FILES["fileToUpload"]["name"])) . " has been uploaded.";
			$stmt = $connection->prepare('update saved_notes set filename=:filename where id=:id');
			$stmt->bindValue(':filename', $target_file);
			$stmt->bindValue(':id', $_POST['id']);
			$stmt->execute();
		} else {
			$file_upload_result .= "Sorry, there was an error uploading your file.";
		}
	}
}
if (isset($redirect) && $redirect) {
	header('Location: paste.php?id=' . $redirect);
	exit;
}
$_POST = [];
$form_textarea_rows = 5;
if (isset($_GET['id'])) {
	$saved_note = getNoteFromDB($_GET['id']);
	if (isset($saved_note['note']) && strlen($saved_note['note']) > 200) {
		$form_textarea_rows = 15;
	}
}
?>

<body style='width:70%'>
	<div class="container-fluid">
		<?php if (isset($_GET['view']) && $_GET['view'] === 'html') { ?>
			<div class="row">
				<div class="col-lg">
					<div style="position: relative;top:5px;border:1px;border-color:black;border-style:solid;">
						<?php if (isset($saved_note['note'])) {
							echo $saved_note['note'];
						} ?>
					</div>
				</div>
			</div>


		<?php } else { ?>
			<div class="row">
				<div class="col-lg">
					<form action="" method="POST" id="save" name="save" enctype="multipart/form-data">
						<div class="form-group">
							<textarea class="form-control" id="note" name="note" rows="<?php echo $form_textarea_rows; ?>" cols="50"><?php if (isset($saved_note['note'])) {
																																			if (strpos($saved_note['note'], '<p>') !== 0) {
																																				echo str_replace("\r\n", "<br>", $saved_note['note']);
																																			} else {
																																				echo $saved_note['note'];
																																			}
																																		} ?></textarea><br>
						</div>
						<?php if (isset($saved_note['id'])) {
							echo '<input type="hidden" name="id" id="id" value="' . $saved_note['id'] . '">';
						} ?>
						<label for="fileToUpload">
							<?php if (isset($saved_note['filename'])) {
								echo "Replace existing file with (max 20MB):";
							} else {
								echo "Select file to upload (max 20MB):";
							} ?>
						</label>
						<input class="form-control-file" type="file" name="fileToUpload" id="fileToUpload" style="max-width:25%;background-color:LightCyan;">
					</form>
					<?php if (isset($file_upload_result)) echo "<h6>" . $file_upload_result . "</h6>"; ?>
					<?php if (isset($saved_note['filename'])) echo "<a target='blank' href=" . $saved_note['filename'] . ">Download file - " . $saved_note['filename'] . "</a>"; ?>
				</div>
			</div>
		<?php } ?>
		<div class="row">
			<div class="col-lg">
				<?php if (isset($saved_note['id'])) { ?>
					<form action="" method="POST" id="remove_note" name="remove_note" onsubmit="return confirm('Do you really want to delete this note?');">
						<input type="hidden" name="remove" id="remove" value="<?php echo $saved_note['id']; ?>">
					</form>
					<form action="" method="POST" id="pin_note_form" name="pin_note_form">
						<input type="hidden" name="pin_note" id="pin_note" value="<?php echo $saved_note['id']; ?>">
						<input type="hidden" name="pin_note_val" id="pin_note_val" value="<?php echo ($saved_note['pinned'] === 1 ? "0" : "1"); ?>">
					</form>
				<?php } ?>
			</div>
		</div>
		<div class="row">
			<div class="col-lg">
				<div class="alert alert-warning" role="alert" id="unsaved_alert" style="display: none;">
					Note is modified, remember to save it!
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-lg">
				<div class="btn-toolbar" role="toolbar" aria-label="Toolbar with button groups">
					<div class="btn-group me-2" role="group">
						<button type="submit" class="btn btn-primary" form="save">Save</button>
					</div>
					<?php if (isset($saved_note['id'])) { ?>
						<div class="btn-group me-2" role="group">
							<button type="submit" class="btn btn-danger" form="remove_note">Remove</button>
						</div>
						<div class="btn-group me-2" role="group">
							<?php if ($saved_note['pinned'] === 1) { ?>
								<button type="submit" class="btn btn-secondary" form="pin_note_form">Unpin this note</button>
							<?php } else { ?>
								<button type="submit" class="btn btn-success" form="pin_note_form">Pin this note</button>
							<?php } ?>
						</div>
						<div class="btn-group me-2" role="group">
							<a class="btn btn-info" href='paste.php'>New note</a>
						</div>
						<?php if (isset($_GET['view']) && $_GET['view'] === 'html') { ?>
							<div class="btn-group me-2" role="group">
								<a class="btn btn-warning" href='paste.php?<?php echo "id=" . $saved_note['id'] . "&view=form"; ?>'>Go to edit form</a>
							</div>
						<?php } else { ?>
							<div class="btn-group me-2" role="group">
								<a class="btn btn-warning" href='paste.php?<?php echo "id=" . $saved_note['id'] . "&view=html"; ?>'>View as HTML</a>
							</div>
						<?php } ?>
					<?php } ?>
				</div>
				<br><br>
			</div>
		</div>
		<div class="row">
			<div class="col-lg">
				<div class="list-group">
					<?php

					$sth = $connection->prepare("SELECT * FROM saved_notes order by pinned DESC, date DESC");
					$sth->execute();
					$result = $sth->fetchAll(PDO::FETCH_ASSOC);
					foreach ($result as $row) {
						echo "<a href='?id=" . $row['id']
							. "&view=html' class='list-group-item list-group-item-action "
							. ($row['pinned'] === 1 ? 'list-group-item-primary' : '') . " "
							. ((isset($saved_note) && $saved_note['id'] === $row['id']) ? 'active' : '')
							. "' >" . $row['id'] . " - " . $row['date'] . " - " . substr(strip_tags($row['note']), 0, 60) . "</a>";
					}
					?>
				</div>
			</div>
		</div>
	</div>
</body>
<script>
	$("#note").on('input propertychange paste', function() {
		$("#unsaved_alert").show();
	});
	CKEDITOR.replace('note');
</script>

</html>