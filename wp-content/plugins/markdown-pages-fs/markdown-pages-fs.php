<?php
/**
 * Plugin Name: Markdown Pages (Filesystem)
 * Description: Markdown editor for Pages that writes to wp-content/markdown-pages/ using kebab-case paths and renders via shortcode or content filter.
 * Version: 0.1.1
 */

if (!defined('ABSPATH')) exit;

class MPFS_Markdown_Pages_Filesystem {
  const META_PATH  = '_mpfs_md_path';
  const META_MODE  = '_mpfs_render_mode';
  const META_DB_MD = '_mpfs_md_db'; // optional: store markdown in DB as a fallback/mirror

  // Render modes
  const MODE_REPLACE   = 'replace';
  const MODE_COMBINE   = 'combine';
  const MODE_SHORTCODE = 'shortcode';

  private function base_dir(): string {
    // wp-content/markdown-pages
    return trailingslashit(WP_CONTENT_DIR) . 'markdown-pages';
  }

  public function __construct() {
    add_action('add_meta_boxes', [$this, 'add_metabox']);
    add_action('save_post_page', [$this, 'save_metabox'], 10, 2);

    add_filter('the_content', [$this, 'filter_the_content'], 20);

    add_shortcode('mp_markdown', [$this, 'shortcode_mp_markdown']);

    // delete markdown file when deleting a page,
    // but only if no other page references the same path.
    add_action('before_delete_post', [$this, 'on_before_delete_post'], 10, 1);

    // Ensure directory exists (best-effort)
    add_action('init', function () {
      $dir = $this->base_dir();
      if (!file_exists($dir)) {
        wp_mkdir_p($dir);
      }
    });

    add_action('admin_notices', [$this, 'admin_notices']);

    add_action('wp_head', [$this, 'inject_markdown_page_css']);

    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
  }

  public function add_metabox() {
    add_meta_box(
      'mpfs_markdown_box',
      'Markdown (filesystem)',
      [$this, 'render_metabox'],
      'page',
      'normal',
      'high'
    );
  }

  public function render_metabox($post) {
    wp_nonce_field('mpfs_save', 'mpfs_nonce');

    $path = (string) get_post_meta($post->ID, self::META_PATH, true);
    $mode = (string) get_post_meta($post->ID, self::META_MODE, true);
    if ($mode === '') $mode = self::MODE_REPLACE;

    // Show markdown from DB mirror (what user last saved), not from file.
    // This prevents surprising UI when file is edited externally.
    $md = (string) get_post_meta($post->ID, self::META_DB_MD, true);

    $defaultPath = $post->post_name ? ($post->post_name . '.md') : '';

    echo '<p><strong>Ruta donde guardar el archivo (.md, kebab-case):</strong> Ej: <code>espana/visados-familiar-ue.md</code></p>';
    echo '<input type="text" style="width:100%; max-width: 720px;" name="mpfs_path" placeholder="' . esc_attr($defaultPath) . '" value="' . esc_attr($path) . '">';

    echo '<p style="margin-top:12px;"><strong>Modo de render:</strong></p>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="mpfs_mode" value="' . esc_attr(self::MODE_REPLACE) . '" ' . checked($mode, self::MODE_REPLACE, false) . '> Reemplazar contenido de la página</label>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="mpfs_mode" value="' . esc_attr(self::MODE_COMBINE) . '" ' . checked($mode, self::MODE_COMBINE, false) . '> Combinar (Markdown arriba + contenido normal abajo)</label>';
    echo '<label style="display:block; margin:4px 0;"><input type="radio" name="mpfs_mode" value="' . esc_attr(self::MODE_SHORTCODE) . '" ' . checked($mode, self::MODE_SHORTCODE, false) . '> Solo shortcode (no tocar the_content)</label>';

    echo '<p style="margin-top:12px;"><strong>Shortcode (para Divi / Gutenberg):</strong></p>';
    echo '<input type="text" readonly style="width:100%; max-width: 520px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;" value="' . esc_attr('[mp_markdown id="' . $post->ID . '"]') . '">';
    echo '<p style="color:#666; margin-top:6px;">Pega ese shortcode en un módulo de texto/código de Divi para renderizar el Markdown ya convertido a HTML.</p>';

    echo '<p style="margin-top:12px;"><strong>Markdown:</strong></p>';
    echo '<textarea name="mpfs_markdown" style="width:100%; min-height: 280px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;">' . esc_textarea($md) . '</textarea>';

    echo '<p style="color:#666; margin-top:8px;">Al guardar, el plugin crea/actualiza el archivo dentro de <code>wp-content/markdown-pages/</code> (crea carpetas si faltan). La ruta debe ser kebab-case (minúsculas, números y guiones) y terminar en <code>.md</code>.</p>';
    echo '<p style="color:#666; margin-top:8px;"><em>Al borrar la página, se borrará el archivo .md solo si ninguna otra página está apuntando a la misma ruta.</em></p>';
  }

  /** Show admin errors stored in transient */
  public function admin_notices() {
    if (!is_admin()) return;
    $key = 'mpfs_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (!$notice) return;

    delete_transient($key);

    $type = isset($notice['type']) ? $notice['type'] : 'error';
    $msg  = isset($notice['msg']) ? $notice['msg'] : 'Error';
    echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($msg) . '</p></div>';
  }

  private function set_admin_notice(string $msg, string $type = 'error'): void {
    $key = 'mpfs_notice_' . get_current_user_id();
    set_transient($key, ['msg' => $msg, 'type' => $type], 30);
  }

  private function normalize_and_validate_path(string $path): array {
    $path = trim($path);
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);

    // No absolute path, no traversal, no drive letters
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || preg_match('/:[\/\\\\]/', $path)) {
      return [false, '', 'Ruta inválida (no se permite ruta absoluta ni "..").'];
    }

    if (!str_ends_with($path, '.md')) {
      $path .= '.md';
    }

    // Strict kebab-case segments + .md (folders and file name)
    $re = '#^[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*\.md$#';
    if (!preg_match($re, $path)) {
      return [false, '', 'La ruta debe ser kebab-case (minúsculas, números y guiones), permitir subcarpetas, y terminar en .md. Ej: espana/visados-familiar-ue.md'];
    }

    return [true, $path, ''];
  }

  private function safe_full_path(string $relPath): array {
    $base = $this->base_dir();
    $baseReal = realpath($base);

    if ($baseReal === false) {
      // try create it
      if (!wp_mkdir_p($base)) {
        return [false, '', 'No se pudo crear el directorio base: ' . $base];
      }
      $baseReal = realpath($base);
      if ($baseReal === false) {
        return [false, '', 'No se pudo resolver el directorio base.'];
      }
    }

    $full = $baseReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);

    // Ensure the parent dir is within base (we’ll check after mkdir with realpath)
    return [true, $full, ''];
  }

  private function ensure_parent_dir(string $fullPath): bool {
    $parent = dirname($fullPath);
    if (file_exists($parent)) return is_dir($parent);
    return wp_mkdir_p($parent);
  }

  private function write_markdown_file(string $relPath, string $markdown): array {
    list($ok, $full, $err) = $this->safe_full_path($relPath);
    if (!$ok) return [false, $err];

    if (!$this->ensure_parent_dir($full)) {
      return [false, 'No se pudo crear el directorio padre para: ' . $relPath];
    }

    // After creating dirs, verify we’re still inside base
    $baseReal = realpath($this->base_dir());
    $parentReal = realpath(dirname($full));
    if ($baseReal === false || $parentReal === false || strpos($parentReal, $baseReal) !== 0) {
      return [false, 'Ruta rechazada por seguridad (fuera del directorio permitido).'];
    }

    // Ensure content ends with a blank line
    $markdown = rtrim($markdown);
    if ($markdown !== '') {
      $markdown .= "\n";
    }

    $bytes = @file_put_contents($full, $markdown);
    if ($bytes === false) {
      return [false, 'No se pudo escribir el archivo: ' . $relPath . ' (revisa permisos).'];
    }

    return [true, ''];
  }

  public function on_before_delete_post($post_id) {
    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'page') return;

    $path = (string) get_post_meta($post_id, self::META_PATH, true);
    $path = trim($path);
    if ($path === '') return;

    // Is this path used by any other page?
    $q = new WP_Query([
      'post_type'      => 'page',
      'post_status'    => 'any',
      'posts_per_page' => 1,
      'fields'         => 'ids',
      'post__not_in'   => [$post_id],
      'meta_query'     => [
        [
          'key'     => self::META_PATH,
          'value'   => $path,
          'compare' => '=',
        ],
      ],
    ]);

    if ($q->have_posts()) {
      // Still referenced somewhere else → keep file
      return;
    }

    // Resolve safe path and delete file
    list($ok, $safePath, $err) = $this->normalize_and_validate_path($path);
    if (!$ok) return;

    list($pok, $full, $perr) = $this->safe_full_path($safePath);
    if (!$pok) return;

    if (file_exists($full)) {
      @unlink($full);
    }

    // Optional: cleanup empty parent folders up to base dir
    $this->cleanup_empty_dirs(dirname($full));
  }

  private function cleanup_empty_dirs(string $dir): void {
    $baseReal = realpath($this->base_dir());
    if ($baseReal === false) return;

    $dirReal = realpath($dir);
    if ($dirReal === false) return;

    // Stop at or above base
    if (strpos($dirReal, $baseReal) !== 0) return;
    if ($dirReal === $baseReal) return;

    $files = @scandir($dirReal);
    if (is_array($files) && count($files) === 2) { // only '.' and '..'
      @rmdir($dirReal);
      $this->cleanup_empty_dirs(dirname($dirReal));
    }
  }

  public function save_metabox($post_id, $post) {
    if (!isset($_POST['mpfs_nonce']) || !wp_verify_nonce($_POST['mpfs_nonce'], 'mpfs_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_page', $post_id)) return;

    $mode = isset($_POST['mpfs_mode']) ? sanitize_text_field(wp_unslash($_POST['mpfs_mode'])) : self::MODE_REPLACE;
    if (!in_array($mode, [self::MODE_REPLACE, self::MODE_COMBINE, self::MODE_SHORTCODE], true)) {
      $mode = self::MODE_REPLACE;
    }

    $md = isset($_POST['mpfs_markdown']) ? (string) wp_unslash($_POST['mpfs_markdown']) : '';
    $md = trim($md);

    $pathInput = isset($_POST['mpfs_path']) ? (string) wp_unslash($_POST['mpfs_path']) : '';
    $pathInput = trim($pathInput);

    // Default path: {slug}.md
    $defaultPath = $post->post_name ? ($post->post_name . '.md') : '';
    $path = $pathInput !== '' ? $pathInput : $defaultPath;

    if ($md === '') {
      // If markdown is empty, we keep path/mode but remove db mirror.
      update_post_meta($post_id, self::META_MODE, $mode);
      if ($pathInput !== '') {
        // still validate and store path if user wants it set
        list($ok, $safePath, $err) = $this->normalize_and_validate_path($path);
        if ($ok) {
          update_post_meta($post_id, self::META_PATH, $safePath);
        } else {
          // don’t block save if empty markdown, just notify
          $this->set_admin_notice($err, 'warning');
        }
      }
      delete_post_meta($post_id, self::META_DB_MD);
      return;
    }

    // Validate path
    list($ok, $safePath, $err) = $this->normalize_and_validate_path($path);
    if (!$ok) {
      $this->set_admin_notice($err, 'error');
      return; // block writing
    }

    // Persist meta
    update_post_meta($post_id, self::META_PATH, $safePath);
    update_post_meta($post_id, self::META_MODE, $mode);
    update_post_meta($post_id, self::META_DB_MD, $md);

    // Write file
    list($wok, $werr) = $this->write_markdown_file($safePath, $md);
    if (!$wok) {
      $this->set_admin_notice($werr, 'error');
      return;
    }

    // Invalidate cached render (if you add caching later)
    $this->set_admin_notice('Markdown guardado en archivo: ' . $safePath, 'success');
  }

  private function load_markdown_for_post(int $post_id): array {
    $path = (string) get_post_meta($post_id, self::META_PATH, true);
    $path = trim($path);

    if ($path === '') return ['', ''];

    list($ok, $safePath, $err) = $this->normalize_and_validate_path($path);
    if (!$ok) return ['', $err];

    list($pok, $full, $perr) = $this->safe_full_path($safePath);
    if (!$pok) return ['', $perr];

    if (!file_exists($full)) {
      return ['', 'No existe el archivo: ' . $safePath];
    }

    $md = @file_get_contents($full);
    if ($md === false) return ['', 'No se pudo leer el archivo: ' . $safePath];

    return [$md, ''];
  }

  private function markdown_to_html(string $markdown): string {
    // Prefer League CommonMark if available, else fallback to very simple formatting.
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
      require_once $autoload;

      $config = [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
      ];

      $converter = new League\CommonMark\CommonMarkConverter($config);
      $html = $converter->convert($markdown)->getContent();

      return '<div class="mpfs-markdown">' . wp_kses_post($html) . '</div>';
    }

    // Fallback: show preformatted markdown (safe)
    return '<pre class="mpfs-markdown">' . esc_html($markdown) . '</pre>';
  }

  public function filter_the_content($content) {
    if (!is_singular('page') || !in_the_loop() || !is_main_query()) return $content;

    global $post;
    if (!$post || $post->post_type !== 'page') return $content;

    $mode = (string) get_post_meta($post->ID, self::META_MODE, true);
    if ($mode === '') $mode = self::MODE_REPLACE;

    if ($mode === self::MODE_SHORTCODE) {
      // Don’t auto-inject; user uses shortcode manually.
      return $content;
    }

    list($md, $err) = $this->load_markdown_for_post((int)$post->ID);
    if ($md === '') {
      // If file missing, fall back to DB mirror if present
      $db = (string) get_post_meta($post->ID, self::META_DB_MD, true);
      $db = trim($db);
      if ($db !== '') {
        $md = $db;
      } else {
        return $content;
      }
    }

    $html = $this->markdown_to_html($md);

    if ($mode === self::MODE_COMBINE) {
      return $html . $content;
    }

    // replace
    return $html;
  }

  public function shortcode_mp_markdown($atts) {
    $atts = shortcode_atts([
      'id'   => 0,
      'path' => '',
    ], $atts, 'mp_markdown');

    $id = (int) $atts['id'];
    $path = trim((string)$atts['path']);

    if ($id > 0) {
      list($md, $err) = $this->load_markdown_for_post($id);
      if ($md === '') {
        $db = (string) get_post_meta($id, self::META_DB_MD, true);
        $db = trim($db);
        if ($db !== '') $md = $db;
      }
      if ($md === '') return ''; // silent
      return $this->markdown_to_html($md);
    }

    if ($path !== '') {
      list($ok, $safePath, $err) = $this->normalize_and_validate_path($path);
      if (!$ok) return '';

      list($pok, $full, $perr) = $this->safe_full_path($safePath);
      if (!$pok || !file_exists($full)) return '';

      $md = @file_get_contents($full);
      if ($md === false) return '';

      return $this->markdown_to_html($md);
    }

    return '';
  }

  public function inject_markdown_page_css() {
    if (!is_singular('page')) return;

    $post_id = get_queried_object_id();
    if (!$post_id) return;

    $path = (string) get_post_meta($post_id, self::META_PATH, true);
    if (trim($path) === '') return;

    echo '<style>
      /* ===== Markdown Pages (FS) - UI tweaks ===== */

      /* Hide title in Divi */
      h1.main_title {
        display: none;
      }

      /* Bottom padding so content doesn\'t stick to viewport */
      #main-content .container {
        padding-top: 0 !important;
        padding-bottom: 3rem;
      }
    </style>';
  }

  public function enqueue_assets() {
    if (!is_singular('page')) return;

    $post_id = get_queried_object_id();
    if (!$post_id) return;

    $path = (string) get_post_meta($post_id, self::META_PATH, true);
    if (trim($path) === '') return;

    wp_enqueue_style('mpfs-markdown-css', plugin_dir_url(__FILE__) . 'assets/css/markdown-pages.css', [], '0.1.0');
  }
}

new MPFS_Markdown_Pages_Filesystem();
