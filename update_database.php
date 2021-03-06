#!/usr/bin/php
<?php

/* find composer autoload */
if (file_exists(__DIR__ . '/vendor/autoload.php')){
	/* local installation */
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	/* assume installed by composer */
	require_once __DIR__ . '/../../autoload.php';
}

require "color_terminal.php";

define('DEFAULT_CONFIG', 'config.php');

function load_config($filename){
	if (is_readable($filename)){
		require($filename);
	} else {
		echo "Please create {$filename}. You can look at config-example.php for ideas.\n";
		exit(1);
	}
}

/* setup argument parser */
$getopt = new \GetOpt\GetOpt([
	['c', 'config', \GetOpt\GetOpt::REQUIRED_ARGUMENT, 'Configuration file to use. Default: "' . DEFAULT_CONFIG . '".' , realpath(dirname($argv[0])) . '/' . DEFAULT_CONFIG],
	['h', 'help', \GetOpt\GetOpt::NO_ARGUMENT, "Show this text."],
	['n', 'dry-run', \GetOpt\GetOpt::NO_ARGUMENT, 'Only checks if there are migrations to run, won\'t perform any modifications.'],
], [\GetOpt\GetOpt::SETTING_STRICT_OPERANDS => true]);
$getopt->addOperand(new \GetOpt\Operand('username', \GetOpt\Operand::OPTIONAL));

/* parse cli arguments */
try {
	$getopt->process();
} catch (UnexpectedValueException $e) {
	echo "Error: {$e->getMessage()}\n";
	echo $getopt->getHelpText();
	exit(1);
}

if ($getopt->getOption('help')){
	echo $getopt->getHelpText();
	exit(0);
}

$dryrun = $getopt->getOption('dry-run');
$username = $getopt->getOperand(0);
$file_dir = realpath(dirname($argv[0]));

load_config($getopt->getOption('config'));

$ignored_files = [
	'^\..*',                        /* skip hidden files */
	'(?<!\.(php|sql))$',            /* everything not .php or .sql */
	'^(update_database|create_migration|config(-example)?|color_terminal)\.php$',
];

/* append project-wide ignores */
if ( is_callable(['MigrationConfig', 'ignored']) ){
	$ignored_files = array_merge($ignored_files, MigrationConfig::ignored());
}

function ask_for_password() {
	echo "Password: ";
	ob_flush();
	flush();
	system('stty -echo');
	$password = trim(fgets(STDIN));
	system('stty echo');
	echo "\n";
	return $password;
}

try {
	$db = MigrationConfig::fix_database($username);
} catch(Exception $e) {
	die("fix_database misslyckades. Exception: ".$e->getMessage()."\n");
}

if($dryrun) {
	$count = 0;
	foreach(migration_list() as $version => $file) {
		if(!migration_applied($version)) ++$count;
	}

	if($count > 0) {
		echo "There are $count new migration(s) to run\n";
	} else {
		echo "Database up-to-date\n";
	}
	exit($count > 0 ? 1 : 0);
}

create_migration_table_if_not_exists();

$db->autocommit(FALSE);

run_hook("begin");

foreach(migration_list() as $version => $file) {
	if(!migration_applied($version)) {
		run_migration($version,$file);
	}
}

ColorTerminal::set("green");
echo "All migrations completed\n";
ColorTerminal::set("normal");

run_hook("end");


$db->close();

function is_ignored($filename, &$match){
	global $ignored_files;

	/* skip files in ignore list */
	foreach ( $ignored_files as $pattern ){
		if ( preg_match("/$pattern/", $filename) ){
			$match = $pattern;
			return true;
		}
	}

	return false;
}

/**
 * Creates a hash :migration_version => file_name
 */
function migration_list() {
	$files = [];

	global $file_dir;
	$search_dir = [$file_dir];
	if ( is_callable(['MigrationConfig', 'search_directory']) ){
		$search_dir = MigrationConfig::search_directory();
	}

	foreach ( $search_dir as $path ){
		$dir = opendir($path);
		while($f = readdir()) {
			if ( is_ignored($f, $match) ) continue;
			$files[get_version($f)] = "$path/$f";
		}
		closedir($dir);
	}

	ksort($files);
	return $files;
}

function get_version($file) {
	return $file;
}

function manual_step_confirm() {
	$ans = '';
	while($ans != 'yes') {
		echo("Please type 'yes' to manual_step_confirm you have completed the step above, or quit with ctrl+c: ");
		flush();
		$ans = trim(fgets(STDIN));
		echo("\n");
		flush();
	}
}


/**
 * Runs the migration
 */
function run_migration($version, $filename) {
	global $db;
	try {
		$ext = pathinfo($filename,  PATHINFO_EXTENSION);

		run_hook("pre_migration", $filename);

		ColorTerminal::set("blue");
		echo "============= BEGIN $filename =============\n";
		ColorTerminal::set("normal");
		if(filesize($filename) == 0) {
			ColorTerminal::set("red");
			echo "$filename is empty. Migrations aborted\n";
			ColorTerminal::set("normal");
			exit(1);
		}
		switch($ext) {
			case "php":
				echo "Parser: PHP\n";
				{
					require $filename;
				}
				break;
			case "sql":
				echo "Parser: MySQL\n";
				$queries = preg_split("/;[[:space:]]*\n/",file_contents($filename));
				foreach($queries as $q) {
					$q = trim($q);
					if($q != "") {
						echo "$q\n";
						$ar=run_sql($q);
						echo "Affected rows: $ar \n";
					}
				}
				break;
			default:
				ColorTerminal::set("red");
				echo "Unknown extention: $ext\n";
				echo "All following migrations aborted\n";
				$db->rollback();
				ColorTerminal::set("normal");
				exit(1);
		}
		//Add migration to schema_migrations:
		run_sql("INSERT INTO `schema_migrations` (`version`) VALUES ('$version');");

		$db->commit();
		ColorTerminal::set("green");
		echo "Migration complete\n";
		echo "============= END $filename =============\n";
		ColorTerminal::set("normal");

		run_hook("post_migration", $filename);

	} catch (Exception $e) {
		ColorTerminal::set("red");
		if($e instanceof QueryException) {
			echo "Error in migration $filename:\n".$e->getMessage()."\n";
		} else {
			echo "Error in migration $filename:\n".$e."\n";
		}
		ColorTerminal::set("lightred");
		echo "============= ROLLBACK  =============\n";
		$db->rollback();
		echo "All following migrations aborted\n";
		ColorTerminal::set("normal");

		run_hook("post_rollback", $filename);

		exit(1);
	}
}

/**
 * Returns true if the specified version is applied
 */
function migration_applied($version) {
	global $db;
	$stmt = $db->prepare("SELECT 1 FROM `schema_migrations` WHERE `version` = ?");
	if ( $stmt === false ){
		echo "{$db->error}\n";
		exit(1);
	}
	$stmt->bind_param('s', $version);
	$stmt->execute();
	$res = $stmt->fetch();
	$stmt->close();
	return $res;
}

/**
 * Helper funcition for migration scripts
 */
function migration_sql($query) {
	echo $query."\n";
	echo "Affected rows: ".run_sql($query)."\n";
}

/**
 * Runs sql query or throw exception
 */
function run_sql($query) {
	global $db;
	$status = $db->query($query);
	if($status == false) {
		throw new QueryException("Query failed: ".$db->error);
	}
	$affected_rows = $db->affected_rows;
	return $affected_rows;
}

function file_contents($filename) {
	$handle = fopen($filename, "r");
	$contents = fread($handle, filesize($filename));
	fclose($handle);
	return $contents;
}

function run_hook($hook, $arg = null) {
	$hook_method = $hook . "_hook";
	if ( is_callable(['MigrationConfig', $hook_method]) ){
		if($arg == null) {
			call_user_func("MigrationConfig::" . $hook_method);
		} else {
			call_user_func("MigrationConfig::" . $hook_method, $arg);
		}
	}
}

/**
 * Creates the migration table if is does not exist
 */
function create_migration_table_if_not_exists() {
	global $db;
	run_sql("CREATE TABLE IF NOT EXISTS `schema_migrations` (
		`version` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
		`timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY `unique_schema_migrations` (`version`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci; ");
}

class QueryException extends Exception{
	public function __construct($message) {
		$this->message = $message;
	}
}
