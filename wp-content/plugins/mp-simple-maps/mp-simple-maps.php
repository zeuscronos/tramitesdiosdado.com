<?php
/**
 * Plugin Name: MP Simple Maps
 * Description: Create reusable Google Maps embeds (coords + zoom) and render via shortcode.
 * Version: 0.1.0
 */

if (!defined('ABSPATH')) exit;

class MP_Simple_Maps {
  const CPT = 'mp_map';
  const META_COORDS = '_mp_map_coords'; // "lat,lng"
  const META_ZOOM   = '_mp_map_zoom';   // int

  public function __construct() {
    add_action('init', [$this, 'register_cpt']);
    add_action('init', [$this, 'register_meta'], 20);
    add_action('add_meta_boxes', [$this, 'add_metaboxes']);
    add_action('save_post_' . self::CPT, [$this, 'save_metabox'], 10, 2);

    add_shortcode('mp_map', [$this, 'shortcode_mp_map']);
  }

  public function register_meta() {
    register_post_meta(self::CPT, self::META_COORDS, [
      'show_in_rest'  => true,
      'single'        => true,
      'type'          => 'string',
      'auth_callback' => function ($allowed, $meta_key, $post_id) {
        return current_user_can('edit_post', $post_id);
      },
    ]);
    register_post_meta(self::CPT, self::META_ZOOM, [
      'show_in_rest'  => true,
      'single'        => true,
      'type'          => 'string',
      'auth_callback' => function ($allowed, $meta_key, $post_id) {
        return current_user_can('edit_post', $post_id);
      },
    ]);
  }

  public function register_cpt() {
    register_post_type(self::CPT, [
      'labels' => [
        'name'          => 'Maps',
        'singular_name' => 'Map',
        'add_new_item'  => 'Add New Map',
        'edit_item'     => 'Edit Map',
      ],
      'public'       => false,
      'show_ui'      => true,
      'show_in_menu' => true,
      'menu_icon'    => 'dashicons-location-alt',
      'supports'     => ['title', 'custom-fields'],
    ]);
  }

  public function add_metaboxes() {
    add_meta_box(
      'mp_simple_maps_box',
      'Map settings',
      [$this, 'render_metabox'],
      self::CPT,
      'normal',
      'high'
    );
  }

  public function render_metabox($post) {
    wp_nonce_field('mp_simple_maps_save', 'mp_simple_maps_nonce');

    $coords = (string) get_post_meta($post->ID, self::META_COORDS, true);
    $zoom   = (string) get_post_meta($post->ID, self::META_ZOOM, true);
    if ($zoom === '') $zoom = '16';

    echo '<p><strong>GPS coords</strong> (lat,lng). Example: <code>40.7410116,-73.9899557</code></p>';
    echo '<input type="text" name="mp_map_coords" style="width:100%; max-width:420px;" value="' . esc_attr($coords) . '" placeholder="40.7410116,-73.9899557" />';

    echo '<p style="margin-top:12px;"><strong>Zoom</strong> (1–20). Example: <code>16</code></p>';
    echo '<input type="number" name="mp_map_zoom" min="1" max="20" style="width:120px;" value="' . esc_attr($zoom) . '" />';

    echo '<hr style="margin:16px 0;" />';

    $shortcode = '[mp_map id="' . (int)$post->ID . '" height="350" zoom="16"]';
    echo '<p><strong>Shortcode</strong> (paste this into Divi Text/Code module):</p>';
    echo '<input type="text" readonly style="width:100%; max-width:420px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;" value="' . esc_attr($shortcode) . '" />';

    echo '<p style="color:#666; margin-top:8px;">Tip: You can also override in the shortcode: <code>[mp_map id="123" height="350" zoom="16"]</code></p>';
  }

  private function parse_coords(string $coords): array {
    $coords = trim($coords);
    $coords = str_replace(' ', '', $coords);

    // Basic "lat,lng" validation
    if (!preg_match('/^-?\d+(\.\d+)?,-?\d+(\.\d+)?$/', $coords)) {
      return [false, '', 0.0, 0.0, 'Coords must be "lat,lng" using numbers.'];
    }

    [$lat, $lng] = array_map('floatval', explode(',', $coords, 2));

    if ($lat < -90 || $lat > 90)   return [false, '', 0.0, 0.0, 'Latitude must be between -90 and 90.'];
    if ($lng < -180 || $lng > 180) return [false, '', 0.0, 0.0, 'Longitude must be between -180 and 180.'];

    return [true, $coords, $lat, $lng, ''];
  }

  public function save_metabox($post_id, $post) {
    if (!isset($_POST['mp_simple_maps_nonce']) || !wp_verify_nonce($_POST['mp_simple_maps_nonce'], 'mp_simple_maps_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $coords_in = isset($_POST['mp_map_coords']) ? (string) wp_unslash($_POST['mp_map_coords']) : '';
    $zoom_in   = isset($_POST['mp_map_zoom']) ? (string) wp_unslash($_POST['mp_map_zoom']) : '16';

    $coords_in = sanitize_text_field($coords_in);
    $zoom      = (int) $zoom_in;
    if ($zoom < 1) $zoom = 1;
    if ($zoom > 20) $zoom = 20;

    if (trim($coords_in) !== '') {
      [$ok, $coords] = $this->parse_coords($coords_in);
      if ($ok) {
        update_post_meta($post_id, self::META_COORDS, $coords);
      } else {
        // Don’t block save; just keep old value if invalid input
        // (You can add admin notices later if you want)
      }
    }

    update_post_meta($post_id, self::META_ZOOM, $zoom);
  }

  public function shortcode_mp_map($atts) {
    $atts = shortcode_atts([
      'id'     => 0,
      'coords' => '',
      'zoom'   => '',
      'width'  => '100%',
      'height' => '350',
      'hl'     => 'es',
      'radius' => '12', // px
    ], $atts, 'mp_map');

    $id = (int) $atts['id'];

    $coords = trim((string)$atts['coords']);
    $zoom   = trim((string)$atts['zoom']);

    if ($id > 0) {
      if ($coords === '') {
        $coords = (string) get_post_meta($id, self::META_COORDS, true);
      }
      if ($zoom === '') {
        $zoom = (string) get_post_meta($id, self::META_ZOOM, true);
      }
    }

    $coords = trim($coords);
    if ($coords === '') return ''; // silent

    [$ok, $coords_norm, $lat, $lng] = $this->parse_coords($coords);
    if (!$ok) return ''; // silent

    $z = (int) $zoom;
    if ($z < 1) $z = 1;
    if ($z > 20) $z = 20;

    $width  = trim((string)$atts['width']);
    $height = (int) $atts['height'];
    if ($height < 100) $height = 100;

    $hl = preg_replace('/[^a-z\-]/i', '', (string)$atts['hl']);
    if ($hl === '') $hl = 'es';

    $radius = (int) $atts['radius'];
    if ($radius < 0) $radius = 0;
    if ($radius > 40) $radius = 40;

    // Build embed URL (q=lat,lng like your example)
    $src = add_query_arg([
      'q'      => $coords_norm,
      'hl'     => $hl,
      'z'      => $z,
      'output' => 'embed',
    ], 'https://www.google.com/maps');

    $style = 'border:0; border-radius:' . $radius . 'px;';

    return sprintf(
      '<iframe width="%s" height="%d" style="%s" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade" src="%s"></iframe>',
      esc_attr($width),
      (int)$height,
      esc_attr($style),
      esc_url($src)
    );
  }
}

new MP_Simple_Maps();
