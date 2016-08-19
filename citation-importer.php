<?php
/*
Plugin Name: Citation Importer
Plugin URI: http://stephanieleary.com/
Description: Import a citation or bibliography as posts.
Author: sillybean
Author URI: http://stephanieleary.com/
Version: 0.6
Text Domain: import-citation
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

// Importer Class
if ( class_exists( 'WP_Importer' ) ) {
class Citation_Importer extends WP_Importer {
	
	function __construct() { }

	function header() {
		echo '<div class="wrap">';
		echo '<h2>'.__( 'Citation Importer', 'import-citation' ).'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function dispatch() {
		
		if ( empty ( $_GET['step'] ) )
			$step = 0;
		else
			$step = ( int ) $_GET['step'];

		$this->header();

		switch ( $step ) {
			case 0 :
				$this->greet();
				break;
			case 1 :
				check_admin_referer( 'citation-import' );
				$transient = $this->lookup();
				$this->display( $transient );
				break;
			case 2 :
				check_admin_referer( 'citation-select' );
				$this->import( $_POST['checked'] );
				break;
		}

		$this->footer();
	}
	
	function greet() { 
		
		$post_types = get_post_types( array( 'public' => true ), 'objects', 'and' );

		_e( '<p>Paste your citations or CrossRef DOIs below. To use another agency\'s DOI, append /agency-name. You may also use <a href="http://search.crossref.org/help/search">any of the other searches accepted by crossref.org</a>, but this importer will return only the first match.</p>', 'import-citation' );
		_e( '<p>You may enter multiple searches as bulleted or numbered lists, or one per line.</p>', 'import-citation' ); 
		?>
		<form method="post" action="admin.php?import=citation&amp;step=1">
			
		<p> <label for="post-type"><?php _e( 'Import citations as...', 'import-citation' ); ?></label>
			<select name="post-type">
				<option value="0"><?php _e( '-- Select --', 'import-citation' ) ?></option>
				<?php foreach ( $post_types as $post_type ) : 
					if ( 'attachment' !== $post_type->name ) : ?>
					<option value="<?php echo esc_attr( $post_type->name ); ?>">
						<?php echo esc_html( $post_type->label ); ?>
					</option>
				<?php endif; endforeach; ?>
			</select>
		</p>
		
		<?php wp_editor( '', 'citation-text', array( 'media_buttons' => false ) ); ?>
		
		<input type="hidden" name="search_id" value="<?php echo time(); ?>" />
		
		<p class="submit"><?php submit_button( __( 'Import Publications', 'import-citation' ), 'primary' ); ?></p>
		<?php wp_nonce_field( 'citation-import' ); ?>
		</form>
	<?php
	}
	
	function lookup() {
		$transient_key = sanitize_key( $_POST['search_id'] );
		
		$citation = preg_replace("/&nbsp;/", "", $_POST['citation-text'] );
		$citation = trim( force_balance_tags( wp_kses_post( $citation ) ) );
		
		$items = $queries = array();
		$is_xml = false;
		libxml_clear_errors();
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $citation );
		if ( $xml ) {
			$rows = $xml->xpath('//li');
			$is_xml = true;
		}
		else {
			$rows = explode( "\n", $citation );
			$rows = array_filter( $rows, array( $this, 'filter_empty_text' ) );
		}
		
		$total = count( $rows );
		$current = 1;
		if ( $total > 20 )
			$batch = __( 'We are processing 20 at a time. Thanks for your patience.', 'import-citation' );
		else
			$batch = '';
		
		if ( $rows ) {
			
			echo '<p>'.sprintf( _n( 'Looking up %d citation...', 'Looking up %d citations...', $total, 'import-citation' ), $total ).$batch.'</p>';
			
			echo '<div class="progress"">
			  <div class="progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"> 
				<span id="valuenow">0%</span> 
			  </div></div>';
			flush();
		}
		
		foreach ( $rows as $query ) {
			if ( $is_xml )
				$query = (string) $query;
			
			$response = $this->retrieve_items( trim( $query ) );
			$doi = $response['DOI'];
			$items[$doi] = $response;
			$queries[$doi] = $query;
			
			$percentage = round( $current / $total * 100 );
			$this->display_progress( $percentage );
			$current++;
			// pause after every 20 records
			if ( $total > 20 && 0 == $total % $current )
				sleep(5);
		}
		
		// store the results, the original queries, and the post type so we can display intermediate screen
		if ( is_array( $items ) ) {
			set_transient( 'citation_search_' . $transient_key, json_encode( $items ), 24 * HOUR_IN_SECONDS );
			set_transient( 'citation_query_' . $transient_key, $queries, 24 * HOUR_IN_SECONDS );
			set_transient( 'citation_type_' . $transient_key, $_POST['post-type'], 24 * HOUR_IN_SECONDS );
		}
		
		return $transient_key;
	}
	
	function display_progress( $percentage ) { ?>
		<script>
			var percentage = <?php echo $percentage; ?>;
			jQuery( ".progress-bar #valuenow" ).html( percentage + '%' );
			jQuery( ".progress-bar" ).css( 'width', percentage + '%' );
			jQuery( ".progress-bar" ).attr( 'aria-valuenow', percentage );
		</script>
		<?php
		flush();
	}
	
	function filter_empty_text( $val ) {
		$val = str_replace( array( "\n", "\r", "\t" ), '', $val );
	    return !empty( $val );
	}
	
	function retrieve_items( $query = '' ) {
		if ( empty( $query ) ) {
			return;
		}
		
		// Query for direct existence of DOI.
		$workUrl = 'http://api.crossref.org/works/' . urlencode( $query );

		// Or search for it.
		// rows=1 returns only the first result. We're feeling lucky.
		$searchUrl = 'http://api.crossref.org/works?rows=1&query=' . urlencode( $query );
				
		$headers = array(
			'cache-control' => 'no-cache',
			'vary'  => 'Accept-Encoding',
			'user-agent'  => 'WordPressCitationImporter/0.6;' . get_home_url(),
		);
		
		// Try the direct DOI first.
		$workResponse = wp_remote_get(
				 $workUrl,
				 array( 'ssl_verify' => true, 'headers' => $headers )
		);

		if ( wp_remote_retrieve_response_code( $workResponse ) == 200 && ! is_wp_error( $workResponse ) ) {
			$result = json_decode( wp_remote_retrieve_body( $workResponse ), true );
			return $result['message'];
		}
		
		// Try as a query next.
		$searchResponse = wp_remote_get(
				 $searchUrl,
				 array( 'ssl_verify' => true, 'headers' => $headers )
		);

		if ( ! is_wp_error( $searchResponse ) ) {
			$result = json_decode( wp_remote_retrieve_body( $searchResponse ), true );
			if ( !empty( $result['message']['items'] ) )
				return $result['message']['items'][0];
			else
				return;
		}

		// Failing that, return error code.
		return current_user_can( 'manage_options' ) ? $searchResponse->get_error_message() : '';
	}
	
	function display( $transient ) {
		$items = json_decode( get_transient( 'citation_search_' . $transient ), true );
		if ( empty( $items ) ) {
			echo '<h3>'.__( 'No citations found.', 'import-citation' ).'</h3>';
			return;
		}
		$queries = get_transient( 'citation_query_' . $transient );
		?>
		<h3><?php _e( 'Citation Search Results', 'import-citation' ); ?></h3>
		<form method="post" action="admin.php?import=citation&amp;step=2">
		<input type="hidden" name="search_id" value="<?php echo esc_attr( $transient ); ?>" />
 		<table class="wp-list-table widefat striped citations">
			<thead>
			<tr>
				<td class="manage-column column-cb check-column" id="cb">
					<label for="cb-select-all-1" class="screen-reader-text"><?php _e( 'Select All', 'import-citation' ); ?></label>
					<input type="checkbox" id="cb-select-all-1">
				</td>
				<th class="manage-column column-name column-primary" id="name" scope="col">
					<?php _e( 'Publication Title', 'import-citation' ); ?>
				</th>
				<th class="manage-column column-authors" id="authors" scope="col">
					<?php _e( 'Authors', 'import-citation' ); ?>
				</th>
				<th class="manage-column column-source" id="source" scope="col">
					<?php _e( 'Source', 'import-citation' ); ?>
				</th>
				<th class="manage-column column-date" id="date" scope="col">
					<?php _e( 'Date', 'import-citation' ); ?>
				</th>
				<th class="manage-column column-doi" id="doi" scope="col">
					<?php _e( 'DOI', 'import-citation' ); ?>
				</th>
			</tr>
			</thead>
			<tbody id="the-list">
				
			<?php
			foreach( $items as $index => $item ) :
				
				if ( empty( $item ) ) { ?>
					<tr>
						<th></th>
						<td class="citation column-primary" colspan="5">
							<?php printf( __( 'No results found for "%s."', 'import-citation' ), $queries[$index] ); ?>
						</td>
					</tr>
				<?php
				continue;
				}
				
				$authors = array();
				$doi = esc_attr( $item['DOI'] ); 
				?>
				<tr data-doi="<?php echo $doi; ?>">
					<th class="check-column" scope="row">
						<label for="checkbox_<?php echo $doi; ?>" class="screen-reader-text">
							<?php printf( __( 'Select %s', 'import-citation' ), esc_html( $item['title'][0] ) ); ?>
						</label>
						<input type="checkbox" id="checkbox_<?php echo $doi; ?>" value="<?php echo $doi; ?>" name="checked[]" checked>
					</th>
					<td class="citation column-primary">
						<a href="http://search.crossref.org/?q=<?php echo urlencode( $item['DOI'] ); ?>"><?php echo esc_html( $item['title'][0] ); ?></a>
						<?php
						foreach ( $item['author'] as $author ) {
							$authors[] = $author['given'] . ' ' . $author['family'];
						}
						?>
						</td>
					<td class="authors"><?php echo esc_html( implode( ', ', $authors ) ); ?></td>
					<td class="source"><?php echo esc_html( $item['container-title'][0] ); ?></td>
					<td class="date"><?php echo date( 'Y', strtotime( $item['created']['date-time'] ) ); ?></td>
					<td class="doi"><?php echo $doi; ?></td>
				</tr>
			<?php 
			endforeach;
			?>
				
			</tbody>
		</table>
		<p class="submit"><?php submit_button( __( 'Import Publications', 'import-citation' ), 'primary' ); ?></p>
		<?php wp_nonce_field( 'citation-select' ); ?>
		</form>
		<?php
	}
	
	
	function import( $citations ) {
		echo '<h2>'.__( 'Importing citations...', 'import-citation' ).'</h2>';
		
		$transient_key = sanitize_key( $_POST['search_id'] );
		$items = json_decode( get_transient( 'citation_search_' . $transient_key ), true );
		$queries = get_transient( 'citation_query_' . $transient_key );
		$type = get_transient( 'citation_type_' . $transient_key );
		if ( post_type_exists( sanitize_text_field( $type ) ) )
			$post_type = $type;
		else
			$post_type = 'post';
		
		foreach ( $citations as $doi ) {
			if ( !isset( $items[$doi] ) ) {
				_e( 'Could not find selected publication in stored item index.', 'import-citation' );
				continue;
			}
			$result = $this->insert_post( $items[$doi], $post_type, $queries[$doi] );
			if ( is_wp_error( $result ) )
				echo $result->get_error_message();
			else
				echo $result;
		}
		echo '<h3>';
		printf( __( 'All done. <a href="edit.php?post_type=%s">Have fun!</a>', 'import-citation' ), $post_type );
		echo '</h3>';
		do_action( 'import_done', 'citation' );
	}
	
	
	function insert_post( $item = '', $type = 'post', $citation = '' ) {
		
		if ( !is_array( $item ) )
			return;
		
		// start building the WP post object to insert
		$post = $fields = $authors = $terms = array();
		
		$date = date( 'Y-m-d H:i:s', strtotime( $item['created']['date-time'] ) );
		
		$post['post_type'] = $type;
		$post['post_content'] = '';
		$post['post_title'] = $item['title'][0];
		$post['post_excerpt'] = $citation; // original query
		$post['post_status'] = 'publish';
		$post['post_date'] = $date;
		
		$post = array_map( 'sanitize_text_field', $post );
		$post = apply_filters( 'citation_importer_postdata', $post, $item );
		
		// custom fields		
		foreach ( $item['author'] as $author ) {
			$authors[] = $author['given'] . ' ' . $author['family'];
		}
		$fields['authors'] = implode( ', ', $authors );
		$fields['doi'] = $item['DOI'];
		$fields['url'] = $item['URL'];
		$fields['pub_date'] = $date;
		$fields['source'] = $item['container-title'][0];
		if ( !empty( $item['volume'] ) )
			$fields['source'] .= ', vol. ' . $item['volume'];
		if ( !empty( $item['issue'] ) )
			$fields['source'] .= ', issue ' . $item['issue'];
		
		$fields = array_map( 'sanitize_text_field', $fields );
		$fields = apply_filters( 'citation_importer_fielddata', $fields, $post, $item );
		
		// taxonomy terms
		$terms['pubtype'] = $item['type']; // slug
		
		$terms = array_map( 'sanitize_text_field', $terms );
		$terms = apply_filters( 'citation_importer_termdata', $terms, $post, $item );
		
		//var_dump( $post, $fields, $terms ); exit;
		
		
		// create post
		$post_id = wp_insert_post( $post );
		
		// handle errors
		if ( !$post_id )
			return __( 'Could not import citation.', 'import-citation' );
			
		if ( is_wp_error( $post_id ) )
			return current_user_can( 'manage_options' ) ? $post_id->get_error_message() : __( 'Could not import citation.', 'import-citation' );
		
		// if no errors, handle custom fields
		foreach ( $fields as $name => $value ) {
			add_post_meta( $post_id, $name, $value, true );
		}
		
		// handle taxonomies
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects', 'and' );
		foreach ( $taxonomies as $tax ) {
			if ( isset( $terms[$tax->name] ) )
				wp_set_post_terms( $post_id, $terms[$tax->name], $tax->name, false );
		}
		
		// show success
		return sprintf( __( '<p>Imported the citation as <a href="%s%d">%s</a>.</p>', 'import-citation' ), 'post.php?action=edit&post=', $post_id, $post['post_title'] );
	}  // import_post()

} // class
} // class_exists( 'WP_Importer' )

global $citation_importer;
$citation_importer = new Citation_Importer();

register_importer( 'citation', __( 'Citation', 'import-citation' ), __( 'Import an HTML citation or bibliography.', 'import-citation' ), array( $citation_importer, 'dispatch' ) );



// Load Importer styles
// Echoing because enqueuing doesn't show up early enough for the ajax progress (why?)
function citation_importer_print_styles() { ?>
	<style>
	.progress {
		background-color: #f5f5f5;
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1) inset;
		height: 1.8em;
		overflow: hidden;
		position: relative;
		width: 100%;
	}
	.progress-bar {
		background-color: #0073aa;
		border-radius: 2px;
		box-shadow: 0 -1px 0 rgba(0, 0, 0, 0.15) inset;
		color: #fff;
		font-size: 1em;
		height: 1.8em;
		line-height: 1.4em;
		min-width: 2em;
		position: absolute;
		text-align: center;
		transition: width 0.2s ease 0s;
	}
	#valuenow {
		display: block;
		padding: 4px;
	}
	</style> <?php
}

add_action( 'admin_head-admin.php',  'citation_importer_print_styles' );
