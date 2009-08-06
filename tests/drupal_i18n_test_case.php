<?php
// $Id$
// Core: Id: drupal_web_test_case.php,v 1.96 2009/04/22 09:57:10 dries Exp
/**
 * @file
 * Provide required modifications to Drupal 7 core DrupalWebTestCase in order
 * for it to function properly in Drupal 6.
 *
 * Copyright 2008-2009 by Jimmy Berry ("boombatower", http://drupal.org/user/214218)
 */

require_once drupal_get_path('module', 'simpletest') . '/drupal_web_test_case.php';

/**
 * Test case for typical Drupal tests.
 */
class Drupali18nTestCase extends DrupalWebTestCase {
  // We may want to install the site in a different language
  var $install_locale = 'en';

  /**
   * Adds a language
   * 
   * @param $langcode
   * @param $default
   *   Whether this is the default language
   * @param $load
   *   Whether to load available translations for that language
   */
  function addLanguage($langcode, $default = FALSE, $load = TRUE) {
    require_once './includes/locale.inc';
    // Enable installation language as default site language.
    locale_add_language($langcode, NULL, NULL, NULL, NULL, NULL, 1, $default);
    // Reset language list
    language_list('language', TRUE);
    // We may need to refresh default language
    drupal_init_language();
    // @todo import po files for this language
  }
  
  /**
   * Enable language switcher block
   */
  function enableBlock($module, $delta, $region = 'left') {
    db_query("UPDATE {blocks} SET status = %d, weight = %d, region = '%s', throttle = %d WHERE module = '%s' AND delta = '%s' AND theme = '%s'", 1, 0, $region, 0, $module, $delta, variable_get('theme_default', 'garland'));
    cache_clear_all();
  }
  /**
   * Reset theme to default so we can play with blocks
   */
  function initTheme() {
    global $theme, $theme_key;
    unset($theme);
    unset($theme_key);
    init_theme();
    _block_rehash();
  }
  /**
   * Generates a random database prefix, runs the install scripts on the
   * prefixed database and enable the specified modules. After installation
   * many caches are flushed and the internal browser is setup so that the
   * page requests will run on the new prefix. A temporary files directory
   * is created with the same name as the database prefix.
   *
   * @param ...
   *   List of modules to enable for the duration of the test.
   */
  function setUp() {
    global $db_prefix, $user, $language; // $language (Drupal 6).
    global $install_locale;
    
    // Store necessary current values before switching to prefixed database.
    $this->db_prefix_original = $db_prefix;
    $clean_url_original = variable_get('clean_url', 0);

    // Generate temporary prefixed database to ensure that tests have a clean starting point.
    $db_prefix = 'simpletest' . mt_rand(1000, 1000000);

    include_once './includes/install.inc';

    drupal_install_system();

    // Add the specified modules to the list of modules in the default profile.
    $args = func_get_args();
    // Add language and basic i18n modules
    $install_locale = $this->install_locale;
    $i18n_modules = array('locale', 'translation', 'i18n', 'i18n_test');
    $modules = array_unique(array_merge(drupal_verify_profile('default', $this->install_locale), $args, $i18n_modules));
    drupal_install_modules($modules);

    // Install locale    
    if ($this->install_locale != 'en') {
      $this->addLanguage($this->install_locale, TRUE);
    }
    // Run default profile tasks.
    $task = 'profile';
    default_profile_tasks($task, '');

    // Rebuild caches.
    actions_synchronize();
    _drupal_flush_css_js();
    $this->refreshVariables();
    $this->checkPermissions(array(), TRUE);
    user_access(NULL, NULL, TRUE); // Drupal 6.

    // Log in with a clean $user.
    $this->originalUser = $user;
//    drupal_save_session(FALSE);
//    $user = user_load(1);
    session_save_session(FALSE);
    $user = user_load(array('uid' => 1));

    // Restore necessary variables.
    variable_set('install_profile', 'default');
    variable_set('install_task', 'profile-finished');
    variable_set('clean_url', $clean_url_original);
    variable_set('site_mail', 'simpletest@example.com');

    // Use temporary files directory with the same prefix as database.
    $this->originalFileDirectory = file_directory_path();
    variable_set('file_directory_path', file_directory_path() . '/' . $db_prefix);
    $directory = file_directory_path();
    // Create the files directory.
    file_check_directory($directory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    set_time_limit($this->timeLimit);

    // Some more includes
    require_once 'includes/language.inc';
    // Refresh theme
    $this->initTheme();
  }
  
  /**
   * Delete created files and temporary files directory, delete the tables created by setUp(),
   * and reset the database prefix.
   */
  protected function tearDown() {
    parent::tearDown();
    
    // Reset language list
    language_list('language', TRUE);      
    drupal_init_language();
    if (module_exists('locale')) {        
      locale(NULL, NULL, TRUE);
    }
  }

  /**
   * Print out a variable for debugging
   */
  function printDebug($data, $title = '') {
    $string = is_array($data) || is_object($data) ? print_r($data, TRUE) : $data;
    $output = $title ? $title . ':' . $string : $string;
    //$this->assertTrue(TRUE, $output);
    $this->assertTrue(TRUE, $output, 'Debug');
  }
  /**
   * Debug dump object with some formatting
   */ 
  function printObject($object, $title = 'Object') {
    $output = $this->formatTable($object);
    $this->printDebug($output, $title);
  }
  
  /**
   * Print out current HTML page
   */
  function printPage() {
    $this->printDebug($this->drupalGetContent());
  }
  
  // Dump table contents
  function dumpTable($table) {
    $result = db_query('SELECT * FROM {' . $table . '}');
    $output = 'Table dump <strong>' . $table . '</strong>:';
    while ($row = db_fetch_array($result)) {
      $rows[] = $row;
      if (empty($header)) {
        $header = array_keys($row);
      }
    }
    if (!empty($rows)) {
      $output .= theme('table', $header, $rows);
    } else {
      $output .= ' No rows';
    }
    $this->assertTrue(TRUE, $output);
  }

  /**
   * Format object as table, recursive
   */
  function formatTable($object) {
    foreach ($object as $key => $value) {
      $rows[] = array(
        $key,
        is_array($value) || is_object($value) ? $this->formatTable($value) : $value,
      );
    }
    if (!empty($rows)) {
      return theme('table', array(), $rows);
    }
    else {
      return 'No properties';
    }
  }
}