<?php
/**
 * Plugin Name: RYC Teacher Email Importer and Data Exporter
 * Description: Import system-email values for teacher directory listings from a CSV, and export complete teacher data to CSV. Only updates system-email on import — all other meta fields are left untouched.
 * Version: 1.0.0
 * Author: Zehad Khan
 * Author URI: https://itbzinc.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once plugin_dir_path( __FILE__ ) . 'teacher-exporter.php';

class RYC_Teacher_Email_Importer {

    public function __construct() {
        add_action( 'admin_menu',             [ $this, 'add_menu' ] );
        add_action( 'admin_post_ryc_preview_csv',  [ $this, 'handle_preview' ] );
        add_action( 'admin_post_ryc_run_import',   [ $this, 'handle_import' ] );
        add_action( 'admin_head',             [ $this, 'inline_styles' ] );
    }

    public function add_menu() {
        add_management_page(
            'Teacher Email Import',
            'Teacher Email Import',
            'manage_options',
            'ryc-teacher-email-import',
            [ $this, 'render_upload_page' ]
        );
    }

    public function inline_styles() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'ryc-teacher-email-import' ) === false ) return;
        ?>
        <style>
            .ryc-summary { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px; }
            .ryc-badge   { padding:10px 18px; border-radius:6px; font-size:14px; }
            .ryc-badge strong { font-size:20px; display:block; }
            .ryc-ready       { background:#d4edda; color:#155724; }
            .ryc-already     { background:#d1ecf1; color:#0c5460; }
            .ryc-notfound    { background:#f8d7da; color:#721c24; }
            .ryc-skip        { background:#fff3cd; color:#856404; }
            .ryc-duplicate   { background:#e2e3e5; color:#383d41; }
            .ryc-tbl td, .ryc-tbl th { font-size:13px; vertical-align:middle; }
            .ryc-tbl .email-cell { font-family:monospace; font-size:12px; }
            .ryc-row-ready     { background:#f0fff4 !important; }
            .ryc-row-already   { background:#f0f8ff !important; }
            .ryc-row-notfound  { background:#fff5f5 !important; }
            .ryc-row-skip      { background:#fffdf0 !important; }
            .ryc-row-duplicate { background:#f5f5f5 !important; }
        </style>
        <?php
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function normalize_email( $email ) {
        return strtolower( trim( str_replace( 'mailto:', '', $email ) ) );
    }

    private function status_badge( $status ) {
        $map = [
            'ready'       => '<span style="color:#155724;font-weight:600;">✅ Ready</span>',
            'already_set' => '<span style="color:#0c5460;">☑️ Already set</span>',
            'not_found'   => '<span style="color:#721c24;font-weight:600;">❌ Not found</span>',
            'skip'        => '<span style="color:#856404;">⏭️ Skip</span>',
            'duplicate'   => '<span style="color:#383d41;">🔁 Duplicate</span>',
        ];
        return isset( $map[ $status ] ) ? $map[ $status ] : esc_html( $status );
    }

    private function result_badge( $result ) {
        $map = [
            'updated' => '<span style="color:#155724;font-weight:600;">✅ Updated</span>',
            'failed'  => '<span style="color:#721c24;font-weight:600;">❌ Failed</span>',
            'skipped' => '<span style="color:#666;">⏭️ Skipped</span>',
        ];
        return isset( $map[ $result ] ) ? $map[ $result ] : esc_html( $result );
    }

    // ─── Load all teacher posts into a map keyed by normalized public email ──

    private function get_teacher_map() {
        $posts = get_posts( [
            'post_type'      => 'teacher',
            'numberposts'    => -1,
            'post_status'    => [ 'publish', 'draft' ],
            'fields'         => 'ids',
        ] );

        $map = [];
        foreach ( $posts as $id ) {
            $meta = get_post_meta( $id, 'ryc-teachers-meta', true );
            if ( ! is_array( $meta ) ) continue;

            $raw   = isset( $meta['email'] ) ? $meta['email'] : '';
            $key   = $this->normalize_email( $raw );
            if ( empty( $key ) ) continue;

            $post = get_post( $id );
            $map[ $key ] = [
                'id'           => $id,
                'name'         => $post->post_title,
                'post_status'  => $post->post_status,
                'meta'         => $meta,
                'current_sys'  => isset( $meta['system-email'] ) ? trim( $meta['system-email'] ) : '',
            ];
        }
        return $map;
    }

    // ─── Parse CSV ────────────────────────────────────────────────────────────

    private function parse_csv( $file_path ) {
        $rows = [];
        if ( ( $handle = fopen( $file_path, 'r' ) ) === false ) return $rows;

        // Detect and skip BOM
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) rewind( $handle );

        $header = fgetcsv( $handle ); // skip header row
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 2 ) continue;
            $rows[] = [
                'name'         => trim( $row[0] ?? '' ),
                'email'        => trim( $row[1] ?? '' ),
                'system_email' => trim( $row[2] ?? '' ),
            ];
        }
        fclose( $handle );
        return $rows;
    }

    // ─── Build preview rows ───────────────────────────────────────────────────

    private function build_preview( $csv_rows, $teacher_map ) {
        // Count email occurrences in CSV to detect duplicates
        $email_counts = [];
        foreach ( $csv_rows as $row ) {
            $key = $this->normalize_email( $row['email'] );
            $email_counts[ $key ] = ( $email_counts[ $key ] ?? 0 ) + 1;
        }

        $results     = [];
        $seen_emails = [];

        foreach ( $csv_rows as $row ) {
            $csv_key      = $this->normalize_email( $row['email'] );
            $system_email = $row['system_email'];

            $entry = [
                'csv_name'     => $row['name'],
                'csv_email'    => $row['email'],
                'system_email' => $system_email,
                'post_id'      => null,
                'db_name'      => null,
                'db_status'    => null,
                'status'       => '',
                'note'         => '',
            ];

            // Duplicate in CSV
            if ( $email_counts[ $csv_key ] > 1 ) {
                if ( isset( $seen_emails[ $csv_key ] ) ) {
                    $entry['status'] = 'duplicate';
                    $entry['note']   = 'Same email appears more than once in CSV — only first used';
                    $results[] = $entry;
                    continue;
                }
                $entry['note'] = 'Duplicate in CSV (using first occurrence)';
            }
            $seen_emails[ $csv_key ] = true;

            // Skip if no system email
            if ( empty( $system_email ) ) {
                $entry['status'] = 'skip';
                $entry['note']   = 'No system email in CSV — skipped';
                $results[] = $entry;
                continue;
            }

            // Match against DB
            if ( isset( $teacher_map[ $csv_key ] ) ) {
                $match = $teacher_map[ $csv_key ];
                $entry['post_id']  = $match['id'];
                $entry['db_name']  = $match['name'];
                $entry['db_status'] = $match['post_status'];

                $current = strtolower( $match['current_sys'] );
                $incoming = strtolower( $system_email );

                if ( $current === $incoming ) {
                    $entry['status'] = 'already_set';
                    $entry['note']   = 'system-email already matches — no change needed';
                } else {
                    $entry['status'] = 'ready';
                    $entry['note']   = $match['current_sys']
                        ? 'Will replace: ' . $match['current_sys']
                        : 'Will set for the first time';
                }
            } else {
                $entry['status'] = 'not_found';
                $entry['note']   = 'No teacher post matched this public email';
            }

            $results[] = $entry;
        }

        return $results;
    }

    // ─── Pages ────────────────────────────────────────────────────────────────

    public function render_upload_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        ?>
        <div class="wrap">
            <h1>Teacher System Email Importer</h1>
            <p>Upload the CSV with columns: <code>Name, Email, System Email</code></p>
            <p>Only the <strong>system-email</strong> field will be written. All other teacher meta is left untouched.</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'ryc_preview_csv' ); ?>
                <input type="hidden" name="action" value="ryc_preview_csv">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="csv_file">CSV File</label></th>
                        <td>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Preview Import →' ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_preview() {
        check_admin_referer( 'ryc_preview_csv' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        if ( empty( $_FILES['csv_file']['tmp_name'] ) ) {
            wp_die( 'No file uploaded.' );
        }

        $csv_rows    = $this->parse_csv( $_FILES['csv_file']['tmp_name'] );
        $teacher_map = $this->get_teacher_map();
        $preview     = $this->build_preview( $csv_rows, $teacher_map );

        set_transient( 'ryc_email_import_preview', $preview, 30 * MINUTE_IN_SECONDS );

        $this->render_preview_table( $preview );
    }

    private function render_preview_table( $preview ) {
        $counts = [ 'ready' => 0, 'already_set' => 0, 'not_found' => 0, 'skip' => 0, 'duplicate' => 0 ];
        foreach ( $preview as $row ) {
            if ( isset( $counts[ $row['status'] ] ) ) $counts[ $row['status'] ]++;
        }
        ?>
        <div class="wrap">
            <h1>Teacher System Email Importer — Preview</h1>

            <div class="ryc-summary">
                <div class="ryc-badge ryc-ready">      <strong><?php echo $counts['ready'];       ?></strong> Ready to update </div>
                <div class="ryc-badge ryc-already">    <strong><?php echo $counts['already_set']; ?></strong> Already set     </div>
                <div class="ryc-badge ryc-notfound">   <strong><?php echo $counts['not_found'];   ?></strong> Not found       </div>
                <div class="ryc-badge ryc-skip">       <strong><?php echo $counts['skip'];        ?></strong> Skipped (empty) </div>
                <div class="ryc-badge ryc-duplicate">  <strong><?php echo $counts['duplicate'];   ?></strong> Duplicate in CSV</div>
            </div>

            <table class="widefat fixed striped ryc-tbl" style="margin-bottom:24px;">
                <thead>
                    <tr>
                        <th style="width:32px;">#</th>
                        <th style="width:160px;">Name (CSV)</th>
                        <th>Public Email (CSV)</th>
                        <th>System Email (will set)</th>
                        <th style="width:70px;">Post ID</th>
                        <th style="width:160px;">DB Name</th>
                        <th style="width:80px;">Status</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $preview as $i => $row ) : ?>
                    <tr class="ryc-row-<?php echo esc_attr( $row['status'] ); ?>">
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo esc_html( $row['csv_name'] ); ?></td>
                        <td class="email-cell"><?php echo esc_html( $row['csv_email'] ); ?></td>
                        <td class="email-cell"><strong><?php echo esc_html( $row['system_email'] ); ?></strong></td>
                        <td>
                            <?php if ( $row['post_id'] ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>" target="_blank">
                                    <?php echo $row['post_id']; ?>
                                </a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row['db_name'] ?? '—' ); ?></td>
                        <td><?php echo $this->status_badge( $row['status'] ); ?></td>
                        <td style="color:#666;font-size:12px;"><?php echo esc_html( $row['note'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $counts['ready'] > 0 ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ryc_run_import' ); ?>
                <input type="hidden" name="action" value="ryc_run_import">
                <p>
                    <strong><?php echo $counts['ready']; ?> records</strong> will have their <code>system-email</code> updated.
                    No other meta fields will be touched.
                </p>
                <?php submit_button( 'Run Import (' . $counts['ready'] . ' records)', 'primary large' ); ?>
            </form>
            <?php else : ?>
            <p><strong>Nothing to import.</strong> All records are already up to date or could not be matched.</p>
            <?php endif; ?>

            <p><a href="<?php echo esc_url( admin_url( 'tools.php?page=ryc-teacher-email-import' ) ); ?>" class="button">← Upload a different CSV</a></p>
        </div>
        <?php
    }

    public function handle_import() {
        check_admin_referer( 'ryc_run_import' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $preview = get_transient( 'ryc_email_import_preview' );
        if ( ! $preview ) {
            wp_die( 'Session expired. Please re-upload the CSV and try again.' );
        }

        $results = [];
        foreach ( $preview as $row ) {
            if ( $row['status'] !== 'ready' ) {
                $results[] = array_merge( $row, [ 'import_result' => 'skipped' ] );
                continue;
            }

            // Re-fetch meta fresh to avoid stale data
            $meta = get_post_meta( $row['post_id'], 'ryc-teachers-meta', true );
            if ( ! is_array( $meta ) ) {
                $results[] = array_merge( $row, [ 'import_result' => 'failed' ] );
                continue;
            }

            // Only write system-email — everything else untouched
            $meta['system-email'] = $row['system_email'];
            $ok = update_post_meta( $row['post_id'], 'ryc-teachers-meta', $meta );

            $results[] = array_merge( $row, [ 'import_result' => $ok ? 'updated' : 'failed' ] );
        }

        delete_transient( 'ryc_email_import_preview' );
        $this->render_results_table( $results );
    }

    private function render_results_table( $results ) {
        $updated = count( array_filter( $results, function( $r ) { return $r['import_result'] === 'updated'; } ) );
        $failed  = count( array_filter( $results, function( $r ) { return $r['import_result'] === 'failed';  } ) );
        $skipped = count( array_filter( $results, function( $r ) { return $r['import_result'] === 'skipped'; } ) );
        ?>
        <div class="wrap">
            <h1>Teacher System Email Importer — Done</h1>

            <div class="ryc-summary">
                <div class="ryc-badge ryc-ready">    <strong><?php echo $updated; ?></strong> Updated </div>
                <div class="ryc-badge ryc-notfound"> <strong><?php echo $failed;  ?></strong> Failed  </div>
                <div class="ryc-badge ryc-duplicate"><strong><?php echo $skipped; ?></strong> Skipped </div>
            </div>

            <table class="widefat fixed striped ryc-tbl">
                <thead>
                    <tr>
                        <th style="width:32px;">#</th>
                        <th style="width:160px;">Name</th>
                        <th>Public Email</th>
                        <th>System Email Set</th>
                        <th style="width:70px;">Post ID</th>
                        <th style="width:100px;">Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $results as $i => $row ) : ?>
                    <tr>
                        <td><?php echo $i + 1; ?></td>
                        <td><?php echo esc_html( $row['csv_name'] ); ?></td>
                        <td class="email-cell"><?php echo esc_html( $row['csv_email'] ); ?></td>
                        <td class="email-cell">
                            <?php echo $row['import_result'] === 'updated'
                                ? '<strong>' . esc_html( $row['system_email'] ) . '</strong>'
                                : '—'; ?>
                        </td>
                        <td>
                            <?php if ( $row['post_id'] ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>" target="_blank">
                                    <?php echo $row['post_id']; ?>
                                </a>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?php echo $this->result_badge( $row['import_result'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <br>
            <a href="<?php echo esc_url( admin_url( 'tools.php?page=ryc-teacher-email-import' ) ); ?>" class="button">← Import Another CSV</a>
        </div>
        <?php
    }
}

new RYC_Teacher_Email_Importer();
