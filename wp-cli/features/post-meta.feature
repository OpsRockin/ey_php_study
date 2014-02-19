Feature: Manage post custom fields

  Scenario: Postmeta CRUD
    Given a WP install

    When I run `wp post-meta add 1 foo 'bar'`
    Then STDOUT should not be empty

    When I run `wp post-meta get 1 foo`
    Then STDOUT should be:
      """
      bar
      """

    When I run `wp post-meta set 1 foo '[ "1", "2" ]' --format=json`
    Then STDOUT should not be empty

    When I run `wp post-meta get 1 foo --format=json`
    Then STDOUT should be:
      """
      ["1","2"]
      """

    When I run `echo 'via STDIN' | wp post-meta set 1 foo`
    And I run `wp post-meta get 1 foo`
    Then STDOUT should be:
      """
      via STDIN
      """

    When I run `wp post-meta delete 1 foo`
    Then STDOUT should not be empty

    When I try `wp post-meta get 1 foo`
    Then the return code should be 1
