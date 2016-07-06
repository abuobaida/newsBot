<?php

try {
	$conn = openConnection();
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}

$data = file_get_contents('php://input');

$sql = "INSERT INTO `log` (dump, created)
VALUES ('".print_r($data, true)."', '".date('Y-m-d h:i:s')."')";
$conn->query($sql);

$bot_id = null;
$telegram_api_token = null;

$sql = "SELECT * from settings";

$settings = fetch($sql, $conn);

foreach ($settings as $row) {
	if ($row["key"] == "bot_id") {
		$bot_id = $row["value"];
	} else if ($row["key"] == "telegram_api_token") {
		$telegram_api_token = $row["value"];
	}
}

$json = json_decode($data, true);

if (isset($json["message"])) {
	
	$message = $json["message"];
	
	$from = $message["from"];
	$from_id = $from["id"];
	$from_first_name = isSetOrEmpty($from, "first_name");
	$from_last_name = isSetOrEmpty($from, "last_name");
	$from_username = isSetOrEmpty($from, "username");
	
	$sql = "INSERT INTO `users` (id, first_name, last_name, username)
	VALUES ('".$from_id."', '".$from_first_name."', '".$from_last_name."', '".$from_username."')
	ON DUPLICATE KEY UPDATE
	`first_name` = '".$from_first_name."', `last_name` = '".$from_last_name."', `username` = '".$from_username."'";
	$conn->query($sql);
	
	$chat = $message["chat"];
	$chat_id = $chat["id"];
	$chat_type = $chat["type"];
	$chat_title = isSetOrEmpty($chat, "title");
	$chat_first_name = isSetOrEmpty($chat, "first_name");
	$chat_last_name = isSetOrEmpty($chat, "last_name");
	$chat_username = isSetOrEmpty($chat, "username");
	
	$sql = "INSERT INTO `chats` (id, first_name, last_name, username, type, title)
	VALUES ('".$chat_id."', '".$chat_first_name."', '".$chat_last_name."', 
	'".$chat_username."', '".$chat_type."', '".$chat_title."')
	ON DUPLICATE KEY UPDATE
	`first_name` = '".$chat_first_name."', `last_name` = '".$chat_last_name."',
	`username` = '".$chat_username."', `type` = '".$chat_type."', `title` = '".$chat_title."'";
	
	$conn->query($sql);
	
	if (isset($message["new_chat_member"])) {
		
		$new_member = $message["new_chat_member"];
		$new_member_id = $new_member["id"];
		
		if ($new_member_id == $bot_id) {
			$sql = "UPDATE `chats` set `is_registered` = '1' where `id` = '".$chat_id."'";
			$conn->query($sql);
			
			sendMessageToChat($chat_id, "Hi, I am foodics news. Glad to be here", $telegram_api_token);
			
		} else {
		
			sendMessageToChat($chat_id, "Welcome ".$new_member["first_name"]." to ".$chat_title, $telegram_api_token);
		}
	}
	
	if (isset($message["group_chat_created"])) {
		$sql = "UPDATE `chats` set `is_registered` = '1' where `id` = '".$chat_id."'";
		$conn->query($sql);
		
		sendMessageToChat($chat_id, "Hi, I am foodics news. Glad to be here. This is the beginning", $telegram_api_token);
	}
	
	if (isset($message["left_chat_member"])) {
		
		$left_member = $message["left_chat_member"];
		$left_member_id = $left_member["id"];
		
		if ($left_member_id == $bot_id) {
			$sql = "UPDATE `chats` set `is_registered` = '0' where `id` = '".$chat_id."'";
			$conn->query($sql);
		} else {
		
			sendMessageToChat($chat_id, "Sorry to see ".$left_member["first_name"]." go", $telegram_api_token);
		}
	}
	
	if (isset($message["entities"])) {
		foreach ($message["entities"] as $entity) {
			if ($entity["type"] = "bot_command") {
				echo("have a command <br/>");
				if (command($message["text"], $entity["offset"], $entity["length"]) == "broadcast") {
					echo("have a broadcast <br/>");
					broadcast(substr($message["text"], $entity["offset"] + $entity["length"] + 1), $conn, $telegram_api_token);
				} else {
					sendMessageToChat($chat_id, "Can only /broadcast for now", $telegram_api_token);
				}
			}
		}
	}
}

$conn = null;

function openConnection() {
	$servername = "localhost";
	$username = "root";
	$password = "";

    $conn = new PDO("mysql:host=$servername;dbname=foodics_bot", $username, $password);
	// set the PDO error mode to exception
	$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	
	return $conn;
}

function isSetOrEmpty($object, $key) {

	if (isset($object[$key])) {	
		return $object[$key];
	} else {
		return "";
	}
}

function fetch($sql, $conn) {
	
	$stmt = $conn->prepare($sql);
	$stmt->execute();
	$stmt->setFetchMode(PDO::FETCH_ASSOC);
	return $stmt->fetchAll();
}

function sendMessageToChat($chat_id, $text, $api_token) {
	$message = array();
	$message["chat_id"] = $chat_id;
	$message["text"] = $text;
	$body = json_encode($message);
	$url = "https://api.telegram.org/bot".$api_token."/sendMessage";

// 	$options = array(
// 		'http' => array(
// 			'header'  => "Content-type: application/json",
// 			'method'  => 'POST',
// 			'content' => $body
// 		)
// 	);
// 	$context  = stream_context_create($options);
// 	file_get_contents($url, false, $context);

	$cmd = "curl -X POST -H 'Content-Type: application/json'";
	$cmd.= " -d '" . $body . "' " . "'" . $url . "'";
	$cmd .= " > /dev/null 2>&1 &";
	
	exec($cmd, $output, $exit);
}

function broadcast($text, $conn, $api_token) {
	
	$chats = fetch("SELECT * FROM `chats` WHERE `is_registered` = '1'", $conn);
	foreach($chats as $chat) {
		sendMessageToChat($chat["id"], $text, $api_token);
	}
}

function command($text, $offset, $length) {
	$sub = substr($text, $offset, $length);
	echo($sub);
	return substr($text, $offset+1, $length-1);
}
?>