<html>
<head>
	<script type="text/javascript" src="include/jquery-3.3.1.js"></script>

	<title>Paste Bin</title>

	<link rel="icon" href="paste.ico" />
	<link rel="stylesheet" href="bootstrap-4.4.1-dist/css/bootstrap.min.css">
	<script src="bootstrap-4.4.1-dist/js/bootstrap.min.js"></script>	
</head>
<?php

/*
create table saved_notes (id int(11) NOT NULL AUTO_INCREMENT,note text NOT NULL,date TIMESTAMP DEFAULT CURRENT_TIMESTAMP, filename varchar(255),PRIMARY KEY (id)) ENGINE=InnoDB;
*/

//DATABASE
$db_user='paste';
$db_pass='password';
$connection = new PDO("mysql:dbname=pastebin;host=localhost", $db_user, $db_pass);
/////////////////////

function getNoteFromDB($id){
	global $connection;
	$sth = $connection->prepare("SELECT * FROM saved_notes where id=?");
	$sth->execute(array($id));
	return $sth->fetch(PDO::FETCH_ASSOC);
}

function removeFile($name){
	if(isset($name)){
		$p = realpath($name);
		if($p !== false){
			unlink($p);
			return true;
		}
	}
	return false;
}

if(isset($_POST['note']) && isset($_POST['id'])){
	$stmt = $connection->prepare('update saved_notes set note=? where id=?');
	$stmt->execute(array($_POST['note'], $_POST['id']));
} elseif(isset($_POST['note'])){
	$stmt = $connection->prepare('insert into saved_notes (note) values (?)');
	$stmt->execute(array($_POST['note']));
	$_POST['id']=$connection->lastInsertId();
	$redirect=$_POST['id'];
} elseif(isset($_POST['remove'])){
	$note=getNoteFromDB($_POST['remove']);
	removeFile($note['filename']);
	$stmt = $connection->prepare('delete from saved_notes where id=?');
	$stmt->execute(array($_POST['remove']));
	header('Location: paste.php');
	exit;
}
$file_upload_result=NULL;
if(isset($_FILES['fileToUpload']['tmp_name']) && $_FILES['fileToUpload']['tmp_name'] !== ''){
	$file_upload_result = "";
	$target_dir = "paste_uploads/";
	$target_file = $target_dir . basename($_FILES["fileToUpload"]["name"]);
	$uploadOk = 1;
	$imageFileType = strtolower(pathinfo($target_file,PATHINFO_EXTENSION));

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
			$note=getNoteFromDB($_POST['id']);
			$r = removeFile($note['filename']);
			$file_upload_result .= "The file ". htmlspecialchars( basename( $_FILES["fileToUpload"]["name"])). " has been uploaded.";
			$stmt = $connection->prepare('update saved_notes set filename=? where id=?');
			$stmt->execute(array($target_file, $_POST['id']));
		} else {
			$file_upload_result .= "Sorry, there was an error uploading your file.";
		}
	}
}
if($redirect){
	header('Location: paste.php?id='.$redirect);
	exit;
}
$_POST=[];
$form_textarea_rows = 5;
if (isset($_GET['id'])){
	$saved_note = getNoteFromDB($_GET['id']);
	if(isset($saved_note['note']) && strlen($saved_note['note']) > 200){
		$form_textarea_rows = 15;
	}
}
?>
<body style='width=70%'>
<div class="container-fluid">
		<div class="row">
		<div class="col-lg">
<form action="" method="POST" id="save" name="save" enctype="multipart/form-data">
	<div class="form-group">
	  <textarea class="form-control" id="note" name="note" rows="<?php echo $form_textarea_rows; ?>" cols="50"><?php if(isset($saved_note['note'])){echo $saved_note['note'];} ?></textarea><br>
	</div>
	  <?php if(isset($saved_note['id'])){echo '<input type="hidden" name="id" id="id" value="'.$saved_note['id'].'">';} ?>
	  <label for="fileToUpload">
	  <?php if(isset($saved_note['filename'])){echo "Replace existing file with (max 20MB):";} else {echo "Select file to upload (max 20MB):";} ?>
		</label>
  <input class="form-control-file" type="file" name="fileToUpload" id="fileToUpload">
</form>
<?php if(isset($file_upload_result)) echo "<h6>".$file_upload_result."</h6>"; ?>
<?php if(isset($saved_note['filename'])) echo "<a target='blank' href=".$saved_note['filename'].">Download file - ".$saved_note['filename']."</a>"; ?>
</div>
</div>
<div class="row">
<div class="col-lg">
<?php if(isset($saved_note['id'])){?>
<form action="" method="POST" id="remove_note" name="remove_note">
	<input type="hidden" name="remove" id="remove" value="<?php echo $saved_note['id']; ?>">
</form>
<?php } ?>
</div>
</div>
<div class="row">
<div class="col-lg">
<div class="btn-toolbar" role="toolbar" aria-label="Toolbar with button groups">
	<div class="btn-group mr-2" role="group">
	  <button type="submit" class="btn btn-primary" form="save">Save</button>
	</div>
	  <?php if(isset($saved_note['id'])) { ?>
	  <div class="btn-group mr-2" role="group">
	  <button type="submit" class="btn btn-danger" form="remove_note">Remove</button>
	  </div>
	  <div class="btn-group mr-2" role="group">
	  <a class="btn btn-info" href='paste.php'>New note</a>
	  </div>
	  <?php } ?>
</div>
<br><br>
</div>
</div>
<div class="row">
<div class="col-lg">
<?php

$sth = $connection->prepare("SELECT * FROM saved_notes order by date");
$sth->execute();
$result = $sth->fetchAll(PDO::FETCH_ASSOC);
foreach($result as $row){
	?>
	
	<div class="list-group">
	  <?php
	echo "<a href='?id=".$row['id']."' class='list-group-item list-group-item-action' >".$row['id']." - ".$row['date']." - ".substr($row['note'], 0, 60)."</a>";
	  ?>
	</div>
	
	
	<?php
}


?>
</div>
</div>
</div>
</body>
</html>