<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	/**
	 * Whether this run built ee.phar/ee-old.phar, so the cleanup only removes its own files.
	 *
	 * @var bool
	 */
	private static $phar_built = false;

	public $command;
	public $commands = [];
	public $webroot_path;
	public $ee_path;

	/**
	 * Only sites with this prefix are ever deleted by the cleanup hooks, so the suite never touches other sites.
	 */
	const TEST_SITE_PREFIX = 'bh-dash-command-';

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			self::$phar_built = true;
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
		self::$phar_built = true;
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * @When /^I run (?:'(.*)'|"(.*)")$/
	 */
	public function iRun($single_quoted, $double_quoted = '')
	{
		$command = '' !== $single_quoted ? $single_quoted : $double_quoted;
		$this->commands[] = EE::launch($command, false, true);
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return exactly$/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return exactly$/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return something like$/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return something like$/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->commands = [];
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		// Delete only this suite's sites; the upstream context deleted every site on the host.
		foreach ( self::testSitesInDb() as $site ) {
			EE::launch( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes < /dev/null', false, true );
		}

		if ( self::$phar_built ) {
			foreach ( [ 'ee.phar', 'ee-old.phar' ] as $phar ) {
				if ( file_exists( $phar ) ) {
					unlink( $phar );
				}
			}
			self::$phar_built = false;
		}
	}

	/**
	 * @return string[] Sites in the EE database whose name starts with TEST_SITE_PREFIX.
	 */
	public static function testSitesInDb()
	{
		return self::sitesInDb( 'LIKE' );
	}

	/**
	 * @return string[] Sites in the EE database that don't belong to this suite.
	 */
	public static function nonTestSitesInDb()
	{
		return self::sitesInDb( 'NOT LIKE' );
	}

	private static function sitesInDb( $operator )
	{
		$db_file = EE_ROOT_DIR . '/db/ee.sqlite';
		if ( ! file_exists( $db_file ) ) {
			return [];
		}
		$pdo  = new PDO( 'sqlite:' . $db_file );
		$stmt = $pdo->prepare( "SELECT site_url FROM sites WHERE site_url $operator ? ESCAPE '\\'" );
		$stmt->execute( [ str_replace( [ '_', '%' ], [ '\\_', '\\%' ], self::TEST_SITE_PREFIX ) . '%' ] );

		return $stmt->fetchAll( PDO::FETCH_COLUMN );
	}

	/*
	 * ==========================================================================
	 * Shared generic steps (named generically so they can move to a common context).
	 * Every string argument supports {NAME} placeholders, see expand().
	 * ==========================================================================
	 */

	/**
	 * @var array Values for {NAME} placeholders in step arguments.
	 */
	private $placeholders = [];

	/**
	 * @BeforeScenario
	 */
	public function initPlaceholders()
	{
		$this->placeholders = [
			'RUN_ID'             => bin2hex( random_bytes( 6 ) ),
			'HOSTNAME'           => gethostname(),
			'EE_ROOT'            => realpath( EE_ROOT ),
			'EASYENGINE_SSH_KEY' => self::EASYENGINE_SSH_KEY,
		];
	}

	/**
	 * Replaces {NAME} placeholders; unknown names are left untouched.
	 */
	private function expand( $text )
	{
		return preg_replace_callback(
			'/\{([A-Z_]+)\}/',
			function ( $m ) {
				return array_key_exists( $m[1], $this->placeholders ) ? (string) $this->placeholders[ $m[1] ] : $m[0];
			},
			$text
		);
	}

	private function lastCommand()
	{
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private function describeLastCommand()
	{
		$run = $this->lastCommand();

		return sprintf( "Command: %s\nReturn code: %d\n--- STDOUT ---\n%s\n--- STDERR ---\n%s", $run->command, $run->return_code, $run->stdout, $run->stderr );
	}

	private function lastOutput( $stream )
	{
		$run = $this->lastCommand();

		return preg_replace( '/\e\[[0-9;]*m/', '', 'STDOUT' === $stream ? $run->stdout : $run->stderr );
	}

	private static function assertTestSite( $site )
	{
		if ( 0 !== strpos( $site, self::TEST_SITE_PREFIX ) ) {
			throw new Exception( "Refusing to touch '$site': test sites must start with " . self::TEST_SITE_PREFIX );
		}
	}

	/**
	 * @Then the return code should be :code
	 */
	public function theReturnCodeShouldBe( $code )
	{
		if ( (int) $code !== (int) $this->lastCommand()->return_code ) {
			throw new Exception( "Expected return code $code.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should (contain|not contain):$/
	 */
	public function outputShouldContain( $stream, $action, PyStringNode $expected )
	{
		$needle = $this->expand( $expected->getRaw() );
		$found  = false !== strpos( $this->lastOutput( $stream ), $needle );

		if ( $found !== ( 'contain' === $action ) ) {
			throw new Exception( "Expected $stream to $action:\n$needle\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should match '(.+)'$/
	 */
	public function outputShouldMatch( $stream, $regex )
	{
		if ( ! preg_match( $this->expand( $regex ), $this->lastOutput( $stream ) ) ) {
			throw new Exception( "Expected $stream to match $regex\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should contain '([^']*)' at most (\d+) times?$/
	 */
	public function outputShouldContainAtMost( $stream, $needle, $max )
	{
		$count = substr_count( $this->lastOutput( $stream ), $this->expand( $needle ) );

		if ( $count > (int) $max ) {
			throw new Exception( "Expected '$needle' at most $max time(s) in $stream, found $count.\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then the file :path should contain :text
	 */
	public function theFileShouldContain( $path, $text )
	{
		$path = $this->expand( $path );
		if ( ! is_readable( $path ) ) {
			throw new Exception( "File $path is not readable." );
		}
		if ( false === strpos( file_get_contents( $path ), $this->expand( $text ) ) ) {
			throw new Exception( "File $path does not contain: " . $this->expand( $text ) );
		}
	}

	/**
	 * @Then the file :path should not contain :text
	 */
	public function theFileShouldNotContain( $path, $text )
	{
		$path = $this->expand( $path );
		if ( ! is_readable( $path ) ) {
			throw new Exception( "File $path is not readable." );
		}
		$lines = preg_grep( '/' . preg_quote( $this->expand( $text ), '/' ) . '/', file( $path ) );
		if ( $lines ) {
			throw new Exception( "File $path contains '" . $this->expand( $text ) . "' on " . count( $lines ) . " line(s), e.g.:\n" . reset( $lines ) );
		}
	}

	/**
	 * Creates the site only when it is missing, so a feature can share expensive fixtures between scenarios.
	 *
	 * @Given the test site :site exists, created with :flags
	 */
	public function theTestSiteExistsCreatedWith( $site, $flags )
	{
		self::assertTestSite( $site );
		if ( in_array( $site, self::testSitesInDb(), true ) ) {
			return;
		}

		$run = EE::launch( 'bin/ee site create ' . escapeshellarg( $site ) . ' ' . $this->expand( $flags ) . ' < /dev/null', false, true );
		if ( 0 !== $run->return_code || ! in_array( $site, self::testSitesInDb(), true ) ) {
			throw new Exception( "Could not create fixture site $site.\nReturn code: {$run->return_code}\nSTDOUT:\n{$run->stdout}\nSTDERR:\n{$run->stderr}" );
		}
	}

	/**
	 * Runs a setup command that must succeed (fixture tweaks such as `ee site disable`); it does not count as the command under test.
	 *
	 * @Given the setup command :command succeeds
	 */
	public function theSetupCommandSucceeds( $command )
	{
		$run = EE::launch( $this->expand( $command ) . ' < /dev/null', false, true );
		if ( 0 !== $run->return_code ) {
			throw new Exception( "Setup command failed: $command\nReturn code: {$run->return_code}\nSTDOUT:\n{$run->stdout}\nSTDERR:\n{$run->stderr}" );
		}
	}

	/**
	 * @Given I set the column :column to :value for the site :site in the EasyEngine database
	 */
	public function iSetTheColumnForTheSite( $column, $value, $site )
	{
		self::assertTestSite( $site );
		if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
			throw new Exception( "Invalid column name: $column" );
		}
		$pdo    = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
		$select = $pdo->prepare( "SELECT $column FROM sites WHERE site_url = ?" );
		$select->execute( [ $site ] );
		$original = $select->fetchColumn();
		$stmt     = $pdo->prepare( "UPDATE sites SET $column = ? WHERE site_url = ?" );
		$stmt->execute( [ $value, $site ] );
		if ( 1 !== $stmt->rowCount() ) {
			throw new Exception( "Site $site not found in the EasyEngine database." );
		}
		$this->undo[] = function () use ( $column, $original, $site ) {
			$pdo = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
			$pdo->prepare( "UPDATE sites SET $column = ? WHERE site_url = ?" )->execute( [ $original, $site ] );
		};
	}

	/**
	 * @Then the site :site should have :column set to :value in the EasyEngine database
	 */
	public function theSiteShouldHaveColumnSetTo( $site, $column, $value )
	{
		if ( ! preg_match( '/^[a-z_]+$/', $column ) ) {
			throw new Exception( "Invalid column name: $column" );
		}
		$pdo  = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
		$stmt = $pdo->prepare( "SELECT $column FROM sites WHERE site_url = ?" );
		$stmt->execute( [ $site ] );
		$row = $stmt->fetch( PDO::FETCH_NUM );
		if ( false === $row ) {
			throw new Exception( "Site $site not found in the EasyEngine database." );
		}
		if ( (string) $row[0] !== $this->expand( $value ) ) {
			throw new Exception( "Expected $column of $site to be '$value', got " . var_export( $row[0], true ) );
		}
	}

	/**
	 * Moves a file inside a test site's directory (e.g. to simulate a missing docker-compose.yml).
	 *
	 * @Given I move the file :from to :to in the site :site
	 */
	public function iMoveTheFileInTheSite( $from, $to, $site )
	{
		self::assertTestSite( $site );
		$root = EE_SITE_ROOT . '/' . $site . '/';
		if ( false !== strpos( $from . $to, '..' ) || ! file_exists( $root . $from ) ) {
			throw new Exception( "Cannot move {$root}{$from}: missing or invalid path." );
		}
		if ( ! rename( $root . $from, $root . $to ) ) {
			throw new Exception( "Could not move {$root}{$from} to {$root}{$to}." );
		}
		$this->undo[] = function () use ( $root, $from, $to ) {
			if ( file_exists( $root . $to ) && ! file_exists( $root . $from ) ) {
				rename( $root . $to, $root . $from );
			}
		};
	}

	/**
	 * @var callable[] Fixture changes to revert after the scenario, even when it fails.
	 */
	private $undo = [];

	/**
	 * Registers a command that reverts a fixture change after the scenario (pass or fail); its failure is ignored.
	 *
	 * @Given the command :command is run after the scenario
	 */
	public function theCommandIsRunAfterTheScenario( $command )
	{
		$command      = $this->expand( $command );
		$this->undo[] = function () use ( $command ) {
			EE::launch( $command . ' < /dev/null', false, true );
		};
	}

	/**
	 * @AfterScenario
	 */
	public function revertFixtureChanges()
	{
		while ( $this->undo ) {
			$revert = array_pop( $this->undo );
			$revert();
		}
	}

	/*
	 * ==========================================================================
	 * EasyDash sandbox: local stub API + private config + private mount namespace.
	 *
	 * `ee dash init` talks to the API from the `ed-api-url` config key, appends a key to
	 * /root/.ssh/authorized_keys and may run `hostnamectl set-hostname`. Sandboxed runs point
	 * EE_CONFIG_PATH at a private config whose ed-api-url is the stub on 127.0.0.1, and run inside
	 * `unshare --mount` with /root/.ssh and hostnamectl bind-mounted over, so the host is untouched.
	 * ==========================================================================
	 */

	const EASYENGINE_SSH_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILbESQqRcGdwnn/u1BkDCD9rDiFqgDhTHBHIIasaDpWV EasyEngine';

	/**
	 * Exit code sandbox.sh uses when it cannot set up the namespace.
	 */
	const SANDBOX_SETUP_FAILED = 97;

	private $stub_dir;
	private $stub_pid;

	/**
	 * @Given an EasyDash stub is running
	 */
	public function anEasydashStubIsRunning()
	{
		if ( function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid() ) {
			throw new Exception( 'The EasyDash sandbox needs root (unshare --mount).' );
		}
		foreach ( [ 'unshare', 'timeout', 'mount' ] as $bin ) {
			exec( 'command -v ' . $bin, $out, $rc );
			if ( 0 !== $rc ) {
				throw new Exception( "The EasyDash sandbox needs `$bin`." );
			}
		}
		// The proxy guard in runInSandbox() only binds the curl transport; Requests' fsockopen fallback ignores proxy env vars.
		if ( ! extension_loaded( 'curl' ) ) {
			throw new Exception( 'The EasyDash sandbox needs the PHP curl extension.' );
		}

		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/ee-behat-dash-' . $this->placeholders['RUN_ID'];
		foreach ( [ $dir, "$dir/bin", "$dir/ssh" ] as $sub ) {
			if ( ! is_dir( $sub ) && ! mkdir( $sub, 0700, true ) ) {
				throw new Exception( "Cannot create $sub" );
			}
		}
		$this->stub_dir = $dir;

		copy( __DIR__ . '/easydash-stub-router.php', "$dir/router.php" );
		file_put_contents( "$dir/state.json", json_encode( [ 'servers' => [], 'sites' => [] ] ) );
		file_put_contents( "$dir/overrides.json", '{}' );
		file_put_contents( "$dir/requests.jsonl", '' );
		file_put_contents( "$dir/ssh/authorized_keys", '' );
		chmod( "$dir/ssh/authorized_keys", 0600 );

		$port = self::freePort();
		$this->stub_pid = (int) trim( shell_exec( sprintf(
			'EASYDASH_STUB_DIR=%s nohup %s -S 127.0.0.1:%d -t %s %s > %s 2>&1 & echo $!',
			escapeshellarg( $dir ),
			escapeshellarg( PHP_BINARY ),
			$port,
			escapeshellarg( $dir ),
			escapeshellarg( "$dir/router.php" ),
			escapeshellarg( "$dir/server.log" )
		) ) );

		$deadline = microtime( true ) + 10;
		while ( ! ( $sock = @fsockopen( '127.0.0.1', $port, $errno, $errstr, 0.5 ) ) ) {
			if ( microtime( true ) > $deadline ) {
				throw new Exception( "EasyDash stub did not start on port $port:\n" . @file_get_contents( "$dir/server.log" ) );
			}
			usleep( 100000 );
		}
		fclose( $sock );

		$this->placeholders['STUB_PORT']   = $port;
		$this->placeholders['STUB_URL']    = "http://127.0.0.1:$port";
		$this->placeholders['CLOSED_PORT'] = self::freePort();
		$this->writeStubConfig( "http://127.0.0.1:$port/api/method/" );

		file_put_contents( "$dir/bin/ee", sprintf( "#!/bin/sh\nprintf '%%s\\n' \"\$*\" >> %s\nexec %s \"\$@\"\n", escapeshellarg( "$dir/ee-calls.log" ), escapeshellarg( realpath( EE_ROOT ) . '/bin/ee' ) ) );
		file_put_contents( "$dir/bin/hostnamectl", sprintf( "#!/bin/sh\nprintf '%%s\\n' \"\$*\" >> %s\n", escapeshellarg( "$dir/hostnamectl-calls.log" ) ) );
		chmod( "$dir/bin/ee", 0755 );
		chmod( "$dir/bin/hostnamectl", 0755 );

		$targets = [];
		foreach ( [ '/usr/bin/hostnamectl', '/bin/hostnamectl', '/usr/sbin/hostnamectl', '/sbin/hostnamectl' ] as $candidate ) {
			if ( file_exists( $candidate ) ) {
				$targets[ realpath( $candidate ) ] = true;
			}
		}
		$sandbox = "#!/bin/sh\n# Runs inside a private mount namespace; the bind mounts are invisible to the host.\n";
		$sandbox .= sprintf( "mount --bind %1\$s /root/.ssh && [ /root/.ssh -ef %1\$s ] || { echo 'EasyDash sandbox: cannot bind-mount /root/.ssh' >&2; exit %2\$d; }\n", escapeshellarg( "$dir/ssh" ), self::SANDBOX_SETUP_FAILED );
		foreach ( array_keys( $targets ) as $target ) {
			$sandbox .= sprintf( "mount --bind %1\$s %2\$s && [ %2\$s -ef %1\$s ] || { echo 'EasyDash sandbox: cannot shadow %3\$s' >&2; exit %4\$d; }\n", escapeshellarg( "$dir/bin/hostnamectl" ), escapeshellarg( $target ), $target, self::SANDBOX_SETUP_FAILED );
		}
		// The marker proves the mounts are in place; without it (e.g. unshare itself failed) the run is not trusted.
		$sandbox .= sprintf( ": > %s\ncd %s\nexec sh %s\n", escapeshellarg( "$dir/sandbox.entered" ), escapeshellarg( realpath( EE_ROOT ) ), escapeshellarg( "$dir/cmd.sh" ) );
		file_put_contents( "$dir/sandbox.sh", $sandbox );
	}

	/**
	 * @AfterScenario
	 */
	public function stopEasydashStub()
	{
		if ( $this->stub_pid ) {
			exec( 'kill ' . (int) $this->stub_pid . ' 2>/dev/null' );
			$this->stub_pid = null;
		}
		if ( $this->stub_dir && 0 === strpos( basename( $this->stub_dir ), 'ee-behat-dash-' ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->stub_dir ) );
		}
		$this->stub_dir = null;
	}

	private static function freePort()
	{
		$server = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		if ( ! $server ) {
			throw new Exception( "Cannot allocate a local port: $errstr" );
		}
		$name = stream_socket_get_name( $server, false );
		fclose( $server );

		return (int) substr( strrchr( $name, ':' ), 1 );
	}

	private function requireStub()
	{
		if ( ! $this->stub_dir ) {
			throw new Exception( 'Start the stub first: Given an EasyDash stub is running' );
		}

		return $this->stub_dir;
	}

	/**
	 * Private config: a copy of the global config with ed-api-url replaced, so nested `ee` calls behave as usual.
	 */
	private function writeStubConfig( $api_url )
	{
		$dir = $this->requireStub();
		if ( '127.0.0.1' !== parse_url( $api_url, PHP_URL_HOST ) ) {
			throw new Exception( "Refusing ed-api-url $api_url: sandboxed runs may only reach 127.0.0.1." );
		}
		$global = EE_ROOT_DIR . '/config/config.yml';
		$lines  = is_readable( $global ) ? file( $global, FILE_IGNORE_NEW_LINES ) : [];
		$lines  = array_filter(
			$lines,
			function ( $line ) {
				return 0 !== strpos( $line, 'ed-api-url:' ) && '---' !== trim( $line );
			}
		);
		$lines[] = 'ed-api-url: "' . addcslashes( $api_url, '"\\' ) . '"';
		file_put_contents( "$dir/config.yml", implode( "\n", $lines ) . "\n" );

		// An unparsable value would make init fall back to the real https://dash.easyengine.io.
		$parsed = Mustangostang\Spyc::YAMLLoad( "$dir/config.yml" );
		if ( ! isset( $parsed['ed-api-url'] ) || $api_url !== $parsed['ed-api-url'] ) {
			throw new Exception( "The private config does not resolve ed-api-url to $api_url:\n" . file_get_contents( "$dir/config.yml" ) );
		}
	}

	private function updateStubJson( $file, callable $change )
	{
		$path = $this->requireStub() . '/' . $file;
		$data = json_decode( file_get_contents( $path ), true );
		file_put_contents( $path, json_encode( $change( $data ) ) );
	}

	/**
	 * @Given the EasyDash API URL is :url
	 */
	public function theEasydashApiUrlIs( $url )
	{
		$this->writeStubConfig( $this->expand( $url ) );
	}

	/**
	 * @Given the EasyDash stub knows the server :ip
	 */
	public function theEasydashStubKnowsTheServer( $ip )
	{
		$this->updateStubJson( 'state.json', function ( $state ) use ( $ip ) {
			$state['servers'][] = [ 'public_ipv4' => $ip, 'hostname' => 'bh-known-server.example.test' ];
			return $state;
		} );
	}

	/**
	 * @Given the EasyDash stub knows the site :domain
	 */
	public function theEasydashStubKnowsTheSite( $domain )
	{
		$this->updateStubJson( 'state.json', function ( $state ) use ( $domain ) {
			$state['sites'][] = [ 'domain' => $domain ];
			return $state;
		} );
	}

	/**
	 * @Given the EasyDash stub forgets all sites
	 */
	public function theEasydashStubForgetsAllSites()
	{
		$this->updateStubJson( 'state.json', function ( $state ) {
			$state['sites'] = [];
			return $state;
		} );
	}

	/**
	 * @Given the EasyDash stub answers :endpoint with status :status and body:
	 */
	public function theEasydashStubAnswersWithStatus( $endpoint, $status, PyStringNode $body )
	{
		$raw = $this->expand( $body->getRaw() );
		$this->updateStubJson( 'overrides.json', function ( $overrides ) use ( $endpoint, $status, $raw ) {
			$overrides[ $endpoint ] = [ 'status' => (int) $status, 'body' => $raw ];
			return $overrides;
		} );
	}

	/**
	 * @Given the EasyDash stub answers :endpoint after :seconds seconds
	 */
	public function theEasydashStubAnswersAfter( $endpoint, $seconds )
	{
		$this->updateStubJson( 'overrides.json', function ( $overrides ) use ( $endpoint, $seconds ) {
			$overrides[ $endpoint ] = [ 'status' => 200, 'body' => '{"message":"late"}', 'delay' => (int) $seconds ];
			return $overrides;
		} );
	}

	/**
	 * Shadows lsb_release through PATH for sandboxed runs only.
	 *
	 * @Given the EasyDash host reports OS :distributor release :release
	 */
	public function theEasydashHostReportsOs( $distributor, $release )
	{
		$path = $this->requireStub() . '/bin/lsb_release';
		file_put_contents( $path, sprintf(
			"#!/bin/sh\ncase \"\$1\" in\n  -i) printf 'Distributor ID:\\t%%s\\n' %s ;;\n  -r) printf 'Release:\\t%%s\\n' %s ;;\n  *) printf 'Distributor ID:\\t%%s\\nRelease:\\t%%s\\n' %s %s ;;\nesac\n",
			escapeshellarg( $distributor ),
			escapeshellarg( $release ),
			escapeshellarg( $distributor ),
			escapeshellarg( $release )
		) );
		chmod( $path, 0755 );
	}

	/**
	 * @Given the sandboxed authorized_keys file contains:
	 */
	public function theSandboxedAuthorizedKeysFileContains( PyStringNode $content )
	{
		file_put_contents( $this->requireStub() . '/ssh/authorized_keys', $this->expand( $content->getRaw() ) . "\n" );
	}

	/**
	 * @When I run :command in the EasyDash sandbox
	 */
	public function iRunInTheEasydashSandbox( $command )
	{
		$this->runInSandbox( $command, '' );
	}

	/**
	 * @When I run :command in the EasyDash sandbox with STDIN:
	 */
	public function iRunInTheEasydashSandboxWithStdin( $command, PyStringNode $stdin )
	{
		$this->runInSandbox( $command, $this->expand( $stdin->getRaw() ) . "\n" );
	}

	private function runInSandbox( $command, $stdin )
	{
		$dir = $this->requireStub();
		// init walks every site (enable/disable, ee shell, add_site), so it must never run next to real sites.
		$foreign = self::nonTestSitesInDb();
		if ( $foreign ) {
			throw new Exception( 'Refusing to run ee dash init: it would also touch these non-test sites: ' . implode( ', ', $foreign ) );
		}
		if ( ! is_dir( '/root/.ssh' ) && ! mkdir( '/root/.ssh', 0700 ) ) {
			throw new Exception( '/root/.ssh is missing and cannot be created as a mount point.' );
		}
		file_put_contents( "$dir/cmd.sh", $this->expand( $command ) . "\n" );
		file_put_contents( "$dir/stdin", $stdin );
		@unlink( "$dir/sandbox.entered" );

		// Proxy guard: any HTTP(S) request not aimed at 127.0.0.1 (the real EasyDash, api.ipify.org) fails fast on a closed port.
		$proxy = 'http://127.0.0.1:' . $this->placeholders['CLOSED_PORT'];
		$run   = EE::launch( sprintf(
			'EE_CONFIG_PATH=%1$s PATH=%2$s http_proxy=%3$s https_proxy=%3$s HTTPS_PROXY=%3$s no_proxy=%4$s NO_PROXY=%4$s timeout --kill-after=10 900 unshare --mount --propagation private sh %5$s < %6$s',
			escapeshellarg( "$dir/config.yml" ),
			escapeshellarg( "$dir/bin:" . getenv( 'PATH' ) ),
			escapeshellarg( $proxy ),
			escapeshellarg( '127.0.0.1,localhost,.test' ),
			escapeshellarg( "$dir/sandbox.sh" ),
			escapeshellarg( "$dir/stdin" )
		), false, true );

		if ( self::SANDBOX_SETUP_FAILED === $run->return_code || ! file_exists( "$dir/sandbox.entered" ) ) {
			throw new Exception( "EasyDash sandbox setup failed (return code {$run->return_code}):\n{$run->stderr}" );
		}
		$run->command = $this->expand( $command );
		$this->commands[] = $run;
	}

	/**
	 * @return array[] Requests recorded by the stub, optionally filtered by endpoint and body domain.
	 */
	private function stubRequests( $endpoint = null, $domain = null )
	{
		$requests = [];
		foreach ( file( $this->requireStub() . '/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$request = json_decode( $line, true );
			if ( null !== $endpoint && $request['endpoint'] !== $endpoint ) {
				continue;
			}
			if ( null !== $domain && ( ! isset( $request['json']['domain'] ) || $request['json']['domain'] !== $domain ) ) {
				continue;
			}
			$requests[] = $request;
		}

		return $requests;
	}

	private function describeStubRequests()
	{
		$lines = [];
		foreach ( $this->stubRequests() as $request ) {
			$lines[] = $request['method'] . ' ' . $request['path'] . ' ' . $request['body'];
		}

		return "Requests received by the EasyDash stub:\n" . ( $lines ? implode( "\n", $lines ) : '(none)' ) . ( $this->commands ? "\n\n" . $this->describeLastCommand() : '' );
	}

	private function lastStubRequest( $endpoint, $domain = null )
	{
		$requests = $this->stubRequests( $endpoint, $domain );
		if ( ! $requests ) {
			throw new Exception( "No $endpoint request" . ( $domain ? " for $domain" : '' ) . " was received.\n" . $this->describeStubRequests() );
		}

		return end( $requests );
	}

	/**
	 * @Then the EasyDash stub should have received :count :endpoint request(s)
	 */
	public function theEasydashStubShouldHaveReceived( $count, $endpoint )
	{
		$expected = 'no' === $count ? 0 : (int) $count;
		$actual   = count( $this->stubRequests( $endpoint ) );
		if ( $expected !== $actual ) {
			throw new Exception( "Expected $expected $endpoint request(s), got $actual.\n" . $this->describeStubRequests() );
		}
	}

	/**
	 * @Then the EasyDash stub should have received :count :endpoint request(s) for :domain
	 */
	public function theEasydashStubShouldHaveReceivedFor( $count, $endpoint, $domain )
	{
		$expected = 'no' === $count ? 0 : (int) $count;
		$actual   = count( $this->stubRequests( $endpoint, $domain ) );
		if ( $expected !== $actual ) {
			throw new Exception( "Expected $expected $endpoint request(s) for $domain, got $actual.\n" . $this->describeStubRequests() );
		}
	}

	/**
	 * @Then the EasyDash stub should have received more than :count :endpoint request(s)
	 */
	public function theEasydashStubShouldHaveReceivedMoreThan( $count, $endpoint )
	{
		$actual = count( $this->stubRequests( $endpoint ) );
		if ( $actual <= (int) $count ) {
			throw new Exception( "Expected more than $count $endpoint request(s), got $actual.\n" . $this->describeStubRequests() );
		}
	}

	/**
	 * @Then the EasyDash stub should not have received any request
	 */
	public function theEasydashStubShouldNotHaveReceivedAnyRequest()
	{
		if ( $this->stubRequests() ) {
			throw new Exception( "Expected no request.\n" . $this->describeStubRequests() );
		}
	}

	/**
	 * @Then the last EasyDash :endpoint request should be a POST to :path
	 */
	public function theLastEasydashRequestShouldBeAPostTo( $endpoint, $path )
	{
		$request = $this->lastStubRequest( $endpoint );
		if ( 'POST' !== $request['method'] || $this->expand( $path ) !== $request['path'] ) {
			throw new Exception( "Expected POST $path, got {$request['method']} {$request['path']}.\n" . $this->describeStubRequests() );
		}
	}

	/**
	 * @Then the last EasyDash :endpoint request should have the header :name set to :value
	 */
	public function theLastEasydashRequestShouldHaveTheHeader( $endpoint, $name, $value )
	{
		$request = $this->lastStubRequest( $endpoint );
		$key     = strtolower( $name );
		$actual  = isset( $request['headers'][ $key ] ) ? $request['headers'][ $key ] : null;
		if ( $this->expand( $value ) !== $actual ) {
			throw new Exception( "Expected header $name: $value, got " . var_export( $actual, true ) . "\nAll headers: " . json_encode( $request['headers'] ) );
		}
	}

	/**
	 * @Then the last EasyDash :endpoint request body should contain JSON:
	 */
	public function theLastEasydashRequestBodyShouldContainJson( $endpoint, PyStringNode $json )
	{
		$this->assertJsonSubset( $this->lastStubRequest( $endpoint ), $json );
	}

	/**
	 * @Then the last EasyDash :endpoint request for :domain should contain JSON:
	 */
	public function theLastEasydashRequestForShouldContainJson( $endpoint, $domain, PyStringNode $json )
	{
		$this->assertJsonSubset( $this->lastStubRequest( $endpoint, $domain ), $json );
	}

	/**
	 * @Then the last EasyDash :endpoint request body should have the key :key
	 */
	public function theLastEasydashRequestBodyShouldHaveTheKey( $endpoint, $key )
	{
		$request = $this->lastStubRequest( $endpoint );
		if ( ! is_array( $request['json'] ) || ! array_key_exists( $key, $request['json'] ) ) {
			throw new Exception( "Expected key '$key' in the $endpoint body: {$request['body']}" );
		}
	}

	/**
	 * DB-backed fields change type with the PHP version (PDO SQLite returns strings before PHP 8.1), so booleans, ints and numeric strings compare by value.
	 *
	 * @Then the last EasyDash :endpoint request for :domain should have :field loosely equal to :value
	 */
	public function theLastEasydashRequestForShouldHaveLooselyEqual( $endpoint, $domain, $field, $value )
	{
		$request = $this->lastStubRequest( $endpoint, $domain );
		if ( ! is_array( $request['json'] ) || ! array_key_exists( $field, $request['json'] ) ) {
			throw new Exception( "Field '$field' missing from: {$request['body']}" );
		}
		$normalize = function ( $v ) {
			if ( is_bool( $v ) ) {
				return (int) $v;
			}
			if ( is_string( $v ) && is_numeric( $v ) ) {
				return $v + 0;
			}
			return $v;
		};
		$expected = json_decode( $this->expand( $value ), true );
		if ( null === $expected && 'null' !== trim( $value ) ) {
			$expected = $this->expand( $value );
		}
		if ( $normalize( $expected ) !== $normalize( $request['json'][ $field ] ) ) {
			throw new Exception( "Expected $field ~= $value, got " . json_encode( $request['json'][ $field ] ) . " in: {$request['body']}" );
		}
	}

	/**
	 * @Then the last EasyDash :endpoint request for :domain should have :field matching :regex
	 */
	public function theLastEasydashRequestForShouldHaveMatching( $endpoint, $domain, $field, $regex )
	{
		$request = $this->lastStubRequest( $endpoint, $domain );
		$actual  = isset( $request['json'][ $field ] ) ? $request['json'][ $field ] : null;
		if ( ! is_scalar( $actual ) || ! preg_match( $regex, (string) $actual ) ) {
			throw new Exception( "Expected $field to match $regex, got " . json_encode( $actual ) . " in: {$request['body']}" );
		}
	}

	private function assertJsonSubset( array $request, PyStringNode $json )
	{
		$expected = json_decode( $this->expand( $json->getRaw() ), true );
		if ( null === $expected ) {
			throw new Exception( 'Invalid JSON in the step: ' . json_last_error_msg() );
		}
		if ( ! is_array( $request['json'] ) ) {
			throw new Exception( "The {$request['endpoint']} body is not JSON: {$request['body']}" );
		}
		$mismatch = self::jsonSubsetMismatch( $expected, $request['json'], '' );
		if ( null !== $mismatch ) {
			throw new Exception( "JSON mismatch at $mismatch\nActual body: {$request['body']}" );
		}
	}

	/**
	 * Strict recursive subset check: objects may have extra keys, lists and scalars must match exactly.
	 *
	 * @return string|null Path of the first mismatch.
	 */
	private static function jsonSubsetMismatch( $expected, $actual, $path )
	{
		if ( is_array( $expected ) && [] !== $expected && array_keys( $expected ) !== range( 0, count( $expected ) - 1 ) ) {
			if ( ! is_array( $actual ) ) {
				return ( $path ?: '/' ) . ' (expected an object)';
			}
			foreach ( $expected as $key => $value ) {
				if ( ! array_key_exists( $key, $actual ) ) {
					return "$path/$key (missing)";
				}
				$mismatch = self::jsonSubsetMismatch( $value, $actual[ $key ], "$path/$key" );
				if ( null !== $mismatch ) {
					return $mismatch;
				}
			}
			return null;
		}

		return $expected === $actual ? null : sprintf( '%s: expected %s, got %s', $path ?: '/', json_encode( $expected ), json_encode( $actual ) );
	}

	/**
	 * @Then the sandboxed authorized_keys file should contain the EasyEngine key :count time(s)
	 */
	public function theSandboxedAuthorizedKeysShouldContainTheKey( $count )
	{
		$content = file_get_contents( $this->requireStub() . '/ssh/authorized_keys' );
		$actual  = substr_count( $content, self::EASYENGINE_SSH_KEY );
		if ( (int) $count !== $actual ) {
			throw new Exception( "Expected the EasyEngine key $count time(s), found $actual. authorized_keys:\n$content" );
		}
	}

	/**
	 * @Then the sandboxed authorized_keys file should contain:
	 */
	public function theSandboxedAuthorizedKeysShouldContain( PyStringNode $expected )
	{
		$content = file_get_contents( $this->requireStub() . '/ssh/authorized_keys' );
		if ( false === strpos( $content, $this->expand( $expected->getRaw() ) ) ) {
			throw new Exception( "authorized_keys does not contain the expected text. Content:\n$content" );
		}
	}

	private function shimCalls( $name )
	{
		$log = $this->requireStub() . "/$name-calls.log";

		return file_exists( $log ) ? file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : [];
	}

	/**
	 * @Then hostnamectl should have been called with :args
	 */
	public function hostnamectlShouldHaveBeenCalledWith( $args )
	{
		if ( ! in_array( $this->expand( $args ), $this->shimCalls( 'hostnamectl' ), true ) ) {
			throw new Exception( "hostnamectl was not called with '$args'. Calls:\n" . implode( "\n", $this->shimCalls( 'hostnamectl' ) ) );
		}
	}

	/**
	 * @Then hostnamectl should not have been called
	 */
	public function hostnamectlShouldNotHaveBeenCalled()
	{
		if ( $this->shimCalls( 'hostnamectl' ) ) {
			throw new Exception( "hostnamectl was called:\n" . implode( "\n", $this->shimCalls( 'hostnamectl' ) ) );
		}
	}

	/**
	 * Checks the nested `ee` calls made by the command under test (recorded by the PATH shim).
	 *
	 * @Then the nested ee command :args should have been run
	 */
	public function theNestedEeCommandShouldHaveBeenRun( $args )
	{
		if ( ! in_array( $this->expand( $args ), $this->shimCalls( 'ee' ), true ) ) {
			throw new Exception( "Nested `ee $args` was not run. Nested ee calls:\n" . implode( "\n", $this->shimCalls( 'ee' ) ) );
		}
	}

	/**
	 * @Then the nested ee command :args should not have been run
	 */
	public function theNestedEeCommandShouldNotHaveBeenRun( $args )
	{
		if ( in_array( $this->expand( $args ), $this->shimCalls( 'ee' ), true ) ) {
			throw new Exception( "Nested `ee $args` was run. Nested ee calls:\n" . implode( "\n", $this->shimCalls( 'ee' ) ) );
		}
	}
}
