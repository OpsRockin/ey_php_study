Feature: Manage WordPress users

  Background:
    Given a WP install

  Scenario: User CRUD operations
    When I try `wp user get bogus-user`
    Then the return code should be 1
    And STDOUT should be empty

    When I run `wp user create testuser2 testuser2@example.com --role=author --porcelain`
    Then STDOUT should be a number
    And save STDOUT as {USER_ID}
    And I run `wp user get {USER_ID}`
    Then STDOUT should be a table containing rows:
      | Field        | Value      |
      | ID           | {USER_ID}  |
      | roles        | author     |

    When I run `wp user delete {USER_ID}`
    Then STDOUT should not be empty

    When I try `wp user create testuser2 testuser2@example.com --role=wrongrole --porcelain`
    Then the return code should be 1
    Then STDOUT should be empty

    When I run `wp user create testuser testuser@example.com --porcelain`
    Then STDOUT should be a number
    And save STDOUT as {USER_ID}

    When I try the previous command again
    Then the return code should be 1

    When I run `wp user update {USER_ID} --display_name=Foo`
    And I run `wp user get {USER_ID}`
    Then STDOUT should be a table containing rows:
      | Field        | Value     |
      | ID           | {USER_ID} |
      | display_name | Foo       |

    When I run `wp user get testuser@example.com`
    Then STDOUT should be a table containing rows:
      | Field        | Value     |
      | ID           | {USER_ID} |
      | display_name | Foo       |

    When I run `wp user delete {USER_ID}`
    Then STDOUT should not be empty

  Scenario: Generating and deleting users
    When I run `wp user list --role=editor --format=count`
    Then STDOUT should be:
      """
      0
      """

    When I run `wp user generate --count=10 --role=editor`
    And I run `wp user list --role=editor --format=count`
    Then STDOUT should be:
      """
      10
      """

    When I try `wp user list --field=ID | xargs wp user delete invalid-user`
    And I run `wp user list --format=count`
    Then STDOUT should be:
      """
      0
      """

  Scenario: Importing users from a CSV file
    Given a users.csv file:
      """
      user_login,user_email,display_name,role
      bobjones,bobjones@domain.com,Bob Jones,contributor
      newuser1,newuser1@domain.com,New User,author
      admin,admin@domain.com,Existing User,administrator
      """

    When I run `wp user import-csv users.csv`
    Then STDOUT should not be empty

    When I run `wp user list --format=count`
    Then STDOUT should be:
      """
      3
      """

    When I run `wp user list --format=json`
    Then STDOUT should be JSON containing:
      """
      [{
        "user_login":"admin",
        "display_name":"Existing User",
        "user_email":"admin@domain.com",
        "roles":"administrator"
      }]
      """

  Scenario: Managing user roles
    When I run `wp user add-role 1 editor`
    Then STDOUT should not be empty
    And I run `wp user get 1 --field=roles`
    Then STDOUT should be:
      """
      administrator, editor
      """

    When I run `wp user set-role 1 author`
    Then STDOUT should not be empty
    And I run `wp user get 1`
    Then STDOUT should be a table containing rows:
      | Field | Value  |
      | roles | author |

    When I run `wp user remove-role 1 editor`
    Then STDOUT should not be empty
    And I run `wp user get 1`
    Then STDOUT should be a table containing rows:
      | Field | Value  |
      | roles | author |

    When I run `wp user remove-role 1`
    Then STDOUT should not be empty
    And I run `wp user get 1`
    Then STDOUT should be a table containing rows:
      | Field | Value |
      | roles |       |
      
  Scenario: Managing user capabilities
    When I run `wp user add-cap 1 edit_vip_product`
    Then STDOUT should be:
      """
      Success: Added 'edit_vip_product' capability for admin (1).
      """
      
    And I run `wp user list-caps 1 | tail -n 1`
    Then STDOUT should be:
      """
      edit_vip_product
      """
      
    And I run `wp user remove-cap 1 edit_vip_product`
    Then STDOUT should be:
      """
      Success: Removed 'edit_vip_product' cap for admin (1).
      """
