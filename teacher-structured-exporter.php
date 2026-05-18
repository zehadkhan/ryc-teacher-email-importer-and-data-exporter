<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RYC_Teacher_Structured_Exporter {

    // ── All possible options for each structured field ────────────────────────

    private $all_teaching_formats = [
        'inperson' => 'In-person',
        'online'   => 'Online',
    ];

    private $all_offerings_types = [
        'ryc-classes'    => 'RYC® Classes',
        'ryc-one-on-one' => 'RYC® One on One Sessions',
        'ryc-inspired'   => 'RYC® Inspired Classes',
        'other-offering' => 'Other',
    ];

    private $all_credentials = [
        'ryc-teacher'        => 'Restore Your Core® Teacher / Professional',
        'physical-therapist' => 'Physical Therapist / Physiotherapist',
        'occ-therapist'      => 'Occupational Therapist',
        'massage-therapist'  => 'Massage Therapist',
        'chiropractor'       => 'Chiropractor',
        'personal-trainer'   => 'Personal Trainer',
        'yoga-teacher'       => 'Yoga Teacher',
        'pilates-teacher'    => 'Pilates Teacher',
        'doula'              => 'Doula / Birth Worker',
        'midwife'            => 'Midwife',
        'other-credential'   => 'Other',
    ];

    private $all_languages = [
        'english'    => 'English',    'spanish'    => 'Spanish',    'french'     => 'French',
        'german'     => 'German',     'italian'    => 'Italian',    'portuguese' => 'Portuguese',
        'dutch'      => 'Dutch',      'hebrew'     => 'Hebrew',     'arabic'     => 'Arabic',
        'persian'    => 'Persian / Farsi',          'russian'    => 'Russian',
        'ukrainian'  => 'Ukrainian',  'polish'     => 'Polish',     'czech'      => 'Czech',
        'slovak'     => 'Slovak',     'hungarian'  => 'Hungarian',  'romanian'   => 'Romanian',
        'bulgarian'  => 'Bulgarian',  'serbian'    => 'Serbian',    'croatian'   => 'Croatian',
        'swedish'    => 'Swedish',    'norwegian'  => 'Norwegian',  'danish'     => 'Danish',
        'finnish'    => 'Finnish',    'greek'      => 'Greek',      'turkish'    => 'Turkish',
        'hindi'      => 'Hindi',      'urdu'       => 'Urdu',       'punjabi'    => 'Punjabi',
        'bengali'    => 'Bengali / Bangla',         'gujarati'   => 'Gujarati',
        'tamil'      => 'Tamil',      'mandarin'   => 'Mandarin Chinese',
        'cantonese'  => 'Cantonese',  'japanese'   => 'Japanese',   'korean'     => 'Korean',
        'thai'       => 'Thai',       'vietnamese' => 'Vietnamese',
        'tagalog'    => 'Tagalog / Filipino',       'indonesian' => 'Indonesian / Malay',
        'swahili'    => 'Swahili',    'afrikaans'  => 'Afrikaans',  'welsh'      => 'Welsh',
    ];

    private $all_matrix_options = [
        'first'  => 'First Row (Max 3)',
        'second' => 'Second Row (Max 4)',
        'third'  => 'Third Row (Max 3)',
        'fourth' => 'Fourth Row (Max 2)',
        'not'    => 'Do not show',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    public function __construct() {
        add_action( 'admin_menu',                                 [ $this, 'add_menu' ] );
        add_action( 'admin_post_ryc_structured_export_teachers',  [ $this, 'handle_export' ] );
    }

    public function add_menu() {
        add_management_page(
            'Teacher Structured Export',
            'Teacher Structured Export',
            'manage_options',
            'ryc-teacher-structured-export',
            [ $this, 'render_page' ]
        );
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        global $wpdb;
        $counts = $wpdb->get_results(
            "SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts}
             WHERE post_type = 'teacher' GROUP BY post_status",
            OBJECT_K
        );
        $total = array_sum( array_column( (array) $counts, 'total' ) );

        $status_colors = [
            'publish'    => '#d4edda', 'draft'      => '#fff3cd',
            'pending'    => '#d1ecf1', 'private'    => '#e2e3e5',
            'auto-draft' => '#f8f9fa', 'trash'      => '#f8d7da',
        ];
        ?>
        <div class="wrap">
            <h1>Teacher Structured JSON Export</h1>
            <p>Exports every field in structured JSON format. Checkbox / multi-select fields include <strong>all options</strong> with <code>1</code> (selected) or <code>0</code> (not selected). Arrays are proper JSON arrays.</p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
                <?php foreach ( (array) $counts as $status => $row ) :
                    $bg = $status_colors[ $status ] ?? '#f0f0f0'; ?>
                <div style="background:<?= $bg ?>;padding:10px 18px;border-radius:6px;font-size:13px;">
                    <strong style="font-size:20px;display:block;"><?= $row->total ?></strong>
                    <?= esc_html( ucfirst( $status ) ) ?>
                </div>
                <?php endforeach; ?>
                <div style="background:#333;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;">
                    <strong style="font-size:20px;display:block;"><?= $total ?></strong>Total
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ryc_structured_export_teachers' ); ?>
                <input type="hidden" name="action" value="ryc_structured_export_teachers">
                <table class="form-table">
                    <tr>
                        <th><label for="export_status">Post Status</label></th>
                        <td>
                            <select name="export_status" id="export_status">
                                <option value="any">All statuses (<?= $total ?>)</option>
                                <option value="publish">Published only (<?= $counts['publish']->total ?? 0 ?>)</option>
                                <option value="draft">Draft only (<?= $counts['draft']->total ?? 0 ?>)</option>
                                <?php foreach ( $counts as $st => $row ) :
                                    if ( in_array( $st, [ 'publish', 'draft', 'auto-draft' ] ) ) continue; ?>
                                    <option value="<?= esc_attr( $st ) ?>"><?= esc_html( ucfirst( $st ) ) ?> (<?= $row->total ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Structure preview</th>
                        <td>
                            <pre style="background:#f6f6f6;padding:14px;border-radius:6px;font-size:12px;line-height:1.6;max-width:700px;">{
  "id": 151,
  "post_title": "Shona Bohmer",
  "post_status": "publish",
  "flags":       { "is_pro": 1, "is_featured": 0 },
  "teaching_format": { "inperson": 1, "online": 0 },
  "credentials": { "ryc-teacher": 1, "yoga-teacher": 0, ... },
  "languages":   { "english": 1, "spanish": 0, ... },
  "offerings_type": { "ryc-classes": 1, "other-offering": 0, ... },
  "keywords":    [{ "name": "Diastasis Recti", "link": "https://..." }],
  "images":      { "main": { "id": 697, "url": "https://..." }, ... },
  "taxonomy":    { "country": "USA", "state": "CA", "city": "Denver" }
}</pre>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Download JSON', 'primary large' ); ?>
            </form>
        </div>
        <?php
    }

    // ── Export handler ────────────────────────────────────────────────────────

    public function handle_export() {
        check_admin_referer( 'ryc_structured_export_teachers' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $status = sanitize_text_field( $_POST['export_status'] ?? 'any' );

        $posts = get_posts( [
            'post_type'      => 'teacher',
            'posts_per_page' => -1,
            'post_status'    => $status === 'any'
                ? [ 'publish', 'draft', 'pending', 'private', 'future' ]
                : [ $status ],
            'orderby' => 'title',
            'order'   => 'ASC',
        ] );

        $data = [];
        foreach ( $posts as $post ) {
            $meta  = get_post_meta( $post->ID, 'ryc-teachers-meta', true );
            $meta  = is_array( $meta ) ? $meta : [];
            $terms = get_the_terms( $post->ID, 'categories-of-teachers' );
            $data[] = $this->build_record( $post, $meta, $terms );
        }

        $filename = 'ryc-teachers-structured-' . $status . '-' . date( 'Y-m-d' ) . '.json';
        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    // ── Build one teacher record ──────────────────────────────────────────────

    private function build_record( $post, $meta, $terms ) {

        // ── Taxonomy ────
        $tax = [ 'country' => '', 'state' => '', 'city' => '' ];
        if ( $terms && ! is_wp_error( $terms ) ) {
            $cat1_id = $cat2_id = 0;
            foreach ( $terms as $t ) { if ( $t->parent == 0 )        { $cat1_id = $t->term_id; $tax['country'] = $t->name; } }
            foreach ( $terms as $t ) { if ( $t->parent == $cat1_id ) { $cat2_id = $t->term_id; $tax['state']   = $t->name !== 'State' ? $t->name : ''; } }
            foreach ( $terms as $t ) { if ( $t->parent == $cat2_id ) { $tax['city'] = $t->name; } }
        }

        // ── Teaching format — merge new + legacy fields ────
        $fmt_keys = is_array( $meta['teacher-teaching-format'] ?? null ) ? $meta['teacher-teaching-format'] : [];
        if ( ! empty( $meta['teacher-online'] )   && ! in_array( 'online',   $fmt_keys, true ) ) $fmt_keys[] = 'online';
        if ( ! empty( $meta['teacher-inperson'] ) && ! in_array( 'inperson', $fmt_keys, true ) ) $fmt_keys[] = 'inperson';

        return [

            // ── WordPress core ────────────────────────────────────────────────
            'id'            => $post->ID,
            'post_title'    => $post->post_title,
            'post_status'   => $post->post_status,
            'url'           => get_permalink( $post->ID ),
            'date_created'  => $post->post_date,
            'date_modified' => $post->post_modified,

            // ── Identity ──────────────────────────────────────────────────────
            'identity' => [
                'name'            => $meta['name']          ?? '',
                'business_name'   => $meta['business_name'] ?? '',
                'credential_text' => $meta['h1-tag-text']   ?? '',
                'bio_html'        => $meta['teacher_bio']   ?? '',
                'bio_text'        => $this->strip( $meta['teacher_bio'] ?? '' ),
            ],

            // ── Contact ───────────────────────────────────────────────────────
            'contact' => [
                'email'        => $this->clean_email( $meta['email']        ?? '' ),
                'system_email' => $this->clean_email( $meta['system-email'] ?? '' ),
                'website'      => $meta['website']   ?? '',
                'phone'        => $meta['telephone'] ?? '',
                'booking_link' => $meta['booking-link'] ?? '',
                'social' => [
                    'facebook'  => $meta['link_facebook']  ?? '',
                    'twitter'   => $meta['link_twitter']   ?? '',
                    'instagram' => $meta['link_instagram'] ?? '',
                    'linkedin'  => $meta['link_linkedin']  ?? '',
                ],
            ],

            // ── Flags ─────────────────────────────────────────────────────────
            'flags' => [
                'is_pro'      => empty( $meta['pro-teacher'] )      ? 0 : 1,
                'is_featured' => empty( $meta['featured-teacher'] ) ? 0 : 1,
            ],

            // ── Teaching format (all options, 1/0) ────────────────────────────
            'teaching_format' => $this->build_flags( $fmt_keys, $this->all_teaching_formats ),

            // ── Offerings type (all options, 1/0) ─────────────────────────────
            'offerings_type' => $this->build_flags(
                is_array( $meta['teacher-offerings-type'] ?? null ) ? $meta['teacher-offerings-type'] : [],
                $this->all_offerings_types
            ),

            // ── Offerings content ─────────────────────────────────────────────
            'offerings_content' => [
                'left_html'  => $meta['offerings']  ?? '',
                'left_text'  => $this->strip( $meta['offerings']  ?? '' ),
                'right_html' => $meta['offerings2'] ?? '',
                'right_text' => $this->strip( $meta['offerings2'] ?? '' ),
            ],

            // ── Credentials (all options, 1/0 + other text) ───────────────────
            'credentials' => array_merge(
                $this->build_flags(
                    is_array( $meta['teacher-credential-type'] ?? null ) ? $meta['teacher-credential-type'] : [],
                    $this->all_credentials
                ),
                [ 'other_text' => $meta['teacher-credential-other'] ?? '' ]
            ),

            // ── Languages (all 43 options, 1/0 + any custom typed values) ─────
            'languages' => $this->build_language_flags(
                is_array( $meta['teacher-language'] ?? null ) ? $meta['teacher-language'] : []
            ),

            // ── Keywords (array of objects) ───────────────────────────────────
            'keywords' => $this->build_keywords( $meta['ryc-teacher-keyword'] ?? [] ),

            // ── Linked events (array of objects) ──────────────────────────────
            'linked_events' => $this->build_linked_events( $meta['linked-location-teacher'] ?? [] ),

            // ── Images (id + url for each slot) ──────────────────────────────
            'images' => [
                'main'         => $this->image_object( $meta['main_images']     ?? '' ),
                'mobile'       => $this->image_object( $meta['mobile_images']   ?? '' ),
                'new_template' => $this->image_object( $meta['main_new_images'] ?? '' ),
                'single_page'  => $this->image_object( $meta['single_images']   ?? '' ),
            ],

            // ── Location ──────────────────────────────────────────────────────
            'location' => [
                'index'          => $meta['index']                ?? '',
                'street'         => $meta['street']               ?? '',
                'street_address' => $meta['streetAddress']        ?? '',
                'city'           => $meta['addressLocality']      ?? '',
                'postal_code'    => $meta['postalCode']           ?? '',
                'state'          => $meta['schema_teacher_state'] ?? '',
                'country'        => $meta['addressCountry']       ?? '',
                'latitude'       => $meta['latitude']             ?? '',
                'longitude'      => $meta['longitude']            ?? '',
                'taxonomy'       => $tax,
            ],

            // ── Homepage matrix ───────────────────────────────────────────────
            'homepage_matrix' => [
                'value' => $meta['home-page-matrix'] ?? 'not',
                'label' => $this->all_matrix_options[ $meta['home-page-matrix'] ?? 'not' ] ?? 'Do not show',
                'options' => $this->build_flags(
                    [ $meta['home-page-matrix'] ?? 'not' ],
                    $this->all_matrix_options
                ),
            ],

        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a flags object: all keys from $all_options, value = 1 if in $selected, else 0.
     */
    private function build_flags( $selected, $all_options ) {
        $selected = array_map( 'strval', (array) $selected );
        $result   = [];
        foreach ( $all_options as $key => $label ) {
            $result[ $key ] = in_array( $key, $selected, true ) ? 1 : 0;
        }
        return $result;
    }

    /**
     * Languages: known keys get 1/0, custom typed values get appended as extra keys with value 1.
     */
    private function build_language_flags( $selected ) {
        $selected = array_map( 'strval', (array) $selected );
        $result   = [];

        // All known languages with 1/0
        foreach ( $this->all_languages as $key => $label ) {
            $result[ $key ] = in_array( $key, $selected, true ) ? 1 : 0;
        }

        // Default to English if nothing selected
        if ( empty( $selected ) ) {
            $result['english'] = 1;
        }

        // Any custom typed values not in the known list
        foreach ( $selected as $val ) {
            $val = trim( $val );
            if ( $val !== '' && ! isset( $this->all_languages[ $val ] ) ) {
                $result[ $val ] = 1;
            }
        }

        return $result;
    }

    /**
     * Keywords: stored as "link[:keyword:]name" — return array of objects.
     */
    private function build_keywords( $value ) {
        if ( empty( $value ) ) return [];
        $arr    = is_array( $value ) ? $value : [ $value ];
        $result = [];
        foreach ( $arr as $item ) {
            $item = trim( (string) $item );
            if ( $item === '' ) continue;
            if ( strpos( $item, '[:keyword:]' ) !== false ) {
                $parts    = explode( '[:keyword:]', $item, 2 );
                $result[] = [
                    'name' => trim( $parts[1] ?? '' ),
                    'link' => trim( $parts[0] ?? '' ),
                ];
            } else {
                $result[] = [ 'name' => $item, 'link' => '' ];
            }
        }
        return $result;
    }

    /**
     * Linked events: stored as array of post IDs — return array of {id, title, url}.
     */
    private function build_linked_events( $value ) {
        if ( empty( $value ) ) return [];
        $arr    = is_array( $value ) ? $value : [ $value ];
        $result = [];
        foreach ( $arr as $id ) {
            $id = (int) $id;
            if ( ! $id ) continue;
            $result[] = [
                'id'    => $id,
                'title' => get_the_title( $id ) ?: '',
                'url'   => get_permalink( $id )  ?: '',
            ];
        }
        return $result;
    }

    /**
     * Image: return {id, url} object or null if not set.
     */
    private function image_object( $id ) {
        $id = (int) $id;
        if ( ! $id ) return [ 'id' => null, 'url' => '' ];
        $url = wp_get_attachment_url( $id );
        return [ 'id' => $id, 'url' => $url ?: '' ];
    }

    private function clean_email( $email ) {
        return preg_replace( '#^mailto:#i', '', trim( $email ) );
    }

    private function strip( $html ) {
        $text = preg_replace( '#<br\s*/?>|</li>|</p>|</div>|</h[1-6]>#i', ', ', $html );
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( '/,\s*,/', ',', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text, " ,\t\n\r" );
    }
}

new RYC_Teacher_Structured_Exporter();
