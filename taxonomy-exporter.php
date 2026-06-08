<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RYC_Taxonomy_Exporter {

    public function __construct() {
        add_action( 'admin_menu',                          [ $this, 'add_menu' ] );
        add_action( 'admin_post_ryc_export_taxonomy',      [ $this, 'handle_export' ] );
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

    // ── Load all terms once and build hierarchy maps ──────────────────────────

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

    // ── Resolve full path for a term ──────────────────────────────────────────

    private function resolve_path( $term, $by_id ) {
        $country = $state = $city = '';

        if ( $term->parent == 0 ) {
            $country = $term->name;
        } elseif ( isset( $by_id[ $term->parent ] ) && $by_id[ $term->parent ]->parent == 0 ) {
            $state   = $term->name;
            $country = $by_id[ $term->parent ]->name;
        } else {
            $city    = $term->name;
            $parent  = $by_id[ $term->parent ] ?? null;
            if ( $parent ) {
                $state   = $parent->name;
                $grand   = $by_id[ $parent->parent ] ?? null;
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

        // Apply filters to build preview rows
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

        // Visible states depend on selected country filter
        $visible_states = [];
        foreach ( $states as $t ) {
            [ $c ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country === '' || $c === $filter_country ) {
                $visible_states[ $t->term_id ] = $t;
            }
        }

        // Visible cities depend on selected country + state filter
        $visible_cities = [];
        foreach ( $cities as $t ) {
            [ $c, $s ] = $this->resolve_path( $t, $by_id );
            if ( $filter_country !== '' && $c !== $filter_country ) continue;
            if ( $filter_state   !== '' && $s !== $filter_state   ) continue;
            $visible_cities[ $t->term_id ] = $t;
        }
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

            <p>
                Showing <strong><?= $shown ?></strong> of <?= $total ?> terms.
            </p>

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
                        <?php foreach ( $rows as $r ) : ?>
                        <tr>
                            <td><?= $r['term']->term_id ?></td>
                            <td><strong><?= esc_html( $r['term']->name ) ?></strong></td>
                            <td style="font-family:monospace;font-size:12px;"><?= esc_html( $r['term']->slug ) ?></td>
                            <td>
                                <?php
                                $level_colors = [ 'Country' => '#d4edda', 'State/Region' => '#d1ecf1', 'City' => '#fff3cd' ];
                                $bg = $level_colors[ $r['level'] ] ?? '#f0f0f0';
                                ?>
                                <span style="background:<?= $bg ?>;padding:2px 8px;border-radius:4px;font-size:12px;"><?= esc_html( $r['level'] ) ?></span>
                            </td>
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
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ryc_export_taxonomy' ); ?>
                <input type="hidden" name="action"         value="ryc_export_taxonomy">
                <input type="hidden" name="filter_country" value="<?= esc_attr( $filter_country ) ?>">
                <input type="hidden" name="filter_state"   value="<?= esc_attr( $filter_state ) ?>">
                <input type="hidden" name="filter_city"    value="<?= esc_attr( $filter_city ) ?>">
                <?php submit_button( 'Download CSV (' . $shown . ' terms)', 'primary large' ); ?>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Export handler ────────────────────────────────────────────────────────

    public function handle_export() {
        check_admin_referer( 'ryc_export_taxonomy' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $filter_country = sanitize_text_field( $_POST['filter_country'] ?? '' );
        $filter_state   = sanitize_text_field( $_POST['filter_state']   ?? '' );
        $filter_city    = sanitize_text_field( $_POST['filter_city']    ?? '' );

        $terms = $this->get_all_terms();
        if ( is_wp_error( $terms ) ) wp_die( 'Error loading terms.' );

        [ $by_id ] = $this->build_hierarchy( $terms );

        $slug_parts = array_filter( [ $filter_country, $filter_state, $filter_city ] );
        $slug_parts = array_map( 'sanitize_title', $slug_parts );
        $label      = $slug_parts ? implode( '-', $slug_parts ) : 'all';
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
                $c,
                $s,
                $ci,
                $t->count,
            ] );
        }

        fclose( $out );
        exit;
    }
}

new RYC_Taxonomy_Exporter();
