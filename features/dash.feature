Feature: ee dash command surface and argument validation
  `ee dash` has a single subcommand, `init`. Every `ee dash init` run goes through the EasyDash sandbox: a local stub API on 127.0.0.1 set as `ed-api-url` in a private EE_CONFIG_PATH, plus a private mount namespace for /root/.ssh and hostnamectl. The real EasyDash service is never contacted.

  Scenario: ee help dash describes the command and lists init
    When I run 'bin/ee help dash'
    Then the return code should be 0
    And STDOUT should contain:
      """
      Manages EasyEngine Dashboard integration.
      """
    And STDOUT should contain:
      """
      init      Integrates the server with the EasyEngine Dashboard.
      """

  Scenario: ee dash without a subcommand prints its usage
    When I run 'bin/ee dash'
    Then STDOUT should contain:
      """
      usage: ee dash init
      """

  Scenario: ee help dash init documents every option
    When I run 'bin/ee help dash init'
    Then the return code should be 0
    And STDOUT should contain:
      """
      ee dash init [--api=<api-key>] [--org=<org-name>] [--ip=<ip-address>]
      """
    And STDOUT should contain:
      """
      [--hostname=<hostname>]
      """
    And STDOUT should contain:
      """
      EasyEngine Dashboard API key.
      """
    # Help wraps at the terminal width (80 without a tty), so only the first line of the --org description is matched.
    And STDOUT should contain:
      """
      EasyEngine Dashboard organization name in which the server needs to be
      """
    And STDOUT should contain:
      """
      $ ee dash init --api=xxx --org=my-org
      """

  Scenario: An unknown dash subcommand is rejected
    When I run 'bin/ee dash sync'
    Then the return code should be 1
    And STDERR should contain:
      """
      'sync' is not a registered subcommand of 'dash'
      """

  Scenario: init without --api fails before any API call
    Given an EasyDash stub is running
    When I run "bin/ee dash init --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Please provide an EasyEngine Dashboard API key using the --api=<token:key> flag.
      """
    And the EasyDash stub should not have received any request
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times

  Scenario: init with a whitespace-only --api is treated as missing
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api='   ' --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Please provide an EasyEngine Dashboard API key using the --api=<token:key> flag.
      """
    And the EasyDash stub should not have received any request

  Scenario: init without --org fails before any API call
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Please provide an EasyEngine Dashboard organization name using the --org=<org-name> flag.
      """
    And the EasyDash stub should not have received any request

  Scenario: init with a whitespace-only --org is treated as missing
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org='  ' --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: Please provide an EasyEngine Dashboard organization name using the --org=<org-name> flag.
      """
    And the EasyDash stub should not have received any request

  Scenario: init with no options at all reports the missing API key first
    Given an EasyDash stub is running
    When I run "bin/ee dash init" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      --api=<token:key>
      """
    And STDERR should not contain:
      """
      --org=<org-name>
      """
    And the EasyDash stub should not have received any request

  Scenario: init rejects an unknown option
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test --token=abc" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      unknown --token parameter
      """
    And the EasyDash stub should not have received any request

  # The synopsis validator drops a valueless `--api`/`--org` (with a warning) before init() sees it.
  Scenario: init with --api but no value is rejected
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      --api parameter needs a value
      """
    And STDERR should contain:
      """
      Please provide an EasyEngine Dashboard API key
      """
    And the EasyDash stub should not have received any request

  Scenario: init with --org but no value is rejected
    Given an EasyDash stub is running
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      --org parameter needs a value
      """
    And STDERR should contain:
      """
      Please provide an EasyEngine Dashboard organization name
      """
    And the EasyDash stub should not have received any request

  Scenario: Argument validation runs before the OS check
    Given an EasyDash stub is running
    And the EasyDash host reports OS 'CentOS' release '9'
    When I run "bin/ee dash init --org=bh-dash-org" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Please provide an EasyEngine Dashboard API key
      """
    And STDERR should not contain:
      """
      only supported on Ubuntu
      """

  Scenario: init refuses an unsupported distribution
    Given an EasyDash stub is running
    And the EasyDash host reports OS 'CentOS' release '9'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: EasyEngine Dashboard integration is only supported on Ubuntu or Debian.
      """
    And the EasyDash stub should not have received any request
    And the sandboxed authorized_keys file should contain the EasyEngine key 0 times

  Scenario: init refuses Ubuntu older than 22.04
    Given an EasyDash stub is running
    And the EasyDash host reports OS 'Ubuntu' release '20.04'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 1
    And STDERR should contain:
      """
      Error: EasyEngine Dashboard integration is only supported on Ubuntu 22.04 or later.
      """
    And the EasyDash stub should not have received any request

  Scenario: init accepts Ubuntu 22.04, the minimum supported release
    Given an EasyDash stub is running
    And the EasyDash host reports OS 'Ubuntu' release '22.04'
    And the EasyDash stub knows the server '203.0.113.10'
    When I run "bin/ee dash init --api=bh-dash-{RUN_ID} --org=bh-dash-org --ip=203.0.113.10 --hostname=bh-dash.example.test" in the EasyDash sandbox
    Then the return code should be 0
    And STDOUT should contain:
      """
      Server with IP 203.0.113.10 already exists on EasyEngine Dashboard. Skipping server addition.
      """
    And the EasyDash stub should have received 1 get_server_list request
