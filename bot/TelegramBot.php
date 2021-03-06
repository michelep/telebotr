<?php

abstract class TelegramBotCore {
  protected $host;
  protected $port;
  protected $apiUrl;

  public    $botId;
  public    $botUsername;
  protected $botToken;

  protected $handle;
  protected $inited = false;

  protected $lpDelay = 1;
  protected $netDelay = 1;

  protected $updatesOffset = false;
  protected $updatesLimit = 30;
  protected $updatesTimeout = 10;

  protected $netTimeout = 10;
  protected $netConnectTimeout = 5;

  public function __construct($token, $options = array()) {
    $options += array(
      'host' => 'api.telegram.org',
      'port' => 443,
    );

    $this->host = $host = $options['host'];
    $this->port = $port = $options['port'];
    $this->botToken = $token;

    $proto_part = ($port == 443 ? 'https' : 'http');
    $port_part = ($port == 443 || $port == 80) ? '' : ':'.$port;

    $this->apiUrl = "{$proto_part}://{$host}{$port_part}/bot{$token}";
  }

  public function init() {
    global $log;
    if ($this->inited) {
      return true;
    }

    $this->handle = curl_init();

    $response = $this->request('getMe');
    if (!$response['ok']) {
      throw new Exception("Can't connect to server");
    }

    $bot = $response['result'];
    $this->botId = $bot['id'];
    $this->botUsername = $bot['username'];

    $log->debug("TelegramBot::init($this->botId);");

    $this->inited = true;
    return true;
  }

  public function runPoll() {
    $this->init();
    $this->poll();
  }

  public function setWebhook($url) {
    $this->init();
    $result = $this->request('setWebhook', array('url' => $url));
    return $result['ok'];
  }

  public function removeWebhook() {
    $this->init();
    $result = $this->request('deleteWebhook');
    return $result['ok'];
  }

  public function getWebhook() {
    $this->init();
    $result = $this->request('getWebhookInfo');
    return $result;
  }

  public function request($method, $params = array(), $options = array()) {
    $options += array(
      'http_method' => 'GET',
      'timeout' => $this->netTimeout,
    );
    $params_arr = array();
    foreach ($params as $key => &$val) {
      if (!is_numeric($val) && !is_string($val)) {
        $val = json_encode($val);
      }
      $params_arr[] = urlencode($key).'='.urlencode($val);
    }
    $query_string = implode('&', $params_arr);

    $url = $this->apiUrl.'/'.$method;

    if ($options['http_method'] === 'POST') {
      curl_setopt($this->handle, CURLOPT_SAFE_UPLOAD, false);
      curl_setopt($this->handle, CURLOPT_POST, true);
      curl_setopt($this->handle, CURLOPT_POSTFIELDS, $query_string);
    } else {
      $url .= ($query_string ? '?'.$query_string : '');
      curl_setopt($this->handle, CURLOPT_HTTPGET, true);
    }

    $connect_timeout = $this->netConnectTimeout;
    $timeout = $options['timeout'] ?: $this->netTimeout;

    curl_setopt($this->handle, CURLOPT_URL, $url);
    curl_setopt($this->handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($this->handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
    curl_setopt($this->handle, CURLOPT_TIMEOUT, $timeout);

    $response_str = curl_exec($this->handle);
    $errno = curl_errno($this->handle);
    $http_code = intval(curl_getinfo($this->handle, CURLINFO_HTTP_CODE));

    if ($http_code == 401) {
        throw new Exception('Invalid access token provided');
    } else if ($http_code >= 500 || $errno) {
      sleep($this->netDelay);
      if ($this->netDelay < 30) {
        $this->netDelay *= 2;
      }
    }

    $response = json_decode($response_str, true);

    return $response;
  }

  protected function poll() {
    $params = array(
      'limit' => $this->updatesLimit,
      'timeout' => $this->updatesTimeout,
    );

    $result = doQuery("SELECT updatesOffset FROM Bots WHERE ID=:botId;", array(":botId" => $this->botId));
    if($result) {
	$row = $result->fetch(PDO::FETCH_ASSOC);
	if(!empty($row["updatesOffset"])) {
	    $updatesOffset = intval($row["updatesOffset"]);
	    $this->updatesOffset = $updatesOffset;
	}
    }

    if ($this->updatesOffset) {
      $params['offset'] = $this->updatesOffset;
    }
    $options = array(
      'timeout' => $this->netConnectTimeout + $this->updatesTimeout + 2,
    );
    $response = $this->request('getUpdates', $params, $options);
    if ($response['ok']) {
      $updates = $response['result'];
      if(is_array($updates) && (count($updates) > 0)) {
        foreach ($updates as $update) {
            $this->updatesOffset = $update['update_id'] + 1;
	    doQuery("UPDATE Bots SET updatesOffset=:updatesOffset WHERE ID=:botId;", array(":updatesOffset" => $this->updatesOffset, ":botId" => $this->botId));
	    $this->onUpdateReceived($update);
        }
      }
    }

    $this->onPoll();
  }

  abstract public function onUpdateReceived($update);
  abstract public function onPoll();
}

class TelegramBot extends TelegramBotCore {
    protected $chatClass;
    protected $chatInstances = array();

    public function __construct($bot_id, $token, $chat_class, $options = array()) {
	parent::__construct($token, $options);

	$instance = new $chat_class($this, $bot_id, 0);
	if (!($instance instanceof TelegramBotChat)) {
	    throw new Exception('ChatClass must be extends TelegramBotChat');
	}
	$this->chatClass = $chat_class;

	/* Clean inactive private chat sessions older that 30 days */
	doQuery("DELETE FROM Chats WHERE DATEDIFF(chgDate,NOW()) > 30 AND Type LIKE 'private';");

	/* Now populate chat instances array */
	$result = doQuery("SELECT ID,Type FROM Chats WHERE botId=:bot_id;",array(":bot_id" => $bot_id));
	if($result) {
	    while($row = $result->fetch(PDO::FETCH_ASSOC)) {
		$chat_id = $row["ID"];
		$chat_type = $row["Type"];
		$instance = new $this->chatClass($this, $bot_id, $chat_id, $chat_type);
		$this->chatInstances[$chat_id] = $instance;
	    }
	}
    }

  public function onUpdateReceived($update) {
    global $log;
    if (!empty($update['message'])) {
	$message = $update['message'];
	$chat_id = $message['chat']['id'];
	$chat_type = $message['chat']['type'];

	$log->debug("onUpdateReceived($chat_id,$chat_type)");

	if ($chat_id) {
	    $chat = $this->getChatInstance($chat_id,$chat_type);
	    if(isset($message['new_chat_member'])) {
		if ($message['new_chat_member']['id'] == $this->botId) {
		    $chat->bot_added_to_chat($message);
		}
	    } else if (isset($message['left_chat_member'])) {
		if ($message['left_chat_member']['id'] == $this->botId) {
		    $chat->bot_kicked_from_chat($message);
		}
	    } else {
		$text = trim($message['text']);
		$username = strtolower('@'.$this->botUsername);
		$username_len = strlen($username);
		if (strtolower(substr($text, 0, $username_len)) == $username) {
		    $text = trim(substr($text, $username_len));
		}
		if (preg_match('/^(?:\/([a-z0-9_]+)(@[a-z0-9_]+)?(?:\s+(.*))?)$/is', $text, $matches)) {
		    $command = $matches[1];
		    $command_owner = $command_params = false;
		    if(isset($matches[2])) $command_owner = strtolower($matches[2]);
			if(isset($matches[3])) $command_params = $matches[3];
			if (!$command_owner || $command_owner == $username) {
			    $method = 'command_'.$command;
			    if (method_exists($chat, $method)) {
			        $chat->$method($command_params, $message);
			    } else {
			        $chat->some_command($command, $command_params, $message);
			    }
			}
		    } else {
		        $chat->message($text, $message);
		    }
		}
	    }
	}
    }

    protected function getChatInstance($chat_id,$chat_type='private') {
	/* If this is a new chat, create a new instance */
	if (!isset($this->chatInstances[$chat_id])) {
	    $instance = new $this->chatClass($this, $this->botId, $chat_id, $chat_type);
	    $this->chatInstances[$chat_id] = $instance;
	    $instance->init();
	}
	return $this->chatInstances[$chat_id];
    }

    public function onPoll() {
	global $log;
	$log->debug("onPoll()");
	/* Call on_poll() on every chat istances ! */
	foreach(array_keys($this->chatInstances) as $chat_id) {
	    if($chat_id) {
		$instance = $this->getChatInstance($chat_id);
        	if(method_exists($instance, 'on_poll')) {
		    $instance->on_poll();
		}
	    }
	}
    }
}

abstract class TelegramBotChat {

    protected $core;
    protected $chatId;
    protected $botId;
    protected $isGroup;
    protected $chatType;

    protected $AVP=array();

    public function __construct($core, $bot_id, $chat_id, $chat_type='private') {
	if (!($core instanceof TelegramBot)) {
	    throw new Exception('$core must be TelegramBot instance');
	}
	$this->core = $core;
	$this->botId = $bot_id;
	$this->chatId = $chat_id;
	$this->chatType = $chat_type;
	$this->isGroup = $chat_id < 0;

	if($chat_id) {
	    doQuery("INSERT IGNORE INTO Chats(ID,Type,botId,addDate) VALUES (:chatId,:chatType,:botId,NOW()) ON DUPLICATE KEY UPDATE chgDate=NOW();",array(":chatId" => $chat_id, ":chatType" => $chat_type, ":botId" => $bot_id));

	    $result = doQuery("SELECT AVP FROM Chats WHERE ID=:chatId AND botId=:botId;", array(":chatId" => $chat_id, ":botId" => $bot_id));
	    if($result) {
		while($row = $result->fetch(PDO::FETCH_ASSOC)) {
		    if($row["AVP"]) {
			$this->AVP = unserialize($row["AVP"]);
		    }
		}
	    }
	}
    }

    function __destruct() {
	doQuery("UPDATE Chats SET AVP=:AVP WHERE ID=:chatId AND botId=:botId;", array(":AVP" => serialize($this->AVP), ":chatId" => $this->chatId, ":botId" => $this->botId));
    }

    function getAVP($key) {
	if(isset($this->AVP[$key])) {
	    return $this->AVP[$key];
	} else return false;
    }

    function clearAVP($key) {
	unset($this->AVP[$key]);
    }

    function setAVP($key, $value) {
	$this->AVP[$key] = $value;
    }

    public function init() {}

    public function on_poll() {}

    public function bot_added_to_chat($message) {}

    public function bot_kicked_from_chat($message) {}

    public function some_command($command, $params, $message) {}

    public function message($text, $message) {}

    protected function apiSendMessage($text, $params = array()) {
	$params += array(
	    'chat_id' => $this->chatId,
	    'text' => $text,
	    'parse_mode' => 'HTML',
	);
	return $this->core->request('sendMessage', $params);
    }
}
