<?php
/*
Plugin Name: WP Bibleviewer
Description: A plugin with a shortcode that includes a JS file, HTML content, and CSS
Version: 0.0.0.0
Author: Jvartamaghleba Church 
 */


// Enqueue the JavaScript file
function wp_bibleviewer_enqueue_scripts() {
  if (is_single() || is_page()) {
    global $post;

    if (has_shortcode($post->post_content, 'wp_bibleviewer')) {


      if (!wp_script_is('htmx', 'enqueued')) {
        wp_enqueue_script('htmx', plugins_url('js/htmx.min.js', __FILE__), array());
      }
      if (!wp_script_is('alpinejs-persist-plugin', 'enqueued')) {
        wp_enqueue_script('alpinejs-persist-plugin', plugins_url('js/persist.min.js', __FILE__), array(), '', true);
      }
      if (!wp_script_is('alpinejs', 'enqueued')) {
        wp_enqueue_script('alpinejs', plugins_url('js/alpine.min.js', __FILE__), array(), '', true);
      }

    }
  }
}
add_action('wp_enqueue_scripts', 'wp_bibleviewer_enqueue_scripts');

// Enqueue the CSS file
function wp_bibleviewer_enqueue_styles() {
  if (is_single() || is_page()) {
    global $post;

    if (has_shortcode($post->post_content, 'wp_bibleviewer')) {
      wp_enqueue_style('wp-bibleviewer-style', plugins_url('css/bibleviewer.css', __FILE__));
    }
  }
}
add_action('wp_enqueue_scripts', 'wp_bibleviewer_enqueue_styles');

function wp_bibleviewer_shortcode_callback($atts) {


  // Get the HTML content
  $html_content = file_get_contents(plugin_dir_path(__FILE__) . 'index.html');

  // Return the output
  return $html_content;
}

// Register the shortcode
add_shortcode('wp_bibleviewer', 'wp_bibleviewer_shortcode_callback');

//delete_transient('wp-bookviewer');

class Wp_Bibleviewer_API_Endpoint extends WP_REST_Controller {

  public function get_items_permissions_check( $request ) {

    return true;
  }


  /**
   * Register the routes for the objects of the controller.
   */
  public function register_routes() {
    $version = '1';
    $namespace = 'wp-bibleviewer/v' . $version;
    $base = 'html';
    register_rest_route($namespace, '/' . $base, array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array($this, 'get_html_page'),
        'permission_callback' => array($this, 'get_items_permissions_check'),
        'args'                => array(
          'm' => array(
            'validate_callback' => function ($value, $request, $param) {
              $file = plugin_dir_path(__FILE__) . "database/{$value}.SQLite3";
              return file_exists($file);
            }
    ),
          'm2' => array(
            'validate_callback' => function ($value, $request, $param) {
              $file = plugin_dir_path(__FILE__) . "database/{$value}.SQLite3";
              return file_exists($file) || $value == "";
            }
    ),
          'b' => array(
            'validate_callback' => function ($value, $request, $param) {
              return (int)$value;
            }
    ),
          'c' => array(
            'validate_callback' => function ($value, $request, $param) {
              return (int)$value;
            }
    ),
        ),
      ),
    ));
    $base = "books";
    register_rest_route($namespace, '/' . $base, array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array($this, 'get_books_page'),
        'permission_callback' => array($this, 'get_items_permissions_check'),
        'args'                => array(
          'm' => array(
            'validate_callback' => function ($value, $request, $param) {
              $file = plugin_dir_path(__FILE__) . "database/{$value}.SQLite3";
              return file_exists($file);
            }
    ),
        ),
      ),
    ));
    $base = "module-script";
    register_rest_route($namespace, '/' . $base, array(
      array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => array($this, 'get_modules_page'),
        'permission_callback' => array($this, 'get_items_permissions_check'),
      ),
    ));
  }

  /**
   * Get an HTML page based on the query parameters
   *
   * @param WP_REST_Request $request Full data about the request.
   * @return WP_Error|WP_REST_Response
   */
  
  public function get_html_page($request) {
    $modules = array();
    $modules[0] = $request->get_param('m');
    $m2 = $request->get_param('m2');
    if ($m2) { 
      $modules[1] = $m2;
    }
    $book_number = $request->get_param('b');
    $chapter = $request->get_param('c');

    $results = array();

    for ($i = 0 ; $i < count($modules); $i++) {
      try {
        $dbFile = plugin_dir_path(__FILE__) . "database/{$modules[$i]}.SQLite3";
        if (!file_exists($dbFile)){
          return new WP_Error('file_error', 'Database file does not exist', array('status' => 500));
        }
        $pdo = new PDO("sqlite:$dbFile");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("SELECT verse, text FROM verses WHERE book_number = ? AND chapter = ?");
        $stmt->bindParam(1, $book_number, PDO::PARAM_INT);
        $stmt->bindParam(2, $chapter, PDO::PARAM_INT);
        $stmt->execute();

        $results[$i] = $stmt->fetchAll(PDO::FETCH_ASSOC);

      } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return new WP_Error('database_error', 'Database error occurred', array('status' => 500));
      }
    }
    
    if (!isset($results[1])){
      header('Content-Type: text/html; charset=UTF-8');
      echo '<h2 x-text="`${book_number == 230 ?  chapter_string_ps : chapter_string} ${chapter}`"></h2>';
      echo "<section id=\"bible\">";
      foreach ($results[0] as $row) {
        echo "<div class=\"flex gap-2\"><span class=\"text-nowrap\">" . $row['verse'] . "</span> <span>" . $row['text'] . "</span></div>";
      }
      echo "</section>";
      exit;
    }else{
      $maxResultCount = max(count($results[0]), count($results[1]));
      $combinedResults = array();

      for ($i = 0 ; $i < $maxResultCount; $i++) {
        $combinedResults[$i] = array(
          'verse' => $results[0][$i]['verse'],
          'text1' => $results[0][$i]['text'],
          'text2' => $results[1][$i]['text'],
        );
      }

      echo "<section id=\"bible\">";
      echo '<h2 x-text="`${book_number == 230 ?  chapter_string_ps : chapter_string} ${chapter}`"></h2>';
      foreach ($combinedResults as $row) {
        echo "<div class=\"flex gap-2\"><span class=\"text-nowrap\">{$row['verse']}</span> <span class=\"w-full\">{$row['text1']}</span> <span class=\"w-full\">{$row['text2']}</span></div>";
      }
      echo "</section>";
      exit;
    }
  }
  public function get_books_page($request) {
    $module = $request->get_param('m');

    try {
      $dbFile = plugin_dir_path(__FILE__) . "database/{$module}.SQLite3";
      if (!file_exists($dbFile)){
        return new WP_Error('file_error', 'Database file does not exist', array('status' => 500));
      }
      $pdo = new PDO("sqlite:$dbFile");
      $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

      $stmt = $pdo->prepare("SELECT * FROM books");
      $stmt->execute();

      $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

      header('Content-Type: text/html; charset=UTF-8');
      echo json_encode($results);
      exit;
    } catch (PDOException $e) {
      error_log("Database error: " . $e->getMessage());
      return new WP_Error('database_error', 'Database error occurred', array('status' => 500));
    }
  }
  public function get_modules_page(){
    $directory = plugin_dir_path(__FILE__) . 'database'; // Specify your directory path

    // Get all files in the specified directory
    $files = scandir($directory);

    // Filter to get only SQLite files
    $sqliteFiles = array_filter($files, function($file) {
      return pathinfo($file, PATHINFO_EXTENSION) === 'SQLite3'; // Change 'sqlite3' to 'sqlite' if needed
    });
    
    $sqliteFiles = array_map(function($sqliteFile){
      return pathinfo($sqliteFile, PATHINFO_FILENAME);
    }, $sqliteFiles);

    header('Content-Type: text/html; charset=UTF-8');
    echo json_encode(array_values($sqliteFiles));
    exit;

  }
}

add_action('rest_api_init', 'wp_bibleviewer_register_api_endpoint');

function wp_bibleviewer_register_api_endpoint() {
  $controller = new Wp_Bibleviewer_API_Endpoint();
  $controller->register_routes();
}
