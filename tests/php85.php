<?php
// Run with PHP 8.5 + gd, mbstring, sqlite3, pdo_sqlite, intl and curl.
require __DIR__ . '/strict.php';
$source = dirname(__DIR__);
$checks = 0;
function check($condition, $label) {
	global $checks;
	if (!$condition) throw new RuntimeException($label);
	$checks++;
	echo "PASS $label\n";
}
function copyTree($source, $target) {
	if (!is_dir($target)) mkdir($target, 0700, true);
	foreach (scandir($source) as $name) {
		if ($name === '.' || $name === '..' || $name === '.git' || $name === 'settings.php') continue;
		$from = "$source/$name"; $to = "$target/$name";
		if (is_dir($from)) copyTree($from, $to); else copy($from, $to);
	}
}
function removeTree($path) {
	foreach (scandir($path) as $name) {
		if ($name === '.' || $name === '..') continue;
		$p = "$path/$name";
		if (is_dir($p)) removeTree($p); else unlink($p);
	}
	rmdir($path);
}
function request($url, $data = null, $cookie = '') {
	$headers = "Cookie: $cookie\r\n";
	$options = array('ignore_errors' => true, 'follow_location' => 0, 'timeout' => 10, 'header' => $headers);
	if ($data !== null) {
		$options['method'] = 'POST';
		if (is_array($data)) {
			$options['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
			$options['content'] = http_build_query($data);
		} else {
			$options['header'] .= "Content-Type: multipart/form-data; boundary=tinyib-test\r\n";
			$options['content'] = $data;
		}
	}
	$body = file_get_contents($url, false, stream_context_create(array('http' => $options)));
	return array($body, http_get_last_response_headers());
}
require "$source/inc/dateformat.php";
date_default_timezone_set('Asia/Tokyo');
check(tinyibFormatDate('%g/%m/%d(%a)%H:%M:%S %%', 1735689600) === '25/01/01(Wed)09:00:00 %', 'legacy date setting and timezone');
check(tinyibFormatDate('%G-%g-%V %j %U %W', 1609459200) === '2020-20-53 001 00 00', 'ISO week-year and week numbers');
require "$source/inc/gettext/src/autoloader.php";
$t = new Gettext\Translator();
$t->loadTranslations(Gettext\Translations::fromPoFile("$source/locale/ja/tinyib.po"));
check($t->gettext('Name') !== 'Name', 'Japanese PO translation without deprecations');
require "$source/inc/recaptcha/autoload.php";
new ReCaptcha\ReCaptcha('test-only');
new ReCaptcha\RequestMethod\CurlPost();
new ReCaptcha\RequestMethod\SocketPost();
check(true, 'reCAPTCHA constructors without nullable-parameter warnings');

$modes = array('flatfile', 'sqlite3');
if (getenv('TINYIB_TEST_MYSQL_HOST')) $modes = array_merge($modes, array('mysqli', 'pdo'));
foreach ($modes as $mode) {
	$root = sys_get_temp_dir() . '/tinyib-test-' . bin2hex(random_bytes(6));
	copyTree($source, $root);
	$settings = file_get_contents("$root/settings.default.php");
	foreach (array("'TINYIB_DBMODE', 'flatfile'" => "'TINYIB_DBMODE', '$mode'", "'TINYIB_LOCALE', ''" => "'TINYIB_LOCALE', 'ja'", "'TINYIB_TRIPSEED', ''" => "'TINYIB_TRIPSEED', 'local-test-seed'", "'TINYIB_NOFILEOK', false" => "'TINYIB_NOFILEOK', true", "'TINYIB_DELAY', 30" => "'TINYIB_DELAY', 0", "'TINYIB_ADMINPASS', ''" => "'TINYIB_ADMINPASS', 'local-test-password'") as $from => $to) $settings = str_replace($from, $to, $settings);
	if ($mode === 'mysqli' || $mode === 'pdo') {
		$dbName = 'test_' . $mode . '_' . bin2hex(random_bytes(4));
		$host = getenv('TINYIB_TEST_MYSQL_HOST');
		$db = new PDO("mysql:host=$host", 'root', '');
		$db->exec("CREATE DATABASE `$dbName`");
		$settings = str_replace(array("'TINYIB_DBHOST', 'localhost'", "'TINYIB_DBUSERNAME', ''", "'TINYIB_DBNAME', ''"), array("'TINYIB_DBHOST', '$host'", "'TINYIB_DBUSERNAME', 'root'", "'TINYIB_DBNAME', '$dbName'"), $settings);
	}
	file_put_contents("$root/settings.php", $settings);
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$address = stream_socket_get_name($socket, false); fclose($socket);
	$url = "http://$address";
	$process = proc_open(array(PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'auto_prepend_file=' . __DIR__ . '/strict.php', '-S', $address, '-t', $root), array(0 => array('pipe', 'r'), 1 => array('file', "$root/server.log", 'a'), 2 => array('file', "$root/server.log", 'a')), $pipes, $root);
	try {
		$ready = false;
		for ($i = 0; $i < 100; $i++) {
			try { $connection = stream_socket_client("tcp://$address", $errno, $error, 0.1); }
			catch (ErrorException $e) { $connection = false; }
			if ($connection) { fclose($connection); $ready = true; break; }
			usleep(20000);
		}
		check($ready, "$mode server starts");
		list($body, $headers) = request("$url/imgboard.php");
		check(strpos($body, 'Fatal error') === false && is_file("$root/index.html"), "$mode board initialization");
		// Intentionally omit email, name, subject and password.
		list($body) = request("$url/imgboard.php", array('message' => '投稿保持テスト', 'parent' => '0'));
		check(strpos($body, 'Fatal error') === false && is_file("$root/res/1.html") && strpos(file_get_contents("$root/res/1.html"), '投稿保持テスト') !== false, "$mode post with omitted optional fields");
		list($body) = request("$url/imgboard.php", array('message' => '返信テスト', 'parent' => '1'));
		check(strpos($body, 'Fatal error') === false && strpos(file_get_contents("$root/res/1.html"), '返信テスト') !== false, "$mode reply and thread regeneration");
		$png = imagecreatetruecolor(600, 400); ob_start(); imagepng($png); $image = ob_get_clean(); unset($png);
		$multipart = '';
		foreach (array('message' => '画像テスト', 'parent' => '1') as $name => $value) $multipart .= "--tinyib-test\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
		$multipart .= "--tinyib-test\r\nContent-Disposition: form-data; name=\"file\"; filename=\"test.png\"\r\nContent-Type: image/png\r\n\r\n$image\r\n--tinyib-test--\r\n";
		list($body) = request("$url/imgboard.php", $multipart);
		check(strpos($body, 'Fatal error') === false && count(glob("$root/thumb/*.png")) === 1, "$mode image upload and GD thumbnail");
		// Simulate overwrite of shipped source files, leaving configuration and data alone.
		$postsFile = $mode === 'flatfile' ? "$root/inc/database/flatfile/.posts" : "$root/.tinyib.db";
		$sqlMode = $mode === 'mysqli' || $mode === 'pdo';
		$before = $sqlMode ? $db->query("SELECT * FROM `$dbName`.b_posts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) : hash_file('sha256', $postsFile);
		copyTree($source, $root);
		$after = $sqlMode ? $db->query("SELECT * FROM `$dbName`.b_posts ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) : hash_file('sha256', $postsFile);
		check($after === $before && file_get_contents("$root/settings.php") === $settings, "$mode source overwrite preserves data and settings");
		list($body) = request("$url/imgboard.php");
		check(strpos($body, 'Fatal error') === false && strpos(file_get_contents("$root/res/1.html"), '投稿保持テスト') !== false, "$mode existing thread readable after overwrite");
		list($body, $headers) = request("$url/imgboard.php?manage", array('username' => 'admin', 'managepassword' => 'local-test-password'));
		$cookie = '';
		foreach ($headers as $header) if (preg_match('/^Set-Cookie: ([^;]+)/i', $header, $match)) $cookie = $match[1];
		check($cookie !== '' && strpos($body, 'Fatal error') === false, "$mode administrator login");
		unlink("$root/res/1.html");
		list($body) = request("$url/imgboard.php?manage&rebuildall", null, $cookie);
		check(strpos($body, 'Fatal error') === false && is_file("$root/res/1.html") && strpos(file_get_contents("$root/res/1.html"), '投稿保持テスト') !== false, "$mode rebuild existing posts after overwrite");
		list($body) = request("$url/inc/captcha.php");
		check(str_starts_with($body, "\x89PNG"), "$mode simple CAPTCHA image");
		list($body) = request("$url/imgboard.php", array('message' => 'bad', 'email' => array('bad')));
		check(strpos($body, 'Invalid post supplied.') !== false && strpos($body, 'Fatal error') === false, "$mode rejects array form fields");
		$log = file_get_contents("$root/server.log");
		check(!preg_match('/(?:Fatal error|Deprecated|Warning|Uncaught)/', $log), "$mode clean E_ALL server log");
	} catch (Throwable $e) {
		fwrite(STDERR, file_get_contents("$root/server.log") . "\n" . ($body ?? '') . "\n");
		throw $e;
	} finally {
		proc_terminate($process); proc_close($process); removeTree($root);
		if (isset($dbName)) { $db->exec("DROP DATABASE `$dbName`"); unset($dbName, $db); }
	}
}
echo "$checks checks passed\n";
