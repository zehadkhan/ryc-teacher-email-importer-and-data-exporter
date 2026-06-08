<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RYC_Taxonomy_Exporter {

    public function __construct() {
        add_action( 'admin_menu',                               [ $this, 'add_menu' ] );
        add_action( 'admin_post_ryc_export_taxonomy',           [ $this, 'handle_csv_export' ] );
        add_action( 'admin_post_ryc_export_taxonomy_json',      [ $this, 'handle_json_export' ] );
    }

    public function add_menu() {
        add_management_page(
            'Teacher Locations Export',
            'Teacher Locations Export',
            'manage_options',
            'ryc-teacher-locations-export',
            [ $this, 'render_page' ]
        );
    }

    // ── Load all terms and build hierarchy maps ───────────────────────────────

    private function get_all_terms() {
        return get_terms( [
            'taxonomy'   => 'categories-of-teachers',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ] );
    }

    private function build_hierarchy( $terms ) {
        $by_id     = [];
        $countries = [];
        $states    = [];
        $cities    = [];

        foreach ( $terms as $t ) {
            $by_id[ $t->term_id ] = $t;
        }

        foreach ( $terms as $t ) {
            if ( $t->parent == 0 ) {
                $countries[ $t->term_id ] = $t;
            } elseif ( isset( $by_id[ $t->parent ] ) && $by_id[ $t->parent ]->parent == 0 ) {
                $states[ $t->term_id ] = $t;
            } else {
                $cities[ $t->term_id ] = $t;
            }
        }

        return [ $by_id, $countries, $states, $cities ];
    }

    private function resolve_path( $term, $by_id ) {
        $country = $state = $city = '';

        if ( $term->parent == 0 ) {
            $country = $term->name;
        } elseif ( isset( $by_id[ $term->parent ] ) && $by_id[ $term->parent ]->parent == 0 ) {
            $state   = $term->name;
            $country = $by_id[ $term->parent ]->name;
        } else {
            $city   = $term->name;
            $parent = $by_id[ $term->parent ] ?? null;
            if ( $parent ) {
                $state = $parent->name;
                $grand = $by_id[ $parent->parent ] ?? null;
                $country = $grand ? $grand->name : '';
            }
        }

        return [ $country, $state, $city ];
    }

    private function get_level( $term, $by_id ) {
        if ( $term->parent == 0 ) return 'Country';
        if ( isset( $by_id[ $term->parent ] ) && $by_id[ $term->parent ]->parent == 0 ) return 'State/Region';
        return 'City';
    }

    // ── Build nested JSON tree ────────────────────────────────────────────────

    private function build_json_tree( $terms, $by_id, $countries, $states, $cities, $filter_country, $filter_state, $filter_city ) {
        $tree = [];

        foreach ( $countries as $country_term ) {
            if ( $filter_country !== '' && $country_term->name !== $filter_country ) continue;

            $country_states = [];
            foreach ( $states as $state_term ) {
                if ( $state_term->parent !== $country_term->term_id ) continue;
                if ( $filter_state !== '' && $state_term->name !== $filter_state ) continue;

                $state_cities = [];
                foreach ( $cities as $city_term ) {
                    if ( $city_term->parent !== $state_term->term_id ) continue;
                    if ( $filter_city !== '' && $city_term->name !== $filter_city ) continue;

                    $state_cities[] = [
                        'term_id'       => $city_term->term_id,
                        'name'          => $city_term->name,
                        'slug'          => $city_term->slug,
                        'teacher_count' => (int) $city_term->count,
                    ];
                }

                $country_states[] = [
                    'term_id'       => $state_term->term_id,
                    'name'          => $state_term->name,
                    'slug'          => $state_term->slug,
                    'teacher_count' => (int) $state_term->count,
                    'cities'        => $state_cities,
                ];
            }

            // Include countries with no states only when not filtering by state/city
            if ( empty( $country_states ) && ( $filter_state !== '' || $filter_city !== '' ) ) continue;

            $tree[] = [
                'term_id'       => $country_term->term_id,
                'name'          => $country_term->name,
                'slug'          => $country_term->slug,
                'teacher_count' => (int) $country_term->count,
                'states'        => $country_states,
            ];
        }

        return $tree;
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $terms = $this->get_all_terms();
        if ( is_wp_error( $terms ) ) {
            echo '<div class="wrap"><p>Error loading terms.</p></div>';
            return;
        }

        [ $by_id, $countries, $states, $cities ] = $this->build_hierarchy( $terms );

        $filter_country = sanitize_text_field( $_GET['filter_country'] ?? '' );
        $filter_state   = sanitize_text_field( $_GET['filter_state']   ?? '' );
        $filter_city    = sanitize_text_field( $_GET['filter_city']    ?? '' );

        // Flat rows for preview table
        $rows = [];
        foreach ( $terms as $t ) {
            [ $c, $s, $ci ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country !== '' && $c !== $filter_country ) continue;
            if ( $filter_state   !== '' && $s !== $filter_state   ) continue;
            if ( $filter_city    !== '' && $ci !== $filter_city    ) continue;
            $rows[] = [
                'term'    => $t,
                'level'   => $this->get_level( $t, $by_id ),
                'country' => $c,
                'state'   => $s,
                'city'    => $ci,
            ];
        }

        $total = count( $terms );
        $shown = count( $rows );

        // Cascade: states visible under selected country
        $visible_states = [];
        foreach ( $states as $t ) {
            [ $c ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country === '' || $c === $filter_country ) {
                $visible_states[ $t->term_id ] = $t;
            }
        }

        // Cascade: cities visible under selected country + state
        $visible_cities = [];
        foreach ( $cities as $t ) {
            [ $c, $s ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country !== '' && $c !== $filter_country ) continue;
            if ( $filter_state   !== '' && $s !== $filter_state   ) continue;
            $visible_cities[ $t->term_id ] = $t;
        }

        $hidden_fields = '
            <input type="hidden" name="filter_country" value="' . esc_attr( $filter_country ) . '">
            <input type="hidden" name="filter_state"   value="' . esc_attr( $filter_state ) . '">
            <input type="hidden" name="filter_city"    value="' . esc_attr( $filter_city ) . '">';
        ?>
        <div class="wrap">
            <h1>Teacher Locations Export</h1>
            <p>Exports the <strong>categories-of-teachers</strong> taxonomy — Country › State/Region › City hierarchy. <?= $total ?> terms total.</p>

            <form method="get" action="" style="margin:20px 0;">
                <input type="hidden" name="page" value="ryc-teacher-locations-export">
                <table class="form-table" style="max-width:600px;">
                    <tr>
                        <th><label for="filter_country">Country</label></th>
                        <td>
                            <select name="filter_country" id="filter_country" onchange="this.form.submit()">
                                <option value="">— All Countries —</option>
                                <?php foreach ( $countries as $t ) : ?>
                                    <option value="<?= esc_attr( $t->name ) ?>" <?= selected( $filter_country, $t->name, false ) ?>>
                                        <?= esc_html( $t->name ) ?> (<?= $t->count ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="filter_state">State / Region</label></th>
                        <td>
                            <select name="filter_state" id="filter_state" onchange="this.form.submit()">
                                <option value="">— All States —</option>
                                <?php foreach ( $visible_states as $t ) : ?>
                                    <option value="<?= esc_attr( $t->name ) ?>" <?= selected( $filter_state, $t->name, false ) ?>>
                                        <?= esc_html( $t->name ) ?> (<?= $t->count ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="filter_city">City</label></th>
                        <td>
                            <select name="filter_city" id="filter_city" onchange="this.form.submit()">
                                <option value="">— All Cities —</option>
                                <?php foreach ( $visible_cities as $t ) : ?>
                                    <option value="<?= esc_attr( $t->name ) ?>" <?= selected( $filter_city, $t->name, false ) ?>>
                                        <?= esc_html( $t->name ) ?> (<?= $t->count ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php if ( $filter_country || $filter_state || $filter_city ) : ?>
                    <a href="<?= esc_url( admin_url( 'tools.php?page=ryc-teacher-locations-export' ) ) ?>" class="button" style="margin-top:4px;">Clear filters</a>
                <?php endif; ?>
            </form>

            <p>Showing <strong><?= $shown ?></strong> of <?= $total ?> terms.</p>

            <!-- Preview table -->
            <table class="widefat fixed striped" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th style="width:40px;">ID</th>
                        <th>Name</th>
                        <th>Slug</th>
                        <th style="width:100px;">Level</th>
                        <th>Country</th>
                        <th>State / Region</th>
                        <th>City</th>
                        <th style="width:60px;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr><td colspan="8" style="text-align:center;color:#888;">No terms match the selected filters.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $r ) :
                            $level_colors = [ 'Country' => '#d4edda', 'State/Region' => '#d1ecf1', 'City' => '#fff3cd' ];
                            $bg = $level_colors[ $r['level'] ] ?? '#f0f0f0';
                        ?>
                        <tr>
                            <td><?= $r['term']->term_id ?></td>
                            <td><strong><?= esc_html( $r['term']->name ) ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;"><?= esc_html( $r['term']->slug ) ?></td>
                            <td><span style="background:<?= $bg ?>;padding:2px 8px;border-radius:4px;font-size:12px;"><?= esc_html( $r['level'] ) ?></span></td>
                            <td><?= esc_html( $r['country'] ) ?></td>
                            <td><?= esc_html( $r['state'] ) ?></td>
                            <td><?= esc_html( $r['city'] ) ?></td>
                            <td style="text-align:center;"><?= $r['term']->count ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $shown > 0 ) : ?>
            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ryc_export_taxonomy' ); ?>
                    <input type="hidden" name="action" value="ryc_export_taxonomy">
                    <?= $hidden_fields ?>
                    <button type="submit" class="button button-primary button-large">Download CSV (<?= $shown ?> terms)</button>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'ryc_export_taxonomy_json' ); ?>
                    <input type="hidden" name="action" value="ryc_export_taxonomy_json">
                    <?= $hidden_fields ?>
                    <button type="submit" class="button button-primary button-large" style="background:#2271b1;border-color:#2271b1;">Download JSON (nested)</button>
                </form>

            </div>

            <p style="margin-top:12px;color:#666;font-size:13px;">
                JSON structure: <code>[ { "name": "USA", "states": [ { "name": "California", "cities": [ { "name": "Los Angeles" } ] } ] } ]</code>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── CSV export handler ────────────────────────────────────────────────────

    public function handle_csv_export() {
        check_admin_referer( 'ryc_export_taxonomy' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $filter_country = sanitize_text_field( $_POST['filter_country'] ?? '' );
        $filter_state   = sanitize_text_field( $_POST['filter_state']   ?? '' );
        $filter_city    = sanitize_text_field( $_POST['filter_city']    ?? '' );

        $terms = $this->get_all_terms();
        if ( is_wp_error( $terms ) ) wp_die( 'Error loading terms.' );

        [ $by_id ] = $this->build_hierarchy( $terms );

        $slug_parts = array_filter( [ $filter_country, $filter_state, $filter_city ] );
        $label      = $slug_parts ? implode( '-', array_map( 'sanitize_title', $slug_parts ) ) : 'all';
        $filename   = 'ryc-teacher-locations-' . $label . '-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fputs( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, [ 'Term ID', 'Name', 'Slug', 'Level', 'Country', 'State/Region', 'City', 'Teacher Count' ] );

        foreach ( $terms as $t ) {
            [ $c, $s, $ci ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country !== '' && $c !== $filter_country ) continue;
            if ( $filter_state   !== '' && $s !== $filter_state   ) continue;
            if ( $filter_city    !== '' && $ci !== $filter_city    ) continue;

            fputcsv( $out, [
                $t->term_id,
                $t->name,
                $t->slug,
                $this->get_level( $t, $by_id ),
                $c, $s, $ci,
                $t->count,
            ] );
        }

        fclose( $out );
        exit;
    }

    // ── JSON export handler ───────────────────────────────────────────────────

    public function handle_json_export() {
        check_admin_referer( 'ryc_export_taxonomy_json' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $filter_country = sanitize_text_field( $_POST['filter_country'] ?? '' );
        $filter_state   = sanitize_text_field( $_POST['filter_state']   ?? '' );
        $filter_city    = sanitize_text_field( $_POST['filter_city']    ?? '' );

        $terms = $this->get_all_terms();
        if ( is_wp_error( $terms ) ) wp_die( 'Error loading terms.' );

        [ $by_id, $countries, $states, $cities ] = $this->build_hierarchy( $terms );

        $tree = $this->build_json_tree( $terms, $by_id, $countries, $states, $cities, $filter_country, $filter_state, $filter_city );

        $slug_parts = array_filter( [ $filter_country, $filter_state, $filter_city ] );
        $label      = $slug_parts ? implode( '-', array_map( 'sanitize_title', $slug_parts ) ) : 'all';
        $filename   = 'ryc-teacher-locations-' . $label . '-' . date( 'Y-m-d' ) . '.json';

        header( 'Content-Type: application/json; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        echo wp_json_encode( $tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }
}

new RYC_Taxonomy_Exporter();
