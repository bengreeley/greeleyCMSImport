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
	
	public $site_url = 'www.waterville-me.gov';
	public $redirects = array();
	public $errors = array();
	public $sourceDB;
	public $sourcesite = '';
	
	public function __construct() {
		$this->sourceDB = new wpdb(DB_USER, DB_PASSWORD, 'wtvlcity_db', 'localhost');
		
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
		$rows = $this->sourceDB->get_results(  $this->sourceDB->prepare("select * from departmentlookup where depturl = '%s'" , $this->sourcesite));
		
		foreach( $rows as $currow ) {
			$addressArray = explode("\n", $currow->deptaddress, 2);
			$deptid = $currow->deptid;
			
			if(count( $addressArray )) {
				update_option( 'wtvl_address', stripslashes( $addressArray[0] ) );
			}
	
			update_option( 'wtvl_phone', $currow->deptphone );
			update_option( 'wtvl_email', $currow->deptemail );
			update_option( 'wtvl_fax', $currow->deptfax );
			update_option( 'wtvl_hours', $currow->depthours );
		
		
			// Create 'front' page of site...
			if( $this->sourcesite != '/') {
				$postCheck = get_page_by_path('front-page',OBJECT,'page');
				
				if( isset($postCheck->ID )) {
					$postID = $postCheck->ID;
				}
				else {
					$postID = '';
				}
				
				$attr = array(
								'ID' => $postID,
								'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($currow->deptdesc) ) )),
								'post_name' => 'front-page',
								'post_title' => 'Front Page',
								'post_status' => 'publish',
								'post_type' => 'page',
								'page_template' => 'page-homepage.php'
				);
				
				$postInsert = wp_insert_post( $attr, false );
		
				if( $postInsert > 0 ) {
					if( $currow->deptlogo != '' ) {
						$this->add_featuredimage( $postInsert, "http://' . $this->site_url . '/images/departments/" . $currow->deptlogo );
					}
					
					update_option( 'page_on_front', $postInsert);
					update_option( 'show_on_front', 'page' );
					update_post_meta($postInsert, 'custom_tagline', $currow->deptblurb);
					
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
		
		$this->sourceDB->show_errors();
		$rows = $this->sourceDB->get_results(  $this->sourceDB->prepare("SELECT * from contactlookup WHERE 
														department = '%s' 
														AND active_ind = 'A' 
													order by contactorder, firstName" , $deptid));
	
		if( count($rows)) {
			$postContent = '<ul id="contactList">';
		
			foreach( $rows as $currow ) {
		
				$postContent .= '<li>';
				
				if( strlen( $currow->photo )) {
					$postContent .= '<img src="http://' . $this->site_url . '/images/contacts/' . $currow->photo . '" class="alignright wp-post-image" title="'.strip_tags($currow->firstName . ' ' . $currow->lastName) . '" />';
				}
				
				$postContent .= '<h3>' . $currow->firstName . ' ' . $currow->lastName . '</h3>';
				
				if( strlen( $currow->title )) {
					$postContent .= '<em>' . $currow->title . '</em><br />';
				}
				
				if( strlen( $currow->phone )) {
					$postContent .= '<strong>Phone:</strong> ' . $currow->phone . '<br />';
				}
				
				if( strlen( $currow->email )) {
					$postContent .= '<strong>E-mail:</strong> <a href="mailto:'.$currow->email.'">' . $currow->email . '</a><br />';
				}
				
				if( strlen( $currow->biography )) {
					$postContent .= '<div class="profile-biography">'. $currow->biography . '</div>';
				}
		
				
				$postContent .= '</li>';
			}
			
			$postContent .= '</ul>';
			
			$postCheck = get_page_by_path('contacts', OBJECT, 'page');
				
			if( isset($postCheck->ID )) {
				$postID = $postCheck->ID;
			}
			else {
				$postID = '';
			}
			
			$attr = array(
							'ID' => $postID,
							'post_content' => $this->clean_html( stripslashes($postContent) ),
							'post_name' => 'contacts',
							'post_title' => 'Contacts',
							'post_status' => 'publish',
							'post_type' => 'page'
			);
			
			$postInsert = wp_insert_post( $attr, false );
			
			if($postInsert === 0) {
				add_error( 'Unable to insert contacts' );
			}
			else {
				// Add redirects...
				$this->redirects[] = '/departments/' . $this->sourcesite . '/contacts/index.php ' .
							get_the_permalink($postInsert);
				$this->redirects[] = '/departments/' . $this->sourcesite . '/contacts/ ' .
							get_the_permalink($postInsert);
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
		
		$rows = $this->sourceDB->get_results(  $this->sourceDB->prepare("SELECT * from newsannouncementlookup WHERE 
														department = '%s' AND 
														(expire_dtm IS NULL OR expire_dtm >= NOW()) 
														AND active_ind = 'A' 
													ORDER BY news_dtm DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$attachmentAppend = '';
			$postCheck = get_page_by_title($currow->newstitle,OBJECT,'post');
			
			if( isset($postCheck->ID ) && strtotime( $postCheck->post_date ) == strtotime( $currow->news_dtm) ) {
				$postID = $postCheck->ID;
			}
			else {
				$postID = '';
			}
			
			$postContent = $currow->newsdesc;
			
			// Check for attachment, add link.
			if( strlen($currow->attachment) ) {
				$attachmentAppend = '<div id="attachmentLink"><a href="http://' . $this->site_url . '/_attachments/'. $currow->attachment . '">View attachment ></a></div>';
				$postContent .= $attachmentAppend;
			}
	
			$attr = array(
							'ID' => $postID,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($postContent) ) )),
							'post_title' => stripslashes( $currow->newstitle),
							'post_status' => 'publish',
							'post_type' => 'post',
							'post_date' => $currow->news_dtm,
							'post_category' => array( get_cat_id('News'))
			);
			
			$postInsert = wp_insert_post( $attr, false );
	
			if( $postInsert == 0 ) {
				add_error( 'Unable to insert news ' . $currow->newstitle );
			}
			else {
				// Create redirects...
				$this->redirects[] = '/news/article.php?id='.$currow->newsid . ' ' .
							get_the_permalink($postInsert);
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
			
		$rows = $this->sourceDB->get_results(  $this->sourceDB->prepare("SELECT * from eventslookup WHERE 
														department = '%s' 
														AND active_ind = 'A' 
													ORDER BY added_on DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$agendaAppend = "";
			$postCheck = get_page_by_title($currow->eventtitle,OBJECT,'post');
			$categoryArray = array( get_cat_id('Event'));
			
			
			if( isset($postCheck->ID ) && strtotime( $postCheck->post_date ) == strtotime( $currow->eventstart_dtm) ) {
				$postID = $postCheck->ID;
			}
			else {
				$postID = '';
			}
			
			$postID = '';
			
			$postContent = $currow->eventdesc;
			
			// Check for agenda
			if( trim( $currow->agenda ) != "" || trim( $currow->agenda_filename ) != "" ) {
				
				if( $currow->attachment_type != '1' ) {
					$agendaAppend = '<div class="agendaLink"><h3>Agenda:</h3>';
				}
				else {
					$agendaAppend = '<div class="agendaLink"><h3>Attachment:</h3>';
				}
				
				if( $currow->attachment_type != '1' ) {
					$categoryArray[] = get_cat_id( 'Agenda' );
				}
				
				if( trim ($currow->agenda) != "") {
					$agendaAppend .= $this->clean_html(wpautop( wptexturize( stripslashes($currow->agenda) ) ));
				}
				
				if( trim( $currow->agenda_filename) != "" ) {
					$agendaAppend .= '<a href="http://' . $this->site_url . '/_attachments/'.stripslashes($currow->agenda_filename).'">';
	
					if( $currow->attachment_type != '1' ) {
						$agendaAppend .= 'Read Agenda ';
					}
					else {
						$agendaAppend .= 'Download ';
					}
					
					$agendaAppend .= '></a>';
				}
				
				$agendaAppend .= '</div>';
				$postContent .= $agendaAppend;			
			}
			
			// Check for minutes
			if( trim( $currow->minutes_filename ) != "" ) {
				$agendaAppend = '<div class="minutesLink"><h3>Minutes:</h3>';
				$categoryArray[] = get_cat_id( 'Minutes' );
				
				if( trim( $currow->minutes_filename) != "" ) {
					$agendaAppend .= "<a href=\"http://' . $this->site_url .'/_attachments/".stripslashes($currow->minutes_filename)."\">Read Minutes ></a>";
				}
				
				$agendaAppend .= '</div>';
	
				$postContent .= $agendaAppend;			
			}
			
			// Check for eventurl
			if( trim( $currow->eventurl) != "" ) {
				$postContent = '<div class="event-external-url"><a href="'. trim( $currow->eventurl ) .'">More Information ></a></div>' . $postContent;
			}
			
			
			$attr = array(
							'ID' => $postID,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($postContent) ) )),
							'post_title' => stripslashes( $currow->eventtitle),
							'post_status' => 'publish',
							'post_type' => 'post',
							'post_date' => $currow->added_on,
							'post_category' => $categoryArray
			);
			
			$postInsert = wp_insert_post( $attr, false );
	
			if( $postInsert == 0 ) {
				add_error( 'Unable to insert event ' . $currow->eventtitle );
			}
			else {
				if( $currow->eventimage != '' ) {
					$this->add_featuredimage( $postInsert, "http://" . $this->site_url . "/images/events/" . $currow->eventimage );					
				}
				
				// Set other custom fields
				// Start date
				// End date
				// All day event
				// Event location
				update_field('field_54b5c2d19f04f',  $currow->eventstart_dtm, $postInsert);		// Start date/time
				update_field('field_54b5c3189f050', $currow->eventend_dtm, $postInsert);		// End date/time
				
				if( date('H:i:s',strtotime( $currow->eventstart_dtm)) == '00:00:00' &&
					date('H:i:s',strtotime( $currow->eventend_dtm)) == '00:00:00') {
						update_field('field_54b72bad18811', 'Yes', $postInsert);							// All day event
				}
				
				if( strlen( $currow->eventcontact )) {
					update_field('field_54b5c3339f051', stripslashes($currow->eventcontact), $postInsert); // Location
				}
				
				// Create redirects...
				$this->redirects[] = '/events/view-event.php?id='.$currow->eventid . ' ' .
							get_the_permalink($postInsert);
	
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
		
		$rows = $this->sourceDB->get_results(  $this->sourceDB->prepare("SELECT * from contentlookup WHERE 
														department = '%s' 
														AND active_ind = 'A' AND
														(expire_dtm IS NULL or expire_dtm = '' OR expire_dtm >= NOW()) AND
														(contenttext <> '' OR filename <> '')
													
													ORDER BY filename, added_on DESC" , $deptid));
		
		foreach( $rows as $currow ) {
			$postContent = '';
			
			$postCheck = get_page_by_title($currow->contenttitle, OBJECT, 'page');
			
			if( isset($postCheck->ID ) && strtotime( $postCheck->post_date ) == strtotime( $currow->added_on) ) {
				$postID = $postCheck->ID;
			}
			else {
				$postID = '';
			}
			
			if( strlen( $currow->filename) ) {
				// Content is a link to a file. Create a page that has a link to this content...
				$postContent .= '<p>To view the content, click on the link below.</p>';
	            $postContent .=  '<div class="filedownload">';
	                
	            $postContent .=  '<a href="/_attachments/'. $currow->filename .'">'. $currow->contenttitle .' ></a><br />';
	            $ext = substr(strrchr( $currow->filename, '.'), 1);
	          
	            switch($ext) {
	                case 'pdf':
	                    $postContent .=  " (PDF document)";
	                    break;
	                case 'doc':
	                case 'docx':
	                    $postContent .=  " (Microsoft Word document)";                    
	                    break;
	                case 'xls':
	                    $postContent .=  " (Microsoft Excel document)";
	                    break;
	                default:   
	                    break;
	            }
	            $postContent .=  '</em>';                            
	            $postContent .=  '</div>';
			}
			
			if( strlen( trim($currow->contenttext))) {
				// Content is text. Create a page that is based off of this...
				$postContent.= '<p>'. $currow->contenttext .'</p>';
			}
			
			$attr = array(
							'ID' => $postID,
							'post_content' => $this->clean_html(wpautop( wptexturize( stripslashes($postContent) ) )),
							'post_title' => strip_tags($currow->contenttitle),
							'post_date' => $currow->added_on,
							'post_status' => 'publish',
							'post_type' => 'page'
			);
			
			$postInsert = wp_insert_post( $attr, false );
			
			if( $postInsert == 0 ) {
				add_error( 'Unable to insert page ' . $currow->contenttitle );
			}
			
			// Create redirects...
			$this->redirects[] = '/departments/'. $this->sourcesite . '/content/' . $currow->contentid . '/'.$this->seotidy($currow->contenttitle) .'.php '.
							get_the_permalink($postInsert);
			
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
	public function add_featuredimage( $postInsert, $filename ) {
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
		
		$attach_id = wp_insert_attachment( $attachment, $file, $postInsert );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
	
		// Generate the metadata for the attachment, and update the database record.
		$attach_data = wp_generate_attachment_metadata( $attach_id, $file);
	
		wp_update_attachment_metadata( $attach_id, $attach_data );
		set_post_thumbnail( $postInsert, $attach_id );
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