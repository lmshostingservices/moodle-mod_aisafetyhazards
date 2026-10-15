@mod @mod_aisafetyhazards
Feature: AI Safety Hazards shows each role only the pages its capabilities allow
  In order to protect scene content and learner data
  As a site administrator
  I need the builder, scenes and reports to be offered only to the right roles

  Background:
    Given the following "courses" exist:
      | fullname       | shortname |
      | Site safety 101 | WHS101    |
    And the following "users" exist:
      | username | firstname | lastname  |
      | teacher2 | Nia       | Assistant |
      | student1 | Sam       | Student   |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | teacher2 | WHS101 | teacher |
      | student1 | WHS101 | student |
    And the following "activities" exist:
      | activity     | course | idnumber | name             |
      | aisafetyhazards | WHS101 | sh1      | Trench hazards |
    And the following "mod_aisafetyhazards > scenes" exist:
      | activity | title            |
      | sh1      | Digging the trench |
    And the following config values are set as admin:
      | name        | value                                         | plugin           |
      | unlockstate | {"status":"unlocked","checkedat":1790000000} | mod_aisafetyhazards |

  Scenario: A learner is offered no teacher pages
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "student1"
    Then "Start practising" "button" should exist
    And "Reports" "link" should not exist in current page administration
    And "Set up the lesson" "link" should not exist in current page administration
    And "Scenes" "link" should not exist in current page administration

  Scenario: A non-editing teacher can see reports but cannot build or edit scenes
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "teacher2"
    Then "Reports" "link" should exist in current page administration
    And "Set up the lesson" "link" should not exist in current page administration
    And "Scenes" "link" should not exist in current page administration
    And I am on the "Trench hazards" "mod_aisafetyhazards > Report" page
    And I should see "Sam Student"
