<?php
/**
 * Plugin Name: Spilbanditten Feeds
 * Description: Henter partnernes produktfeeds (Partner-ads og Adtraction) én gang i døgnet, gemmer prishistorik og henter produktbilleder fra feedet til mediebiblioteket. Kortkoden [sb_priser] viser en prissammenligning ud fra feedene.
 * Version: 1.6.0
 * Author: Spilbanditten
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Feeds gemmes som JSON i wp-content/uploads/sb-feeds/<butik>.json med fælles felter (produktnavn, pris,
 * lager, url, ean, billedurl, kategori, fragt), uanset netværk (Partner-ads eller Adtraction).
 * Feed-URL'erne indtastes under Indstillinger > Spilbanditten Feeds og gemmes kun i databasen; de
 * skrives aldrig i status, fejlbeskeder eller REST-svar. Prishistorik: sb-feeds/historik/AAAA-MM.csv.
 *
 * REST (kræver login):
 *   POST /wp-json/sb/v1/feeds/refresh  (manage_options)  planlægger hentning nu i baggrunden (evt. ?shop=proshop)
 *   GET  /wp-json/sb/v1/feeds/status   (manage_options)  status for seneste hentning
 *   POST /wp-json/sb/v1/probe          (upload_files)    tester, om serveren kan hente et billede (gemmer intet)
 *   POST /wp-json/sb/v1/sideload       (upload_files)    henter et billede til mediebiblioteket
 *   GET  /wp-json/sb/v1/diag/rank-math (manage_options)  viser filerne i uploads/rank-math (kun læsning)
 *   GET  /wp-json/sb/v1/diag/feed?shop=proshop (manage_options)  feltnavne, eksempelprodukt og kategorier (uden feed-URL)
 *   GET|POST /wp-json/sb/v1/historik/ean (manage_options)  EAN'erne, der gemmes prishistorik for
 *   GET  /wp-json/sb/v1/priser?ean=... (edit_posts)  forhåndsvisning af prissammenligningen (samme HTML som kortkoden)
 *
 * Kortkode: [sb_priser ean="6430018272702"] (flere EAN'er adskilt af komma) viser butikker, pris og fragt fra
 * feedene i en sammenfoldet tabel, sorteret efter pris inkl. fragt. Kun match på EAN, kun varer der ikke er meldt
 * udsolgt, og kun butikker hvis feed er hentet inden for 48 timer. Linkene er feedets egne klik-links.
 * Opdatering: installerer kun udgivelser (GitHub release med tag som v1.6.1) fra det offentlige repo
 * BorisBuranium/spilbanditten-feeds, uden token. Advarsler (feedfejl, gamle feeds, store prisfald) sendes
 * som mail til adressen i indstillingerne.
 * Kortkode: [sb_annonce ean="..."] er annonceoplysningen øverst på siden med navnene på de butikker, tabellen viser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SB_FEEDS_OPTION   = 'sb_feeds_urls';
const SB_FEEDS_STATUS   = 'sb_feeds_status';
const SB_FEEDS_DIAG     = 'sb_feeds_diag';
const SB_FEEDS_HIST_EAN = 'sb_feeds_historik_ean';
const SB_FEEDS_CRON     = 'sb_feeds_hent';
const SB_FEEDS_JOB      = 'sb_feeds_hent_butik';
const SB_FEEDS_META     = '_sb_kilde_url';
const SB_FEEDS_MAIL     = 'sb_feeds_mail';
const SB_FEEDS_TJEK     = 'sb_feeds_tjek';
const SB_FEEDS_REPO     = 'BorisBuranium/spilbanditten-feeds';
const SB_PRISER_MIN     = 20;

/**
 * Butikkerne, i den rækkefølge de hentes og vises. De store blandede feeds hentes sidst, så deres filter
 * kan bruge EAN'erne fra spilbutikkernes feeds.
 *   filter:   'prefix' = behold kun kategorier, der starter med 'kategori_prefix', eller kendte EAN'er.
 *             'regex'  = behold kun brætspil/kortspil (sb_feeds_kategori_regex()) eller kendte EAN'er.
 *   encoding: feedets tegnsæt, hvis det ikke er UTF-8 (bruges, selv om XML-erklæringen siger andet).
 *   timeout:  sekunder til hentningen (Adtraction kan bruge over 45 sekunder på at lave feedet).
 */
function sb_feeds_butikker() {
	return array(
		'spilskabet'    => array( 'navn' => 'Spilskabet', 'net' => 'partner-ads' ),
		'lad-os-spille' => array( 'navn' => 'Lad-os-Spille', 'net' => 'partner-ads' ),
		'nordisk-spil'  => array( 'navn' => 'Nordisk Spil', 'net' => 'partner-ads' ),
		'kelz0r'        => array( 'navn' => 'Kelz0r', 'net' => 'partner-ads' ),
		'bog-og-ide'    => array( 'navn' => 'Bog & idé (feedet "andet")', 'vis' => 'Bog & idé', 'net' => 'partner-ads', 'filter' => 'prefix', 'kategori_prefix' => 'Spil', 'encoding' => 'ISO-8859-1' ),
		'proshop'       => array( 'navn' => 'Proshop (Adtraction)', 'vis' => 'Proshop', 'net' => 'adtraction', 'filter' => 'regex', 'timeout' => 900 ),
	);
}

/**
 * Feltmapping pr. netværk: vores felt => feltnavne i feedet (små bogstaver, uden navnerum som "g:").
 * Det første felt med en værdi vinder. Ret her, når diagnosen (/sb/v1/diag/feed) viser de rigtige navne.
 */
function sb_feeds_feltmapping( $net ) {
	$m = array(
		'partner-ads' => array(
			'produktnavn' => array( 'produktnavn' ),
			'pris'        => array( 'nypris' ),
			'lager'       => array( 'lagerantal' ),
			'url'         => array( 'vareurl' ),
			'ean'         => array( 'ean' ),
			'billedurl'   => array( 'billedurl' ),
			'kategori'    => array( 'kategorinavn' ),
			'fragt'       => array( 'fragtomk' ),
		),
		'adtraction'  => array(
			'produktnavn' => array( 'name', 'productname', 'product_name', 'title' ),
			'pris'        => array( 'price', 'saleprice', 'sale_price', 'currentprice' ),
			'lager'       => array( 'instock', 'in_stock', 'stock', 'availability', 'stockstatus' ),
			'url'         => array( 'trackingurl', 'tracking_url', 'deeplink', 'producturl', 'product_url', 'link' ),
			'ean'         => array( 'ean', 'gtin', 'ean13', 'gtin13' ),
			'billedurl'   => array( 'imageurl', 'image_url', 'image', 'image_link', 'imagelink' ),
			'kategori'    => array( 'category', 'categoryname', 'category_name', 'product_type', 'google_product_category' ),
			'fragt'       => array( 'shipping', 'shippingcost', 'shipping_cost', 'shippingprice', 'deliverycost' ),
		),
	);
	return isset( $m[ $net ] ) ? $m[ $net ] : $m['partner-ads'];
}

/** Kategorier, der tæller som brætspil/kortspil ved filtrering (regex, uden skelnen mellem store og små bogstaver). */
function sb_feeds_kategori_regex() {
	return '/br(æ|ae|a)tspil|kortspil|selskabsspil|familiespil|strategispil|partyspil|board ?games?|card ?games?|brettspill|brädspel|sällskapsspel|gesellschaftsspiel|brettspiel/iu';
}

function sb_feeds_dir() {
	$up = wp_upload_dir();
	return array(
		'path' => trailingslashit( $up['basedir'] ) . 'sb-feeds',
		'url'  => trailingslashit( $up['baseurl'] ) . 'sb-feeds',
	);
}

/** Fjerner feed-URL'en (og dens værtsnavn med sti) fra en tekst, så den aldrig havner i status, logs eller REST-svar. */
function sb_feeds_skjul( $tekst, $url ) {
	$tekst = (string) $tekst;
	if ( $url ) {
		$tekst = str_replace( array( $url, rawurlencode( $url ), esc_url( $url ), html_entity_decode( $url ) ), '[feed-URL]', $tekst );
		$p     = wp_parse_url( $url );
		if ( ! empty( $p['query'] ) ) {
			$tekst = str_replace( $p['query'], '[skjult]', $tekst );
		}
	}
	return $tekst;
}

/* ---------- Aktivering og cron ---------- */

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( SB_FEEDS_CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', SB_FEEDS_CRON );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( SB_FEEDS_CRON );
	wp_clear_scheduled_hook( SB_FEEDS_JOB );
	wp_clear_scheduled_hook( SB_FEEDS_TJEK );
} );

add_action( SB_FEEDS_CRON, 'sb_feeds_hent_alle' );
add_action( SB_FEEDS_JOB, 'sb_feeds_hent_butik' );

/* ---------- Hentning af feeds ---------- */

/**
 * Planlægger én baggrundsjob (WP-Cron) pr. butik med feed-URL, med 60 sekunders mellemrum, så et
 * langsomt feed (Proshop) hverken får admin-siden til at hænge eller stopper de andre.
 * Returnerer listen over planlagte butikker.
 */
function sb_feeds_hent_alle( $kun = '' ) {
	$urls = (array) get_option( SB_FEEDS_OPTION, array() );
	$plan = array();
	$i    = 0;
	foreach ( sb_feeds_butikker() as $key => $b ) {
		if ( ( $kun && $kun !== $key ) || empty( $urls[ $key ] ) ) {
			continue;
		}
		if ( ! wp_next_scheduled( SB_FEEDS_JOB, array( $key ) ) ) {
			wp_schedule_single_event( time() + 60 * $i, SB_FEEDS_JOB, array( $key ) );
		}
		sb_feeds_saet_status( $key, array( 'ok' => null, 'planlagt' => gmdate( 'c', time() + 60 * $i ) ), true );
		$plan[] = $key;
		$i++;
	}
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
	return array( 'planlagt' => $plan );
}

/** Opdaterer status for én butik. Med $behold bevares sidste resultat ved siden af den nye planlægning. */
function sb_feeds_saet_status( $key, $s, $behold = false ) {
	$status = (array) get_option( SB_FEEDS_STATUS, array() );
	if ( ! isset( $status['butikker'] ) ) {
		$status['butikker'] = array();
	}
	if ( $behold && isset( $status['butikker'][ $key ] ) ) {
		$s = array_merge( $status['butikker'][ $key ], $s );
	}
	$status['butikker'][ $key ] = $s;
	$status['tid']              = gmdate( 'c' );
	update_option( SB_FEEDS_STATUS, $status, false );
}

/** Baggrundsjobbet: henter én butiks feed, gemmer det og tager dagens prishistorik for butikken. */
function sb_feeds_hent_butik( $key ) {
	$alle = sb_feeds_butikker();
	$urls = (array) get_option( SB_FEEDS_OPTION, array() );
	if ( ! isset( $alle[ $key ] ) ) {
		return;
	}
	if ( get_transient( 'sb_feeds_laas_' . $key ) ) {
		return; // Kører allerede.
	}
	set_transient( 'sb_feeds_laas_' . $key, 1, 30 * MINUTE_IN_SECONDS );
	@set_time_limit( 0 );
	@ignore_user_abort( true );

	$dir = sb_feeds_dir();
	$url = isset( $urls[ $key ] ) ? trim( $urls[ $key ] ) : '';
	$start = time();
	if ( ! wp_mkdir_p( $dir['path'] ) ) {
		$s = array( 'ok' => false, 'fejl' => 'Kunne ikke oprette mappen sb-feeds' );
	} elseif ( '' === $url ) {
		$s = array( 'ok' => false, 'fejl' => 'ingen feed-URL' );
	} else {
		if ( ! file_exists( $dir['path'] . '/index.php' ) ) {
			file_put_contents( $dir['path'] . '/index.php', "<?php\n// Stille.\n" );
		}
		$s = sb_feeds_hent_en( $key, $alle[ $key ], $url, $dir['path'], sb_feeds_kendte_ean( $dir['path'] ) );
		if ( ! empty( $s['ok'] ) ) {
			sb_feeds_gem_historik( $dir['path'], $key );
			sb_feeds_tjek_prisfald( $dir['path'], $key );
			sb_priser_purge();
		}
	}
	$s['hentet']   = gmdate( 'c' );
	$s['sekunder'] = time() - $start;
	sb_feeds_saet_status( $key, $s );
	delete_transient( 'sb_feeds_laas_' . $key );
	if ( empty( $s['ok'] ) ) {
		sb_feeds_advar( 'Feedet fra ' . $alle[ $key ]['navn'] . ' fejlede', 'Fejl: ' . sb_feeds_skjul( isset( $s['fejl'] ) ? $s['fejl'] : 'ukendt', $url ) );
	}
}

/** EAN'erne i spilbutikkernes seneste feeds (dem uden filter). Bruges til at filtrere de store feeds. */
function sb_feeds_kendte_ean( $path ) {
	$kendte = array();
	foreach ( sb_feeds_butikker() as $key => $b ) {
		$fil = $path . '/' . $key . '.json';
		if ( ! empty( $b['filter'] ) || ! file_exists( $fil ) ) {
			continue;
		}
		$data = json_decode( (string) file_get_contents( $fil ), true );
		foreach ( (array) ( isset( $data['produkter'] ) ? $data['produkter'] : array() ) as $p ) {
			if ( ! empty( $p['ean'] ) ) {
				$kendte[ sb_feeds_ean( $p['ean'] ) ] = true;
			}
		}
	}
	return $kendte;
}

function sb_feeds_hent_en( $key, $b, $url, $path, $kendte_ean ) {
	$tmp = wp_tempnam( 'sb-feed-' . $key );
	$res = wp_remote_get( $url, array(
		'timeout'  => isset( $b['timeout'] ) ? (int) $b['timeout'] : 300,
		'stream'   => true,
		'filename' => $tmp,
	) );
	if ( is_wp_error( $res ) ) {
		@unlink( $tmp );
		return array( 'ok' => false, 'fejl' => sb_feeds_skjul( $res->get_error_message(), $url ) );
	}
	$kode = (int) wp_remote_retrieve_response_code( $res );
	if ( 200 !== $kode ) {
		@unlink( $tmp );
		return array( 'ok' => false, 'http' => $kode, 'fejl' => 'HTTP ' . $kode );
	}

	$filter = null;
	if ( ! empty( $b['filter'] ) ) {
		$filter = array(
			'type'   => $b['filter'],
			'prefix' => isset( $b['kategori_prefix'] ) ? $b['kategori_prefix'] : '',
			'ean'    => $kendte_ean,
		);
	}
	$res = sb_feeds_parse( $tmp, $b['net'], $filter, isset( $b['encoding'] ) ? $b['encoding'] : null );
	@unlink( $tmp );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'http' => $kode, 'fejl' => sb_feeds_skjul( $res->get_error_message(), $url ) );
	}

	$diag         = (array) get_option( SB_FEEDS_DIAG, array() );
	$diag[ $key ] = $res['diag'];
	update_option( SB_FEEDS_DIAG, $diag, false );

	$data = array(
		'butik'     => $b['navn'],
		'hentet'    => gmdate( 'c' ),
		'antal'     => count( $res['produkter'] ),
		'produkter' => $res['produkter'],
	);
	$fil = $path . '/' . $key . '.json';
	if ( false === file_put_contents( $fil, wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ) {
		return array( 'ok' => false, 'fejl' => 'kunne ikke skrive ' . basename( $fil ) );
	}
	$s = array( 'ok' => true, 'http' => $kode, 'antal' => count( $res['produkter'] ) );
	if ( null !== $filter ) {
		$s['gemt']        = $res['gemt'];
		$s['frasorteret'] = $res['frasorteret'];
	}
	return $s;
}

function sb_feeds_ean( $ean ) {
	$e = preg_replace( '/\D/', '', (string) $ean );
	return ltrim( $e, '0' );
}

/** Normaliserer ét produkt (rå felter => vores felter) med netværkets mapping. */
function sb_feeds_normaliser( $raa, $net ) {
	$p = array();
	foreach ( sb_feeds_feltmapping( $net ) as $felt => $kandidater ) {
		foreach ( $kandidater as $k ) {
			if ( isset( $raa[ $k ] ) && '' !== $raa[ $k ] ) {
				$p[ $felt ] = $raa[ $k ];
				break;
			}
		}
	}
	return $p;
}

/**
 * Læser et feed (XML eller CSV) produkt for produkt, så store feeds ikke fylder hukommelsen.
 * XML: produkterne er børn af rodelementet (eller <item> i <channel>). CSV: første linje er feltnavne.
 * Med $filter (type 'prefix' eller 'regex', prefix, ean => kendte EAN'er) gemmes kun spil eller kendte EAN'er.
 * $encoding: feedets tegnsæt, hvis det ikke er UTF-8 (fx ISO-8859-1). Alt gemmes som UTF-8.
 * Returnerer array( produkter, diag, gemt, frasorteret ) eller WP_Error.
 */
function sb_feeds_parse( $fil, $net, $filter = null, $encoding = null ) {
	$fh    = fopen( $fil, 'rb' );
	$start = $fh ? ltrim( (string) fread( $fh, 512 ), "\xEF\xBB\xBF \t\r\n" ) : '';
	if ( $fh ) {
		fclose( $fh );
	}
	if ( '' === $start ) {
		return new WP_Error( 'sb_feed', 'feedet er tomt' );
	}
	$ud   = array( 'produkter' => array(), 'gemt' => 0, 'frasorteret' => 0 );
	$diag = array( 'format' => '', 'felter' => array(), 'eksempel' => null, 'kategorier' => array(), 'antal_raa' => 0 );

	$haandter = function ( $raa ) use ( $net, $filter, &$ud, &$diag ) {
		$diag['antal_raa']++;
		if ( $diag['antal_raa'] <= 200 ) {
			foreach ( $raa as $k => $v ) {
				$diag['felter'][ $k ] = isset( $diag['felter'][ $k ] ) ? $diag['felter'][ $k ] + 1 : 1;
			}
		}
		if ( null === $diag['eksempel'] ) {
			$diag['eksempel'] = array_map( function ( $v ) { return mb_substr( (string) $v, 0, 300 ); }, $raa );
		}
		$p = sb_feeds_normaliser( $raa, $net );
		if ( ! empty( $p['kategori'] ) ) {
			$kat = mb_substr( $p['kategori'], 0, 120 );
			$diag['kategorier'][ $kat ] = isset( $diag['kategorier'][ $kat ] ) ? $diag['kategorier'][ $kat ] + 1 : 1;
		}
		if ( empty( $p['produktnavn'] ) ) {
			return;
		}
		if ( null !== $filter ) {
			$kat = isset( $p['kategori'] ) ? $p['kategori'] : '';
			$ok  = 'prefix' === $filter['type']
				? ( '' !== $filter['prefix'] && 0 === stripos( $kat, $filter['prefix'] ) )
				: ( '' !== $kat && preg_match( sb_feeds_kategori_regex(), $kat ) );
			$ok  = $ok || ( ! empty( $p['ean'] ) && isset( $filter['ean'][ sb_feeds_ean( $p['ean'] ) ] ) );
			if ( ! $ok ) {
				$ud['frasorteret']++;
				return;
			}
			$ud['gemt']++;
		}
		$ud['produkter'][] = $p;
	};

	if ( '<' === $start[0] ) {
		$diag['format'] = 'xml';
		$fejl           = sb_feeds_laes_xml( $fil, $haandter, $encoding );
	} else {
		$diag['format'] = 'csv';
		$fejl           = sb_feeds_laes_csv( $fil, $haandter, $encoding );
	}
	if ( is_wp_error( $fejl ) ) {
		return $fejl;
	}
	if ( ! $diag['antal_raa'] ) {
		return new WP_Error( 'sb_feed', 'ingen produkter fundet i feedet (' . $diag['format'] . ')' );
	}
	arsort( $diag['kategorier'] );
	$diag['kategorier'] = array_slice( $diag['kategorier'], 0, 40, true );
	arsort( $diag['felter'] );
	$ud['diag'] = $diag;
	return $ud;
}

function sb_feeds_laes_xml( $fil, $haandter, $encoding = null ) {
	if ( ! class_exists( 'XMLReader' ) ) {
		return new WP_Error( 'sb_xml', 'XMLReader findes ikke på serveren' );
	}
	$r = new XMLReader();
	// Med $encoding læses filen i det tegnsæt (libxml konverterer til UTF-8), også hvis erklæringen mangler.
	if ( ! $r->open( $fil, $encoding, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT ) ) {
		return new WP_Error( 'sb_xml', 'feedet kunne ikke åbnes som XML' );
	}
	$niveau = 1;
	$videre = @$r->read();
	while ( $videre ) {
		$element = XMLReader::ELEMENT === $r->nodeType;
		if ( $element && 1 === $r->depth && 'channel' === strtolower( $r->localName ) ) {
			$niveau = 2; // RSS: produkterne er <item> i <channel>.
		}
		$er_produkt = $element && $r->depth === $niveau
			&& ( 1 === $niveau || in_array( strtolower( $r->localName ), array( 'item', 'entry' ), true ) );
		if ( ! $er_produkt ) {
			$videre = @$r->read();
			continue;
		}
		// expand() bevarer navnerum (fx g:title i RSS), som readOuterXML() mister.
		$node   = @$r->expand();
		$videre = @$r->next();
		if ( ! $node ) {
			continue;
		}
		$raa = array();
		foreach ( $node->childNodes as $barn ) {
			if ( XML_ELEMENT_NODE === $barn->nodeType ) {
				$raa[ strtolower( $barn->localName ) ] = trim( $barn->textContent );
			}
		}
		if ( $node->attributes ) {
			foreach ( $node->attributes as $att ) {
				$raa[ '@' . strtolower( $att->localName ) ] = trim( $att->value );
			}
		}
		$haandter( $raa );
	}
	$r->close();
	return true;
}

function sb_feeds_laes_csv( $fil, $haandter, $encoding = null ) {
	$fh = fopen( $fil, 'rb' );
	if ( ! $fh ) {
		return new WP_Error( 'sb_csv', 'feedet kunne ikke åbnes' );
	}
	$foerste = (string) fgets( $fh );
	$foerste = preg_replace( '/^\xEF\xBB\xBF/', '', $foerste );
	$tegn    = array( ';' => substr_count( $foerste, ';' ), ',' => substr_count( $foerste, ',' ), "\t" => substr_count( $foerste, "\t" ), '|' => substr_count( $foerste, '|' ) );
	arsort( $tegn );
	$sep    = key( $tegn );
	$hoved  = array_map( function ( $h ) { return strtolower( trim( preg_replace( '/^.*:/', '', $h ) ) ); }, str_getcsv( trim( $foerste ), $sep ) );
	while ( false !== ( $linje = fgetcsv( $fh, 0, $sep ) ) ) {
		if ( count( $linje ) < 2 ) {
			continue;
		}
		$raa = array();
		foreach ( $hoved as $i => $h ) {
			$v         = isset( $linje[ $i ] ) ? trim( $linje[ $i ] ) : '';
			$raa[ $h ] = $encoding ? mb_convert_encoding( $v, 'UTF-8', $encoding ) : $v;
		}
		$haandter( $raa );
	}
	fclose( $fh );
	return true;
}

/* ---------- Prishistorik ---------- */

/**
 * Gemmer ét snapshot pr. dag pr. butik pr. EAN for de EAN'er, der står i SB_FEEDS_HIST_EAN
 * (sættes via POST /sb/v1/historik/ean ud fra affiliate/mapping.json). Én CSV pr. måned:
 * sb-feeds/historik/AAAA-MM.csv med kolonnerne dato,butik,ean,pris,lager.
 */
function sb_feeds_gem_historik( $path, $kun = '' ) {
	$liste = (array) get_option( SB_FEEDS_HIST_EAN, array() );
	if ( ! $liste ) {
		return;
	}
	$ean_set = array();
	foreach ( $liste as $e ) {
		$ean_set[ sb_feeds_ean( $e ) ] = true;
	}
	$hdir = $path . '/historik';
	if ( ! wp_mkdir_p( $hdir ) ) {
		return;
	}
	if ( ! file_exists( $hdir . '/index.php' ) ) {
		file_put_contents( $hdir . '/index.php', "<?php\n// Stille.\n" );
	}
	$dato = gmdate( 'Y-m-d' );
	$fil  = $hdir . '/' . gmdate( 'Y-m' ) . '.csv';
	$har  = array();
	if ( file_exists( $fil ) ) {
		foreach ( (array) file( $fil, FILE_IGNORE_NEW_LINES ) as $l ) {
			$c = str_getcsv( $l );
			if ( isset( $c[2] ) && $c[0] === $dato ) {
				$har[ $c[1] . '|' . $c[2] ] = true;
			}
		}
	}
	$fh = fopen( $fil, 'ab' );
	if ( ! $fh ) {
		return;
	}
	if ( 0 === filesize( $fil ) ) {
		fputcsv( $fh, array( 'dato', 'butik', 'ean', 'pris', 'lager' ) );
	}
	foreach ( sb_feeds_butikker() as $key => $b ) {
		if ( $kun && $kun !== $key ) {
			continue;
		}
		$feed = $path . '/' . $key . '.json';
		if ( ! file_exists( $feed ) ) {
			continue;
		}
		$data = json_decode( (string) file_get_contents( $feed ), true );
		foreach ( (array) ( isset( $data['produkter'] ) ? $data['produkter'] : array() ) as $p ) {
			$e = isset( $p['ean'] ) ? sb_feeds_ean( $p['ean'] ) : '';
			if ( '' === $e || ! isset( $ean_set[ $e ] ) || isset( $har[ $key . '|' . $e ] ) ) {
				continue;
			}
			$har[ $key . '|' . $e ] = true;
			fputcsv( $fh, array( $dato, $key, $e, isset( $p['pris'] ) ? $p['pris'] : '', isset( $p['lager'] ) ? $p['lager'] : '' ) );
		}
	}
	fclose( $fh );
}

/* ---------- Indstillinger ---------- */

add_action( 'admin_menu', function () {
	add_options_page( 'Spilbanditten Feeds', 'Spilbanditten Feeds', 'manage_options', 'sb-feeds', 'sb_feeds_side' );
} );

add_action( 'admin_init', function () {
	register_setting( 'sb_feeds', SB_FEEDS_MAIL, array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_email',
		'default'           => '',
	) );
	register_setting( 'sb_feeds', SB_FEEDS_OPTION, array(
		'type'              => 'array',
		'sanitize_callback' => function ( $v ) {
			$ud = array();
			foreach ( sb_feeds_butikker() as $key => $b ) {
				$ud[ $key ] = isset( $v[ $key ] ) ? esc_url_raw( trim( $v[ $key ] ) ) : '';
			}
			return $ud;
		},
		'default'           => array(),
	) );
} );

add_action( 'admin_post_sb_feeds_hent_nu', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang' );
	}
	check_admin_referer( 'sb_feeds_hent_nu' );
	sb_feeds_hent_alle(); // Planlægger baggrundsjob; siden venter ikke på hentningen.
	wp_safe_redirect( admin_url( 'options-general.php?page=sb-feeds&hentet=1' ) );
	exit;
} );

function sb_feeds_side() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$urls   = (array) get_option( SB_FEEDS_OPTION, array() );
	$status = get_option( SB_FEEDS_STATUS, array() );
	?>
	<div class="wrap">
		<h1>Spilbanditten Feeds</h1>
		<p>Indsæt feed-URL'en fra Partner-ads (og Adtraction for Proshop) for hver butik. Feeds hentes automatisk én gang i døgnet i baggrunden. URL'erne vises kun her.</p>
		<form method="post" action="options.php">
			<?php settings_fields( 'sb_feeds' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( sb_feeds_butikker() as $key => $b ) : ?>
					<tr>
						<th scope="row"><label for="sb-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $b['navn'] ); ?></label></th>
						<td><input type="url" class="large-text" id="sb-<?php echo esc_attr( $key ); ?>"
							name="<?php echo esc_attr( SB_FEEDS_OPTION . '[' . $key . ']' ); ?>"
							value="<?php echo esc_attr( isset( $urls[ $key ] ) ? $urls[ $key ] : '' ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</table>
			<h2>Advarsler</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="sb-mail">Advarsler sendes til</label></th>
					<td><input type="email" class="regular-text" id="sb-mail" name="<?php echo esc_attr( SB_FEEDS_MAIL ); ?>"
						value="<?php echo esc_attr( get_option( SB_FEEDS_MAIL, '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description">Tom = sitets admin-mail.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Gem' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sb_feeds_hent_nu">
			<?php wp_nonce_field( 'sb_feeds_hent_nu' ); ?>
			<?php submit_button( 'Hent feeds nu', 'secondary' ); ?>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sb_feeds_tjek_opdatering">
			<?php wp_nonce_field( 'sb_feeds_tjek_opdatering' ); ?>
			<?php submit_button( 'Søg efter opdatering nu', 'secondary', 'submit', false ); ?>
			<?php $u = get_option( 'sb_feeds_opdatering', array() ); ?>
			<span class="description"><?php echo esc_html( empty( $u['tid'] ) ? '' : 'Seneste tjek ' . $u['tid'] . ': ' . ( empty( $u['fejl'] ) ? 'seneste udgivelse er ' . $u['version'] : $u['fejl'] ) ); ?></span>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sb_feeds_testmail">
			<?php wp_nonce_field( 'sb_feeds_testmail' ); ?>
			<?php submit_button( 'Send testmail', 'secondary', 'submit', false ); ?>
		</form>
		<?php if ( isset( $_GET['mail'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-info"><p><?php echo '1' === $_GET['mail'] ? 'Testmailen er sendt.' : 'Testmailen kunne ikke sendes. Tjek WP Mail SMTP.'; // phpcs:ignore ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['hentet'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-info"><p>Hentningen er startet i baggrunden, én butik ad gangen. Opdatér siden om et par minutter.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $status['butikker'] ) ) : ?>
			<h2>Status</h2>
			<ul>
				<?php foreach ( sb_feeds_butikker() as $key => $b ) : ?>
					<?php
					if ( ! isset( $status['butikker'][ $key ] ) ) {
						continue;
					}
					$st = $status['butikker'][ $key ];
					if ( ! empty( $st['ok'] ) ) {
						$tekst = 'OK, ' . $st['antal'] . ' produkter' . ( isset( $st['frasorteret'] ) ? ' gemt, ' . $st['frasorteret'] . ' frasorteret' : '' );
					} elseif ( isset( $st['ok'] ) && false === $st['ok'] ) {
						$tekst = 'FEJL: ' . ( isset( $st['fejl'] ) ? $st['fejl'] : '' );
					} else {
						$tekst = 'venter';
					}
					if ( ! empty( $st['hentet'] ) ) {
						$tekst .= ' (hentet ' . $st['hentet'] . ( isset( $st['sekunder'] ) ? ', ' . $st['sekunder'] . ' sek.' : '' ) . ')';
					}
					if ( ! empty( $st['planlagt'] ) && ( empty( $st['hentet'] ) || $st['planlagt'] > $st['hentet'] ) ) {
						$tekst .= ', ny hentning planlagt ' . $st['planlagt'];
					}
					?>
					<li><strong><?php echo esc_html( $b['navn'] ); ?>:</strong> <?php echo esc_html( $tekst ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

/* ---------- Diagnose (kun læsning) ---------- */

/** Lister filerne i uploads/rank-math (Rank Maths statiske sitemap-kopier) med størrelse og tidspunkt. */
function sb_feeds_diag_rank_math() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'rank-math';
	$ud  = array(
		'mappe'          => $dir,
		'findes'         => is_dir( $dir ),
		'skrivbar'       => is_dir( $dir ) && wp_is_writable( $dir ),
		'enable_caching' => (bool) apply_filters( 'rank_math/sitemap/enable_caching', true ),
		'filer'          => array(),
	);
	if ( $ud['findes'] ) {
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			$sti = $dir . '/' . $f;
			$ud['filer'][] = array(
				'navn'     => $f,
				'bytes'    => is_file( $sti ) ? filesize( $sti ) : null,
				'aendret'  => gmdate( 'c', filemtime( $sti ) ),
				'skrivbar' => wp_is_writable( $sti ),
			);
		}
	}
	return $ud;
}

/* ---------- Billeder ---------- */

/** Tjekker, at URL'en er https/http og hører til et af partnernes billed-domæner. */
function sb_feeds_url_tilladt( $url ) {
	$p = wp_parse_url( $url );
	if ( empty( $p['host'] ) || empty( $p['scheme'] ) || ! in_array( $p['scheme'], array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( $p['host'] );
	foreach ( sb_feeds_tilladte_domaener() as $d ) {
		if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
			return true;
		}
	}
	return false;
}

/** Billedtype ud fra filens første bytes. */
function sb_feeds_signatur( $bytes ) {
	if ( "\xFF\xD8\xFF" === substr( $bytes, 0, 3 ) ) {
		return 'image/jpeg';
	}
	if ( "\x89PNG\r\n\x1a\n" === substr( $bytes, 0, 8 ) ) {
		return 'image/png';
	}
	if ( 'RIFF' === substr( $bytes, 0, 4 ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
		return 'image/webp';
	}
	if ( in_array( substr( $bytes, 0, 6 ), array( 'GIF87a', 'GIF89a' ), true ) ) {
		return 'image/gif';
	}
	return '';
}

/**
 * Henter et billede. Returnerer array( body, mime ) eller WP_Error.
 * Afviser alt, hvor Content-Type ikke starter med image/, eller hvor filen ikke er JPEG/PNG/WebP/GIF.
 */
function sb_feeds_hent_billede( $url ) {
	if ( ! sb_feeds_url_tilladt( $url ) ) {
		return new WP_Error( 'sb_domaene', 'domænet er ikke på listen over partnere', array( 'status' => 400 ) );
	}
	$res = wp_remote_get( $url, array( 'timeout' => 60, 'redirection' => 3, 'limit_response_size' => 15 * MB_IN_BYTES ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sb_hent', $res->get_error_message(), array( 'status' => 502 ) );
	}
	$kode  = (int) wp_remote_retrieve_response_code( $res );
	$ctype = strtolower( trim( explode( ';', (string) wp_remote_retrieve_header( $res, 'content-type' ) )[0] ) );
	$body  = wp_remote_retrieve_body( $res );
	if ( 200 !== $kode ) {
		return new WP_Error( 'sb_http', 'HTTP ' . $kode, array( 'status' => 502, 'http' => $kode, 'content_type' => $ctype ) );
	}
	if ( 0 !== strpos( $ctype, 'image/' ) ) {
		return new WP_Error( 'sb_type', 'Content-Type ' . ( $ctype ? $ctype : 'mangler' ) . ', ikke et billede', array( 'status' => 422, 'http' => $kode, 'content_type' => $ctype ) );
	}
	$mime = sb_feeds_signatur( $body );
	if ( '' === $mime ) {
		return new WP_Error( 'sb_signatur', 'indholdet er ikke JPEG, PNG, WebP eller GIF', array( 'status' => 422, 'http' => $kode, 'content_type' => $ctype ) );
	}
	return array( 'body' => $body, 'mime' => $mime, 'http' => $kode, 'content_type' => $ctype );
}

function sb_feeds_eksisterende( $url ) {
	$q = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => SB_FEEDS_META,
		'meta_value'     => $url,
	) );
	return $q ? (int) $q[0] : 0;
}

function sb_feeds_svar_medie( $id, $status ) {
	$meta = wp_get_attachment_metadata( $id );
	return array(
		'id'     => $id,
		'status' => $status,
		'url'    => wp_get_attachment_url( $id ),
		'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height' => isset( $meta['height'] ) ? (int) $meta['height'] : null,
	);
}

function sb_feeds_rest_probe( WP_REST_Request $req ) {
	$url = (string) $req->get_param( 'url' );
	$b   = sb_feeds_hent_billede( $url );
	if ( is_wp_error( $b ) ) {
		$d = (array) $b->get_error_data();
		return array( 'ok' => false, 'fejl' => $b->get_error_message(), 'http' => isset( $d['http'] ) ? $d['http'] : null, 'content_type' => isset( $d['content_type'] ) ? $d['content_type'] : null );
	}
	return array( 'ok' => true, 'http' => $b['http'], 'content_type' => $b['content_type'], 'mime' => $b['mime'], 'bytes' => strlen( $b['body'] ), 'findes_som' => sb_feeds_eksisterende( $url ) ?: null );
}

function sb_feeds_rest_sideload( WP_REST_Request $req ) {
	$url     = (string) $req->get_param( 'url' );
	$post_id = (int) $req->get_param( 'post_id' );
	$alt     = sanitize_text_field( (string) $req->get_param( 'alt' ) );
	$filnavn = sanitize_file_name( (string) $req->get_param( 'filnavn' ) );
	$featured = (bool) $req->get_param( 'set_featured' );

	if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'sb_adgang', 'ingen adgang til indlægget', array( 'status' => 403 ) );
	}

	$id = sb_feeds_eksisterende( $url );
	$status = 'findes';
	if ( ! $id ) {
		$b = sb_feeds_hent_billede( $url );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		$stam = $filnavn ? pathinfo( $filnavn, PATHINFO_FILENAME ) : 'produktbillede';
		$tmp  = wp_tempnam( $stam );
		file_put_contents( $tmp, $b['body'] );
		$id = media_handle_sideload( array( 'name' => $stam . '.' . $ext[ $b['mime'] ], 'tmp_name' => $tmp ), $post_id );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			return new WP_Error( 'sb_sideload', $id->get_error_message(), array( 'status' => 500 ) );
		}
		update_post_meta( $id, SB_FEEDS_META, esc_url_raw( $url ) );
		$status = 'uploadet';
	}
	if ( $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	if ( $featured && $post_id ) {
		set_post_thumbnail( $post_id, $id );
	}
	return sb_feeds_svar_medie( $id, $status );
}

add_action( 'rest_api_init', function () {
	$admin  = function () { return current_user_can( 'manage_options' ); };
	$upload = function () { return current_user_can( 'upload_files' ); };
	$url    = array( 'required' => true, 'type' => 'string', 'format' => 'uri' );

	register_rest_route( 'sb/v1', '/feeds/refresh', array(
		'methods'             => 'POST',
		'callback'            => function ( WP_REST_Request $req ) { return sb_feeds_hent_alle( sanitize_key( (string) $req->get_param( 'shop' ) ) ); },
		'permission_callback' => $admin,
	) );
	register_rest_route( 'sb/v1', '/diag/feed', array(
		'methods'             => 'GET',
		'callback'            => function ( WP_REST_Request $req ) {
			$shop = sanitize_key( (string) $req->get_param( 'shop' ) );
			$alle = sb_feeds_butikker();
			if ( ! isset( $alle[ $shop ] ) ) {
				return new WP_Error( 'sb_shop', 'ukendt butik', array( 'status' => 404 ) );
			}
			$diag = (array) get_option( SB_FEEDS_DIAG, array() );
			return array(
				'shop'        => $shop,
				'net'         => $alle[ $shop ]['net'],
				'feltmapping' => sb_feeds_feltmapping( $alle[ $shop ]['net'] ),
				'diag'        => isset( $diag[ $shop ] ) ? $diag[ $shop ] : null,
			);
		},
		'permission_callback' => $admin,
		'args'                => array( 'shop' => array( 'required' => true, 'type' => 'string' ) ),
	) );
	register_rest_route( 'sb/v1', '/historik/ean', array(
		array(
			'methods'             => 'GET',
			'callback'            => function () { return array( 'ean' => array_values( (array) get_option( SB_FEEDS_HIST_EAN, array() ) ) ); },
			'permission_callback' => $admin,
		),
		array(
			'methods'             => 'POST',
			'callback'            => function ( WP_REST_Request $req ) {
				$liste = array();
				foreach ( (array) $req->get_param( 'ean' ) as $e ) {
					$e = sb_feeds_ean( $e );
					if ( strlen( $e ) >= 8 ) {
						$liste[ $e ] = $e;
					}
				}
				update_option( SB_FEEDS_HIST_EAN, array_values( $liste ), false );
				return array( 'antal' => count( $liste ) );
			},
			'permission_callback' => $admin,
			'args'                => array( 'ean' => array( 'required' => true, 'type' => 'array', 'items' => array( 'type' => 'string' ) ) ),
		),
	) );
	register_rest_route( 'sb/v1', '/diag/rank-math', array(
		'methods'             => 'GET',
		'callback'            => 'sb_feeds_diag_rank_math',
		'permission_callback' => $admin,
	) );
	register_rest_route( 'sb/v1', '/priser', array(
		'methods'             => 'GET',
		'callback'            => function ( WP_REST_Request $req ) {
			$rk = sb_priser_raekker( $req['ean'] );
			return array( 'raekker' => array_map( function ( $r ) { unset( $r['url'] ); return $r; }, $rk ), 'html' => sb_priser_html( $req['ean'] ), 'annonce' => sb_annonce_html( $req['ean'] ) );
		},
		'permission_callback' => function () { return current_user_can( 'edit_posts' ); },
		'args'                => array( 'ean' => array( 'type' => 'string', 'required' => true ) ),
	) );
	register_rest_route( 'sb/v1', '/feeds/status', array(
		'methods'             => 'GET',
		'callback'            => function () {
			$d = sb_feeds_dir();
			$u = get_option( 'sb_feeds_opdatering', array() );
			return array(
				'status'     => get_option( SB_FEEDS_STATUS, array() ),
				'mappe'      => $d['url'],
				'naeste'     => wp_next_scheduled( SB_FEEDS_CRON ),
				'naeste_tjek' => wp_next_scheduled( SB_FEEDS_TJEK ),
				'version'    => sb_feeds_version(),
				'opdatering' => array( 'github_version' => $u['version'] ?? '', 'tjekket' => $u['tid'] ?? '', 'fejl' => $u['fejl'] ?? '' ),
			);
		},
		'permission_callback' => $admin,
	) );
	register_rest_route( 'sb/v1', '/probe', array(
		'methods'             => 'POST',
		'callback'            => 'sb_feeds_rest_probe',
		'permission_callback' => $upload,
		'args'                => array( 'url' => $url ),
	) );
	register_rest_route( 'sb/v1', '/sideload', array(
		'methods'             => 'POST',
		'callback'            => 'sb_feeds_rest_sideload',
		'permission_callback' => $upload,
		'args'                => array(
			'url'          => $url,
			'post_id'      => array( 'type' => 'integer', 'default' => 0 ),
			'alt'          => array( 'type' => 'string', 'default' => '' ),
			'filnavn'      => array( 'type' => 'string', 'default' => '' ),
			'set_featured' => array( 'type' => 'boolean', 'default' => false ),
		),
	) );
} );

/* ---------- Prissammenligning: [sb_priser ean="..."] ---------- */

/** "229.95", "1.299,00", "213 DKK" og "DK 39 DKK" => tal (float) eller null. */
function sb_priser_tal( $v ) {
	if ( ! preg_match_all( '/\d[\d.,]*/', (string) $v, $m ) ) {
		return null;
	}
	$s = end( $m[0] );
	if ( preg_match( '/,\d{1,2}$/', $s ) ) {
		$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
	} else {
		$s = str_replace( ',', '', $s );
	}
	return is_numeric( $s ) ? (float) $s : null;
}

function sb_priser_kr( $v ) {
	return number_format( $v, floor( $v ) == $v ? 0 : 2, ',', '.' ) . ' kr.';
}

function sb_priser_udsolgt( $lager ) {
	$l = strtolower( trim( (string) $lager ) );
	return in_array( $l, array( 'out of stock', 'out_of_stock', 'outofstock', 'no', 'nej', 'false', '0', 'sold out', 'udsolgt' ), true );
}

/** Rækker (én pr. butik) for EAN'erne, billigste først. Caches, til et feed bliver hentet igen. */
function sb_priser_raekker( $eans ) {
	$eans = array_filter( array_map( 'sb_feeds_ean', explode( ',', (string) $eans ) ) );
	if ( ! $eans ) {
		return array();
	}
	$dir   = sb_feeds_dir();
	$noegle = implode( ',', $eans );
	foreach ( array_keys( sb_feeds_butikker() ) as $key ) {
		$f       = $dir['path'] . '/' . $key . '.json';
		$noegle .= '|' . ( file_exists( $f ) ? filemtime( $f ) : 0 );
	}
	$cache = 'sb_priser_' . md5( $noegle );
	$rk    = get_transient( $cache );
	if ( is_array( $rk ) ) {
		return sb_priser_friske( $rk );
	}
	$rk = array();
	foreach ( sb_feeds_butikker() as $key => $b ) {
		$f = $dir['path'] . '/' . $key . '.json';
		$data = file_exists( $f ) ? json_decode( file_get_contents( $f ), true ) : null;
		if ( empty( $data['produkter'] ) ) {
			continue;
		}
		$bedst = null;
		foreach ( $data['produkter'] as $p ) {
			if ( ! in_array( sb_feeds_ean( $p['ean'] ?? '' ), $eans, true ) ) {
				continue;
			}
			$url   = $p['url'] ?? ( $p['vareurl'] ?? '' );
			$pris  = sb_priser_tal( $p['pris'] ?? ( $p['nypris'] ?? '' ) );
			$lager = $p['lager'] ?? ( $p['lagerantal'] ?? '' );
			if ( ! $url || ! $pris || $pris < SB_PRISER_MIN || sb_priser_udsolgt( $lager ) || ! wp_http_validate_url( $url ) ) {
				continue;
			}
			if ( ! $bedst || $pris < $bedst['pris'] ) {
				$fragt = ( isset( $p['fragt'] ) || isset( $p['fragtomk'] ) ) ? sb_priser_tal( $p['fragt'] ?? $p['fragtomk'] ) : null;
				$bedst = array(
					'butik'   => $b['vis'] ?? $b['navn'],
					'pris'    => $pris,
					'fragt'   => $fragt,
					'url'     => $url,
					'produkt' => (string) ( $p['produktnavn'] ?? '' ),
					'hentet'  => (string) ( $data['hentet'] ?? '' ),
				);
			}
		}
		if ( $bedst ) {
			$rk[] = $bedst;
		}
	}
	$rk = sb_priser_uden_prisfejl( $rk );
	usort( $rk, function ( $a, $b ) { return sb_priser_total( $a ) <=> sb_priser_total( $b ); } );
	set_transient( $cache, $rk, DAY_IN_SECONDS );
	return sb_priser_friske( $rk );
}

/** Fjerner prisfejl: rækker over 10 gange medianen for samme EAN (priser under 20 kr. er sorteret fra ved udvælgelsen). */
function sb_priser_uden_prisfejl( $rk ) {
	$priser = array_column( $rk, 'pris' );
	if ( count( $priser ) < 2 ) {
		return $rk;
	}
	sort( $priser );
	$n      = count( $priser );
	$median = $n % 2 ? $priser[ intdiv( $n, 2 ) ] : ( $priser[ $n / 2 - 1 ] + $priser[ $n / 2 ] ) / 2;
	return array_values( array_filter( $rk, function ( $r ) use ( $median ) {
		return $r['pris'] <= 10 * $median;
	} ) );
}

/** Pris inkl. fragt (fragt ukendt = kun varepris). */
function sb_priser_total( $r ) {
	return $r['pris'] + ( null === $r['fragt'] ? 0 : $r['fragt'] );
}

/** Skjuler rækker, hvis butikkens feed er hentet for over 48 timer siden (eller tidspunktet mangler). */
function sb_priser_friske( $rk ) {
	return array_values( array_filter( $rk, function ( $r ) {
		$t = strtotime( $r['hentet'] );
		return $t && time() - $t <= 48 * HOUR_IN_SECONDS;
	} ) );
}

/** "22. okt. 2026" */
function sb_priser_kort_dato( $t ) {
	$m = array( 'jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.' );
	return wp_date( 'j', $t ) . '. ' . $m[ (int) wp_date( 'n', $t ) - 1 ] . ' ' . wp_date( 'Y', $t );
}

/** Spillets navn til klikmålingen: kortkodens spil="", ellers indlæggets titel før " – ". */
function sb_priser_spilnavn( $spil ) {
	if ( '' !== $spil ) {
		return $spil;
	}
	$titel = function_exists( 'get_the_title' ) ? wp_strip_all_tags( html_entity_decode( (string) get_the_title() ) ) : '';
	$titel = preg_split( '/\s[–-]\s/u', $titel )[0];
	return trim( preg_replace( '/\s+brætspil$/iu', '', $titel ) );
}

/** Ét link med data til klikmålingen (GA4-event pris_klik). */
function sb_priser_link( $r, $spil, $placering, $tekst ) {
	return '<a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="sponsored noopener" data-sb-butik="' . esc_attr( $r['butik'] )
		. '" data-sb-spil="' . esc_attr( $spil ) . '" data-sb-placering="' . esc_attr( $placering ) . '"' . ( 'koeb-linje-auto' === $placering
		? ' style="color:#d4621a;font-weight:600;text-decoration:none"' : '' ) . '>' . $tekst . '</a>';
}

function sb_priser_html( $eans, $spil = '' ) {
	$rk = sb_priser_raekker( $eans );
	if ( ! $rk ) {
		return '';
	}
	$spil = sb_priser_spilnavn( $spil );
	if ( 1 === count( $rk ) ) {
		// Kun én butik med gyldig pris: den almindelige køb-linje (skabelonen i CLAUDE.md) med automatisk pris og dato.
		$r = $rk[0];
		return '<p class="sb-kob" style="font-size:14px;line-height:1.5;color:#666;margin:-0.4em 0 1.6em;padding:0">'
			. esc_html( sb_priser_kr( $r['pris'] ) ) . ' <span style="font-size:12px;color:#999">(' . esc_html( sb_priser_kort_dato( strtotime( $r['hentet'] ) ) ) . ')</span> · '
			. sb_priser_link( $r, $spil, 'koeb-linje-auto', 'Se pris hos ' . esc_html( $r['butik'] ) . ' &rarr;' )
			. ' · <span style="font-size:12px;color:#999">Annoncelink</span></p>';
	}
	$dato = '';
	foreach ( $rk as $r ) {
		$t = strtotime( $r['hentet'] );
		if ( $t && ( ! $dato || $t < $dato ) ) {
			$dato = $t;
		}
	}
	$css = '.sb-priser{margin:-0.4em 0 1.6em;font-size:14px;line-height:1.5;color:#444}'
		. '.sb-priser summary{cursor:pointer;color:#d4621a;font-weight:600;list-style:none}'
		. '.sb-priser summary::-webkit-details-marker{display:none}'
		. '.sb-priser summary::after{content:" \25BE";font-size:12px}.sb-priser[open] summary::after{content:" \25B4"}'
		. '.sb-priser table{width:100%;border-collapse:collapse;margin:.6em 0 0;border:0}'
		. '.sb-priser th,.sb-priser td{padding:6px 8px 6px 0;border:0;border-bottom:1px solid #eee;text-align:left;vertical-align:middle;background:none}'
		. '.sb-priser th{font-size:12px;font-weight:600;color:#999;text-transform:uppercase;letter-spacing:.04em}'
		. '.sb-priser td.pris{font-weight:700;color:#1a2a1a;white-space:nowrap}.sb-priser td.fragt{color:#666;white-space:nowrap}'
		. '.sb-priser td.link{text-align:right;padding-right:0;line-height:1.3}'
		. '.sb-priser a{color:#d4621a;font-weight:600;text-decoration:none;white-space:nowrap}'
		. '.sb-priser .al{display:block;font-size:11px;color:#999}'
		. '.sb-priser .note{font-size:12px;color:#999;margin:.5em 0 0}';
	$h  = '<details class="sb-priser"><style>' . $css . '</style><summary>Sammenlign priser hos ' . count( $rk ) . ' ' . ( 1 === count( $rk ) ? 'butik' : 'butikker' ) . '</summary>'
		. '<table><thead><tr><th>Butik</th><th>Pris</th><th>Fragt fra</th><th></th></tr></thead><tbody>';
	foreach ( $rk as $r ) {
		$fragt = null === $r['fragt'] ? 'Se butik' : ( $r['fragt'] > 0 ? sb_priser_kr( $r['fragt'] ) : 'Fri fragt' );
		$h    .= '<tr><td>' . esc_html( $r['butik'] ) . '</td><td class="pris">' . esc_html( sb_priser_kr( $r['pris'] ) ) . '</td>'
			. '<td class="fragt">' . esc_html( $fragt ) . '</td><td class="link">' . sb_priser_link( $r, $spil, 'tabel', 'Se pris &rarr;' )
			. '<span class="al">Annoncelink</span></td></tr>';
	}
	$h .= '</tbody></table><p class="note">Sorteret efter pris inkl. fragt. Priser fra vores partnerbutikker, inkl. moms.'
		. ( $dato ? ' Opdateret ' . esc_html( date_i18n( 'j. F Y', $dato ) ) . '.' : '' ) . '</p></details>';
	return $h;
}

/** Butiksnavne som "A, B og C". */
function sb_priser_liste( $navne ) {
	$sidste = array_pop( $navne );
	return $navne ? implode( ', ', $navne ) . ' og ' . $sidste : (string) $sidste;
}

/** Annonceoplysningen øverst på siden, med de butikker prissammenligningen viser (samme stil som mønster 1054). */
function sb_annonce_html( $eans ) {
	$navne = array_column( sb_priser_raekker( $eans ), 'butik' );
	$hvem  = $navne ? 'reklame gennem affiliate links for ' . esc_html( sb_priser_liste( $navne ) ) : 'annoncelinks til danske webshops';
	return '<div class="sb-annonce" style="background:#f3f5f1;border:1px solid #dfe4dc;border-radius:10px;padding:12px 16px;margin:0 0 1.5rem;font-size:13.5px;line-height:1.55;color:#333">'
		. '<strong>Annoncelinks:</strong> Denne side indeholder ' . $hvem . '. Køber du via et link, får vi en lille provision. Det koster dig ikke noget ekstra. '
		. 'Vi vælger spillene først og finder butikkerne bagefter. <a href="/annoncelinks/">Sådan tjener vi penge</a>.</div>';
}

add_shortcode( 'sb_annonce', function ( $atts ) {
	$a = shortcode_atts( array( 'ean' => '' ), $atts, 'sb_annonce' );
	return sb_annonce_html( $a['ean'] );
} );

add_shortcode( 'sb_priser', function ( $atts ) {
	$a = shortcode_atts( array( 'ean' => '', 'spil' => '' ), $atts, 'sb_priser' );
	return sb_priser_html( $a['ean'], $a['spil'] );
} );

/* ---------- Planlægning (også efter en opdatering, hvor aktiveringen ikke kører igen) ---------- */

add_action( 'init', function () {
	if ( ! wp_next_scheduled( SB_FEEDS_CRON ) ) {
		wp_schedule_event( time() + 300, 'daily', SB_FEEDS_CRON );
	}
	if ( ! wp_next_scheduled( SB_FEEDS_TJEK ) ) {
		wp_schedule_event( time() + 600, 'hourly', SB_FEEDS_TJEK );
	}
} );

/* ---------- Advarsler på mail ---------- */

function sb_feeds_modtager() {
	$m = get_option( SB_FEEDS_MAIL, '' );
	return is_email( $m ) ? $m : get_option( 'admin_email' );
}

/** Sender en advarsel, højst én gang i døgnet pr. emne. Returnerer true, hvis mailen blev sendt. */
function sb_feeds_advar( $emne, $tekst ) {
	$noegle = 'sb_feeds_advaret_' . md5( $emne );
	if ( get_transient( $noegle ) ) {
		return false;
	}
	$ok = wp_mail( sb_feeds_modtager(), '[Spilbanditten Feeds] ' . $emne, $tekst . "\n\n" . admin_url( 'options-general.php?page=sb-feeds' ) );
	if ( $ok ) {
		set_transient( $noegle, 1, DAY_IN_SECONDS );
	}
	return $ok;
}

add_action( SB_FEEDS_TJEK, 'sb_feeds_tjek_alder' );

/** Hver time: advarer, hvis en butiks seneste vellykkede hentning er over 36 timer gammel. */
function sb_feeds_tjek_alder() {
	$urls   = (array) get_option( SB_FEEDS_OPTION, array() );
	$status = (array) get_option( SB_FEEDS_STATUS, array() );
	$gamle  = array();
	foreach ( sb_feeds_butikker() as $key => $b ) {
		if ( empty( $urls[ $key ] ) ) {
			continue;
		}
		$f = sb_feeds_dir()['path'] . '/' . $key . '.json';
		$t = file_exists( $f ) ? filemtime( $f ) : 0;
		if ( time() - $t > 36 * HOUR_IN_SECONDS ) {
			$gamle[] = $b['navn'] . ': ' . ( $t ? 'seneste vellykkede hentning ' . wp_date( 'j. F Y H:i', $t ) : 'aldrig hentet' )
				. ( isset( $status['butikker'][ $key ]['fejl'] ) ? ' (seneste fejl: ' . sb_feeds_skjul( $status['butikker'][ $key ]['fejl'], $urls[ $key ] ) . ')' : '' );
		}
	}
	if ( $gamle ) {
		sb_feeds_advar( 'Feeds er over 36 timer gamle', "Disse feeds er ikke hentet i over 36 timer, så deres priser vises ikke efter 48 timer:\n\n" . implode( "\n", $gamle )
			. "\n\nTjek, at GitHub Action'en wp-cron i repoet spilbanditten kører (hver 3. time)." );
	}
	return $gamle;
}

/** Læser prishistorikken (denne og sidste måned) for én butik: [dato][ean] => pris. */
function sb_feeds_historik_for( $path, $key ) {
	$ud = array();
	foreach ( array( gmdate( 'Y-m', strtotime( 'first day of last month' ) ), gmdate( 'Y-m' ) ) as $mdr ) {
		$fil = $path . '/historik/' . $mdr . '.csv';
		if ( ! file_exists( $fil ) ) {
			continue;
		}
		foreach ( (array) file( $fil, FILE_IGNORE_NEW_LINES ) as $l ) {
			$c = str_getcsv( $l );
			if ( isset( $c[3] ) && $c[1] === $key ) {
				$ud[ $c[0] ][ $c[2] ] = sb_priser_tal( $c[3] );
			}
		}
	}
	return $ud;
}

/** Efter hentning: mail, hvis en pris er faldet over 50 % i forhold til butikkens egen pris dagen før. */
function sb_feeds_tjek_prisfald( $path, $key ) {
	$h   = sb_feeds_historik_for( $path, $key );
	$i_dag = gmdate( 'Y-m-d' );
	$i_gaar = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	if ( empty( $h[ $i_dag ] ) || empty( $h[ $i_gaar ] ) ) {
		return array();
	}
	$fald = array();
	foreach ( $h[ $i_dag ] as $ean => $ny ) {
		$gl = isset( $h[ $i_gaar ][ $ean ] ) ? $h[ $i_gaar ][ $ean ] : null;
		if ( $gl && $ny && $ny < 0.5 * $gl ) {
			$fald[] = 'EAN ' . $ean . ': ' . sb_priser_kr( $gl ) . ' i går, ' . sb_priser_kr( $ny ) . ' i dag';
		}
	}
	if ( $fald ) {
		$navn = sb_feeds_butikker()[ $key ]['navn'];
		sb_feeds_advar( 'Stort prisfald hos ' . $navn . ' ' . $i_dag, "Priser, der er faldet over 50 % siden i går. De vises stadig i pristabellerne, så tjek, om det er en fejl i feedet:\n\n" . implode( "\n", $fald ) );
	}
	return $fald;
}

add_action( 'admin_post_sb_feeds_testmail', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang' );
	}
	check_admin_referer( 'sb_feeds_testmail' );
	$ok = wp_mail( sb_feeds_modtager(), '[Spilbanditten Feeds] Testmail', 'Advarsler fra Spilbanditten Feeds kommer frem.' );
	wp_safe_redirect( admin_url( 'options-general.php?page=sb-feeds&mail=' . ( $ok ? '1' : '0' ) ) );
	exit;
} );

/* ---------- Cache: purge sider med pristabel efter hentning ---------- */

/** Purger sider med [sb_priser] eller [sb_annonce] i LiteSpeed Cache (gør intet, hvis LiteSpeed ikke cacher). */
function sb_priser_purge() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND ( post_content LIKE '%[sb\\_priser%' OR post_content LIKE '%[sb\\_annonce%' )" );
	foreach ( (array) $ids as $id ) {
		clean_post_cache( (int) $id );
		do_action( 'litespeed_purge_post', (int) $id );
	}
	return array_map( 'intval', (array) $ids );
}

/* ---------- Klikmåling: GA4-event pris_klik ---------- */

/**
 * Sender pris_klik (butik, spil, placering) til sitets eksisterende GA4 (gtag fra Site Kit), når nogen klikker et
 * annoncelink: pristabellen (placering "tabel"), den automatiske køb-linje ("koeb-linje-auto") og de gamle
 * køb-linjer ("koeb-linje", butik og spil læses fra teksten og nærmeste overskrift). Intet sendes uden gtag.
 */
add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_singular() ) {
		return;
	}
	?>
<script>
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[rel~="sponsored"]');if(!a||typeof window.gtag!=='function')return;
var d=a.dataset,b=d.sbButik,s=d.sbSpil,p=d.sbPlacering||'koeb-linje';
if(!b){var m=a.textContent.match(/hos\s+(.+?)\s*→/);b=m?m[1]:a.hostname;}
if(!s){var h=null;document.querySelectorAll('h1,h2,h3,h4').forEach(function(x){if(x.compareDocumentPosition(a)&Node.DOCUMENT_POSITION_FOLLOWING)h=x;});s=h?h.textContent.split(/\s[–-]\s/)[0].replace(/^\d+\.\s*/,'').trim():'';}
window.gtag('event','pris_klik',{butik:b,spil:s,placering:p});},true);
</script>
	<?php
}, 99 );

/* ---------- Opdatering fra GitHub-udgivelser ---------- */

/*
 * Det offentlige repo BorisBuranium/spilbanditten-feeds laver en udgivelse, når der pushes et tag som v1.6.1
 * (GitHub Action i repoet). Udgivelsen har to filer: spilbanditten-feeds.zip og spilbanditten-feeds.json
 * ({"version": "1.6.1"}). Plugin'et henter json'en fra "seneste udgivelse" (kladder og pre-releases tæller ikke)
 * via github.com's download-links, ikke API'et, så der ikke rammes rate limits. Push uden tag gør intet.
 */

function sb_feeds_basename() {
	return plugin_basename( __FILE__ );
}

function sb_feeds_release_url( $fil, $version = '' ) {
	return 'https://github.com/' . SB_FEEDS_REPO . '/releases/' . ( $version ? 'download/v' . $version : 'latest/download' ) . '/' . $fil;
}

/** Versionen i seneste udgivelse, eller null. Caches 6 timer. */
function sb_feeds_github_version( $frisk = false ) {
	$u = get_option( 'sb_feeds_opdatering', array() );
	if ( ! $frisk && ! empty( $u['tjekket'] ) && time() - $u['tjekket'] < 6 * HOUR_IN_SECONDS ) {
		return empty( $u['version'] ) ? null : $u['version'];
	}
	$u    = array( 'tjekket' => time(), 'tid' => wp_date( 'j. F Y H:i' ), 'version' => '', 'fejl' => '' );
	$svar = wp_remote_get( sb_feeds_release_url( 'spilbanditten-feeds.json' ), array( 'timeout' => 15 ) );
	if ( is_wp_error( $svar ) ) {
		$u['fejl'] = 'GitHub svarede ikke: ' . $svar->get_error_message();
	} elseif ( 200 !== (int) wp_remote_retrieve_response_code( $svar ) ) {
		$kode      = (int) wp_remote_retrieve_response_code( $svar );
		$u['fejl'] = 404 === $kode ? 'Ingen udgivelse på GitHub endnu' : 'GitHub svarede ' . $kode;
	} else {
		$j = json_decode( wp_remote_retrieve_body( $svar ), true );
		if ( isset( $j['version'] ) && preg_match( '/^\d+(\.\d+)*$/', $j['version'] ) ) {
			$u['version'] = $j['version'];
		} else {
			$u['fejl'] = 'Udgivelsen har ingen gyldig spilbanditten-feeds.json';
		}
	}
	update_option( 'sb_feeds_opdatering', $u, false );
	return $u['version'] ? $u['version'] : null;
}

function sb_feeds_version() {
	return get_file_data( __FILE__, array( 'v' => 'Version' ) )['v'];
}

/** Melder en ny version til WordPress' opdateringssystem. Zip'en hentes fra udgivelsen med netop den version. */
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$ny   = sb_feeds_github_version();
	$item = (object) array(
		'id'          => SB_FEEDS_REPO,
		'slug'        => 'spilbanditten-feeds',
		'plugin'      => sb_feeds_basename(),
		'new_version' => $ny ? $ny : sb_feeds_version(),
		'url'         => 'https://github.com/' . SB_FEEDS_REPO,
		'package'     => $ny ? sb_feeds_release_url( 'spilbanditten-feeds.zip', $ny ) : '',
	);
	if ( $ny && version_compare( $ny, sb_feeds_version(), '>' ) ) {
		$transient->response[ sb_feeds_basename() ] = $item;
	} else {
		unset( $transient->response[ sb_feeds_basename() ] );
		$item->package = '';
		$transient->no_update[ sb_feeds_basename() ] = $item;
	}
	return $transient;
} );

/** Automatisk opdatering for netop dette plugin. */
add_filter( 'auto_update_plugin', function ( $opdater, $item ) {
	return ( isset( $item->plugin ) && sb_feeds_basename() === $item->plugin ) ? true : $opdater;
}, 10, 2 );

add_action( 'admin_post_sb_feeds_tjek_opdatering', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Ingen adgang' );
	}
	check_admin_referer( 'sb_feeds_tjek_opdatering' );
	sb_feeds_github_version( true );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'options-general.php?page=sb-feeds' ) );
	exit;
} );
