<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RYC_Teacher_Exporter {

    // ── Label maps ────────────────────────────────────────────────────────────

    private $lang_map = [
        'english'=>'English','spanish'=>'Spanish','french'=>'French','german'=>'German',
        'italian'=>'Italian','portuguese'=>'Portuguese','dutch'=>'Dutch','hebrew'=>'Hebrew',
        'arabic'=>'Arabic','persian'=>'Persian / Farsi','russian'=>'Russian',
        'ukrainian'=>'Ukrainian','polish'=>'Polish','czech'=>'Czech','slovak'=>'Slovak',
        'hungarian'=>'Hungarian','romanian'=>'Romanian','bulgarian'=>'Bulgarian',
        'serbian'=>'Serbian','croatian'=>'Croatian','swedish'=>'Swedish',
        'norwegian'=>'Norwegian','danish'=>'Danish','finnish'=>'Finnish','greek'=>'Greek',
        'turkish'=>'Turkish','hindi'=>'Hindi','urdu'=>'Urdu','punjabi'=>'Punjabi',
        'bengali'=>'Bengali / Bangla','gujarati'=>'Gujarati','tamil'=>'Tamil',
        'mandarin'=>'Mandarin Chinese','cantonese'=>'Cantonese','japanese'=>'Japanese',
        'korean'=>'Korean','thai'=>'Thai','vietnamese'=>'Vietnamese',
        'tagalog'=>'Tagalog / Filipino','indonesian'=>'Indonesian / Malay',
        'swahili'=>'Swahili','afrikaans'=>'Afrikaans','welsh'=>'Welsh',
    ];

    private $credential_map = [
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

    private $offering_map = [
        'ryc-classes'    => 'RYC® Classes',
        'ryc-one-on-one' => 'RYC® One on One Sessions',
        'ryc-inspired'   => 'RYC® Inspired Classes',
        'other-offering' => 'Other',
    ];

    private $format_map = [
        'inperson' => 'In-person',
        'online'   => 'Online',
    ];

    private $matrix_map = [
        'first'  => 'First Row',
        'second' => 'Second Row',
        'third'  => 'Third Row',
        'fourth' => 'Fourth Row',
        'not'    => 'Do not show',
    ];

    // ── Boot ──────────────────────────────────────────────────────────────────

    public function __construct() {
        add_action( 'admin_menu',                        [ $this, 'add_menu' ] );
        add_action( 'admin_post_ryc_export_teachers',    [ $this, 'handle_export' ] );
    }

    public function add_menu() {
        add_management_page(
            'Teacher Data Export',
            'Teacher Data Export',
            'manage_options',
            'ryc-teacher-export',
            [ $this, 'render_page' ]
        );
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        // Quick summary counts
        global $wpdb;
        $counts = $wpdb->get_results(
            "SELECT post_status, COUNT(*) AS total FROM {$wpdb->posts}
             WHERE post_type = 'teacher' GROUP BY post_status",
            OBJECT_K
        );
        $total = array_sum( array_column( (array) $counts, 'total' ) );
        ?>
        <div class="wrap">
            <h1>Teacher Data Export</h1>
            <p>Exports every field from the teacher edit screen, both page templates, and taxonomy — nothing omitted.</p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;">
                <?php
                $status_colors = [
                    'publish'    => '#d4edda',
                    'draft'      => '#fff3cd',
                    'pending'    => '#d1ecf1',
                    'private'    => '#e2e3e5',
                    'auto-draft' => '#f8f9fa',
                    'trash'      => '#f8d7da',
                ];
                foreach ( (array) $counts as $status => $row ) :
                    $bg = $status_colors[ $status ] ?? '#f0f0f0';
                ?>
                <div style="background:<?= $bg ?>;padding:10px 18px;border-radius:6px;font-size:13px;">
                    <strong style="font-size:20px;display:block;"><?= $row->total ?></strong>
                    <?= esc_html( ucfirst( $status ) ) ?>
                </div>
                <?php endforeach; ?>
                <div style="background:#333;color:#fff;padding:10px 18px;border-radius:6px;font-size:13px;">
                    <strong style="font-size:20px;display:block;"><?= $total ?></strong>
                    Total
                </div>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ryc_export_teachers' ); ?>
                <input type="hidden" name="action" value="ryc_export_teachers">

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="export_status">Post Status</label></th>
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
                        <th scope="row">Fields included</th>
                        <td style="font-size:13px;color:#555;line-height:1.8;">
                            Post ID · Title · Status · URL · Date Created · Date Modified<br>
                            Teacher Name · Business Name · Credential/H1 Text · Bio · Public Email · System Email<br>
                            Website · Facebook · Twitter · Instagram · LinkedIn · Phone · Booking Link<br>
                            Is Pro · Is Featured · Teaching Format · Offerings & Services · Languages · Credentials<br>
                            Index · Street · Schema Address · City · Postal Code · State · Country · Lat · Lng<br>
                            Offerings Left · Offerings Right · Home Matrix · Keywords · Linked Event IDs<br>
                            Image URLs (Main / Mobile / New Template / Single Page)<br>
                            Taxonomy: Country · State · City
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Download CSV', 'primary large' ); ?>
            </form>
        </div>
        <?php
    }

    // ── Export handler ────────────────────────────────────────────────────────

    public function handle_export() {
        check_admin_referer( 'ryc_export_teachers' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $status = sanitize_text_field( $_POST['export_status'] ?? 'any' );

        $query_args = [
            'post_type'      => 'teacher',
            'posts_per_page' => -1,
            'post_status'    => $status === 'any' ? [ 'publish', 'draft', 'pending', 'private', 'future' ] : [ $status ],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        $posts = get_posts( $query_args );

        // Stream CSV directly
        $filename = 'ryc-teachers-' . $status . '-' . date( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel
        fputs( $out, "\xEF\xBB\xBF" );

        // Headers row
        fputcsv( $out, $this->get_headers() );

        foreach ( $posts as $post ) {
            $meta  = get_post_meta( $post->ID, 'ryc-teachers-meta', true );
            $meta  = is_array( $meta ) ? $meta : [];
            $terms = get_the_terms( $post->ID, 'categories-of-teachers' );
            fputcsv( $out, $this->build_row( $post, $meta, $terms ) );
        }

        fclose( $out );
        exit;
    }

    // ── CSV column headers ────────────────────────────────────────────────────

    private function get_headers() {
        return [
            // Core
            'Post ID',
            'Post Title',
            'Post Status',
            'Post URL',
            'Date Created',
            'Date Modified',

            // Identity
            'Teacher Name',
            'Business Name',
            'Credential / H1 Text',
            'Bio',

            // Contact
            'Public Email',
            'System Email',
            'Website',
            'Phone',
            'Facebook',
            'Twitter',
            'Instagram',
            'LinkedIn',

            // Flags
            'Is Pro Teacher',
            'Is Featured',
            'Booking Link',

            // Teaching
            'Teaching Format',
            'Offerings & Services',
            'Languages',
            'Credentials',
            'Other Credential',

            // Address / Location
            'Index',
            'Street',
            'Schema Street Address',
            'Address City',
            'Postal Code',
            'State / Region',
            'Country',
            'Latitude',
            'Longitude',

            // Content
            'Offerings Left',
            'Offerings Right',
            'Homepage Matrix',
            'Keywords',
            'Linked Event IDs',

            // Images (URLs)
            'Image URL (Main)',
            'Image URL (Mobile)',
            'Image URL (New Template)',
            'Image URL (Single Page)',

            // Taxonomy
            'Taxonomy Country',
            'Taxonomy State',
            'Taxonomy City',
        ];
    }

    // ── Build one CSV row ─────────────────────────────────────────────────────

    private function build_row( $post, $meta, $terms ) {

        // ── Taxonomy breakdown ───
        $tax_country = $tax_state = $tax_city = '';
        if ( $terms && ! is_wp_error( $terms ) ) {
            $cat1_id = $cat2_id = 0;
            foreach ( $terms as $t ) { if ( $t->parent == 0 )        { $cat1_id = $t->term_id; $tax_country = $t->name; } }
            foreach ( $terms as $t ) { if ( $t->parent == $cat1_id ) { $cat2_id = $t->term_id; $tax_state   = $t->name !== 'State' ? $t->name : ''; } }
            foreach ( $terms as $t ) { if ( $t->parent == $cat2_id ) { $tax_city = $t->name; } }
        }

        // ── Teaching format — merge new field + legacy fallback fields ───
        $fmt_keys = is_array( $meta['teacher-teaching-format'] ?? null ) ? $meta['teacher-teaching-format'] : [];
        if ( ! empty( $meta['teacher-online'] )   && ! in_array( 'online',   $fmt_keys, true ) ) $fmt_keys[] = 'online';
        if ( ! empty( $meta['teacher-inperson'] ) && ! in_array( 'inperson', $fmt_keys, true ) ) $fmt_keys[] = 'inperson';
        $teaching_format = $this->map_array( $fmt_keys, $this->format_map );
        $offerings_type  = $this->map_array( $meta['teacher-offerings-type']  ?? [], $this->offering_map );
        $languages_raw   = $this->map_array( $meta['teacher-language'] ?? [], $this->lang_map, true );
        $languages       = $languages_raw !== '' ? $languages_raw : 'English';
        $credentials     = $this->map_array( $meta['teacher-credential-type'] ?? [], $this->credential_map );

        // ── Keywords → names ───
        $keywords = $this->resolve_keywords( $meta['ryc-teacher-keyword'] ?? [] );

        // ── Linked events → IDs list ───
        $linked_events = '';
        if ( ! empty( $meta['linked-location-teacher'] ) && is_array( $meta['linked-location-teacher'] ) ) {
            $linked_events = implode( ', ', array_map( 'intval', $meta['linked-location-teacher'] ) );
        }

        // ── Image URLs ───
        $img_main     = $this->attachment_url( $meta['main_images']     ?? '' );
        $img_mobile   = $this->attachment_url( $meta['mobile_images']   ?? '' );
        $img_new      = $this->attachment_url( $meta['main_new_images'] ?? '' );
        $img_single   = $this->attachment_url( $meta['single_images']   ?? '' );

        // ── Clean text fields ───
        $bio        = $this->clean_html( $meta['teacher_bio']  ?? '' );
        $off_left   = $this->clean_html( $meta['offerings']    ?? '' );
        $off_right  = $this->clean_html( $meta['offerings2']   ?? '' );

        // ── Flags ───
        $is_pro      = ! empty( $meta['pro-teacher'] )       ? 'Yes' : 'No';
        $is_featured = ! empty( $meta['featured-teacher'] )  ? 'Yes' : 'No';

        // ── Matrix ───
        $matrix = $this->matrix_map[ $meta['home-page-matrix'] ?? '' ] ?? ( $meta['home-page-matrix'] ?? '' );

        // ── Strip mailto: ───
        $email     = preg_replace( '#^mailto:#i', '', trim( $meta['email']        ?? '' ) );
        $sys_email = preg_replace( '#^mailto:#i', '', trim( $meta['system-email'] ?? '' ) );

        return [
            // Core
            $post->ID,
            $post->post_title,
            $post->post_status,
            get_permalink( $post->ID ),
            $post->post_date,
            $post->post_modified,

            // Identity
            $meta['name']          ?? '',
            $meta['business_name'] ?? '',
            $meta['h1-tag-text']   ?? '',
            $bio,

            // Contact
            $email,
            $sys_email,
            $meta['website']        ?? '',
            $meta['telephone']      ?? '',
            $meta['link_facebook']  ?? '',
            $meta['link_twitter']   ?? '',
            $meta['link_instagram'] ?? '',
            $meta['link_linkedin']  ?? '',

            // Flags
            $is_pro,
            $is_featured,
            $meta['booking-link'] ?? '',

            // Teaching
            $teaching_format,
            $offerings_type,
            $languages,
            $credentials,
            $meta['teacher-credential-other'] ?? '',

            // Address
            $meta['index']                ?? '',
            $meta['street']               ?? '',
            $meta['streetAddress']        ?? '',
            $meta['addressLocality']      ?? '',
            $meta['postalCode']           ?? '',
            $meta['schema_teacher_state'] ?? '',
            $meta['addressCountry']       ?? '',
            $meta['latitude']             ?? '',
            $meta['longitude']            ?? '',

            // Content
            $off_left,
            $off_right,
            $matrix,
            $keywords,
            $linked_events,

            // Images
            $img_main,
            $img_mobile,
            $img_new,
            $img_single,

            // Taxonomy
            $tax_country,
            $tax_state,
            $tax_city,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function map_array( $value, $map, $allow_custom = false ) {
        if ( empty( $value ) ) return '';
        $arr = is_array( $value ) ? $value : [ $value ];
        $labels = [];
        foreach ( $arr as $k ) {
            $k = trim( (string) $k );
            if ( isset( $map[ $k ] ) ) {
                $labels[] = $map[ $k ];
            } elseif ( $allow_custom && $k !== '' ) {
                $labels[] = $k;
            }
        }
        return implode( ', ', $labels );
    }

    private function resolve_keywords( $value ) {
        if ( empty( $value ) ) return '';
        $arr   = is_array( $value ) ? $value : [ $value ];
        $names = [];
        foreach ( $arr as $item ) {
            // format: "link[:keyword:]name"
            if ( strpos( $item, '[:keyword:]' ) !== false ) {
                $names[] = trim( explode( '[:keyword:]', $item )[1] );
            } elseif ( trim( $item ) !== '' ) {
                $names[] = trim( $item );
            }
        }
        return implode( ', ', $names );
    }

    private function attachment_url( $id ) {
        $id = (int) $id;
        if ( ! $id ) return '';
        $url = wp_get_attachment_url( $id );
        return $url ?: '';
    }

    private function clean_html( $html ) {
        // Convert list items and block elements to comma-separated text before stripping
        $text = preg_replace( '#<br\s*/?>|</li>|</p>|</div>|</h[1-6]>#i', ', ', $html );
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        // Clean up multiple commas/spaces left over
        $text = preg_replace( '/,\s*,/', ',', $text );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text, " ,\t\n\r" );
    }
}

new RYC_Teacher_Exporter();
