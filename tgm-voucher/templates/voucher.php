<?php
/**
 * Public voucher template.
 *
 * Available: $data (voucher array), $settings, $public_url, $post
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Left block: per-voucher agency (only when filled — default brand sits in the center).
$agency_name = $data['agency_name'];
$agency_logo = $data['agency_logo'];

// Center block: default brand (TGM) logo + name, overridable from settings.
$center_logo = $settings['center_logo'] ? $settings['center_logo'] : TGMV_Settings::brand_logo_url( $settings );

$total_nights = 0;
foreach ( $data['hotels'] as $h ) {
	$total_nights += (int) $h['nights'];
}

$approved  = ( 'approved' === $data['status'] );
$wm_text   = $approved ? 'Approved' : 'Unapproved';
$wm_class  = $approved ? 'tgmv-wm-green' : 'tgmv-wm-red';

$qr_src = '';
if ( ! empty( $settings['show_qr'] ) ) {
	$qr_src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=0&data=' . rawurlencode( $public_url );
}

$terms = array_filter( array_map( 'trim', explode( "\n", $settings['terms_urdu'] ) ) );
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $data['voucher_no'] . ' — ' . $settings['center_title'] ); ?></title>
<style>
	* { margin: 0; padding: 0; box-sizing: border-box; }
	body {
		font-family: Verdana, Tahoma, Arial, sans-serif;
		font-size: 11px;
		color: #000;
		background: #777;
	}
	.sheet {
		position: relative;
		width: 210mm;
		min-height: 296mm;
		margin: 10px auto;
		padding: 8mm 7mm;
		background: #fff;
		box-shadow: 0 2px 8px rgba(0,0,0,.4);
		overflow: hidden;
	}
	table { border-collapse: collapse; width: 100%; }

	/* ---------- Watermark ---------- */
	.tgmv-wm {
		position: absolute;
		top: 42%;
		left: 50%;
		transform: translate(-50%, -50%) rotate(-40deg);
		font-size: 84px;
		font-weight: bold;
		font-family: Georgia, 'Times New Roman', serif;
		opacity: .30;
		white-space: nowrap;
		pointer-events: none;
		z-index: 5;
	}
	.tgmv-wm-green { color: #2e9e4f; }
	.tgmv-wm-red   { color: #d63638; }

	/* ---------- Header ---------- */
	.tgmv-header { width: 100%; margin-bottom: 6px; }
	.tgmv-header td { vertical-align: top; }
	.tgmv-h-left  { width: 38%; }
	.tgmv-h-mid   { width: 24%; text-align: center; }
	.tgmv-h-right { width: 38%; text-align: right; }

	.tgmv-brand { display: flex; gap: 8px; }
	.tgmv-brand img { max-height: 74px; max-width: 90px; object-fit: contain; }
	.tgmv-brand-name { color: #00008B; font-weight: bold; font-size: 14px; margin-bottom: 4px; }
	.tgmv-brand-meta div { margin-bottom: 2px; font-size: 11px; }
	.tgmv-brand-meta .lbl { display: inline-block; min-width: 78px; }

	.tgmv-h-mid img { max-height: 62px; max-width: 110px; object-fit: contain; }
	.tgmv-h-mid .brand { color: #00008B; font-weight: bold; font-size: 12px; }
	.tgmv-h-mid .title { font-weight: bold; font-size: 12px; }

	.tgmv-h-right .name { color: #00008B; font-weight: bold; font-size: 14px; }
	.tgmv-h-right div { margin-bottom: 2px; }
	.tgmv-h-right .strong { font-weight: bold; }

	/* ---------- Family head bar ---------- */
	.tgmv-fam { border: 1.5px solid #000; margin-bottom: 4px; }
	.tgmv-fam td { border: 1px solid #000; padding: 4px 8px; font-size: 12px; }
	.tgmv-fam .num { font-weight: bold; text-align: center; width: 20%; }

	/* ---------- Section tables ---------- */
	.tgmv-tbl { border: 1.5px solid #000; margin-bottom: 6px; }
	.tgmv-tbl caption {
		border: 1.5px solid #000;
		border-bottom: 0;
		font-weight: bold;
		text-align: center;
		padding: 2px;
		font-size: 12px;
		background: #f2f2f2;
	}
	.tgmv-tbl th {
		border: 1px solid #000;
		padding: 3px 4px;
		font-size: 11px;
		text-align: left;
	}
	.tgmv-tbl td {
		border-bottom: 1px dotted #555;
		padding: 4px;
		font-size: 11px;
		vertical-align: top;
	}
	.tgmv-tbl tr:last-child td { border-bottom: 0; }
	.tgmv-center { text-align: center; }

	.tgmv-total-row { margin: -2px 0 6px; text-align: right; font-size: 12px; }
	.tgmv-total-row .box {
		display: inline-block;
		border: 1.5px solid #000;
		padding: 2px 14px;
		font-weight: bold;
		margin-left: 6px;
	}
	.tgmv-total-row em { font-weight: bold; }

	/* ---------- Flights + QR ---------- */
	.tgmv-flights { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 6px; }
	.tgmv-flights .ftbl { flex: 1; }
	.tgmv-ftbl { border: 1.5px solid #000; }
	.tgmv-ftbl .fhead {
		border: 1px solid #000;
		background: #dce6f1;
		color: #00008B;
		font-weight: bold;
		text-align: center;
		padding: 3px;
		font-size: 12px;
	}
	.tgmv-ftbl th { border: 1px solid #000; padding: 3px; font-size: 11px; }
	.tgmv-ftbl td { border: 1px solid #000; padding: 3px; font-size: 11px; text-align: center; }
	.tgmv-qr { width: 96px; }
	.tgmv-qr img { width: 96px; height: 96px; }

	.tgmv-instr { color: #00008B; font-weight: bold; font-style: italic; font-size: 12px; margin-top: 2px; }
	.tgmv-instr-text { font-size: 11px; margin-top: 2px; white-space: pre-line; }

	/* ---------- Page 2 (terms) ---------- */
	.tgmv-terms {
		direction: rtl;
		font-family: 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', 'Urdu Typesetting', serif;
		font-size: 15px;
		line-height: 2.4;
	}
	.tgmv-terms li { margin-bottom: 6px; list-style: none; }
	.tgmv-terms li::before { content: '۔ '; }

	/* ---------- Toolbar ---------- */
	.tgmv-toolbar {
		position: fixed;
		top: 12px;
		right: 12px;
		z-index: 99;
	}
	.tgmv-toolbar button {
		background: #2271b1;
		color: #fff;
		border: 0;
		padding: 10px 18px;
		font-size: 14px;
		border-radius: 4px;
		cursor: pointer;
		box-shadow: 0 2px 6px rgba(0,0,0,.3);
	}

	/* ---------- Print ---------- */
	@page { size: A4; margin: 0; }
	@media print {
		body { background: #fff; }
		.sheet {
			margin: 0;
			box-shadow: none;
			width: auto;
			min-height: auto;
			page-break-after: always;
		}
		.sheet:last-child { page-break-after: auto; }
		.tgmv-toolbar { display: none; }
		.tgmv-wm, .tgmv-ftbl .fhead, .tgmv-tbl caption {
			-webkit-print-color-adjust: exact;
			print-color-adjust: exact;
		}
	}
</style>
</head>
<body>

<div class="tgmv-toolbar"><button onclick="window.print()">&#128424; Print / Save PDF</button></div>

<!-- ============ PAGE 1 ============ -->
<div class="sheet">
	<div class="tgmv-wm <?php echo esc_attr( $wm_class ); ?>"><?php echo esc_html( $wm_text ); ?></div>

	<table class="tgmv-header">
		<tr>
			<td class="tgmv-h-left">
				<div class="tgmv-brand">
					<?php if ( $agency_logo ) : ?>
						<img src="<?php echo esc_url( $agency_logo ); ?>" alt="">
					<?php endif; ?>
					<div class="tgmv-brand-meta">
						<?php if ( $agency_name ) : ?>
							<div class="tgmv-brand-name"><?php echo esc_html( $agency_name ); ?></div>
						<?php endif; ?>
						<div><span class="lbl">Voucher Date:</span> <?php echo esc_html( TGMV_Frontend::fdate( $data['voucher_date'], 'd/m/y' ) ); ?></div>
						<div><span class="lbl">Package:</span> <?php echo esc_html( $data['package'] ); ?></div>
						<div><span class="lbl">PAX:</span> <?php echo esc_html( TGMV_Data::pax_line( $data ) ); ?></div>
					</div>
				</div>
			</td>
			<td class="tgmv-h-mid">
				<img src="<?php echo esc_url( $center_logo ); ?>" alt=""><br>
				<span class="brand"><?php echo esc_html( $settings['brand_name'] ); ?></span><br>
				<span class="title"><?php echo esc_html( $settings['center_title'] ); ?></span>
			</td>
			<td class="tgmv-h-right">
				<div class="name"><?php echo esc_html( $data['arkan_name'] ); ?></div>
				<div><?php echo esc_html( $data['arkan_ref'] ); ?></div>
				<div class="strong"><?php echo esc_html( $data['arkan_city'] ); ?></div>
				<div class="strong">Whats APP: <?php echo esc_html( $data['arkan_whatsapp'] ); ?></div>
			</td>
		</tr>
	</table>

	<table class="tgmv-fam">
		<tr>
			<td style="width:45%;">Family Head: <strong><?php echo esc_html( $data['family_head'] ); ?></strong></td>
			<td class="num"><?php echo esc_html( $data['voucher_no'] ); ?></td>
			<td style="width:35%;">Manual No: <strong><?php echo esc_html( $data['manual_no'] ); ?></strong></td>
		</tr>
	</table>

	<table class="tgmv-tbl">
		<caption>Mutamers</caption>
		<thead>
			<tr>
				<th style="width:4%;">SNO</th>
				<th style="width:11%;">Passport</th>
				<th>Mutamer Name</th>
				<th style="width:4%;">G</th>
				<th style="width:6%;">PAX</th>
				<th style="width:5%;">Bed</th>
				<th style="width:9%;">MOFA #</th>
				<th style="width:11%;">GRP #</th>
				<th style="width:9%;">Visa #</th>
				<th style="width:9%;">PNR</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['mutamers'] as $i => $m ) : ?>
			<tr>
				<td><?php echo (int) $i + 1; ?></td>
				<td><?php echo esc_html( $m['passport'] ); ?></td>
				<td><?php echo esc_html( $m['name'] ); ?></td>
				<td><?php echo esc_html( $m['gender'] ); ?></td>
				<td><?php echo esc_html( $m['pax'] ); ?></td>
				<td><?php echo esc_html( $m['bed'] ); ?></td>
				<td><?php echo esc_html( $m['mofa'] ); ?></td>
				<td><?php echo esc_html( $m['grp'] ); ?></td>
				<td><?php echo esc_html( $m['visa'] ); ?></td>
				<td><?php echo esc_html( $m['pnr'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $data['hotels'] ) : ?>
	<table class="tgmv-tbl">
		<caption>Accommodation</caption>
		<thead>
			<tr>
				<th style="width:8%;">City</th>
				<th>Hotel Name</th>
				<th style="width:9%;">View</th>
				<th style="width:7%;">Meal</th>
				<th style="width:7%;">Conf#</th>
				<th style="width:15%;">Room Type</th>
				<th style="width:9%;">Checkin</th>
				<th style="width:9%;">Checkout</th>
				<th style="width:6%;">Nights</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['hotels'] as $h ) : ?>
			<tr>
				<td><?php echo esc_html( $h['city'] ); ?></td>
				<td><?php echo esc_html( $h['hotel'] ); ?></td>
				<td><?php echo esc_html( $h['view'] ); ?></td>
				<td><?php echo esc_html( $h['meal'] ); ?></td>
				<td><?php echo esc_html( $h['conf'] ); ?></td>
				<td><?php echo esc_html( $h['room_type'] ); ?></td>
				<td><?php echo esc_html( TGMV_Frontend::fdate( $h['checkin'] ) ); ?></td>
				<td><?php echo esc_html( TGMV_Frontend::fdate( $h['checkout'] ) ); ?></td>
				<td class="tgmv-center"><?php echo esc_html( $h['nights'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<div class="tgmv-total-row"><em>Total Nights:</em><span class="box"><?php echo (int) $total_nights; ?></span></div>
	<?php endif; ?>

	<?php if ( $data['transport'] ) : ?>
	<table class="tgmv-tbl">
		<caption>Transport/Services</caption>
		<thead>
			<tr>
				<th style="width:14%;">Travel Date</th>
				<th style="width:30%;">Transporter</th>
				<th style="width:22%;">Type</th>
				<th>Description</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $data['transport'] as $t ) : ?>
			<tr>
				<td><?php echo esc_html( TGMV_Frontend::fdate( $t['travel_date'] ) ); ?></td>
				<td class="tgmv-center"><?php echo esc_html( $t['transporter'] ); ?></td>
				<td class="tgmv-center"><?php echo esc_html( $t['type'] ); ?></td>
				<td><?php echo esc_html( $t['description'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php if ( $data['dep_flights'] || $data['arr_flights'] || $qr_src ) : ?>
	<div class="tgmv-flights">
		<div class="ftbl">
			<table class="tgmv-ftbl">
				<tr><td class="fhead" colspan="4">Departure (Pakistan to KSA)</td></tr>
				<tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr>
				<?php foreach ( $data['dep_flights'] as $f ) : ?>
				<tr>
					<td><?php echo esc_html( $f['flight'] ); ?></td>
					<td><?php echo esc_html( $f['sector'] ); ?></td>
					<td><?php echo esc_html( TGMV_Frontend::fdatetime( $f['departure'] ) ); ?></td>
					<td><?php echo esc_html( TGMV_Frontend::fdatetime( $f['arrival'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<div class="ftbl">
			<table class="tgmv-ftbl">
				<tr><td class="fhead" colspan="4">Arrival (KSA to PAK)</td></tr>
				<tr><th>Flight</th><th>Sector</th><th>Departure</th><th>Arrival</th></tr>
				<?php foreach ( $data['arr_flights'] as $f ) : ?>
				<tr>
					<td><?php echo esc_html( $f['flight'] ); ?></td>
					<td><?php echo esc_html( $f['sector'] ); ?></td>
					<td><?php echo esc_html( TGMV_Frontend::fdatetime( $f['departure'] ) ); ?></td>
					<td><?php echo esc_html( TGMV_Frontend::fdatetime( $f['arrival'] ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			</table>
		</div>
		<?php if ( $qr_src ) : ?>
		<div class="tgmv-qr"><img src="<?php echo esc_url( $qr_src ); ?>" alt="QR"></div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="tgmv-instr">Special Instructions:</div>
	<?php if ( $data['instructions'] ) : ?>
		<div class="tgmv-instr-text"><?php echo esc_html( $data['instructions'] ); ?></div>
	<?php endif; ?>
</div>

<!-- ============ PAGE 2 (Urdu terms) ============ -->
<?php if ( $terms ) : ?>
<div class="sheet">
	<div class="tgmv-wm <?php echo esc_attr( $wm_class ); ?>"><?php echo esc_html( $wm_text ); ?></div>
	<ul class="tgmv-terms">
		<?php foreach ( $terms as $line ) : ?>
			<li><?php echo esc_html( $line ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
<?php endif; ?>

</body>
</html>
