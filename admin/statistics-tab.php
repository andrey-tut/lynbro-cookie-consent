<?php
/**
 * Admin — Statistics tab renderer (Wave D).
 *
 * Shows the local, cookieless, aggregated statistics: events per period, the
 * accept-rate, browser-language / device / OS distributions and the average
 * time-to-action. When telemetry is enabled and a benchmark has been fetched,
 * it also shows how the site's accept-rate compares to the average.
 *
 * All numbers are read from the local stats table — no external request is made
 * to render this tab. Output is escaped; no input is processed here.
 *
 * @package LynbroCookieConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Render a simple horizontal bar list for a distribution map.
 *
 * @param array<string,int>     $map    key => count.
 * @param array<string,string>  $labels Optional key => display label.
 * @return void
 */
function lynbro_cookie_consent_stats_render_bars( $map, $labels = array() ) {
	if ( empty( $map ) ) {
		echo '<p class="description">' . esc_html__( 'No data yet.', 'lynbro-cookie-consent' ) . '</p>';
		return;
	}
	$total = array_sum( array_map( 'intval', $map ) );
	$total = $total > 0 ? $total : 1;
	echo '<ul class="lynbro-cc-stat-bars">';
	foreach ( $map as $key => $count ) {
		$count   = (int) $count;
		$pct     = (int) round( ( $count / $total ) * 100 );
		$label   = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
		?>
		<li class="lynbro-cc-stat-bar">
			<span class="lynbro-cc-stat-bar__label"><?php echo esc_html( $label ); ?></span>
			<span class="lynbro-cc-stat-bar__track" aria-hidden="true">
				<span class="lynbro-cc-stat-bar__fill" style="width:<?php echo esc_attr( $pct ); ?>%;"></span>
			</span>
			<span class="lynbro-cc-stat-bar__value"><?php echo esc_html( number_format_i18n( $count ) ); ?> (<?php echo esc_html( $pct ); ?>%)</span>
		</li>
		<?php
	}
	echo '</ul>';
}

/**
 * Render the Statistics tab content.
 *
 * @return void
 */
function lynbro_cookie_consent_render_statistics_tab() {
	$enabled = function_exists( 'lynbro_cookie_consent_stats_enabled' ) && lynbro_cookie_consent_stats_enabled();

	$periods = array(
		'today'     => array( __( 'Today', 'lynbro-cookie-consent' ), 1 ),
		'yesterday' => array( __( 'Yesterday', 'lynbro-cookie-consent' ), 2 ), // Handled specially below.
		'7d'        => array( __( 'Last 7 days', 'lynbro-cookie-consent' ), 7 ),
		'30d'       => array( __( 'Last 30 days', 'lynbro-cookie-consent' ), 30 ),
		'365d'      => array( __( 'Last 365 days', 'lynbro-cookie-consent' ), 365 ),
	);

	$device_labels = array(
		'desktop' => __( 'Desktop', 'lynbro-cookie-consent' ),
		'tablet'  => __( 'Tablet', 'lynbro-cookie-consent' ),
		'mobile'  => __( 'Mobile', 'lynbro-cookie-consent' ),
	);
	$os_labels = array(
		'windows' => __( 'Windows', 'lynbro-cookie-consent' ),
		'macos'   => __( 'macOS', 'lynbro-cookie-consent' ),
		'linux'   => __( 'Linux', 'lynbro-cookie-consent' ),
		'android' => __( 'Android', 'lynbro-cookie-consent' ),
		'ios'     => __( 'iOS', 'lynbro-cookie-consent' ),
		'other'   => __( 'Other', 'lynbro-cookie-consent' ),
	);
	?>
	<div class="lynbro-cc-tab-panel" id="lynbro-cc-tab-statistics" hidden>

		<p class="description">
			<?php echo esc_html__( 'Private, cookieless statistics stored only in your own database. They are fully aggregated — no IP address, no visitor identifier and no personal data is ever recorded. Because of that, these statistics do not by themselves require consent.', 'lynbro-cookie-consent' ); ?>
		</p>

		<?php if ( ! $enabled ) : ?>
			<div class="notice notice-warning inline">
				<p><?php echo esc_html__( 'Statistics are currently turned off. Enable “Collect anonymous statistics” in the General tab to start collecting aggregated numbers.', 'lynbro-cookie-consent' ); ?></p>
			</div>
		<?php endif; ?>

		<?php lynbro_cookie_consent_render_benchmark_block(); ?>

		<?php
		// Headline period summary table.
		$summary = array();
		foreach ( $periods as $pkey => $period ) {
			if ( 'yesterday' === $pkey ) {
				// Yesterday = (last 2 days) minus (today).
				$two   = lynbro_cookie_consent_stats_aggregate( 2 );
				$today = lynbro_cookie_consent_stats_aggregate( 1 );
				$agg   = array();
				foreach ( array( 'shown', 'accept', 'reject', 'manage_open', 'lang_switch' ) as $k ) {
					$agg[ $k ] = max( 0, (int) $two[ $k ] - (int) $today[ $k ] );
				}
				$summary[ $pkey ] = $agg;
			} else {
				$summary[ $pkey ] = lynbro_cookie_consent_stats_aggregate( $period[1] );
			}
		}
		?>

		<h2 class="title"><?php echo esc_html__( 'Overview', 'lynbro-cookie-consent' ); ?></h2>
		<table class="widefat striped lynbro-cc-stat-table">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Period', 'lynbro-cookie-consent' ); ?></th>
					<th><?php echo esc_html__( 'Banner shown', 'lynbro-cookie-consent' ); ?></th>
					<th><?php echo esc_html__( 'Accept all', 'lynbro-cookie-consent' ); ?></th>
					<th><?php echo esc_html__( 'Reject all', 'lynbro-cookie-consent' ); ?></th>
					<th><?php echo esc_html__( 'Manage opened', 'lynbro-cookie-consent' ); ?></th>
					<th><?php echo esc_html__( 'Accept rate', 'lynbro-cookie-consent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $periods as $pkey => $period ) : ?>
					<?php
					$row     = $summary[ $pkey ];
					$shown   = isset( $row['shown'] ) ? (int) $row['shown'] : 0;
					$accept  = isset( $row['accept'] ) ? (int) $row['accept'] : 0;
					$reject  = isset( $row['reject'] ) ? (int) $row['reject'] : 0;
					$manage  = isset( $row['manage_open'] ) ? (int) $row['manage_open'] : 0;
					$decided = $accept + $reject;
					$rate    = $decided > 0 ? round( ( $accept / $decided ) * 100 ) : 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $period[0] ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $shown ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $accept ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $reject ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $manage ) ); ?></td>
						<td>
							<?php if ( $decided > 0 ) : ?>
								<?php echo esc_html( $rate ); ?>%
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		// Distributions are most meaningful over a longer window.
		$agg30 = lynbro_cookie_consent_stats_aggregate( 30 );
		$avg   = isset( $agg30['avg_tta_ms'] ) ? (int) $agg30['avg_tta_ms'] : 0;
		?>

		<h2 class="title"><?php echo esc_html__( 'Engagement (last 30 days)', 'lynbro-cookie-consent' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__( 'Average time to decision', 'lynbro-cookie-consent' ); ?></th>
				<td>
					<?php if ( $avg > 0 ) : ?>
						<?php
						printf(
							/* translators: %s: number of seconds, e.g. 4.2. */
							esc_html__( '%s seconds (from banner shown to the visitor’s choice).', 'lynbro-cookie-consent' ),
							esc_html( number_format_i18n( $avg / 1000, 1 ) )
						);
						?>
					<?php else : ?>
						<span class="description"><?php echo esc_html__( 'No data yet.', 'lynbro-cookie-consent' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__( 'Language switches', 'lynbro-cookie-consent' ); ?></th>
				<td><?php echo esc_html( number_format_i18n( isset( $agg30['lang_switch'] ) ? (int) $agg30['lang_switch'] : 0 ) ); ?></td>
			</tr>
		</table>

		<div class="lynbro-cc-stat-grid">
			<div class="lynbro-cc-stat-col">
				<h3><?php echo esc_html__( 'Devices', 'lynbro-cookie-consent' ); ?></h3>
				<?php lynbro_cookie_consent_stats_render_bars( isset( $agg30['device'] ) ? (array) $agg30['device'] : array(), $device_labels ); ?>
			</div>
			<div class="lynbro-cc-stat-col">
				<h3><?php echo esc_html__( 'Operating systems', 'lynbro-cookie-consent' ); ?></h3>
				<?php lynbro_cookie_consent_stats_render_bars( isset( $agg30['os'] ) ? (array) $agg30['os'] : array(), $os_labels ); ?>
			</div>
			<div class="lynbro-cc-stat-col">
				<h3><?php echo esc_html__( 'Browser languages', 'lynbro-cookie-consent' ); ?></h3>
				<?php
				$lang_map    = isset( $agg30['lang'] ) ? (array) $agg30['lang'] : array();
				$lang_labels = array( 'other' => __( 'Other', 'lynbro-cookie-consent' ) );
				lynbro_cookie_consent_stats_render_bars( $lang_map, $lang_labels );
				?>
			</div>
		</div>

		<?php
		/**
		 * Extension point: add-ons can append extra statistics UI here.
		 */
		do_action( 'lynbro_cookie_consent_statistics_tab_after' );
		?>
	</div>
	<?php
}

/**
 * Render the benchmark comparison block (only when telemetry + a benchmark exist).
 *
 * @return void
 */
function lynbro_cookie_consent_render_benchmark_block() {
	if ( ! function_exists( 'lynbro_cookie_consent_telemetry_enabled' ) || ! lynbro_cookie_consent_telemetry_enabled() ) {
		return;
	}
	$bench = function_exists( 'lynbro_cookie_consent_get_benchmark' ) ? lynbro_cookie_consent_get_benchmark() : null;
	if ( ! $bench ) {
		?>
		<div class="notice notice-info inline">
			<p><?php echo esc_html__( 'Benchmark sharing is on. Your first comparison will appear here after the weekly report has run.', 'lynbro-cookie-consent' ); ?></p>
		</div>
		<?php
		return;
	}

	$agg     = lynbro_cookie_consent_stats_aggregate( 7 );
	$decided = (int) $agg['accept'] + (int) $agg['reject'];
	$mine    = $decided > 0 ? round( ( (int) $agg['accept'] / $decided ) * 100 ) : null;
	$avg     = round( ( isset( $bench['accept_rate_avg'] ) ? (float) $bench['accept_rate_avg'] : 0 ) * 100 );
	?>
	<div class="lynbro-cc-benchmark">
		<h2 class="title"><?php echo esc_html__( 'How you compare', 'lynbro-cookie-consent' ); ?></h2>
		<p>
			<?php if ( null !== $mine ) : ?>
				<?php
				printf(
					/* translators: 1: this site's accept rate, 2: the average accept rate across opted-in sites. */
					esc_html__( 'Your accept rate over the last 7 days is %1$s%%. The average across opted-in sites is %2$s%%.', 'lynbro-cookie-consent' ),
					esc_html( $mine ),
					esc_html( $avg )
				);
				?>
			<?php else : ?>
				<?php
				printf(
					/* translators: %s: the average accept rate across opted-in sites. */
					esc_html__( 'The average accept rate across opted-in sites is %s%%. Yours will show here once visitors start choosing.', 'lynbro-cookie-consent' ),
					esc_html( $avg )
				);
				?>
			<?php endif; ?>
		</p>
	</div>
	<?php
}
