<?php
/**
 * PDF report template — rendered by Dompdf via CW_Report_Export.
 *
 * Available variables:
 * - $ctx           Report context from CW_Business_Reports::get_context()
 * - $custom_labels Extra participant custom-field labels
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! isset( $ctx ) ) {
    return;
}

$k        = $ctx['kpis'];
$roster   = $ctx['roster'];
$campaigns = $ctx['campaigns'];
$staged   = $ctx['staged'];

$max_roster = 100;
$truncated  = false;
if ( count( $roster ) > $max_roster ) {
    $truncated   = true;
    $roster_view = array_slice( $roster, 0, $max_roster );
} else {
    $roster_view = $roster;
}

$max_campaigns = 12;
$campaign_view = array_slice( $campaigns, 0, $max_campaigns );
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Report — <?php echo esc_html( $ctx['campaign_title'] ); ?></title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; }
    h1 { font-size: 20px; margin: 0 0 4px; color: #006599; }
    h2 { font-size: 14px; margin: 18px 0 8px; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
    .meta { color: #475569; font-size: 11px; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border-bottom: 1px solid #e2e8f0; padding: 5px 6px; vertical-align: top; text-align: left; }
    th { background: #f1f5f9; color: #334155; font-size: 10px; text-transform: uppercase; letter-spacing: 0.3px; }
    .kpi-grid { display: table; width: 100%; border-spacing: 0; }
    .kpi { display: table-cell; width: 25%; padding: 8px; background: #f8fafc; border: 1px solid #e2e8f0; }
    .kpi-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px; }
    .kpi-value { font-size: 16px; font-weight: 700; color: #006599; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .muted { color: #64748b; font-size: 10px; font-style: italic; margin-bottom: 12px; }
</style>
</head>
<body>

<h1><?php echo esc_html__( 'Business Report', 'creativewings-core' ); ?></h1>
<div class="meta">
    <strong><?php echo esc_html( $ctx['business_name'] ); ?></strong><br>
    <?php echo esc_html( $ctx['campaign_title'] ); ?> · <?php echo esc_html( $ctx['range_label'] ); ?><br>
    <?php echo esc_html__( 'Generated', 'creativewings-core' ); ?>: <?php echo esc_html( $ctx['generated_at'] ); ?>
</div>

<h2><?php echo esc_html__( 'Summary', 'creativewings-core' ); ?></h2>
<div class="kpi-grid">
    <div class="kpi">
        <div class="kpi-label"><?php echo esc_html__( 'Campaigns', 'creativewings-core' ); ?></div>
        <div class="kpi-value"><?php echo (int) $k['campaigns_total']; ?></div>
        <div class="kpi-label"><?php echo (int) $k['campaigns_active']; ?> active · <?php echo (int) $k['campaigns_past']; ?> past</div>
    </div>
    <div class="kpi">
        <div class="kpi-label"><?php echo esc_html__( 'Participants', 'creativewings-core' ); ?></div>
        <div class="kpi-value"><?php echo (int) $k['participants']; ?></div>
        <div class="kpi-label"><?php echo esc_html__( 'Completed registrations', 'creativewings-core' ); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label"><?php echo esc_html__( 'Revenue', 'creativewings-core' ); ?></div>
        <div class="kpi-value"><?php echo esc_html( number_format( (float) $k['revenue'], 2 ) ); ?></div>
        <div class="kpi-label"><?php echo esc_html__( 'Avg', 'creativewings-core' ); ?> <?php echo esc_html( number_format( (float) $k['avg_revenue'], 2 ) ); ?> / campaign</div>
    </div>
    <div class="kpi">
        <div class="kpi-label"><?php echo esc_html__( 'Submissions', 'creativewings-core' ); ?></div>
        <div class="kpi-value"><?php echo (int) ( $k['staged'] + $k['claimed'] ); ?></div>
        <div class="kpi-label"><?php echo (int) $k['staged']; ?> staged · <?php echo (int) $k['claimed']; ?> claimed</div>
    </div>
</div>

<?php if ( ! empty( $campaign_view ) ) : ?>
    <h2><?php echo esc_html__( 'Campaigns', 'creativewings-core' ); ?></h2>
    <table>
        <thead>
            <tr>
                <th><?php echo esc_html__( 'Title', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Type', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'State', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Participants', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Revenue', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Staged', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Claimed', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Deadline', 'creativewings-core' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $campaign_view as $c ) : ?>
            <tr>
                <td><?php echo esc_html( $c['title'] ); ?></td>
                <td><?php echo esc_html( $c['type_label'] ); ?></td>
                <td><?php echo esc_html( $c['state_label'] ); ?></td>
                <td class="num"><?php echo (int) $c['participants']; ?></td>
                <td class="num"><?php echo esc_html( number_format( (float) $c['revenue'], 2 ) ); ?></td>
                <td class="num"><?php echo (int) $c['staged']; ?></td>
                <td class="num"><?php echo (int) $c['claimed']; ?></td>
                <td><?php echo esc_html( $c['deadline'] ?: '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ( count( $campaigns ) > $max_campaigns ) : ?>
        <div class="muted">
            <?php
            /* translators: 1: shown rows, 2: total rows */
            printf( esc_html__( 'Showing %1$d of %2$d campaigns. Full list in Excel/CSV export.', 'creativewings-core' ), (int) $max_campaigns, count( $campaigns ) );
            ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ( ! empty( $roster_view ) ) : ?>
    <h2><?php echo esc_html__( 'Participants', 'creativewings-core' ); ?></h2>
    <table>
        <thead>
            <tr>
                <th><?php echo esc_html__( 'Date', 'creativewings-core' ); ?></th>
                <?php if ( $ctx['is_all'] ) : ?><th><?php echo esc_html__( 'Campaign', 'creativewings-core' ); ?></th><?php endif; ?>
                <th><?php echo esc_html__( 'Participant', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Email', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Order', 'creativewings-core' ); ?></th>
                <th class="num"><?php echo esc_html__( 'Amount', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Age', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'School', 'creativewings-core' ); ?></th>
                <?php if ( $ctx['has_competitions'] ) : ?>
                    <th class="num"><?php echo esc_html__( 'Score', 'creativewings-core' ); ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $roster_view as $row ) : ?>
            <tr>
                <td><?php echo esc_html( date_i18n( 'd M Y', strtotime( $row['date'] ) ) ); ?></td>
                <?php if ( $ctx['is_all'] ) : ?><td><?php echo esc_html( $row['campaign'] ); ?></td><?php endif; ?>
                <td><?php echo esc_html( $row['participant'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $row['email'] ?: '—' ); ?></td>
                <td class="num"><?php echo $row['order_id'] ? '#' . (int) $row['order_id'] : '—'; ?></td>
                <td class="num"><?php echo $row['amount'] !== '' ? esc_html( number_format( (float) $row['amount'], 2 ) ) : '—'; ?></td>
                <td><?php echo esc_html( $row['age_label'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $row['school_code'] ?: '—' ); ?></td>
                <?php if ( $ctx['has_competitions'] ) : ?>
                    <td class="num"><?php echo $row['score'] !== '' ? esc_html( $row['score'] ) : '—'; ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ( $truncated ) : ?>
        <div class="muted">
            <?php
            printf( esc_html__( 'Showing first %1$d of %2$d participants. Full participant data including custom fields is in the Excel/CSV export.', 'creativewings-core' ), (int) $max_roster, count( $roster ) );
            ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ( ! empty( $staged ) ) : ?>
    <h2>
        <?php echo esc_html__( 'Staged submissions', 'creativewings-core' ); ?>
        <span style="font-weight:400;font-size:11px;color:#475569;">(<?php echo count( $staged ); ?>)</span>
    </h2>
    <table>
        <thead>
            <tr>
                <th><?php echo esc_html__( 'Code', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Student', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'School', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Status', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Moderation', 'creativewings-core' ); ?></th>
                <th><?php echo esc_html__( 'Created', 'creativewings-core' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( array_slice( $staged, 0, 60 ) as $s ) : ?>
            <tr>
                <td><?php echo esc_html( $s['submission_code'] ); ?></td>
                <td><?php echo esc_html( $s['student_name'] ); ?></td>
                <td><?php echo esc_html( $s['school_code'] ); ?></td>
                <td><?php echo esc_html( $s['status'] ); ?></td>
                <td><?php echo esc_html( $s['moderation_status'] ); ?></td>
                <td><?php echo esc_html( $s['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ( count( $staged ) > 60 ) : ?>
        <div class="muted">
            <?php
            printf( esc_html__( 'Showing 60 of %d staged rows. Full list in Excel/CSV export.', 'creativewings-core' ), count( $staged ) );
            ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>
