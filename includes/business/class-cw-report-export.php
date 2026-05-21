<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Business reports export — CSV, Excel (PhpSpreadsheet) and PDF (Dompdf).
 *
 * Hooked via admin-post.php so the request is authenticated against the
 * current WordPress session. Every code path re-verifies role + nonce +
 * campaign ownership before emitting data.
 */
class CW_Report_Export {

    public function __construct() {
        add_action( 'admin_post_cw_export_report',        [ $this, 'handle' ] );
        add_action( 'admin_post_nopriv_cw_export_report', [ $this, 'reject_anon' ] );
    }

    public function reject_anon() {
        wp_safe_redirect( wp_login_url() );
        exit;
    }

    public function handle() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in.', 'creativewings-core' ), 401 );
        }
        if ( ! class_exists( 'CW_Roles' ) || ! CW_Roles::is_business_user() ) {
            wp_die( esc_html__( 'You do not have permission to export reports.', 'creativewings-core' ), 403 );
        }
        check_admin_referer( 'cw_export_report' );

        $uid         = get_current_user_id();
        $campaign_id = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $range       = isset( $_GET['range'] ) ? CW_Business_Reports::sanitize_range( $_GET['range'] ) : CW_Business_Reports::DEFAULT_RANGE;
        $format      = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'csv';

        if ( $campaign_id && ! CW_Business_Reports::user_can_view_campaign( $campaign_id, $uid ) ) {
            wp_die( esc_html__( 'You do not own this campaign.', 'creativewings-core' ), 403 );
        }

        $context = CW_Business_Reports::get_context( $uid, $campaign_id, $range );

        if ( empty( $context['campaign_ids'] ) ) {
            wp_die( esc_html__( 'No campaigns available to export.', 'creativewings-core' ), 400 );
        }

        $filename = $this->build_filename( $context, $format );

        switch ( $format ) {
            case 'xlsx':
                $this->stream_xlsx( $context, $filename );
                break;
            case 'pdf':
                $this->stream_pdf( $context, $filename );
                break;
            case 'csv':
            default:
                $this->stream_csv( $context, $filename );
        }
        exit;
    }

    private function build_filename( $context, $format ) {
        $slug = $context['campaign_id'] ? sanitize_title( $context['campaign_title'] ) : 'all-campaigns';
        $stamp = date_i18n( 'Ymd-His' );
        return sprintf( 'cw-report-%s-%s.%s', $slug, $stamp, $format );
    }

    /* ------------------------------------------------------------------ CSV */

    private function stream_csv( $context, $filename ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        // UTF-8 BOM so Excel opens Malay names cleanly.
        fwrite( $out, "\xEF\xBB\xBF" );

        $this->write_csv_section( $out, '=== Summary ===', $this->summary_rows( $context ) );
        $this->write_csv_section( $out, '=== Campaigns ===', $this->campaign_rows( $context, true ) );
        $this->write_csv_section( $out, '=== Participants ===', $this->roster_rows( $context, true ) );
        if ( $context['has_staged'] ) {
            $this->write_csv_section( $out, '=== Staged Submissions ===', $this->staged_rows( $context, true ) );
        }
        $this->write_csv_section( $out, '=== Revenue Daily ===', $this->revenue_timeseries_rows( $context, true ) );

        fclose( $out );
    }

    private function write_csv_section( $handle, $heading, array $rows ) {
        fputcsv( $handle, [ $heading ] );
        foreach ( $rows as $r ) {
            fputcsv( $handle, $r );
        }
        fputcsv( $handle, [] );
    }

    /* ----------------------------------------------------------------- XLSX */

    private function stream_xlsx( $context, $filename ) {
        if ( ! class_exists( '\\PhpOffice\\PhpSpreadsheet\\Spreadsheet' ) ) {
            wp_die( esc_html__( 'Excel exporter is not installed. Run composer install.', 'creativewings-core' ), 500 );
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle( 'Summary' );
        $this->fill_sheet( $sheet, $this->summary_rows( $context ) );

        $campaigns_sheet = $spreadsheet->createSheet();
        $campaigns_sheet->setTitle( 'Campaigns' );
        $this->fill_sheet( $campaigns_sheet, $this->campaign_rows( $context, true ) );

        $roster_sheet = $spreadsheet->createSheet();
        $roster_sheet->setTitle( 'Participants' );
        $this->fill_sheet( $roster_sheet, $this->roster_rows( $context, true ) );

        if ( $context['has_staged'] ) {
            $staged_sheet = $spreadsheet->createSheet();
            $staged_sheet->setTitle( 'Staged' );
            $this->fill_sheet( $staged_sheet, $this->staged_rows( $context, true ) );
        }

        $rev_sheet = $spreadsheet->createSheet();
        $rev_sheet->setTitle( 'Revenue Daily' );
        $this->fill_sheet( $rev_sheet, $this->revenue_timeseries_rows( $context, true ) );

        $spreadsheet->setActiveSheetIndex( 0 );

        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet );
        $writer->save( 'php://output' );
    }

    private function fill_sheet( $sheet, array $rows ) {
        if ( empty( $rows ) ) {
            return;
        }
        $row_index = 1;
        $col_count = is_array( $rows[0] ) ? count( $rows[0] ) : 1;

        foreach ( $rows as $r ) {
            $col_index = 1;
            foreach ( (array) $r as $value ) {
                $sheet->setCellValueByColumnAndRow( $col_index, $row_index, $value );
                $col_index++;
            }
            $row_index++;
        }

        // Bold the header row.
        if ( $col_count > 0 ) {
            $last_col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $col_count );
            $sheet->getStyle( "A1:{$last_col}1" )->getFont()->setBold( true );
            foreach ( range( 1, $col_count ) as $c ) {
                $sheet->getColumnDimensionByColumn( $c )->setAutoSize( true );
            }
        }
    }

    /* ------------------------------------------------------------------ PDF */

    private function stream_pdf( $context, $filename ) {
        if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
            wp_die( esc_html__( 'PDF exporter is not installed. Run composer install.', 'creativewings-core' ), 500 );
        }

        $template = CW_PATH . 'includes/business/views/report-pdf-template.php';
        if ( ! file_exists( $template ) ) {
            wp_die( esc_html__( 'PDF template missing.', 'creativewings-core' ), 500 );
        }

        ob_start();
        $ctx = $context;
        $custom_labels = CW_Business_Reports::collect_custom_field_labels( $ctx['roster'] );
        include $template;
        $html = ob_get_clean();

        $options = new \Dompdf\Options();
        $options->set( 'isRemoteEnabled', false );
        $options->set( 'defaultFont', 'DejaVu Sans' );

        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $html, 'UTF-8' );
        $dompdf->setPaper( 'A4', 'landscape' );
        $dompdf->render();

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo $dompdf->output();
    }

    /* -------------------------------------------------------- Row builders */

    private function summary_rows( $context ) {
        $k = $context['kpis'];
        return [
            [ 'Metric', 'Value' ],
            [ 'Business', $context['business_name'] ],
            [ 'Filter', $context['campaign_title'] ],
            [ 'Range', $context['range_label'] ],
            [ 'Generated at', $context['generated_at'] ],
            [],
            [ 'Total campaigns', $k['campaigns_total'] ],
            [ 'Active campaigns', $k['campaigns_active'] ],
            [ 'Past campaigns', $k['campaigns_past'] ],
            [ 'Draft / pending', $k['campaigns_pending'] ],
            [ 'Participants', $k['participants'] ],
            [ 'Revenue', $k['revenue'] ],
            [ 'Avg revenue per campaign', $k['avg_revenue'] ],
            [ 'Staged submissions', $k['staged'] ],
            [ 'Claimed submissions', $k['claimed'] ],
            [ 'Moderation pending', $k['moderation_pending'] ],
        ];
    }

    private function campaign_rows( $context, $with_header = true ) {
        $rows = [];
        if ( $with_header ) {
            $rows[] = [ 'Campaign ID', 'Title', 'Type', 'Status', 'State', 'Participants', 'Revenue', 'Staged', 'Claimed', 'Deadline' ];
        }
        foreach ( $context['campaigns'] as $c ) {
            $rows[] = [
                $c['id'],
                $c['title'],
                $c['type_label'],
                $c['status'],
                $c['state_label'],
                $c['participants'],
                $c['revenue'],
                $c['staged'],
                $c['claimed'],
                $c['deadline'],
            ];
        }
        return $rows;
    }

    private function roster_rows( $context, $with_header = true ) {
        $rows          = [];
        $custom_labels = CW_Business_Reports::collect_custom_field_labels( $context['roster'] );
        $has_comp      = $context['has_competitions'];

        if ( $with_header ) {
            $header = [
                'Entry ID', 'Date', 'Campaign ID', 'Campaign',
                'Entry type', 'Participant', 'Account login', 'Email',
                'Order ID', 'Amount paid',
                'Age bracket', 'Submission code', 'School code',
            ];
            if ( $has_comp ) {
                $header[] = 'Judge score';
                $header[] = 'Judge comment';
                $header[] = 'Winner';
            }
            foreach ( $custom_labels as $label ) {
                $header[] = $label;
            }
            $rows[] = $header;
        }

        foreach ( $context['roster'] as $r ) {
            $row = [
                $r['entry_id'],
                $r['date'],
                $r['campaign_id'],
                $r['campaign'],
                $r['entry_type'],
                $r['participant'],
                $r['user_login'],
                $r['email'],
                $r['order_id'] ?: '',
                $r['amount'],
                $r['age_label'],
                $r['submission_code'],
                $r['school_code'],
            ];
            if ( $has_comp ) {
                $row[] = $r['score'];
                $row[] = $r['comment'];
                $row[] = $r['winner'] ? 'Yes' : '';
            }
            foreach ( $custom_labels as $label ) {
                $row[] = $r['custom'][ $label ] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function staged_rows( $context, $with_header = true ) {
        $rows = [];
        if ( $with_header ) {
            $rows[] = [ 'ID', 'Campaign ID', 'Submission code', 'Student name', 'School code', 'Status', 'Moderation', 'Claimed by user', 'Order ID', 'Created at' ];
        }
        foreach ( $context['staged'] as $s ) {
            $rows[] = [
                $s['id'],
                $s['campaign_id'],
                $s['submission_code'],
                $s['student_name'],
                $s['school_code'],
                $s['status'],
                $s['moderation_status'],
                $s['claimed_by_user_id'],
                $s['order_id'],
                $s['created_at'],
            ];
        }
        return $rows;
    }

    private function revenue_timeseries_rows( $context, $with_header = true ) {
        $rows = [];
        if ( $with_header ) {
            $rows[] = [ 'Date', 'Registrations', 'Revenue' ];
        }
        $entry_labels = $context['timeseries']['entries']['labels'] ?? [];
        $entry_data   = $context['timeseries']['entries']['data']   ?? [];
        $rev_data     = $context['timeseries']['revenue']['data']   ?? [];
        foreach ( $entry_labels as $i => $d ) {
            $rows[] = [ $d, (int) ( $entry_data[ $i ] ?? 0 ), (float) ( $rev_data[ $i ] ?? 0 ) ];
        }
        return $rows;
    }
}
