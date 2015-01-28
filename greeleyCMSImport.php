<?php
/*
 * Plugin Name:       Greeley CMS Import
 * Plugin URI:        http://www.bengreeley.com
 * Description:       Import scripts for importing from Greeley CMS
 * Version:           1.0
 * Author:            Ben Greeley
 * Author URI:        http://www.bengreeley.com
 */	

/*
	[importsite] Shortcode:
	ex. [importsite sourcesite="fire"]
	
	Will import a site from a 'Greeley CMS' website to the current site that this script is run on. Add this shortcode to any page to kick off the import.
	This script can take a couple of minutes to execute with departments that contain thousands of records to create.
*/

class greeleyCMSImport  {
	
	public $site_url = 'www.waterville-me.gov';		// CHANGE THIS to the URL of the site you are updating
	public $redirects = array();
	public $errors = array();
	public $source_db;
	public $sourcesite = '';
	
	public function __construct() {
		$this->source_db = new wpdb(DB_USER, DB_PASSWORD, 'wtvlcity_db', 'localhost');
		
		add_shortcode( 'importsite', array( $this, 'site_import'));
	}
	
	/*
		Site import function/shortcode	
	*/
	public function site_import($atts){
		$return = '';
		
		set_time_limit( 99999 );			// Make sure we don't time out on larger websites
		
		extract( shortcode_atts( array(
				'sourcesite' => '',			// Site URL to pull from (eg. police, fire, etc)
				
			), $atts, 'importsite' ) );
			
		
		if( !strlen( $sourcesite )) {
			return false;
		}
		else {
			$this->sourcesite = $sourcesite;
		}
		
		ob_start();
		
		// Import inintial department information based on sourcesite variable...
		$deptid = $this->importDefaultInfo();
		
		if( !isset( $deptid ) || !is_numeric( $deptid ) || $deptid < 1 ) {
			echo 'No department ID set';
			return false;
		}

		// Import rest of content...
		$this->importContacts( $deptid );
		$this->importNews( $deptid );
		$this->importEvents ( $deptid );
		$this->importContent( $deptid );
		
		// Imports completed...check for errors and output
		if( !count( $this->errors ) ) {
		
			echo '<div><strong>Import completed successfully.</strong></div>';
		}
		else {
			echo '<div><strong style="color:red;">Import completed with errors:</strong></div>';
			
			foreach( $this->errors as $curerror ) {
				echo $curerror . '<br />';
			}
		}
	
		echo '<h3>' . count($this->redirects) .' Redirects (copy to redirect file)</h3><textarea rows="50" style="width:800px;font-size: .7em;">';	
		foreach( $this->redirects as $redirect ) {
			echo 'Redirect 301' . $redirect . "\n";
		}
		echo '</textarea>';
		
		$return = ob_get_contents();
		ob_end_clean();
		
		return $return;
		
	}
	
	/*
		DEPARTMENT DEFAULT INFO
		Import from departmentlookup and set options (d.deptphone, d.deptemail, d.deptaddress, d.deptfax, d.deptlogo (import)	
		
		Returns integer of department id or 0 if error
	*/
	public function importDefaultInfo() {
		
		$deptid = 0;
		$rows = $this->source_db->get_results(  $this->source_db->prepare("select * from departmentlookup where depturl = '%s'" , $this->sourcesite));
		
		foreach( $rows as $currow ) {
			$address_array = explode("\n", $currow->deptaddress, 2);
			$deptid = $currow->deptid;
			
			if(count( $address_array )) {
				update_option( 'wtvl_address', stripslashes( $address_array[0] ) );
			}
	
			update_option( 'wtvl_phone', $currow->deptphone );
			update_option( 'wtvl_email', $currow->deptemail );
			update_option( 'wtvl_fax', $currow->deptfax );
			update_option( 'wtvl_hours', $currow->depthours );
		
		
			// Create 'front' page of site...
			if( $this->sourcesite != '/') {
				$post_check = get_page_by_path('front-page',OBJECT,'page');
				
				if( isset($post_check->ID )) {
					$post_id = $post_check->ID;
				}
				else {
					$post_id = '';
				}
				
				$attr = array(
								'ID' => $post_id,
								'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($currow->deptdesc) ) )),
								'post_name' => 'front-page',
								'post_title' => 'Front Page',
								'post_status' => 'publish',
								'post_type' => 'page',
								'page_template' => 'page-homepage.php'
				);
				
				$post_insert = wp_insert_post( $attr, false );
		
				if( $post_insert > 0 ) {
					if( $currow->deptlogo != '' ) {
						$this->add_featuredimage( $post_insert, "http://' . $this->site_url . '/images/departments/" . $currow->deptlogo );
					}
					
					update_option( 'page_on_front', $post_insert);
					update_option( 'show_on_front', 'page' );
					update_post_meta($post_insert, 'custom_tagline', $currow->deptblurb);
					
					// Create redirects...
					$this->redirects[] = '/departments/'.$this->sourcesite.'/index.php '. get_blog_details('path');
					$this->redirects[] = '/departments/'.$this->sourcesite.'/ '. get_blog_details('path');
				}
			}
		}
		
		return $deptid;
	}
	
	/*
		CONTACTS
			Compile into one new page for contacts	
	*/
	public function importContacts( $deptid ) {
		
		if( !is_numeric( $deptid )) {
			return false;
		}
		
		$this->source_db->show_errors();
		$rows = $this->source_db->get_results(  $this->source_db->prepare("SELECT * from contactlookup WHERE 
														department = '%s' 
														AND active_ind = 'A' 
													order by contactorder, firstName" , $deptid));
	
		if( count($rows)) {
			$post_content = '<ul id="contactList">';
		
			foreach( $rows as $currow ) {
		
				$post_content .= '<li>';
				
				if( strlen( $currow->photo )) {
					$post_content .= '<img src="http://' . $this->site_url . '/images/contacts/' . $currow->photo . '" class="alignright wp-post-image" title="'.strip_tags($currow->firstName . ' ' . $currow->lastName) . '" />';
				}
				
				$post_content .= '<h3>' . $currow->firstName . ' ' . $currow->lastName . '</h3>';
				
				if( strlen( $currow->title )) {
					$post_content .= '<em>' . $currow->title . '</em><br />';
				}
				
				if( strlen( $currow->phone )) {
					$post_content .= '<strong>Phone:</strong> ' . $currow->phone . '<br />';
				}
				
				if( strlen( $currow->email )) {
					$post_content .= '<strong>E-mail:</strong> <a href="mailto:'.$currow->email.'">' . $currow->email . '</a><br />';
				}
				
				if( strlen( $currow->biography )) {
					$post_content .= '<div class="profile-biography">'. $currow->biography . '</div>';
				}
		
				
				$post_content .= '</li>';
			}
			
			$post_content .= '</ul>';
			
			$post_check = get_page_by_path('contacts', OBJECT, 'page');
				
			if( isset($post_check->ID )) {
				$post_id = $post_check->ID;
			}
			else {
				$post_id = '';
			}
			
			$attr = array(
							'ID' => $post_id,
							'post_content' => $this->clean_html( stripslashes($post_content) ),
							'post_name' => 'contacts',
							'post_title' => 'Contacts',
							'post_status' => 'publish',
							'post_type' => 'page'
			);
			
			$post_insert = wp_insert_post( $attr, false );
			
			if($post_insert === 0) {
				add_error( 'Unable to insert contacts' );
			}
			else {
				// Add redirects...
				$this->redirects[] = '/departments/' . $this->sourcesite . '/contacts/index.php ' .
							get_the_permalink($post_insert);
				$this->redirects[] = '/departments/' . $this->sourcesite . '/contacts/ ' .
							get_the_permalink($post_insert);
			}
		}
	}
	
	
	/*
		NEWS
		
		Import from newsannouncementlookup AS post categorized 'news'	
	*/
	public function importNews( $deptid ) {
		
		if( !is_numeric( $deptid ) ) {
			return false;
		}
		
		$rows = $this->source_db->get_results(  $this->source_db->prepare("SELECT * from newsannouncementlookup WHERE 
														department = '%s' AND 
														(expire_dtm IS NULL OR expire_dtm >= NOW()) 
														AND active_ind = 'A' 
													ORDER BY news_dtm DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$attachment_append = '';
			$post_check = get_page_by_title($currow->newstitle,OBJECT,'post');
			
			if( isset($post_check->ID ) && strtotime( $post_check->post_date ) == strtotime( $currow->news_dtm) ) {
				$post_id = $post_check->ID;
			}
			else {
				$post_id = '';
			}
			
			$post_content = $currow->newsdesc;
			
			// Check for attachment, add link.
			if( strlen($currow->attachment) ) {
				$attachment_append = '<div id="attachmentLink"><a href="http://' . $this->site_url . '/_attachments/'. $currow->attachment . '">View attachment ></a></div>';
				$post_content .= $attachment_append;
			}
	
			$attr = array(
							'ID' => $post_id,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($post_content) ) )),
							'post_title' => stripslashes( $currow->newstitle),
							'post_status' => 'publish',
							'post_type' => 'post',
							'post_date' => $currow->news_dtm,
							'post_category' => array( get_cat_id('News'))
			);
			
			$post_insert = wp_insert_post( $attr, false );
	
			if( $post_insert == 0 ) {
				add_error( 'Unable to insert news ' . $currow->newstitle );
			}
			else {
				// Create redirects...
				$this->redirects[] = '/news/article.php?id='.$currow->newsid . ' ' .
							get_the_permalink($post_insert);
			}
			
		}
		
		return;
	}
	
	/*
		EVENTS
		Import from eventslookup AS post categorized 'events'
			Add custom field values for date/time/location/etc.
	*/
	public function importEvents( $deptid ) {
		
		if( !is_numeric( $deptid )) {
			return false;
		}
			
		$rows = $this->source_db->get_results(  $this->source_db->prepare("SELECT * from eventslookup WHERE 
														department = '%s' 
														AND active_ind = 'A' 
													ORDER BY added_on DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$agenda_append = "";
			$post_check = get_page_by_title($currow->eventtitle,OBJECT,'post');
			$categoryArray = array( get_cat_id('Event'));
			
			
			if( isset($post_check->ID ) && strtotime( $post_check->post_date ) == strtotime( $currow->eventstart_dtm) ) {
				$post_id = $post_check->ID;
			}
			else {
				$post_id = '';
			}
			
			$post_id = '';
			
			$post_content = $currow->eventdesc;
			
			// Check for agenda
			if( trim( $currow->agenda ) != "" || trim( $currow->agenda_filename ) != "" ) {
				
				if( $currow->attachment_type != '1' ) {
					$agenda_append = '<div class="agendaLink"><h3>Agenda:</h3>';
				}
				else {
					$agenda_append = '<div class="agendaLink"><h3>Attachment:</h3>';
				}
				
				if( $currow->attachment_type != '1' ) {
					$categoryArray[] = get_cat_id( 'Agenda' );
				}
				
				if( trim ($currow->agenda) != "") {
					$agenda_append .= $this->clean_html(wpautop( wptexturize( stripslashes($currow->agenda) ) ));
				}
				
				if( trim( $currow->agenda_filename) != "" ) {
					$agenda_append .= '<a href="http://' . $this->site_url . '/_attachments/'.stripslashes($currow->agenda_filename).'">';
	
					if( $currow->attachment_type != '1' ) {
						$agenda_append .= 'Read Agenda ';
					}
					else {
						$agenda_append .= 'Download ';
					}
					
					$agenda_append .= '></a>';
				}
				
				$agenda_append .= '</div>';
				$post_content .= $agenda_append;			
			}
			
			// Check for minutes
			if( trim( $currow->minutes_filename ) != "" ) {
				$agenda_append = '<div class="minutesLink"><h3>Minutes:</h3>';
				$categoryArray[] = get_cat_id( 'Minutes' );
				
				if( trim( $currow->minutes_filename) != "" ) {
					$agenda_append .= "<a href=\"http://' . $this->site_url .'/_attachments/".stripslashes($currow->minutes_filename)."\">Read Minutes ></a>";
				}
				
				$agenda_append .= '</div>';
	
				$post_content .= $agenda_append;			
			}
			
			// Check for eventurl
			if( trim( $currow->eventurl) != "" ) {
				$post_content = '<div class="event-external-url"><a href="'. trim( $currow->eventurl ) .'">More Information ></a></div>' . $post_content;
			}
			
			
			$attr = array(
							'ID' => $post_id,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($post_content) ) )),
							'post_title' => stripslashes( $currow->eventtitle),
							'post_status' => 'publish',
							'post_type' => 'post',
							'post_date' => $currow->added_on,
							'post_category' => $categoryArray
			);
			
			$post_insert = wp_insert_post( $attr, false );
	
			if( $post_insert == 0 ) {
				add_error( 'Unable to insert event ' . $currow->eventtitle );
			}
			else {
				if( $currow->eventimage != '' ) {
					$this->add_featuredimage( $post_insert, "http://" . $this->site_url . "/images/events/" . $currow->eventimage );					
				}
				
				// Set other custom fields
				// Start date
				// End date
				// All day event
				// Event location
				update_field('field_54b5c2d19f04f',  $currow->eventstart_dtm, $post_insert);		// Start date/time
				update_field('field_54b5c3189f050', $currow->eventend_dtm, $post_insert);		// End date/time
				
				if( date('H:i:s',strtotime( $currow->eventstart_dtm)) == '00:00:00' &&
					date('H:i:s',strtotime( $currow->eventend_dtm)) == '00:00:00') {
						update_field('field_54b72bad18811', 'Yes', $post_insert);							// All day event
				}
				
				if( strlen( $currow->eventcontact )) {
					update_field('field_54b5c3339f051', stripslashes($currow->eventcontact), $post_insert); // Location
				}
				
				// Create redirects...
				$this->redirects[] = '/events/view-event.php?id='.$currow->eventid . ' ' .
							get_the_permalink($post_insert);
	
			}
		}
		
		return;
	}
	
	/*
		CONTENT
			Different types of content to import.
			- Filename not null: Create page and link to the content
			- Contenttext not null: Create page with content
			- Ignore links (contenturl entered)
			
			Import as pages	
	*/
	
	public function importContent( $deptid ) {
		
		if( !is_numeric( $deptid )) {
			return;
		}	
		
		$rows = $this->source_db->get_results(  $this->source_db->prepare("SELECT * from contentlookup WHERE 
														department = '%s' 
														AND active_ind = 'A' AND
														(expire_dtm IS NULL or expire_dtm = '' OR expire_dtm >= NOW()) AND
														(contenttext <> '' OR filename <> '')
													
													ORDER BY filename, added_on DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$post_content = '';
			
			$post_check = get_page_by_title($currow->contenttitle, OBJECT, 'page');
			
			if( isset($post_check->ID ) && strtotime( $post_check->post_date ) == strtotime( $currow->added_on) ) {
				$post_id = $post_check->ID;
			}
			else {
				$post_id = '';
			}
			
			if( strlen( $currow->filename) ) {
				// Content is a link to a file. Create a page that has a link to this content...
				$post_content .= '<p>To view the content, click on the link below.</p>';
	            $post_content .=  '<div class="filedownload">';
	                
	            $post_content .=  '<a href="/_attachments/'. $currow->filename .'">'. $currow->contenttitle .' ></a><br />';
	            $ext = substr(strrchr( $currow->filename, '.'), 1);
	          
	            switch($ext) {
	                case 'pdf':
	                    $post_content .=  " (PDF document)";
	                    break;
	                case 'doc':
	                case 'docx':
	                    $post_content .=  " (Microsoft Word document)";                    
	                    break;
	                case 'xls':
	                    $post_content .=  " (Microsoft Excel document)";
	                    break;
	                default:   
	                    break;
	            }
	            $post_content .=  '</em>';                            
	            $post_content .=  '</div>';
			}
			
			if( strlen( trim($currow->contenttext))) {
				// Content is text. Create a page that is based off of this...
				$post_content.= '<p>'. $currow->contenttext .'</p>';
			}
			
			$attr = array(
							'ID' => $post_id,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($post_content) ) )),
							'post_title' => strip_tags($currow->contenttitle),
							'post_date' => $currow->added_on,
							'post_status' => 'publish',
							'post_type' => 'page'
			);
			
			$post_insert = wp_insert_post( $attr, false );
			
			if( $post_insert == 0 ) {
				add_error( 'Unable to insert page ' . $currow->contenttitle );
			}
			
			// Create redirects...
			$this->redirects[] = '/departments/'. $this->sourcesite . '/content/' . $currow->contentid . '/'.$this->seotidy($currow->contenttitle) .'.php '.
							get_the_permalink($post_insert);
			
		}
		
		return;
	}
	
	/*
		Add error to passed error array...	
	*/
	public function add_error( $errormessage ) {
		$this->errors[] = $errormessage;
		return;
	}
	
	/*
		Logic to add featured image and download if fily doesn't exist.
	*/
	public function add_featuredimage( $post_insert, $filename ) {
		$image_data = file_get_contents( $filename );
		$filename = basename( $filename );
		$wp_upload_dir = wp_upload_dir();
		
		if( wp_mkdir_p( $wp_upload_dir['path'] ) ) {
		    $file = $wp_upload_dir['path'] . '/' . $filename;
		} else {
		    $file = $wp_upload_dir['basedir'] . '/' . $filename;
		}
		
		if( !file_exists( $file ) ) {
			file_put_contents( $file, $image_data );
		}
		
		$wp_filetype = wp_check_filetype($filename, null );
		
		$attachment = array(
			'post_mime_type' => $wp_filetype['type'],
			'post_title' => sanitize_file_name($filename),
			'post_content' => '',
			'post_status' => 'inherit'
		);
		
		$attach_id = wp_insert_attachment( $attachment, $file, $post_insert );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
	
		// Generate the metadata for the attachment, and update the database record.
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file);
	
		wp_update_attachment_metadata( $attach_id, $attach_data );
		set_post_thumbnail( $post_insert, $attach_id );
		return;
	}
	
	public function clean_html( $string, $allowtags = '<a><b><strong><i><em><br><p><div><table><td><tr><tbody><h1><h2><h3><h4><h5><h6><iframe><object><embed><ul><li><span>', $allowattributes = NULL ) {
		// from: http://us3.php.net/manual/en/function.strip-tags.php#91498
	    $string = strip_tags($string,$allowtags);
	    if (!is_null($allowattributes)) {
	        if(!is_array($allowattributes))
	            $allowattributes = explode(",",$allowattributes);
	        if(is_array($allowattributes))
	            $allowattributes = implode(")(?<!",$allowattributes);
	        if (strlen($allowattributes) > 0)
	            $allowattributes = "(?<!".$allowattributes.")";
	        $string = preg_replace_callback("/<[^>]*>/i",create_function(
	            '$matches',
	            'return preg_replace("/ [^ =]*'.$allowattributes.'=(\"[^\"]*\"|\'[^\']*\')/i", "", $matches[0]);'   
	        ),$string);
	    }
		// reduce line breaks and remove empty tags
		$string = str_replace( "Õ", "'", $string );
		$string = str_replace( '\n', ' ', $string ); 
		$string = preg_replace( "/<[^\/>]*>([\s]?)*<\/[^>]*>/", ' ', $string );
		// get rid of remaining newlines; basic HTML cleanup
		$string = str_replace('&#13;', ' ', $string); 
		$string = preg_replace("/[\n\r]/", " ", $string); 
		$string = preg_replace_callback('|<(/?[A-Z]+)|', create_function('$match', 'return "<" . strtolower($match[1]);'), $string);
		$string = str_replace('<br>', '<br />', $string);
		$string = str_replace('<hr>', '<hr />', $string);
		$string = str_replace('&nbsp;&nbsp;', '&nbsp;', $string);
		$string = str_replace('&nbsp;', ' ', $string);
		$string = preg_replace('/(<[^>]+) style=".*?"/i', '$1', $string);
		return $string;
	}
	
	/*
		Generate old page name (same script that was used on old CMS)...
	*/
	public function seotidy($passedvalue) {
	    return strtolower(str_replace("'","",(str_replace('\\','-',str_replace('/','-',str_replace('&','and',str_replace(' ','-',($passedvalue))))))));
	}
}

$greeleyCMSImport = new greeleyCMSImport();